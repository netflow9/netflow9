<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new class: the base of the FortiGate steps (Addresses, Banned IPs, Policies, Users & VPN, System, API; not registered itself), through a FortiGate
    credential of the project (CredentialFortiGate, provides `fortigate`; util\FortiGate: FortiOS REST API). As NodeAppVeeam:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every FortiGate step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the query of a list, or to the body of an action), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (FortiOS's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (up to At most); another operation's: FortiOS's results as they are
	- FortiOS lists are not paged: At most is asked as `count` (one more, to tell it was cut)
*/

namespace models\node;

abstract class NodeAppFortiGate extends \models\wf\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-bricks';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'fgt';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $fgt = null;

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
	    foreach(\models\Credential::choicesFor('fortigate', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'FortiGate credential',
		'choices' => $choices,
		'help' => 'A FortiGate credential of the project',
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
	    'help' => 'A JSON object added to the query of a read (e.g. {"filter": "name=@web"}) or to the body of a change: see the FortiOS REST API reference',
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
	    'default' => 60,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, total, truncated }; else FortiOS\'s results',
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
	    'help' => 'FortiOS\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as FortiOS gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'the items read (FortiOS gives no total)' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'FortiOS\'s results (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'fortigate')))) {
	    $this->errors('config', static::$label . ': choose a FortiGate credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'fortigate');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->fgt = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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
	//CLD: 2026-11-01T21:50:00+02:00: setResult() (a tool keeps it)
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

    //a read (Other parameters in the query): FortiOS's results
    protected function get($path, array $query = []) {
	return $this->fgt->request('GET', $path, array_replace($query, $this->extraParams()));
    }

    //a change ($method POST / PUT / DELETE; Other parameters in the body; $body null: none)
    protected function change($method, $path, $body = null) {
	$extra = $this->extraParams();
	if ($extra)
	    $body = array_replace_recursive((array)$body, $extra);
	return $this->fgt->request($method, $path, [], $body);
    }

    //a list: [ { items, count, total, truncated }, summary ]; At most asked as `count` (+1: truncated), cut here too (a monitor list may not take it)
    protected function listOf($path, array $query = [], $what = 'item') {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$r = $this->fgt->request('GET', $path, array_replace($query + [ 'count' => $max + 1 ], $this->extraParams()));
	$items = is_array($r) && array_is_list($r) ? $r : (is_array($r) ? [ $r ] : []);
	$truncated = count($items) > $max;
	$items = array_slice($items, 0, $max);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => count($items), 'truncated' => $truncated ],
	    \util\Format::plural(count($items), $what) . ($truncated ? ' (truncated)' : '') ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //an object's name (FortiOS: up to 79 characters, no control characters)
    public static function objName($value, $label) {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['name'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (mb_strlen($v) > 79 || preg_match('/[\x00-\x1f"]/', $v))
	    throw new \RuntimeException("{$label}: not a FortiGate object name: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //IP addresses (v4 or v6): a list, or text separated by commas / spaces / line breaks (a list of objects: their `ip`); each checked
    public static function ips($value, $label, $max = 1000) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? ($v['ip'] ?? $v['srcip'] ?? '') : $v, array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$ips = [];
	foreach($parts as $p) {
	    $p = trim(\util\Expression::toText($p));
	    if ($p === '')
		continue;
	    if (!filter_var($p, FILTER_VALIDATE_IP))
		throw new \RuntimeException("{$label}: not an IP address: `" . mb_substr($p, 0, 64) . '`');
	    $ips[] = $p;
	}
	$ips = array_values(array_unique($ips));
	if (!$ips)
	    throw new \RuntimeException("{$label}: empty");
	if (count($ips) > $max)
	    throw new \RuntimeException("{$label}: " . count($ips) . " addresses, at most {$max} in one step");
	return $ips;
    }
}
