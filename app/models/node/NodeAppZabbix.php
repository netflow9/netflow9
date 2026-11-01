<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new class: the base of the Zabbix steps (Problems, Acknowledge & Maintenance, Hosts, API; not registered itself), through a Zabbix credential of the
    project (CredentialZabbix, provides `zabbix`; util\Zabbix: JSON-RPC). As NodeAppCheckmk:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Zabbix step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object merged into the parameters of the operation's API call), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Zabbix's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (up to At most)
*/

namespace models\node;

abstract class NodeAppZabbix extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-speedometer2';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'monitoring';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'zbx';

    const OPERATIONS = [];
    const LISTS = [];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $zbx = null;

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
	    foreach(\models\Credential::choicesFor('zabbix', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Zabbix credential',
		'choices' => $choices,
		'help' => 'A Zabbix credential of the project',
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
	    'help' => 'A JSON object merged into the parameters of the API call (e.g. {"selectTags": "extend"}): see the Zabbix API documentation',
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
	    'help' => 'A list: { items, count, total, truncated }; else Zabbix\'s answer',
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
	    'help' => 'Zabbix\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Zabbix\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'zabbix')))) {
	    $this->errors('config', static::$label . ': choose a Zabbix credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$value = fn($key) => \util\Expression::render((string)$this->cfg($key, ''), $vars);
	$text = fn($key) => trim(\util\Expression::toText($value($key)));
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'zabbix');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->zbx = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $text, $value);
	} catch (\RuntimeException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //Other parameters: a JSON object (or an expression giving one); [] when empty
    protected function extraParams() {
	$raw = trim((string)$this->cfg('params', ''));
	if ($raw === '')
	    return [];
	return self::object(\util\Expression::render($raw, $this->exec->expressionVariables()), 'Other parameters');
    }

    //a JSON object of a setting's value ([] when empty)
    protected static function object($v, $label) {
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON object");
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    //an API call of the operation, with Other parameters merged into its parameters
    protected function call($method, array $params = []) {
	return $this->zbx->call($method, array_replace_recursive($params, $this->extraParams()));
    }

    //a list of rows: [ { items, count, total, truncated }, summary ]; $rows read with one more than At most (limit), so `truncated` is known
    protected function listResult(array $rows, $what) {
	$max = $this->maxItems();
	$truncated = count($rows) > $max;
	$rows = array_slice(array_values($rows), 0, $max);
	return [ [ 'items' => $rows, 'count' => count($rows), 'total' => count($rows), 'truncated' => $truncated ], \util\Format::plural(count($rows), $what) . ($truncated ? ' (truncated)' : '') ];
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
    }

    // ------------------------------------------------------------------------------------
    //values

    //names: a list (of names, or of objects with `host` / `name`), or separated by commas or new lines
    public static function names($value) {
	$parts = is_array($value) ? array_values($value) : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	return array_values(array_unique(array_filter(array_map(fn($v) => trim(\util\Expression::toText(is_array($v) ? ($v['host'] ?? $v['name'] ?? '') : $v)), $parts), fn($v) => $v !== '')));
    }

    //ids (digits): a list, or separated by commas / spaces / new lines; objects with `eventid` / `hostid` / `maintenanceid` too
    public static function ids($value, $label) {
	$parts = is_array($value) ? array_values($value) : preg_split('/[\s,]+/', \util\Expression::toText($value));
	$out = [];
	foreach($parts as $v) {
	    if (is_array($v))
		$v = $v['eventid'] ?? $v['hostid'] ?? $v['maintenanceid'] ?? $v['id'] ?? '';
	    $v = trim(\util\Expression::toText($v));
	    if ($v === '')
		continue;
	    if (!ctype_digit($v))
		throw new \RuntimeException("{$label}: not an id: `" . mb_substr($v, 0, 40) . '`');
	    $out[] = $v;
	}
	if (!$out)
	    throw new \RuntimeException("{$label}: empty");
	return array_values(array_unique($out));
    }

    //a host's technical name
    public static function host($value, $label = 'Host') {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['host'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (mb_strlen($v) > 128 || !preg_match('/^[\p{L}\p{N} ._-]+$/u', $v))
	    throw new \RuntimeException("{$label}: not a host name: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //tags: a JSON object { tag: value }, a list of { tag, value }, or tag=value per line / comma: [ { tag, value } ]
    public static function tags($value) {
	if (is_string($value) && (str_starts_with(trim($value), '{') || str_starts_with(trim($value), '[')))
	    $value = json_decode(trim($value), true) ?? $value;
	$out = [];
	if (is_array($value)) {
	    foreach($value as $k => $v) {
		if (is_array($v))
		    $out[] = [ 'tag' => trim((string)($v['tag'] ?? '')), 'value' => trim((string)($v['value'] ?? '')) ];
		else
		    $out[] = [ 'tag' => trim((string)$k), 'value' => trim(\util\Expression::toText($v)) ];
	    }
	} else {
	    foreach(preg_split('/[\r\n,]+/', \util\Expression::toText($value)) as $line) {
		if (trim($line) === '')
		    continue;
		[ $t, $v ] = array_map('trim', explode('=', $line, 2) + [ 1 => '' ]);
		$out[] = [ 'tag' => $t, 'value' => $v ];
	    }
	}
	return array_values(array_filter($out, fn($t) => $t['tag'] !== ''));
    }
}
