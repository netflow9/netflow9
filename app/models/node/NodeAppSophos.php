<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new class: the base of the Sophos Firewall steps (Hosts, Rules, Users & VPN, Web, System, API; not registered itself), through a Sophos Firewall
    credential of the project (CredentialSophos, provides `sophos`; util\Sophos: the SFOS XML API). As NodeAppFortiGate:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Sophos step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other fields (advanced: a JSON object merged into the entity a change sends), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (the firewall's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (up to At most); another operation's: the entity, or { name, message } of a change
	- the XML API's lists are not paged: the whole list is read, At most kept
	- a change of an entity is read, changed, and sent back whole (Set operation="update" replaces the entity: its lists too)
*/

namespace models\node;

abstract class NodeAppSophos extends \models\wf\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-fire';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'sfos';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $sfos = null;

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
	    foreach(\models\Credential::choicesFor('sophos', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Sophos Firewall credential',
		'choices' => $choices,
		'help' => 'A Sophos Firewall credential of the project',
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
	    'label' => 'Other fields',
	    'default' => '',
	    'help' => 'A JSON object of more fields of the entity a change sends (e.g. {"Description": "From Rail22"}): see the Sophos Firewall API reference',
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
	    'help' => 'A list: { items, count, total, truncated }; else the entity, or what was changed',
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
	    'help' => 'The firewall\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an entity, its fields as the firewall gives them (e.g. Name)' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items kept' ],
		'total' => [ 'type' => 'number', 'info' => 'the items the firewall gave' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'the entity, or what was changed (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'sophos')))) {
	    $this->errors('config', static::$label . ': choose a Sophos Firewall credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'sophos');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->sfos = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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

    //Other fields: a JSON object (or an expression giving one); [] when empty
    protected function extraFields() {
	$raw = trim((string)$this->cfg('params', ''));
	if ($raw === '')
	    return [];
	$v = \util\Expression::render($raw, $this->exec->expressionVariables());
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException('Other fields: not a JSON object');
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException('Other fields: not a JSON object');
	return $v;
    }

    //a change: Set $operation (add / update) of $entity, Other fields merged into it
    protected function change($operation, $tag, array $entity) {
	$extra = $this->extraFields();
	return $this->sfos->set($operation, $tag, $extra ? array_replace_recursive($entity, $extra) : $entity);
    }

    //the entity $tag named $name: thrown when there is none
    protected function need($tag, $name, $what) {
	$e = $this->sfos->one($tag, $name);
	if ($e === null)
	    throw new \RuntimeException("{$what} `{$name}` does not exist");
	return $e;
    }

    //a list: [ { items, count, total, truncated }, summary ]; $contains: the entities whose name contains it (the API's `like`); $keep: those kept
    protected function listOf($tag, $contains = '', $what = 'item', \Closure $keep = null) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$items = $this->sfos->get($tag, $contains !== '' ? [ \util\Sophos::nameKey($tag), 'like', $contains ] : null);
	if ($keep)
	    $items = array_values(array_filter($items, $keep));
	$total = count($items);
	$items = array_slice($items, 0, $max);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $total > $max ],
	    \util\Format::plural(count($items), $what) . ($total > $max ? " of {$total} (truncated)" : '') ];
    }

    //the list field $list.$item of an entity (a host group's HostList.Host) changed: $add put in, $drop taken out; [ entity, changed ]
    protected static function withMembers(array $entity, $list, $item, array $add, array $drop) {
	$now = array_map('strval', \util\Sophos::items($entity[$list][$item] ?? null));
	$new = array_values(array_unique(array_merge(array_values(array_diff($now, $drop)), $add)));
	$changed = $new !== $now;
	$entity[$list] = $new ? [ $item => $new ] : null;
	return [ $entity, $changed ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //an entity's name (up to 60 characters here, no control characters, no < > " ')
    public static function objName($value, $label) {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['Name'] ?? $value['name'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (mb_strlen($v) > 60 || preg_match('/[\x00-\x1f"<>\']/', $v))
	    throw new \RuntimeException("{$label}: not a Sophos Firewall name: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //IP addresses (v4 or v6): as NodeAppFortiGate::ips (a list, text separated by commas / spaces / lines, objects' ip / srcip)
    public static function ips($value, $label, $max = 1000) {
	return NodeAppFortiGate::ips($value, $label, $max);
    }
}
