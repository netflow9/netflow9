<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify Orders - the store's orders. Operations (setting `operation`):
	list        the orders a search finds (orders), sorted; line items only when asked (they make each page smaller: Shopify's cost limit)
	get         an order with its line items, fulfillments and fulfillment orders (order); by its ID, its GID, its name (#1001) or a webhook's order
	update      its note, email, tags (orderUpdate; other fields: Other fields, e.g. shippingAddress)
	addTags     tags added / removed (tagsAdd / tagsRemove)
	removeTags
	markPaid    marked as paid (orderMarkAsPaid: a manual payment of what is due)
	fulfill     what is left to ship fulfilled, with the tracking number (fulfillmentCreate of each open fulfillment order)
	close       closed / opened again (orderClose / orderOpen)
	reopen
	cancel      cancelled (orderCancel): the reason, refunded or not, restocked or not, the customer notified or not. Shopify cancels in a job: the result has its id
    Refunds, returns and edits are left to the Shopify GraphQL step
*/

namespace models\node;

class NodeAppShopOrders extends NodeAppShopify {
    public static $name = 'shopify-orders';
    public static $label = 'Shopify Orders';
    public static $icon = 'bi-receipt';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'orders';

    const OPERATIONS = [
	'list' => 'List orders',
	'get' => 'Get an order',
	'update' => 'Update an order',
	'addTags' => 'Add tags',
	'removeTags' => 'Remove tags',
	'markPaid' => 'Mark as paid',
	'fulfill' => 'Fulfill an order',
	'close' => 'Close an order',
	'reopen' => 'Reopen an order',
	'cancel' => 'Cancel an order',
    ];
    const WRITES = [ 'update', 'addTags', 'removeTags', 'markPaid', 'fulfill', 'close', 'reopen', 'cancel' ];
    const LISTS = [ 'list' ];
    const INPUTS = [ 'update', 'fulfill' ];
    const ONE = [ 'get', 'update', 'addTags', 'removeTags', 'markPaid', 'fulfill', 'close', 'reopen', 'cancel' ];
    const SORTS = [ 'CREATED_AT_DESC' => 'Newest first', 'CREATED_AT' => 'Oldest first', 'UPDATED_AT_DESC' => 'Updated, latest first', 'PROCESSED_AT_DESC' => 'Processed, latest first',
	'ORDER_NUMBER_DESC' => 'Number, highest first', 'TOTAL_PRICE_DESC' => 'Total, highest first' ];
    const REASONS = [ 'CUSTOMER' => 'The customer changed their mind', 'DECLINED' => 'Payment declined', 'FRAUD' => 'Fraudulent', 'INVENTORY' => 'Out of stock', 'STAFF' => 'Staff error',
	'OTHER' => 'Other' ];
    //the fulfillment orders that can still be fulfilled
    const OPEN = [ 'OPEN', 'IN_PROGRESS' ];

    const ADDRESS = 'name firstName lastName company address1 address2 city province provinceCode zip country countryCodeV2 phone';
    const ORDER = 'id legacyResourceId name email phone createdAt updatedAt processedAt cancelledAt cancelReason closedAt confirmed test displayFinancialStatus displayFulfillmentStatus'
	. ' tags note currencyCode totalPrice: totalPriceSet { shopMoney { ' . self::MONEY . ' } } subtotalPrice: subtotalPriceSet { shopMoney { ' . self::MONEY . ' } }'
	. ' totalTax: totalTaxSet { shopMoney { ' . self::MONEY . ' } } totalShipping: totalShippingPriceSet { shopMoney { ' . self::MONEY . ' } }'
	. ' totalDiscounts: totalDiscountsSet { shopMoney { ' . self::MONEY . ' } } customer { id firstName lastName email }'
	. ' shippingAddress { ' . self::ADDRESS . ' } billingAddress { ' . self::ADDRESS . ' }';
    const LINE = 'id name title sku quantity currentQuantity vendor variant { id sku } product { id } originalUnitPrice: originalUnitPriceSet { shopMoney { ' . self::MONEY . ' } }';
    //a page of the list (Shopify's cost limit of a query: 1000)
    const PAGE = 50;
    const PAGE_LINES = 10;

    protected static function operationFields() {
	return [
	    self::searchField([ 'list' ], 'financial_status:paid fulfillment_status:unfulfilled created_at:>2026-01-01'),
	    self::field('sort', 'Sorted by', [ 'list' ], [ 'type' => 'select', 'default' => 'CREATED_AT_DESC', 'choices' => self::SORTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('withLines', 'With line items', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Fewer orders per request', 'ui' => [ 'width' => 6 ] ]),
	    self::field('order', 'Order', self::ONE, [ 'required' => true, 'help' => 'Its ID, its name (#1001), or an order of a list or a webhook', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('note', 'Note', [ 'update' ], [ 'type' => 'textarea', 'help' => 'Empty: unchanged', 'ui' => [ 'rows' => 2, 'expression' => 'text' ] ]),
	    self::field('email', 'Email', [ 'update' ], [ 'help' => 'Empty: unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('tags', 'Tags', [ 'update', 'addTags', 'removeTags' ], [ 'help' => 'Separated by commas, or a list. Update: replaces them all (empty: unchanged)',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('company', 'Carrier', [ 'fulfill' ], [ 'help' => 'e.g. DHL Express, UPS; empty: no tracking', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('trackingNumber', 'Tracking number', [ 'fulfill' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('trackingUrl', 'Tracking address', [ 'fulfill' ], [ 'help' => 'Empty: Shopify\'s for known carriers', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('reason', 'Reason', [ 'cancel' ], [ 'type' => 'select', 'required' => true, 'default' => 'OTHER', 'choices' => self::REASONS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('staffNote', 'Staff note', [ 'cancel' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('refund', 'Refund', [ 'cancel' ], [ 'type' => 'switch', 'default' => true, 'help' => 'To the original payment methods', 'ui' => [ 'width' => 4 ] ]),
	    self::field('restock', 'Restock', [ 'cancel' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 4 ] ]),
	    self::field('notify', 'Notify the customer', [ 'fulfill', 'cancel' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 4 ] ]),
	];
    }

    //the GID of the setting `order`: an ID, a GID, an object (a list item, a webhook's payload), or an order's name (#1001: looked up)
    protected function orderGid(\Closure $value) {
	$v = $value('order');
	$t = is_array($v) ? '' : trim(\util\Expression::toText($v));
	if (preg_match('/^#\S+$/', $t)) {
	    $o = $this->findOne('orders', 'name:' . \util\Shopify::quoted($t), 'id name');
	    if (!$o || strcasecmp((string)$o['name'], $t) !== 0)
		throw new \RuntimeException("Order: no order {$t}");
	    return $o['id'];
	}
	return \util\Shopify::gid('Order', $v, 'Order');
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $sort = isset(self::SORTS[(string)$this->cfg('sort', 'CREATED_AT_DESC')]) ? (string)$this->cfg('sort', 'CREATED_AT_DESC') : 'CREATED_AT_DESC';
	    $lines = (bool)$this->cfg('withLines', false);
	    $this->shop->pageSize = $lines ? self::PAGE_LINES : self::PAGE;
	    return $this->listOf('orders', self::ORDER . ($lines ? ' lineItems(first: 50) { nodes { ' . self::LINE . ' } }' : ''), $text('search'), 'order',
		', sortKey: ' . preg_replace('/_DESC$/', '', $sort) . ', reverse: ' . (str_ends_with($sort, '_DESC') ? 'true' : 'false'));
	}
	$id = $this->orderGid($value);
	$S = $this->shop;
	switch($operation) {
	    case 'get':
		$o = $this->one('order', $id, self::ORDER . ' lineItems(first: 250) { nodes { ' . self::LINE . ' } } fulfillments(first: 20) { id status createdAt trackingInfo { company number url } }'
		    . ' fulfillmentOrders(first: 20) { nodes { id status requestStatus assignedLocation { name location { id } } } }', 'order');
		return [ $o, ($o['name'] ?? $id) . ': ' . ($o['displayFinancialStatus'] ?? '') . ', ' . ($o['displayFulfillmentStatus'] ?? '') . ', ' . ($o['totalPrice']['amount'] ?? '') . ' ' . ($o['totalPrice']['currencyCode'] ?? '') ];
	    case 'update':
		$input = [ 'id' => $id ];
		foreach([ 'note', 'email' ] as $k)
		    if (($t = $text($k)) !== '')
			$input[$k] = $t;
		if (($tags = self::names($value('tags'))) !== [])
		    $input['tags'] = $tags;
		$input = $this->withFields($input);
		if (count($input) < 2)
		    throw new \RuntimeException('nothing to change: a note, an email, tags or Other fields');
		$p = $S->mutate('mutation($input: OrderInput!) { orderUpdate(input: $input) { order { ' . self::ORDER . ' } userErrors { field message } } }', [ 'input' => $input ], 'orderUpdate');
		return [ \util\Shopify::simplify($p['order']), ($p['order']['name'] ?? $id) . ' updated: ' . implode(', ', array_keys(array_diff_key($input, [ 'id' => 1 ]))) ];
	    case 'addTags':
	    case 'removeTags':
		return $this->changeTags($id, $value('tags'), $operation === 'addTags', 'order');
	    case 'markPaid':
		$p = $S->mutate('mutation($input: OrderMarkAsPaidInput!) { orderMarkAsPaid(input: $input) { order { id name displayFinancialStatus } userErrors { field message } } }',
		    [ 'input' => [ 'id' => $id ] ], 'orderMarkAsPaid');
		return [ $p['order'], ($p['order']['name'] ?? $id) . ': ' . ($p['order']['displayFinancialStatus'] ?? 'paid') ];
	    case 'close':
	    case 'reopen':
		$m = $operation === 'close' ? 'orderClose' : 'orderOpen';
		$type = $operation === 'close' ? 'OrderCloseInput' : 'OrderOpenInput';
		$p = $S->mutate("mutation(\$input: {$type}!) { {$m}(input: \$input) { order { id name closed closedAt } userErrors { field message } } }", [ 'input' => [ 'id' => $id ] ], $m);
		return [ $p['order'], ($p['order']['name'] ?? $id) . ($operation === 'close' ? ' closed' : ' reopened') ];
	    case 'cancel':
		$reason = isset(self::REASONS[(string)$this->cfg('reason', 'OTHER')]) ? (string)$this->cfg('reason', 'OTHER') : 'OTHER';
		$vars = [ 'id' => $id, 'reason' => $reason, 'refund' => (bool)$this->cfg('refund', true), 'restock' => (bool)$this->cfg('restock', true), 'notify' => (bool)$this->cfg('notify', true),
		    'note' => $text('staffNote') ?: null ];
		$p = $S->mutate('mutation($id: ID!, $reason: OrderCancelReason!, $refund: Boolean!, $restock: Boolean!, $notify: Boolean, $note: String) { orderCancel(orderId: $id, reason: $reason,'
		    . ' refund: $refund, restock: $restock, notifyCustomer: $notify, staffNote: $note) { job { id done } orderCancelUserErrors { field message code } } }', $vars, 'orderCancel');
		return [ [ 'id' => $id, 'job' => $p['job'] ?? null, 'reason' => $reason, 'refund' => $vars['refund'], 'restock' => $vars['restock'] ], "{$id} cancelled ({$reason})"
		    . ($vars['refund'] ? ', refunded' : '') . ($vars['restock'] ? ', restocked' : '') ];
	    case 'fulfill':
		return $this->fulfill($id, $text);
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //what is left of the order's open fulfillment orders, in one fulfillment (one per location: Shopify's fulfillment takes the fulfillment orders of one location)
    protected function fulfill($id, \Closure $text) {
	$o = $this->one('order', $id, 'id name fulfillmentOrders(first: 20) { nodes { id status assignedLocation { location { id } } } }', 'order');
	$byLocation = [];
	foreach((array)($o['fulfillmentOrders'] ?? []) as $fo)
	    if (in_array((string)($fo['status'] ?? ''), self::OPEN, true))
		$byLocation[(string)($fo['assignedLocation']['location']['id'] ?? '')][] = [ 'fulfillmentOrderId' => $fo['id'] ];
	if (!$byLocation)
	    throw new \RuntimeException(($o['name'] ?? $id) . ': nothing left to fulfill');
	$tracking = array_filter([ 'company' => $text('company'), 'number' => $text('trackingNumber'), 'url' => $text('trackingUrl') ], fn($v) => $v !== '');
	$done = [];
	foreach($byLocation as $groups) {
	    $input = $this->withFields([ 'lineItemsByFulfillmentOrder' => $groups, 'notifyCustomer' => (bool)$this->cfg('notify', true) ] + ($tracking ? [ 'trackingInfo' => $tracking ] : []));
	    $p = $this->shop->mutate('mutation($f: FulfillmentInput!) { fulfillmentCreate(fulfillment: $f) { fulfillment { id status trackingInfo { company number url } } userErrors { field message } } }',
		[ 'f' => $input ], 'fulfillmentCreate');
	    $done[] = $p['fulfillment'];
	}
	return [ [ 'id' => $id, 'name' => $o['name'] ?? null, 'fulfillments' => $done ], ($o['name'] ?? $id) . ' fulfilled' . (isset($tracking['number']) ? " ({$tracking['number']})" : '') ];
    }
}
