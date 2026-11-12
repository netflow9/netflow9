<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: a fake Shopify Admin API for the check suite (CheckCommand::suiteShopify), run by PHP's built-in server:
    SHOP_STATE=<a folder> php -S 127.0.0.1:<port> ShopifyTestServer.php
	POST /admin/oauth/access_token: client credentials grant (client_id cid-check-123, client_secret csecret): a token, issued tokens kept in <state>/tokens.json
	POST /admin/api/<version>/graphql.json: X-Shopify-Access-Token shpat_check or an issued token (else 401). The answer picked by the query's first root field (mutations: theirs);
	    the objects kept in <state>/<name>.json in GraphQL's shape (orders, products, variants, locations, customers, webhooks); <state>/throttle.json: how many THROTTLED answers next
	Each request appended to <state>/requests.jsonl (path, token, query, variables, form)
    It reads what the steps send, not GraphQL: field selections are not applied, the objects are given whole
*/

$state = getenv('SHOP_STATE') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$token = $_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '';
file_put_contents("{$state}/requests.jsonl", json_encode([ 'path' => $path, 'token' => $token, 'query' => $body['query'] ?? null, 'variables' => $body['variables'] ?? null, 'form' => $_POST ],
    JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200) {
    http_response_code($status);
    echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$data = fn(array $d) => $out([ 'data' => $d, 'extensions' => [ 'cost' => [ 'requestedQueryCost' => 10, 'actualQueryCost' => 5, 'throttleStatus' => [ 'maximumAvailable' => 2000, 'currentlyAvailable' => 1990,
    'restoreRate' => 100 ] ] ] ]);
$userError = fn($field, $message) => [ 'userErrors' => [ [ 'field' => [ $field ], 'message' => $message ] ] ];

if ($path === '/admin/oauth/access_token') {
    if (($_POST['grant_type'] ?? '') !== 'client_credentials' || ($_POST['client_id'] ?? '') !== 'cid-check-123' || ($_POST['client_secret'] ?? '') !== 'csecret')
	$out([ 'error' => 'invalid_client', 'error_description' => 'Client authentication failed' ], 400);
    $tokens = $load('tokens');
    $t = 'tok-' . (count($tokens) + 1);
    $tokens[] = $t;
    $save('tokens', $tokens);
    $out([ 'access_token' => $t, 'scope' => 'read_orders,write_orders', 'expires_in' => 86399 ]);
}
if (!preg_match('~^/admin/api/\d{4}-\d{2}/graphql\.json$~', $path))
    $out([ 'errors' => 'Not Found' ], 404);
if ($token !== 'shpat_check' && !in_array($token, $load('tokens'), true))
    $out([ 'errors' => '[API] Invalid API key or access token (unrecognized login or wrong password)' ], 401);
$throttle = (int)$load('throttle', 0);
if ($throttle > 0) {
    $save('throttle', $throttle - 1);
    $out([ 'errors' => [ [ 'message' => 'Throttled', 'extensions' => [ 'code' => 'THROTTLED', 'documentation' => 'https://shopify.dev/api/usage/rate-limits' ] ] ],
	'extensions' => [ 'cost' => [ 'requestedQueryCost' => 60, 'actualQueryCost' => null, 'throttleStatus' => [ 'maximumAvailable' => 2000, 'currentlyAvailable' => 10, 'restoreRate' => 100 ] ] ] ]);
}

$q = (string)($body['query'] ?? '');
$v = (array)($body['variables'] ?? []);
$mutation = (bool)preg_match('/^\s*mutation/', $q);
$stripped = preg_replace('/^\s*(query|mutation)\s*(\([^)]*\))?\s*/', '', $q);
$root = preg_match('/^\{\s*(\w+)/', $stripped, $m) ? $m[1] : '';

//a connection of $all: first / after (an index), the search applied by $match
$page = function(array $all, ?callable $match = null) use ($v) {
    $all = array_values($match ? array_filter($all, $match) : $all);
    $from = (int)($v['after'] ?? 0);
    $first = (int)($v['first'] ?? 1);
    $nodes = array_slice($all, $from, $first);
    $end = $from + count($nodes);
    return [ 'nodes' => $nodes, 'pageInfo' => [ 'hasNextPage' => $end < count($all), 'endCursor' => (string)$end ] ];
};
//the value of `key:"value"` (or key:value) in the search
$term = function($key) use ($v) {
    $s = (string)($v['query'] ?? '');
    if (preg_match('/' . preg_quote($key, '/') . ':"((?:[^"\\\\]|\\\\.)*)"/', $s, $m))
	return stripcslashes($m[1]);
    return preg_match('/' . preg_quote($key, '/') . ':(\S+)/', $s, $m) ? $m[1] : null;
};
$byId = function($name, $id) use ($load) {
    foreach($load($name) as $o)
	if (($o['id'] ?? null) === $id)
	    return $o;
    return null;
};
$change = function($name, $id, callable $f) use ($load, $save) {
    $all = $load($name);
    foreach($all as $i => $o)
	if (($o['id'] ?? null) === $id) {
	    $all[$i] = $f($o);
	    $save($name, $all);
	    return $all[$i];
	}
    return null;
};

switch($root) {
    case 'shop':
	$d = [ 'shop' => [ 'id' => 'gid://shopify/Shop/1', 'name' => 'Check Store', 'myshopifyDomain' => 'check-store.myshopify.com', 'currencyCode' => 'RON', 'plan' => [ 'displayName' => 'Basic' ],
	    'primaryDomain' => [ 'url' => 'https://shop.example.com', 'host' => 'shop.example.com' ] ] ];
	if (str_contains($q, 'currentAppInstallation'))
	    $d['currentAppInstallation'] = [ 'accessScopes' => [ [ 'handle' => 'read_orders' ], [ 'handle' => 'write_products' ] ] ];
	$data($d);
    case 'orders':
	$name = $term('name');
	$data([ 'orders' => $page($load('orders'), $name === null ? null : fn($o) => $o['name'] === $name) ]);
    case 'order':
	$data([ 'order' => $byId('orders', $v['id'] ?? '') ]);
    case 'orderUpdate':
	$o = $change('orders', $v['input']['id'] ?? '', fn($o) => array_replace($o, array_diff_key($v['input'], [ 'id' => 1 ])));
	$data([ 'orderUpdate' => $o ? [ 'order' => $o, 'userErrors' => [] ] : [ 'order' => null ] + $userError('id', 'Order does not exist') ]);
    case 'tagsAdd':
    case 'tagsRemove':
	foreach([ 'orders', 'products', 'customers' ] as $name)
	    $change($name, $v['id'] ?? '', fn($o) => array_replace($o, [ 'tags' => array_values($root === 'tagsAdd' ? array_unique(array_merge($o['tags'] ?? [], $v['tags'])) : array_diff($o['tags'] ?? [], $v['tags'])) ]));
	$data([ $root => [ 'node' => [ 'id' => $v['id'] ], 'userErrors' => [] ] ]);
    case 'orderMarkAsPaid':
	$o = $change('orders', $v['input']['id'] ?? '', fn($o) => array_replace($o, [ 'displayFinancialStatus' => 'PAID' ]));
	$data([ 'orderMarkAsPaid' => $o ? [ 'order' => $o, 'userErrors' => [] ] : [ 'order' => null ] + $userError('id', 'Order does not exist') ]);
    case 'orderClose':
    case 'orderOpen':
	$o = $change('orders', $v['input']['id'] ?? '', fn($o) => array_replace($o, [ 'closed' => $root === 'orderClose', 'closedAt' => $root === 'orderClose' ? '2026-11-12T10:00:00Z' : null ]));
	$data([ $root => [ 'order' => $o, 'userErrors' => [] ] ]);
    case 'orderCancel':
	if (!$byId('orders', $v['id'] ?? ''))
	    $data([ 'orderCancel' => [ 'job' => null, 'orderCancelUserErrors' => [ [ 'field' => [ 'orderId' ], 'message' => 'Order not found', 'code' => 'NOT_FOUND' ] ] ] ]);
	$change('orders', $v['id'], fn($o) => array_replace($o, [ 'cancelledAt' => '2026-11-12T10:00:00Z', 'cancelReason' => $v['reason'] ]));
	$data([ 'orderCancel' => [ 'job' => [ 'id' => 'gid://shopify/Job/77', 'done' => false ], 'orderCancelUserErrors' => [] ] ]);
    case 'fulfillmentCreate':
	$ids = array_column((array)($v['f']['lineItemsByFulfillmentOrder'] ?? []), 'fulfillmentOrderId');
	foreach($load('orders') as $o)
	    foreach($o['fulfillmentOrders']['nodes'] ?? [] as $fo)
		if (in_array($fo['id'], $ids, true))
		    $change('orders', $o['id'], function($o) use ($ids) {
			foreach($o['fulfillmentOrders']['nodes'] as $i => $fo)
			    if (in_array($fo['id'], $ids, true))
				$o['fulfillmentOrders']['nodes'][$i]['status'] = 'CLOSED';
			return $o;
		    });
	$data([ 'fulfillmentCreate' => [ 'fulfillment' => [ 'id' => 'gid://shopify/Fulfillment/' . (100 + count($ids)), 'status' => 'SUCCESS', 'trackingInfo' => isset($v['f']['trackingInfo'])
	    ? [ $v['f']['trackingInfo'] ] : [] ], 'userErrors' => [] ] ]);
    case 'products':
	$handle = $term('handle');
	$data([ 'products' => $page($load('products'), $handle === null ? null : fn($p) => $p['handle'] === $handle) ]);
    case 'product':
	$data([ 'product' => $byId('products', $v['id'] ?? '') ]);
    case 'productCreate':
	$all = $load('products');
	$n = count($all) + 1;
	$p = $v['product'] + [ 'id' => "gid://shopify/Product/{$n}", 'handle' => strtolower(preg_replace('/\W+/', '-', $v['product']['title'])), 'status' => 'DRAFT',
	    'variants' => [ 'nodes' => [ [ 'id' => "gid://shopify/ProductVariant/{$n}0", 'price' => '0.00', 'sku' => '' ] ] ] ];
	$all[] = $p;
	$save('products', $all);
	$data([ 'productCreate' => [ 'product' => $p, 'userErrors' => [] ] ]);
    case 'productUpdate':
	$p = $change('products', $v['product']['id'] ?? '', fn($p) => array_replace($p, $v['product']));
	$data([ 'productUpdate' => $p ? [ 'product' => $p, 'userErrors' => [] ] : [ 'product' => null ] + $userError('id', 'Product does not exist') ]);
    case 'productDelete':
	$data([ 'productDelete' => [ 'deletedProductId' => $v['input']['id'], 'userErrors' => [] ] ]);
    case 'productVariantsBulkUpdate':
	$out1 = [];
	foreach($v['variants'] as $in) {
	    $sku = $in['inventoryItem']['sku'] ?? null;
	    unset($in['inventoryItem']);
	    $out1[] = array_replace([ 'id' => $in['id'], 'sku' => $sku ?? 'SKU-1', 'price' => '0.00', 'compareAtPrice' => null ], $byId('variants', $in['id']) ?? [], $in, $sku !== null ? [ 'sku' => $sku ] : []);
	}
	$data([ 'productVariantsBulkUpdate' => [ 'productVariants' => $out1, 'userErrors' => [] ] ]);
    case 'productVariants':
	$sku = $term('sku');
	$data([ 'productVariants' => $page($load('variants'), $sku === null ? null : fn($x) => $x['sku'] === $sku) ]);
    case 'productVariant':
	$data([ 'productVariant' => $byId('variants', $v['id'] ?? '') ]);
    case 'inventoryAdjustQuantities':
    case 'inventorySetQuantities':
	$changes = $root === 'inventoryAdjustQuantities' ? $v['input']['changes'] : $v['input']['quantities'];
	foreach($changes as $c)
	    $change('variants', array_values(array_filter($load('variants'), fn($x) => $x['inventoryItem']['id'] === $c['inventoryItemId']))[0]['id'] ?? '', function($x) use ($c, $root) {
		foreach($x['inventoryItem']['inventoryLevels']['nodes'] as $i => $l)
		    if ($l['location']['id'] === $c['locationId'])
			foreach($l['quantities'] as $j => $qq)
			    if ($qq['name'] === 'available')
				$x['inventoryItem']['inventoryLevels']['nodes'][$i]['quantities'][$j]['quantity'] = $root === 'inventoryAdjustQuantities' ? $qq['quantity'] + $c['delta'] : $c['quantity'];
		return $x;
	    });
	$data([ $root => [ 'inventoryAdjustmentGroup' => [ 'id' => 'gid://shopify/InventoryAdjustmentGroup/1', 'reason' => $v['input']['reason'] ], 'userErrors' => [] ] ]);
    case 'locations':
	$name = $term('name');
	$data([ 'locations' => $page($load('locations'), fn($l) => ($name === null || $l['name'] === $name) && (!str_contains((string)($v['query'] ?? $q), 'active:true') || $l['isActive'])) ]);
    case 'customers':
	$email = $term('email');
	$data([ 'customers' => $page($load('customers'), $email === null ? null : fn($c) => strcasecmp($c['email'], $email) === 0) ]);
    case 'customer':
	$data([ 'customer' => $byId('customers', $v['id'] ?? '') ]);
    case 'customerCreate':
	$all = $load('customers');
	if (array_filter($all, fn($c) => strcasecmp($c['email'], (string)($v['input']['email'] ?? '')) === 0))
	    $data([ 'customerCreate' => [ 'customer' => null ] + $userError('email', 'Email has already been taken') ]);
	$c = $v['input'] + [ 'id' => 'gid://shopify/Customer/' . (count($all) + 1), 'displayName' => trim(($v['input']['firstName'] ?? '') . ' ' . ($v['input']['lastName'] ?? '')), 'numberOfOrders' => '0' ];
	$all[] = $c;
	$save('customers', $all);
	$data([ 'customerCreate' => [ 'customer' => $c, 'userErrors' => [] ] ]);
    case 'customerUpdate':
	$c = $change('customers', $v['input']['id'] ?? '', fn($c) => array_replace($c, $v['input']));
	$data([ 'customerUpdate' => [ 'customer' => $c, 'userErrors' => [] ] ]);
    case 'webhookSubscriptions':
	$data([ 'webhookSubscriptions' => $page($load('webhooks')) ]);
    case 'webhookSubscriptionCreate':
	$all = $load('webhooks');
	$w = [ 'id' => 'gid://shopify/WebhookSubscription/' . (count($all) + 1 + (int)$load('webhookSeq', 0)), 'topic' => $v['topic'], 'format' => $v['sub']['format'] ?? 'JSON', 'createdAt' => '2026-11-12T10:00:00Z',
	    'endpoint' => [ '__typename' => 'WebhookHttpEndpoint', 'callbackUrl' => $v['sub']['callbackUrl'] ] ];
	$save('webhookSeq', (int)$load('webhookSeq', 0) + 1);
	$all[] = $w;
	$save('webhooks', $all);
	$data([ 'webhookSubscriptionCreate' => [ 'webhookSubscription' => $w, 'userErrors' => [] ] ]);
    case 'webhookSubscriptionDelete':
	$save('webhooks', array_values(array_filter($load('webhooks'), fn($w) => $w['id'] !== $v['id'])));
	$data([ 'webhookSubscriptionDelete' => [ 'deletedWebhookSubscriptionId' => $v['id'], 'userErrors' => [] ] ]);
}
$out([ 'errors' => [ [ 'message' => "Field '{$root}' doesn't exist on type '" . ($mutation ? 'Mutation' : 'QueryRoot') . "'", 'extensions' => [ 'code' => 'undefinedField' ] ] ] ]);
