<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify GraphQL - any query or mutation of the GraphQL Admin API (what the other Shopify steps do not have: refunds, returns, draft orders,
    discounts, metafields, collections, ...): the query and its variables (a JSON object, or an expression giving one). Its result: the answer's `data`, simplified unless switched
    off (util\Shopify::simplify: connections as lists, money as { amount, currencyCode }). A mutation's userErrors are not checked here: they are in the result
*/

namespace models\node;

class NodeAppShopGraphql extends NodeAppShopify {
    public static $name = 'shopify-graphql';
    public static $label = 'Shopify GraphQL';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'query';
    public static $defaultVariable = 'shopify';

    const OPERATIONS = [ 'query' => 'Run a query or mutation' ];
    const WRITES = [ 'query' ];

    protected static function operationFields() {
	return [
	    self::field('query', 'Query', [ 'query' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'GraphQL, e.g. query($id: ID!) { order(id: $id) { name note } }',
		'ui' => [ 'rows' => 8, 'code' => true, 'spellcheck' => false ] ]),
	    self::field('variables', 'Variables', [ 'query' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"id": "gid://shopify/Order/1001"}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('simplify', 'Simplify', [ 'query' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Connections as lists, money as { amount, currencyCode }' ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	//the query as written: {{ }} in it would clash with GraphQL's braces, values go in Variables
	$query = trim((string)$this->cfg('query', ''));
	$vars = $this->objectOf((string)$this->cfg('variables', ''), 'Variables');
	$d = $this->shop->query($query, $vars);
	$first = preg_match('/^\s*(query|mutation)?\s*\w*\s*(\([^)]*\))?\s*\{\s*(\w+)/', $query, $m) ? $m[3] : 'query';
	return [ (bool)$this->cfg('simplify', true) ? \util\Shopify::simplify($d) : $d, (preg_match('/^\s*mutation/', $query) ? 'mutation ' : '') . $first ];
    }
}
