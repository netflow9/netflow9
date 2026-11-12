<?php

/*
    the base of the Proxmox VE steps (Guests, Snapshots, Backups, Cluster, API; not registered itself), through a Proxmox credential of the
    project (CredentialProxmox, provides `proxmox`; util\Proxmox). As NodeAppProxmox:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Proxmox step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the parameters of the request), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Proxmox's errors,
	    no connection, a failed task, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (Proxmox answers whole lists: the first At most kept)
	- a guest (VM or container) given by its VMID, its name (exactly) or an item of a list: its node and type found in the cluster's resources (guest())
	- an action's task waited for until it stops (Wait for the task), within the step's Timeout
*/

namespace models\node;

abstract class NodeAppProxmox extends \models\wf\WfNode {
    //its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-hdd-rack';
    public static $category = 'virtualization';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'proxmox';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 3600;

    //the client of the run
    protected $pve = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('proxmox', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Proxmox credential',
		'choices' => $choices,
		'help' => 'A Proxmox VE credential of the project',
		'required' => true,
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => static::$defaultOperation,
		'choices' => static::OPERATIONS,
	    ],
	];
	foreach(static::operationFields() as $f) {
	    $ops = $f['ops'] ?? null;
	    unset($f['ops']);
	    $f += [ 'default' => '' ];
	    if ($ops !== null) {
		$f['ui'] = ($f['ui'] ?? []) + [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ] ];
		if (!empty($f['required'])) {
		    unset($f['required']);
		    $f['requiredIf'] = [ 'if' => 'operation', 'in' => $ops ];
		}
	    }
	    $fields[] = $f;
	}
	$fields[] = [
	    'key' => 'params',
	    'type' => 'textarea',
	    'label' => 'Other parameters',
	    'default' => '',
	    'help' => 'A JSON object added to the request\'s parameters (e.g. {"bwlimit": 50000}): see the Proxmox VE API viewer',
	    'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ],
	];
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 1000,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => 300,
	    'help' => 'Waiting for its task included',
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, total, truncated }',
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	];
	$fields[] = [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => 'Proxmox\'s errors, a failed task, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => static::itemInfo($op) ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items kept' ],
		'total' => [ 'type' => 'number', 'info' => 'the items Proxmox gave' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => static::resultInfo($op) ];
    }

    //what an item of list $op is (the variables' help)
    protected static function itemInfo($op) {
	return 'an item, as Proxmox gives it';
    }

    //the variable of operation $op (not a list)
    protected static function resultInfo($op) {
	return [ 'type' => 'any', 'info' => 'Proxmox\'s answer (null on error)' ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'proxmox')))) {
	    $this->errors('config', static::$label . ': choose a Proxmox VE credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//the settings through settingValue() (as they are when the step runs as a tool: AgentToolTrait)
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'proxmox');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->pve = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $this->settingText(...), $this->settingOf(...));
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	//setResult() (a tool keeps it)
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //Other parameters: a JSON object (or an expression giving one); [] when empty
    protected function extraParams() {
	$raw = trim((string)$this->cfg('params', ''));
	if ($raw === '')
	    return [];
	$v = \util\Expression::render($raw, $this->exec->expressionVariables());
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException('Other parameters: not a JSON object');
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException('Other parameters: not a JSON object');
	return $v;
    }

    //a request with Other parameters added
    protected function call($method, $path, array $params = []) {
	return $this->pve->request($method, $path, array_replace($params, $this->extraParams()));
    }

    //a list of a GET: [ { items, count, total, truncated }, summary ]; $keep: the items kept, $map: each changed
    protected function listOf($path, array $query = [], $what = 'item', ?\Closure $keep = null, ?\Closure $map = null) {
	$all = $this->call('GET', $path, $query);
	$all = is_array($all) ? array_values($all) : [];
	if ($keep)
	    $all = array_values(array_filter($all, $keep));
	return $this->listResult($all, $what, $map);
    }

    //a list's result of items read: [ { items, count, total, truncated }, summary ]
    protected function listResult(array $all, $what, ?\Closure $map = null) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$items = array_slice($all, 0, $max);
	if ($map)
	    $items = array_map($map, $items);
	$truncated = count($all) > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => count($all), 'truncated' => $truncated ],
	    \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . count($all) . ' (truncated)' : '') ];
    }

    //an action's task: waited for until it stops when Wait for the task is on; [ upid, its status or null ]
    protected function task($upid, $what) {
	if (!is_string($upid) || \util\Proxmox::taskNode($upid) === null)
	    return [ is_string($upid) ? $upid : null, null ];
	if (!$this->cfg('wait', true))
	    return [ $upid, null ];
	return [ $upid, $this->pve->waitTask($upid, $what) ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //the guests (VMs and containers) of the cluster: GET /cluster/resources?type=vm
    protected function guests() {
	return array_values(array_filter((array)$this->pve->request('GET', '/cluster/resources', [ 'type' => 'vm' ]), fn($g) => isset($g['vmid'])));
    }

    //a guest by its VMID, its name (exactly) or an item of a list: { vmid, node, type (qemu / lxc), name, status, path: /nodes/<node>/<type>/<vmid> }; \RuntimeException
    //when there is none, or several of that name
    protected function guest($value, $label = 'Guest') {
	if (is_array($value))
	    $value = $value['vmid'] ?? $value['name'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	$all = $this->guests();
	$found = array_values(ctype_digit($v) ? array_filter($all, fn($g) => (string)$g['vmid'] === $v) : array_filter($all, fn($g) => (string)($g['name'] ?? '') === $v));
	if (!$found)
	    throw new \RuntimeException("{$label}: " . (ctype_digit($v) ? "no VM or container {$v}" : "none named `{$v}`"));
	if (count($found) > 1)
	    throw new \RuntimeException("{$label}: several are named `{$v}`: give its VMID");
	$g = $found[0];
	$type = (string)($g['type'] ?? 'qemu');
	return [ 'vmid' => (int)$g['vmid'], 'node' => (string)$g['node'], 'type' => $type, 'name' => (string)($g['name'] ?? ''), 'status' => (string)($g['status'] ?? ''),
	    'path' => "/nodes/{$g['node']}/{$type}/{$g['vmid']}" ];
    }

    //a node's name (as Proxmox shows it)
    public static function nodeName($value, $label = 'Node') {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['node'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,62}$/', $v))
	    throw new \RuntimeException("{$label}: not a node name: `" . mb_substr($v, 0, 64) . '`');
	return $v;
    }

    //a whole number setting (empty: $default)
    public static function number($value, $label, $min, $max, $default = null) {
	$v = trim(\util\Expression::toText($value));
	if ($v === '' && $default !== null)
	    return $default;
	if (!preg_match('/^-?\d+$/', $v) || (int)$v < $min || (int)$v > $max)
	    throw new \RuntimeException("{$label}: a whole number from {$min} to {$max}, not `" . mb_substr($v, 0, 32) . '`');
	return (int)$v;
    }
}
