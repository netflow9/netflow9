<?php

/*
    CLD: 2026-11-05T13:00:00+02:00: new class: the base of the Namecheap steps (Domains, DNS, Account, API; not registered itself), through a Namecheap credential of the project
    (CredentialNamecheap, provides `namecheap`; util\Namecheap). As NodeAppWazuh:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Namecheap step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the command's parameters), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Namecheap's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated }, its items the attributes Namecheap gives (its names: Name, Expires, IsLocked, ...; true / false as booleans)
*/

namespace models\node;

abstract class NodeAppNamecheap extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-globe2';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'domains';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'namecheap';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;

    //the client of the run
    protected $nc = null;

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
	    foreach(\models\Credential::choicesFor('namecheap', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Namecheap credential',
		'choices' => self::credentialChoices(),
		'help' => 'A Namecheap credential of the project',
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
	    'help' => 'A JSON object added to the command\'s parameters, as Namecheap names them (e.g. {"SortBy": "NAME"})',
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
	    'help' => 'A list: { items, count, total, truncated }; otherwise what the operation gives',
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
	    'help' => 'Namecheap\'s errors, or no connection',
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
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as Namecheap gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'all the matching items' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Namecheap\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'namecheap')))) {
	    $this->errors('config', static::$label . ': choose a Namecheap credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$vars = $this->varsForSettings();
	$value = fn($key) => $this->settingValue($this->cfg($key, ''), $vars);
	$text = fn($key) => trim(\util\Expression::toText($value($key)));
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'namecheap');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->nc = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //Other parameters: a JSON object (or an expression giving one) of scalars; [] when empty
    protected function extraParams() {
	$raw = trim((string)$this->cfg('params', ''));
	if ($raw === '')
	    return [];
	$v = $this->settingValue($raw, $this->varsForSettings());
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException('Other parameters: not a JSON object');
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException('Other parameters: not a JSON object');
	foreach($v as $k => $x) {
	    if (is_array($x) || is_object($x))
		throw new \RuntimeException("Other parameters: `{$k}`: a text, a number or true / false");
	}
	return $v;
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
    }

    //a command, with Other parameters added to its own
    protected function call($command, array $params = []) {
	return $this->nc->call($command, array_replace($params, $this->extraParams()));
    }

    //a paged list (Page, PageSize; <Paging><TotalItems>): [ { items, count, total, truncated }, summary ]; $item: the attributes of each <$tag> of <$result> (+ $more of it)
    protected function listOf($command, array $params, $result, $tag, $what, ?\Closure $item = null) {
	$max = $this->maxItems();
	$items = [];
	$total = 0;
	for ($page = 1; ; $page++) {
	    $r = $this->call($command, $params + [ 'Page' => $page, 'PageSize' => \util\Namecheap::PAGE ]);
	    $n = 0;
	    foreach($r->{$result}->{$tag} ?? [] as $el) {
		$n++;
		if (count($items) < $max)
		    $items[] = $item ? $item($el) : \util\Namecheap::attrs($el);
	    }
	    $total = isset($r->Paging->TotalItems) ? (int)(string)$r->Paging->TotalItems : ($page - 1) * \util\Namecheap::PAGE + $n;
	    if ($n < \util\Namecheap::PAGE || count($items) >= $max || $page * \util\Namecheap::PAGE >= $total)
		break;
	}
	$total = max($total, count($items));
	$truncated = $total > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? " of {$total}" : '') ];
    }

    //a list given whole, as $maxItems cuts it: [ { items, count, total, truncated }, summary ]
    protected function listResult(array $all, $what) {
	$items = array_slice(array_values($all), 0, $this->maxItems());
	$truncated = count($all) > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => count($all), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . count($all) : '') ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //texts: a list (of texts, or of objects with one of $keys), or separated by commas / new lines
    public static function names($value, array $keys = [ 'Name', 'Domain', 'DomainName', 'name', 'domain' ]) {
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

    //[ domain, sld, tld ] of the setting `domain`
    protected function domainOf(\Closure $value, $key = 'domain', $label = 'Domain') {
	return \util\Namecheap::domain($value($key), $label);
    }
}
