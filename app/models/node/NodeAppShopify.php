<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: the base of the Shopify steps (Orders, Products, Inventory, Customers, Store, GraphQL; not registered itself), through a Shopify
    credential of the project (CredentialShopify, provides `shopify`; util\Shopify, the GraphQL Admin API). As NodeAppNamecheap:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Shopify step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other fields (advanced: a JSON object merged into a change's input, as Shopify names the fields), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Shopify's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }; items as Shopify gives them, simplified (util\Shopify::simplify: connections as lists, money as { amount, currencyCode })
	- the search of a list: Shopify's search syntax (e.g. financial_status:paid tag:wholesale created_at:>2026-01-01)
*/

namespace models\node;

abstract class NodeAppShopify extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-bag';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'commerce';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'shopify';

    const OPERATIONS = [];
    const LISTS = [];
    //the operations whose input Other fields is merged into
    const INPUTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const MONEY = 'amount currencyCode';

    //the client of the run
    protected $shop = null;

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
	    foreach(\models\Credential::choicesFor('shopify', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Shopify credential',
		'choices' => self::credentialChoices(),
		'help' => 'A Shopify credential of the project',
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
	if (static::INPUTS)
	    $fields[] = [
		'key' => 'fields',
		'type' => 'textarea',
		'label' => 'Other fields',
		'default' => '',
		'help' => 'A JSON object merged into the change\'s input, as Shopify\'s GraphQL names the fields (e.g. {"templateSuffix": "special"})',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::INPUTS ] ],
	    ];
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 250,
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
	    'help' => 'Throttled queries wait for Shopify\'s limit within it',
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
	    'help' => 'Shopify\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the search setting of a list (Shopify's search syntax)
    protected static function searchField(array $ops, $example) {
	return self::field('search', 'Search', $ops, [ 'help' => "Shopify's search syntax, e.g. {$example}; empty: all", 'ui' => [ 'expression' => 'text' ] ]);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as Shopify gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'Shopify\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'shopify')))) {
	    $this->errors('config', static::$label . ': choose a Shopify credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'shopify');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->shop = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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

    //a JSON object setting (or an expression giving one): [] when empty
    protected function objectOf($raw, $label) {
	$v = is_string($raw) ? $this->settingValue($raw, $this->settingVars) : $raw;
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON object");
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    //Other fields merged into $input (theirs win)
    protected function withFields(array $input) {
	return array_replace($input, $this->objectOf((string)$this->cfg('fields', ''), 'Other fields'));
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 250)));
    }

    //a connection `$field(first: $first, after: $after, query: $query[, $args]) { nodes { $nodeFields } pageInfo { hasNextPage endCursor } }`: [ { items, count, truncated }, summary ]
    protected function listOf($field, $nodeFields, $search, $what, $args = '', array $vars = [], $varDecl = '') {
	$q = "query(\$first: Int!, \$after: String, \$query: String{$varDecl}) { {$field}(first: \$first, after: \$after, query: \$query{$args}) { nodes { {$nodeFields} } pageInfo { hasNextPage endCursor } } }";
	[ $items, $truncated ] = $this->shop->connection($q, [ 'query' => $search !== '' ? $search : null ] + $vars, $field, $this->maxItems());
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' (more left out)' : '') ];
    }

    //one object by its GID (`node`-less: `$field(id: $id) { $nodeFields }`); not found: \RuntimeException
    protected function one($field, $gid, $nodeFields, $what) {
	$d = $this->shop->query("query(\$id: ID!) { {$field}(id: \$id) { {$nodeFields} } }", [ 'id' => $gid ]);
	if (empty($d[$field]))
	    throw new \RuntimeException("no {$what} {$gid}");
	return \util\Shopify::simplify($d[$field]);
    }

    //the first node a search finds (e.g. a customer by email), or null
    protected function findOne($field, $search, $nodeFields) {
	$d = $this->shop->query("query(\$query: String!) { {$field}(first: 1, query: \$query) { nodes { {$nodeFields} } } }", [ 'query' => $search ]);
	$n = $d[$field]['nodes'][0] ?? null;
	return $n ? \util\Shopify::simplify($n) : null;
    }

    //tags added to / removed from an object (tagsAdd / tagsRemove): [ { id, tags }, summary ]
    protected function changeTags($gid, $value, $add, $what) {
	$tags = self::names($value);
	if (!$tags)
	    throw new \RuntimeException('Tags: empty');
	$m = $add ? 'tagsAdd' : 'tagsRemove';
	$this->shop->mutate("mutation(\$id: ID!, \$tags: [String!]!) { {$m}(id: \$id, tags: \$tags) { node { id } userErrors { field message } } }", [ 'id' => $gid, 'tags' => $tags ], $m);
	return [ [ 'id' => $gid, 'tags' => $tags ], \util\Format::plural(count($tags), 'tag') . ($add ? ' added to ' : ' removed from ') . "{$what} {$gid}" ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //texts: a list (of texts, or of objects with one of $keys), or separated by commas / new lines
    public static function names($value, array $keys = [ 'name', 'title', 'tag', 'id' ]) {
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

    //a money amount ("19.90", 19.9) as Shopify takes it (a decimal text); '' stays ''
    public static function money($v, $label) {
	$t = trim(str_replace(',', '.', \util\Expression::toText($v)));
	if ($t === '')
	    return '';
	if (!preg_match('/^\d{1,12}(\.\d{1,4})?$/', $t))
	    throw new \RuntimeException("{$label}: not an amount: `" . mb_substr($t, 0, 40) . '`');
	return $t;
    }

    //a whole number of the setting (negative allowed with $signed)
    public static function integer($v, $label, $signed = false) {
	$t = trim(\util\Expression::toText($v));
	if (!preg_match($signed ? '/^-?\d{1,9}$/' : '/^\d{1,9}$/', $t))
	    throw new \RuntimeException("{$label}: not a whole number: `" . mb_substr($t, 0, 40) . '`');
	return (int)$t;
    }
}
