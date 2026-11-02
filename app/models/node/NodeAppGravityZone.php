<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new class: the base of the Bitdefender GravityZone steps (Endpoints, Incidents, Blocklist, Quarantine, Investigation, Reports, API; not registered
    itself), through a GravityZone credential of the project (CredentialGravityZone, provides `gravityzone`; util\GravityZone: JSON-RPC 2.0).
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every GravityZone step has: credential, operation (static::OPERATIONS), the operation's own settings (the subclass's operationFields(): each shown for its
	    operations), Other parameters (advanced: a JSON object added to the call's parameters, over the step's own), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass) calls the API through rpc() / listOf(); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)";
	    a \RuntimeException (GravityZone's errors, no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (the pages read up to At most); another operation's: GravityZone's result as it is
*/

namespace models\node;

abstract class NodeAppGravityZone extends \models\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-shield-check';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'gz';

    //operation => label; the subclass's
    const OPERATIONS = [];
    //the operations giving a list (At most, the list's result)
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $gz = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated, a single expression keeps its type)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    //the operation's own settings (fields with `ops`: the operations they are shown for); the subclass's
    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('gravityzone', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'GravityZone credential',
		'choices' => $choices,
		'help' => 'A Bitdefender GravityZone credential of the project',
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
	    'help' => 'A JSON object added to the call\'s parameters (over the step\'s own), e.g. {"filters": {...}}: see the GravityZone API reference',
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
	    'help' => 'A list: { items, count, total, truncated }; else GravityZone\'s result',
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
	    'help' => 'GravityZone\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    //a field of operationFields(): $key, $label, the operations it is shown for, more properties
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as GravityZone gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'the items GravityZone has' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'GravityZone\'s result (null on error)' ] ];
    }

    //the credential: of the workflow's project, a GravityZone one
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'gravityzone')))) {
	    $this->errors('config', static::$label . ': choose a Bitdefender GravityZone credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'gravityzone');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->gz = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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

    //one call; the step's parameters with Other parameters over them
    protected function rpc($area, $method, array $params = [], $version = '1.0') {
	return $this->gz->call($area, $method, array_replace_recursive($params, $this->extraParams()), $version);
    }

    //a paged list: [ { items, count, total, truncated }, summary ]
    protected function listOf($area, $method, array $params = [], $what = 'item', $version = '1.0') {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	[ $items, $total, $truncated ] = $this->gz->all($area, $method, array_replace_recursive($params, $this->extraParams()), $max, $version);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ],
	    \util\Format::plural(count($items), $what) . ($truncated ? " of {$total} (truncated)" : '') ];
    }

    //a result that is GravityZone's own: [ result, summary ]
    protected static function done($result, $summary) {
	return [ $result, $summary ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //an id (GravityZone's: letters, digits, - _); required: refused when empty
    public static function id($value, $label, $required = true) {
	$v = trim(\util\Expression::toText($value));
	if ($v === '') {
	    if ($required)
		throw new \RuntimeException("{$label}: empty");
	    return null;
	}
	if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v))
	    throw new \RuntimeException("{$label}: not an id: `" . mb_substr($v, 0, 64) . '`');
	return $v;
    }

    //ids: a list, or text separated by commas / spaces / line breaks (a list of objects: their `id`)
    public static function ids($value, $label) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? ($v['id'] ?? '') : $v, array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$ids = [];
	foreach($parts as $p) {
	    if (trim(\util\Expression::toText($p)) !== '')
		$ids[] = self::id($p, $label);
	}
	if (!$ids)
	    throw new \RuntimeException("{$label}: empty");
	return array_values(array_unique($ids));
    }

    //texts: a list, or text separated by commas / line breaks
    public static function texts($value) {
	$parts = is_array($value) ? array_values($value) : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	return array_values(array_filter(array_map(fn($v) => trim(\util\Expression::toText($v)), $parts), fn($v) => $v !== ''));
    }
}
