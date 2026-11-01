<?php

/*
    CLD: 2026-11-01T08:00:00+02:00: new class: the base of the Checkmk steps (Status, Acknowledge & Downtime, Hosts, API; not registered itself), through a Checkmk credential of the
    project (CredentialCheckmk, provides `checkmk`; util\Checkmk: REST API). As NodeAppFortiGate:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Checkmk step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the body of a change, or to the query of a read), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Checkmk's problems,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (up to At most)
*/

namespace models\node;

abstract class NodeAppCheckmk extends \models\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-activity';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'monitoring';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'cmk';

    const OPERATIONS = [];
    const LISTS = [];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $cmk = null;

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
	    foreach(\models\Credential::choicesFor('checkmk', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Checkmk credential',
		'choices' => $choices,
		'help' => 'A Checkmk credential of the project',
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
	    'help' => 'A JSON object added to the body of a change (e.g. {"sticky": true}) or to the query of a read: see the Checkmk REST API documentation',
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
	    'help' => 'A list: { items, count, total, truncated }; else Checkmk\'s answer',
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
	    'help' => 'Checkmk\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as Checkmk gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Checkmk\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'checkmk')))) {
	    $this->errors('config', static::$label . ': choose a Checkmk credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'checkmk');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->cmk = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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

    //a read (Other parameters in the query)
    protected function get($path, array $query = []) {
	return $this->cmk->request('GET', $path, array_replace($query, $this->extraParams()));
    }

    //a change ($method POST / PUT / DELETE; Other parameters in the body; $body null: none; $headers: e.g. If-Match)
    protected function change($method, $path, $body = null, array $headers = []) {
	$extra = $this->extraParams();
	if ($extra)
	    $body = array_replace_recursive((array)$body, $extra);
	return $this->cmk->request($method, $path, [], $body, $headers);
    }

    //a list of rows: [ { items, count, total, truncated }, summary ]
    protected function listResult(array $rows, $truncated, $what) {
	return [ [ 'items' => $rows, 'count' => count($rows), 'total' => count($rows), 'truncated' => $truncated ], \util\Format::plural(count($rows), $what) . ($truncated ? ' (truncated)' : '') ];
    }

    //the rows of a Livestatus table: [ { items, ... }, summary ] (Other parameters: added to the query, e.g. more columns)
    protected function rowsOf($table, $filter, array $columns, $what) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$extra = $this->extraParams();
	if (isset($extra['columns']))
	    $columns = array_values(array_unique(array_merge($columns, (array)$extra['columns'])));
	[ $rows, $truncated ] = $this->cmk->rows($table, $filter, $columns, $max);
	return $this->listResult($rows, $truncated, $what);
    }

    //a collection (its items' extensions, id, title): [ { items, ... }, summary ]
    protected function collection($path, array $query, $what) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$rows = \util\Checkmk::items($this->get($path, $query));
	return $this->listResult(array_slice($rows, 0, $max), count($rows) > $max, $what);
    }

    // ------------------------------------------------------------------------------------
    //values

    //a host name (Checkmk's: letters, digits, . _ -)
    public static function host($value, $label = 'Host') {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['host_name'] ?? $value['id'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (!preg_match('/^[A-Za-z0-9_.-]{1,253}$/', $v))
	    throw new \RuntimeException("{$label}: not a host name: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //texts: a list, or one per line (e.g. service names, which may hold commas)
    public static function lines($value) {
	$parts = is_array($value) ? array_values($value) : preg_split('/\r?\n/', \util\Expression::toText($value));
	return array_values(array_unique(array_filter(array_map(fn($v) => trim(\util\Expression::toText(is_array($v) ? ($v['description'] ?? '') : $v)), $parts), fn($v) => $v !== '')));
    }
}
