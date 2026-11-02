<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new class: the base of the VMware steps (VMs, Snapshots, Inventory, Alarms, API; not registered itself), through a VMware credential of the project
    (CredentialVmware, provides `vmware`; util\VSphere: vCenter's REST API and VI/JSON). As NodeAppVeeam:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every VMware step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the query of a list, or to the body of an action), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (vCenter's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (vCenter answers whole lists: the first At most kept); another operation's: vCenter's answer, or what the step says
	- a VM, host, cluster, ... given by its id (vm-42) or by its name (exactly; resolve())
*/

namespace models\node;

abstract class NodeAppVmware extends \models\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-boxes';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'virtualization';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'vmware';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 3600;
    //what resolve() looks up: REST collection => [ the id's key in its items, the label ]
    const KINDS = [
	'vm' => [ 'vm', 'VM' ],
	'host' => [ 'host', 'Host' ],
	'cluster' => [ 'cluster', 'Cluster' ],
	'datacenter' => [ 'datacenter', 'Datacenter' ],
	'folder' => [ 'folder', 'Folder' ],
	'datastore' => [ 'datastore', 'Datastore' ],
	'network' => [ 'network', 'Network' ],
	'resource-pool' => [ 'resource_pool', 'Resource pool' ],
    ];

    //the client of the run
    protected $vc = null;

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
	    foreach(\models\Credential::choicesFor('vmware', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'VMware credential',
		'choices' => $choices,
		'help' => 'A VMware vCenter credential of the project',
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
	    'help' => 'A JSON object added to the query of a list (e.g. {"datacenters": "datacenter-3"}) or to the body of an action: see the vSphere API reference',
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
	    'default' => 120,
	    'help' => 'Waiting for a task or a power state included',
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
	    'help' => 'vCenter\'s errors, or no connection',
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
		'total' => [ 'type' => 'number', 'info' => 'the items vCenter gave' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => static::resultInfo($op) ];
    }

    //what an item of list $op is (the variables' help)
    protected static function itemInfo($op) {
	return 'an item, as vCenter gives it';
    }

    //the variable of operation $op (not a list)
    protected static function resultInfo($op) {
	return [ 'type' => 'any', 'info' => 'vCenter\'s answer (null on error)' ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'vmware')))) {
	    $this->errors('config', static::$label . ': choose a VMware vCenter credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//CLD: 2026-11-01T21:50:00+02:00: the settings through settingValue() (as they are when the step runs as a tool: AgentToolTrait)
	$vars = $this->varsForSettings();
	$value = fn($key) => $this->settingValue($this->cfg($key, ''), $vars);
	$text = fn($key) => trim(\util\Expression::toText($value($key)));
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'vmware');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->vc = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $text, $value);
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	//CLD: 2026-11-01T21:50:00+02:00: setResult() (a tool keeps it; was the variable only)
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

    //a GET of the REST API (Other parameters in the query)
    protected function get($path, array $query = []) {
	return $this->vc->request('GET', $path, array_replace($query, $this->extraParams()));
    }

    //an action of the REST API (POST; Other parameters in the body; $body null: none)
    protected function post($path, array $query = [], $body = null) {
	$extra = $this->extraParams();
	if ($extra)
	    $body = array_replace_recursive((array)$body, $extra);
	return $this->vc->request('POST', $path, $query, $body);
    }

    //a VI/JSON method (Other parameters in the body)
    protected function invoke($path, array $body = []) {
	return $this->vc->vim('POST', $path, array_replace_recursive($body, $this->extraParams()));
    }

    //a list of the REST API: [ { items, count, total, truncated }, summary ] (vCenter answers the whole list; the first At most kept)
    protected function listOf($path, array $query = [], $what = 'item', ?\Closure $map = null) {
	$all = $this->vc->request('GET', $path, array_replace($query, $this->extraParams()));
	return $this->listResult(is_array($all) ? array_values($all) : [], $what, $map);
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

    // ------------------------------------------------------------------------------------
    //values

    //the id of a VM, host, cluster, ... ($kind: KINDS) given by its id (vm-42), an item of a list ({ vm: ... }) or its name (exactly); \RuntimeException when there is none,
    //or several of that name
    protected function resolve($kind, $value, $label = null) {
	[ $key, $kindLabel ] = self::KINDS[$kind];
	$label = $label ?? $kindLabel;
	if (is_array($value))
	    $value = $value[$key] ?? $value['id'] ?? $value['value'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (preg_match(\util\VSphere::ID, $v))
	    return $v;
	$found = array_values(array_filter((array)$this->vc->request('GET', "/api/vcenter/{$kind}", [ 'names' => $v ]), fn($x) => (string)($x['name'] ?? '') === $v));
	if (!$found)
	    throw new \RuntimeException("{$label}: none named `{$v}`");
	if (count($found) > 1)
	    throw new \RuntimeException("{$label}: several are named `{$v}`: give its id");
	return (string)$found[0][$key];
    }

    //a list of names or ids (one per line, or comma separated; or a list) resolved to ids; [] when empty
    protected function resolveAll($kind, $value, $label = null) {
	$list = is_array($value) && array_is_list($value) ? $value : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	$ids = [];
	foreach($list as $one) {
	    if (is_string($one) && trim($one) === '')
		continue;
	    $ids[] = $this->resolve($kind, $one, $label);
	}
	return array_values(array_unique($ids));
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
