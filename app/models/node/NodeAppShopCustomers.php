<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify Customers - the store's customers. Operations (setting `operation`):
	list        the customers a search finds (customers), sorted
	get         a customer (customer), by its ID, its email, or a customer of a list, an order or a webhook; not found: null (no error), so a workflow can create it
	create      a customer (customerCreate): email, name, phone, tags, note, email marketing consent
	update      what is not empty of those (customerUpdate)
	addTags     tags added / removed (tagsAdd / tagsRemove)
	removeTags
*/

namespace models\node;

class NodeAppShopCustomers extends NodeAppShopify {
    public static $name = 'shopify-customers';
    public static $label = 'Shopify Customers';
    public static $icon = 'bi-person-badge';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'customers';

    const OPERATIONS = [
	'list' => 'List customers',
	'get' => 'Get a customer',
	'create' => 'Create a customer',
	'update' => 'Update a customer',
	'addTags' => 'Add tags',
	'removeTags' => 'Remove tags',
    ];
    const WRITES = [ 'create', 'update', 'addTags', 'removeTags' ];
    const LISTS = [ 'list' ];
    const INPUTS = [ 'create', 'update' ];
    const ONE = [ 'get', 'update', 'addTags', 'removeTags' ];
    const SORTS = [ 'UPDATED_AT_DESC' => 'Updated, latest first', 'CREATED_AT_DESC' => 'Newest first', 'NAME' => 'Name', 'ID' => 'Oldest first' ];

    const CUSTOMER = 'id legacyResourceId firstName lastName displayName email phone state verifiedEmail tags note numberOfOrders amountSpent { amount currencyCode } createdAt updatedAt'
	. ' emailMarketingConsent { marketingState } defaultAddress { ' . NodeAppShopOrders::ADDRESS . ' }';

    protected static function operationFields() {
	$writeOps = [ 'create', 'update' ];
	return [
	    self::searchField([ 'list' ], 'country:Romania orders_count:>3 tag:vip'),
	    self::field('sort', 'Sorted by', [ 'list' ], [ 'type' => 'select', 'default' => 'UPDATED_AT_DESC', 'choices' => self::SORTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('customer', 'Customer', self::ONE, [ 'required' => true, 'help' => 'Its ID, its email, or a customer of a list, an order or a webhook', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('email', 'Email', $writeOps, [ 'help' => 'Create: an email or a phone is required; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('firstName', 'First name', $writeOps, [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('lastName', 'Last name', $writeOps, [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('phone', 'Phone', $writeOps, [ 'help' => 'International, e.g. +40721000000', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('tags', 'Tags', array_merge($writeOps, [ 'addTags', 'removeTags' ]), [ 'help' => 'Separated by commas, or a list; update: replaces them all',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('note', 'Note', $writeOps, [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('marketing', 'Email marketing', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Subscribed (single opt-in): only with the customer\'s consent', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //the GID of the setting `customer` (an email: looked up; null when no customer has it)
    protected function customerGid(\Closure $value) {
	$v = $value('customer');
	if (is_array($v) && !isset($v['id']) && !isset($v['admin_graphql_api_id']) && isset($v['email']))
	    $v = $v['email'];
	$t = is_array($v) ? '' : trim(\util\Expression::toText($v));
	if (str_contains($t, '@')) {
	    $c = $this->findOne('customers', 'email:' . \util\Shopify::quoted($t), 'id email');
	    return $c && strcasecmp((string)$c['email'], $t) === 0 ? $c['id'] : null;
	}
	return \util\Shopify::gid('Customer', $v, 'Customer');
    }

    protected function customerInput(\Closure $text, \Closure $value) {
	$input = [];
	foreach([ 'email', 'firstName', 'lastName', 'phone', 'note' ] as $k)
	    if (($t = $text($k)) !== '')
		$input[$k] = $t;
	if (($tags = self::names($value('tags'))) !== [])
	    $input['tags'] = $tags;
	return $input;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->shop;
	switch($operation) {
	    case 'list':
		$sort = isset(self::SORTS[(string)$this->cfg('sort', 'UPDATED_AT_DESC')]) ? (string)$this->cfg('sort', 'UPDATED_AT_DESC') : 'UPDATED_AT_DESC';
		$S->pageSize = 100;
		return $this->listOf('customers', self::CUSTOMER, $text('search'), 'customer',
		    ', sortKey: ' . preg_replace('/_DESC$/', '', $sort) . ', reverse: ' . (str_ends_with($sort, '_DESC') ? 'true' : 'false'));
	    case 'create':
		$input = $this->customerInput($text, $value);
		if (empty($input['email']) && empty($input['phone']))
		    throw new \RuntimeException('Email or Phone: required to create a customer');
		if ((bool)$this->cfg('marketing', false) && !empty($input['email']))
		    $input['emailMarketingConsent'] = [ 'marketingState' => 'SUBSCRIBED', 'marketingOptInLevel' => 'SINGLE_OPT_IN' ];
		$p = $S->mutate('mutation($input: CustomerInput!) { customerCreate(input: $input) { customer { ' . self::CUSTOMER . ' } userErrors { field message } } }',
		    [ 'input' => $this->withFields($input) ], 'customerCreate');
		$c = \util\Shopify::simplify($p['customer']);
		return [ $c, (($c['displayName'] ?? '') ?: ($c['email'] ?? '')) . ' created (' . ($c['id'] ?? '?') . ')' ];
	}
	$id = $this->customerGid($value);
	if ($id === null) {
	    if ($operation === 'get')
		return [ null, 'no customer with that email' ];
	    throw new \RuntimeException('Customer: no customer with that email');
	}
	switch($operation) {
	    case 'get':
		$c = $this->one('customer', $id, self::CUSTOMER . ' addresses { ' . NodeAppShopOrders::ADDRESS . ' }', 'customer');
		return [ $c, (($c['displayName'] ?? '') ?: ($c['email'] ?? $id)) . ': ' . \util\Format::plural((int)($c['numberOfOrders'] ?? 0), 'order') ];
	    case 'update':
		$input = $this->withFields($this->customerInput($text, $value));
		if (!$input)
		    throw new \RuntimeException('nothing to change');
		$p = $S->mutate('mutation($input: CustomerInput!) { customerUpdate(input: $input) { customer { ' . self::CUSTOMER . ' } userErrors { field message } } }',
		    [ 'input' => [ 'id' => $id ] + $input ], 'customerUpdate');
		return [ \util\Shopify::simplify($p['customer']), ($p['customer']['displayName'] ?? $id) . ' updated: ' . implode(', ', array_keys($input)) ];
	    case 'addTags':
	    case 'removeTags':
		return $this->changeTags($id, $value('tags'), $operation === 'addTags', 'customer');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
