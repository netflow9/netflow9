<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: the base of the Zendesk steps (Tickets, Users, Setup, API; not registered itself), through a Zendesk credential of the project
    (CredentialZendesk, provides `zendesk`; util\Zendesk). As NodeAppJira:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Zendesk step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Zendesk's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }
    What the steps share: a user (id, email, name), a group or an organization (id, name) given as such (userId, groupId, organizationId), tags, JSON settings
*/




namespace models\node;

abstract class NodeAppZendesk extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-life-preserver';
    public static $category = 'work';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'zendesk';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;

    //the client of the run
    protected $zendesk = null;

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
	    foreach(\models\Credential::choicesFor('zendesk', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Zendesk credential',
		'choices' => self::credentialChoices(),
		'help' => 'A Zendesk credential of the project',
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
	    'help' => 'Zendesk\'s errors, or no connection',
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
	return [ $name => [ 'type' => 'any', 'info' => 'Zendesk\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'zendesk')))) {
	    $this->errors('config', static::$label . ': choose a Zendesk credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'zendesk');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->zendesk = $cred->client(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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

    //an id of a user, a group or an organization: its id, an item ({ id }), or a text $find gives the id of (an email, a name); '' : null
    protected function refId($value, $label, \Closure $find) {
	if (is_array($value) && isset($value['id']))
	    return (int)$value['id'];
	$t = trim(\util\Expression::toText($value));
	if ($t === '')
	    return null;
	if (preg_match('/^\d{1,15}$/', $t))
	    return (int)$t;
	return $find($t, $label);
    }

    //a user's id by email or name (users/search)
    protected function userId($value, $label) {
	return $this->refId($value, $label, function($t, $label) {
	    $found = (array)($this->zendesk->request('GET', '/users/search', [ 'query' => str_contains($t, '@') ? "email:{$t}" : $t ])['users'] ?? []);
	    $exact = array_values(array_filter($found, fn($u) => strcasecmp((string)($u['email'] ?? ''), $t) === 0 || strcasecmp((string)($u['name'] ?? ''), $t) === 0));
	    $u = count($exact) === 1 ? $exact[0] : (count($found) === 1 && !$exact ? $found[0] : null);
	    if (!$u)
		throw new \RuntimeException("{$label}: " . (count($exact) > 1 ? count($exact) . " users match `{$t}`: give the email or the id" : "no user `{$t}`"));
	    return (int)$u['id'];
	});
    }

    //a group's id by name (groups, all)
    protected function groupId($value, $label = 'Group') {
	return $this->refId($value, $label, function($t, $label) {
	    [ $all ] = $this->zendesk->pages('/groups', 'groups', [], self::MAX_ITEMS);
	    $found = array_values(array_filter($all, fn($g) => strcasecmp((string)($g['name'] ?? ''), $t) === 0));
	    if (count($found) !== 1)
		throw new \RuntimeException("{$label}: " . ($found ? count($found) . " groups are named `{$t}`" : "no group `{$t}`"));
	    return (int)$found[0]['id'];
	});
    }

    //an organization's id by name (organizations/search?name=, exact)
    protected function organizationId($value, $label = 'Organization') {
	return $this->refId($value, $label, function($t, $label) {
	    $found = (array)($this->zendesk->request('GET', '/organizations/search', [ 'name' => $t ])['organizations'] ?? []);
	    if (!$found)
		throw new \RuntimeException("{$label}: no organization `{$t}`");
	    return (int)$found[0]['id'];
	});
    }

    //tags: a list, or separated by commas / spaces (Zendesk's tags have no spaces)
    public static function tags($value) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? (string)($v['name'] ?? '') : \util\Expression::toText($v), array_values($value)) : preg_split('/[\s,]+/', \util\Expression::toText($value));
	return array_values(array_unique(array_filter(array_map(fn($t) => strtolower(trim($t)), $parts), fn($t) => $t !== '')));
    }
}
