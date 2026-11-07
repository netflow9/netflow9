<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new class: the base of the Wazuh steps (Alerts, Vulnerabilities, Agents, Active Response, Scans, Inventory, Manager, API; not registered itself),
    through a Wazuh credential of the project (CredentialWazuh, provides `wazuh`; util\Wazuh). As NodeAppCheckmk:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Wazuh step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the query, the body of a change or an indexer search), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Wazuh's problems, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated }; a change's: { affected, failed, count, failedCount, message } (Wazuh answers 200 for a change refused for each agent:
	    nothing done is an error here, done for some is a result that says which failed)
    An agents_list left out means every agent to Wazuh: agentList() refuses an empty one, `all` must be asked for
*/

namespace models\node;

abstract class NodeAppWazuh extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-shield-shaded';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'wazuh';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 600;
    //the slower answers of Wazuh's cluster are waited for (without it its API gives up after its own request_timeout, 10 s by default)
    const WAIT = [ 'wait_for_complete' => true ];

    //the client of the run
    protected $wz = null;

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
	    foreach(\models\Credential::choicesFor('wazuh', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Wazuh credential',
		'choices' => self::credentialChoices(),
		'help' => 'A Wazuh credential of the project',
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
	    'help' => 'A JSON object added to the query of the Server API (e.g. {"q": "os.platform=ubuntu"}), to the body of what it creates, or to the search of the indexer (e.g. {"_source": ["rule", "agent"]})',
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
	    'help' => 'A list: { items, count, total, truncated }; a change: { affected, failed, count, failedCount, message }',
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
	    'help' => 'Wazuh\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as Wazuh gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'all the matching items' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Wazuh\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'wazuh')))) {
	    $this->errors('config', static::$label . ': choose a Wazuh credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'wazuh');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->wz = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
    }

    //a read of the Server API: its affected_items
    protected function read($path, array $query = []) {
	return (array)($this->wz->request('GET', $path, self::WAIT + array_replace($query, $this->extraParams()))['data']['affected_items'] ?? []);
    }

    //the one item of a read; an error when there is none
    protected function one($path, $what, array $query = []) {
	$items = $this->read($path, $query);
	if (!$items)
	    throw new \RuntimeException("Wazuh has no {$what}");
	return $items[0];
    }

    //a paged list of the Server API: [ { items, count, total, truncated }, summary ]
    protected function listOf($path, array $query, $what) {
	[ $rows, $total, $truncated ] = $this->wz->rows($path, self::WAIT + array_replace($query, $this->extraParams()), $this->maxItems());
	return [ [ 'items' => $rows, 'count' => count($rows), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($rows), $what) . ($truncated ? " of {$total}" : '') ];
    }

    //a change of the Server API: [ { affected, failed, count, failedCount, message }, summary ]
    protected function change($method, $path, array $query, $body, $done) {
	$r = self::affected($this->wz->request($method, $path, self::WAIT + array_replace($query, $this->extraParams()), $body));
	$failed = $r['failedCount'] ? ", failed for {$r['failedCount']}: " . $r['failed'][0]['message'] : '';
	return [ $r, "{$done}: {$r['count']}{$failed}" ];
    }

    //Wazuh's answer to a change: nothing done and something failed is an error (Wazuh answers 200 and "error": 1)
    public static function affected($answer) {
	$d = (array)($answer['data'] ?? []);
	$failed = [];
	foreach((array)($d['failed_items'] ?? []) as $f)
	    $failed[] = [ 'ids' => array_values((array)($f['id'] ?? [])), 'code' => $f['error']['code'] ?? null, 'message' => (string)($f['error']['message'] ?? 'failed') ];
	$affected = array_values((array)($d['affected_items'] ?? []));
	$count = (int)($d['total_affected_items'] ?? count($affected));
	$failedCount = (int)($d['total_failed_items'] ?? count($failed));
	$message = (string)($answer['message'] ?? '');
	if (!$count && $failedCount)
	    throw new \RuntimeException(($message !== '' ? "{$message}: " : '') . implode('; ', array_map(fn($f) => implode(', ', $f['ids']) . ': ' . $f['message'], array_slice($failed, 0, 5))));
	return [ 'affected' => $affected, 'failed' => $failed, 'count' => $count, 'failedCount' => $failedCount, 'message' => $message ];
    }

    // ------------------------------------------------------------------------------------
    //values

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

    //an agent's id, as Wazuh writes it (at least 3 digits: 1 -> 001); from a value, an agent or an alert ({ agent: { id } })
    public static function agentId($value, $label = 'Agent') {
	if (is_array($value))
	    $value = $value['agent']['id'] ?? $value['id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (!preg_match('/^\d{1,10}$/', $v))
	    throw new \RuntimeException("{$label}: not an agent's id: `" . mb_substr($v, 0, 64) . '`');
	return str_pad($v, 3, '0', STR_PAD_LEFT);
    }

    //the agents of a change: their ids (agents, alerts, ids, separated by commas), or null for `all`, only with $allowAll. Never empty: a list left out is every agent to Wazuh
    public static function agentList($value, $allowAll = true, $label = 'Agents') {
	if (is_array($value) && !array_is_list($value))
	    $value = [ $value ];
	$parts = is_array($value) ? $value : self::names($value);
	if (count($parts) === 1 && is_string($parts[0]) && strtolower(trim($parts[0])) === 'all') {
	    if (!$allowAll)
		throw new \RuntimeException("{$label}: name the agents, `all` cannot be used here");
	    return null;
	}
	$ids = array_values(array_unique(array_map(fn($p) => self::agentId($p, $label), $parts)));
	if (!$ids)
	    throw new \RuntimeException("{$label}: empty (`all` for every agent)");
	return $ids;
    }

    //a group's name (Wazuh's: letters, digits, . _ -)
    public static function group($value, $label = 'Group') {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['name'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $v) || in_array($v, [ '.', '..' ], true))
	    throw new \RuntimeException("{$label}: not a group's name: `" . mb_substr($v, 0, 64) . '`');
	return $v;
    }

    //a date / time, as the indexer's range takes it; empty: $default ('' for none)
    public static function when($text, $label, $default = '') {
	$text = trim((string)$text) === '' ? $default : trim((string)$text);
	if ($text === '')
	    return '';
	$t = strtotime($text);
	if ($t === false)
	    throw new \RuntimeException("{$label}: not a date / time: `" . mb_substr($text, 0, 64) . '`');
	return gmdate('Y-m-d\TH:i:s.000\Z', $t);
    }
}
