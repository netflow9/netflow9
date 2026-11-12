<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: the base of the Infobip steps (SMS, WhatsApp, Email, 2FA, Account, API; not registered itself), through an Infobip credential of the project
    (CredentialInfobip, provides `infobip`; util\Infobip). As NodeAppJira:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Infobip step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Infobip's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }; a send's: { bulkId, messages: [ { to, messageId, status, statusGroup, description } ], count } (sent())
    The senders: the step's, else the credential's (sender()); phone numbers: util\Infobip::numbers
*/





namespace models\node;

abstract class NodeAppInfobip extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-chat-dots';
    public static $category = 'messaging';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'infobip';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;

    //the client of the run
    protected $infobip = null;
    //the credential of the run (its default senders)
    protected $cred = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function credentialChoices() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('infobip', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Infobip credential',
		'choices' => self::credentialChoices(),
		'help' => 'An Infobip credential of the project',
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
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 100,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => static::DEFAULT_TIMEOUT,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, truncated }; otherwise what the operation gives',
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
	    'help' => 'Infobip\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Infobip\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'infobip')))) {
	    $this->errors('config', static::$label . ': choose an Infobip credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'infobip');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->cred = $cred;
	$this->infobip = $cred->client(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 100)));
    }

    //a list given whole, as At most cuts it: [ { items, count, truncated }, summary ]
    protected function listResult(array $all, $what) {
	$items = array_slice(array_values($all), 0, $this->maxItems());
	$truncated = count($all) > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . count($all) : '') ];
    }

    //texts: a list (of texts, or of objects with one of $keys), or separated by commas / new lines
    public static function names($value, array $keys = [ 'id', 'name' ]) {
	$parts = is_array($value) && !array_is_list($value) ? [ $value ] : (is_array($value) ? $value : preg_split('/[\r\n,]+/', \util\Expression::toText($value)));
	$names = [];
	foreach($parts as $p) {
	    if (is_array($p)) {
		foreach($keys as $k) {
		    if (isset($p[$k]) && !is_array($p[$k])) {
			$p = $p[$k];
			break;
		    }
		}
	    }
	    $t = is_array($p) ? '' : trim(\util\Expression::toText($p));
	    if ($t !== '')
		$names[] = $t;
	}
	return array_values(array_unique($names));
    }

    //a JSON setting (Other fields, a body): [] when empty; $list: a JSON list
    protected function jsonOf($key, $label, $list = false) {
	$v = $this->settingOf($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return [];
	    $v = json_decode($v, true);
	}
	if (!is_array($v) || ($v && array_is_list($v) !== $list))
	    throw new \RuntimeException("{$label}: not a JSON " . ($list ? 'list' : 'object'));
	return $v;
    }

    //the sender of a setting, else the credential's $default (one of its senders); '' when neither
    protected function sender(\Closure $text, $key, $default) {
	$s = $text($key);
	return $s !== '' ? $s : trim((string)($this->cred?->{$default} ?? ''));
    }

    //the messages' answer of a send (SMS, WhatsApp): { bulkId, messages: [ { to, messageId, status, statusGroup, description } ], count }
    public static function sent(array $d) {
	$list = isset($d['messages']) ? (array)$d['messages'] : (isset($d['messageId']) ? [ $d ] : []);
	$messages = array_map(fn($m) => [ 'to' => $m['to'] ?? $m['destination'] ?? null, 'messageId' => $m['messageId'] ?? null, 'status' => $m['status']['name'] ?? null,
	    'statusGroup' => $m['status']['groupName'] ?? null, 'description' => $m['status']['description'] ?? null ], $list);
	return [ 'bulkId' => $d['bulkId'] ?? null, 'messages' => $messages, 'count' => count($messages) ];
    }

    //the summary of a send: "2 messages to 40722…, 40733…: PENDING"
    public static function sentSummary(array $r, $what = 'message', $plural = null) {
	$groups = array_values(array_unique(array_filter(array_column($r['messages'], 'statusGroup'))));
	return \util\Format::plural($r['count'], $what, $plural) . ' to ' . implode(', ', array_slice(array_filter(array_column($r['messages'], 'to')), 0, 3)) . ($r['count'] > 3 ? ', …' : '')
	    . ($groups ? ': ' . implode(', ', $groups) : '');
    }
}
