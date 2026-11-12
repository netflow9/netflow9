<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: the client of a Shopify store's GraphQL Admin API, for the Shopify credential, steps and webhook trigger.
    One address: https://<shop>.myshopify.com/admin/api/<version>/graphql.json, POST { query, variables }, the token as X-Shopify-Access-Token. The token is either
	- an Admin API access token (shpat_…) of a custom app made in the store's admin (Settings > Apps > Develop apps), or
	- got with the client credentials grant of an app made in the Dev Dashboard and installed on the store (POST /admin/oauth/access_token: grant_type=client_credentials,
	  client_id, client_secret): valid about 24 hours, kept in this process until shortly before it expires, asked again once when a request is answered 401.
    Shopify's limits are a cost per query (a bucket refilled each second, extensions.cost.throttleStatus): a THROTTLED answer (or 429) waits for the bucket and is sent again while
    the step's timeout allows. Errors: `errors` of the answer (a GraphQL error: Shopify's message and code), a mutation's userErrors (field: message).

    $s = new Shopify($cred, $aid, $timeout);
    $s->query($query, $variables)                    the answer's `data`; \RuntimeException with Shopify's errors
    $s->mutate($mutation, $variables, $field)        data[$field]; its userErrors as a \RuntimeException
    $s->connection($query, $variables, $field, $max) a connection's nodes, paged (first / after, $s->pageSize a page): [ items (simplified), truncated ]
    Shopify::gid($type, $value)                      a global ID (gid://shopify/<type>/<n>) of a number, a GID, or an object with id / admin_graphql_api_id (a webhook's payload)
    Shopify::simplify($v)                            connections as lists ({ nodes } / { edges: [ { node } ] }), MoneyBags as their shop money ({ amount, currencyCode })
    Shopify::shopDomain($v)                          <shop>.myshopify.com of a name, a domain or an admin address (admin.shopify.com/store/<shop>)
    Shopify::verifyHmac($raw, $signature, $secret)   a webhook's X-Shopify-Hmac-Sha256: base64 HMAC-SHA256 of the body

    Shopify::$testUrl: the check suite's local server (http://127.0.0.1:…, in place of https://<shop>): no https, no address guard
*/

namespace util;

class Shopify {
    //the API version used unless the credential names another (Shopify supports each for at least 12 months)
    const DEFAULT_VERSION = '2026-10';
    const VERSION = '~^\d{4}-(01|04|07|10)$~';
    const DOMAIN = '~^[a-z0-9][a-z0-9-]{0,62}\.myshopify\.com$~';
    const GID = '~^gid://shopify/([A-Za-z]+)/(\d+)(\?.*)?$~';
    const MAX_ANSWER = 20 * 1024 * 1024;
    //the most nodes a connection gives in one page
    const PAGE = 250;
    //a token is asked again this many seconds before it expires
    const MARGIN = 300;
    //the longest wait for Shopify's cost bucket before a throttled query is sent again
    const MAX_WAIT = 20;

    public static $testUrl = null;
    //the tokens of the client credentials grant in this process: key => [ token, expires (unix) ]
    protected static $tokens = [];

    //the nodes asked for in one page of a connection (fewer for nodes with connections of their own: Shopify's cost limit of a query is 1000)
    public $pageSize = self::PAGE;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //<shop>.myshopify.com of a store's name, its myshopify domain or address, or its admin address (admin.shopify.com/store/<shop>); \RuntimeException otherwise
    public static function shopDomain($value) {
	$v = strtolower(trim((string)$value));
	if (preg_match('~^(?:https?://)?admin\.shopify\.com/store/([a-z0-9-]+)~', $v, $m))
	    $v = $m[1];
	$v = preg_replace('~^https?://~', '', $v);
	$v = rtrim(explode('/', $v, 2)[0], '.');
	if ($v !== '' && !str_contains($v, '.'))
	    $v .= '.myshopify.com';
	if (!preg_match(self::DOMAIN, $v))
	    throw new \RuntimeException('the store: not a <shop>.myshopify.com address: `' . mb_substr((string)$value, 0, 80) . '`');
	return $v;
    }

    public function version() {
	$v = trim((string)$this->cred->apiVersion);
	return preg_match(self::VERSION, $v) ? $v : self::DEFAULT_VERSION;
    }

    public function base() {
	return self::$testUrl ?? 'https://' . self::shopDomain($this->cred->shop);
    }

    public function endpoint() {
	return $this->base() . '/admin/api/' . $this->version() . '/graphql.json';
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	$testing = self::$testUrl !== null;
	return [ 'https' => !$testing, 'public' => !$testing, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left() ];
    }

    //forget this process's tokens (the checks)
    public static function forgetTokens() {
	self::$tokens = [];
    }

    //the access token: the credential's, or one of the client credentials grant (kept until MARGIN before it expires)
    protected function token($fresh = false) {
	if (($this->cred->authMode ?? 'token') !== 'client')
	    return trim((string)$this->cred->accessToken);
	$key = sha1((string)$this->cred->_id . "\n" . $this->base() . "\n" . (string)$this->cred->clientId . "\n" . (string)$this->cred->clientSecret);
	$t = self::$tokens[$key] ?? null;
	if (!$fresh && $t && $t[1] - self::MARGIN > time())
	    return $t[0];
	$r = Http::json('POST', $this->base() . '/admin/oauth/access_token', [ 'form' => [ 'grant_type' => 'client_credentials', 'client_id' => trim((string)$this->cred->clientId),
	    'client_secret' => (string)$this->cred->clientSecret ] ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	$token = (string)($r['data']['access_token'] ?? '');
	if ($r['status'] >= 400 || $token === '') {
	    $m = self::errorText($r['data']['error_description'] ?? $r['data']['errors'] ?? $r['data']['error'] ?? '');
	    throw new \RuntimeException('Shopify refused the client ID and secret' . ($m !== '' ? ": {$m}" : " (HTTP status {$r['status']})")
		. ' (is the app installed on the store, and the app\'s Client ID and secret those of the Dev Dashboard?)');
	}
	self::$tokens[$key] = [ $token, time() + max(60, (int)($r['data']['expires_in'] ?? 86399)) ];
	return $token;
    }

    //Shopify's errors as one line: a text, a list of { message, extensions: { code } }, or { field: [ messages ] } (REST style)
    public static function errorText($errors) {
	if (is_string($errors))
	    return mb_substr(trim($errors), 0, 1000);
	if (!is_array($errors))
	    return '';
	$list = [];
	foreach($errors as $k => $e) {
	    if (is_array($e) && isset($e['message'])) {
		$code = (string)($e['extensions']['code'] ?? '');
		$list[] = trim((string)$e['message']) . ($code !== '' ? " ({$code})" : '');
	    } elseif (is_array($e))
		$list[] = (is_string($k) ? "{$k}: " : '') . implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $e));
	    elseif (is_scalar($e))
		$list[] = (is_string($k) ? "{$k}: " : '') . $e;
	}
	return mb_substr(implode('; ', array_slice($list, 0, 5)), 0, 1000);
    }

    //the seconds to wait for $cost points of the bucket (extensions.cost), at least 1
    public static function waitFor(array $cost) {
	$need = (float)($cost['requestedQueryCost'] ?? 0);
	$have = (float)($cost['throttleStatus']['currentlyAvailable'] ?? 0);
	$rate = (float)($cost['throttleStatus']['restoreRate'] ?? 50) ?: 50;
	return max(1, (int)ceil(($need - $have) / $rate));
    }

    //a query or mutation: its `data`; Shopify's errors (also a partial answer with errors) as a \RuntimeException
    public function query($query, array $variables = []) {
	$query = (string)$query;
	if (trim($query) === '')
	    throw new \RuntimeException('the query is empty');
	$body = [ 'query' => $query ] + ($variables ? [ 'variables' => $variables ] : []);
	$renewed = false;
	while (true) {
	    $r = Http::json('POST', $this->endpoint(), [ 'headers' => [ 'X-Shopify-Access-Token' => $this->token() ], 'json' => $body ] + $this->options());
	    if ($r['error'] !== null)
		throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	    if ($r['status'] === 401 && ($this->cred->authMode ?? 'token') === 'client' && !$renewed) {
		$renewed = true;
		$this->token(true);
		continue;
	    }
	    $errors = is_array($r['data']) ? ($r['data']['errors'] ?? null) : null;
	    $throttled = $r['status'] === 429 || (is_array($errors) && in_array('THROTTLED', array_map(fn($e) => is_array($e) ? (string)($e['extensions']['code'] ?? '') : '', $errors), true));
	    if ($throttled) {
		$wait = min(self::MAX_WAIT, $r['status'] === 429 ? max(1, (int)($r['headers']['retry-after'] ?? 2)) : self::waitFor((array)($r['data']['extensions']['cost'] ?? [])));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Shopify\'s query limit is reached (THROTTLED), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['status'] >= 400 || !is_array($r['data'])) {
	    $m = is_array($r['data']) ? self::errorText($r['data']['errors'] ?? '') : '';
	    $hint = [ 401 => 'the access token is refused', 402 => 'the store is frozen (unpaid)', 403 => 'the app lacks an access scope', 404 => 'no such store or API version',
		423 => 'the store is locked' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : (is_array($r['data']) ? '' : ': the answer is not JSON'))));
	}
	if (!empty($errors))
	    throw new \RuntimeException(self::errorText($errors) ?: 'Shopify refused the query');
	return (array)($r['data']['data'] ?? []);
    }

    //a mutation's payload (data[$field]); its userErrors (or <x>UserErrors) as a \RuntimeException "field: message; …"
    public function mutate($mutation, array $variables, $field) {
	$payload = $this->query($mutation, $variables)[$field] ?? null;
	if (!is_array($payload))
	    throw new \RuntimeException("Shopify's answer has no {$field}");
	foreach($payload as $k => $list) {
	    if ((strcasecmp($k, 'userErrors') === 0 || str_ends_with($k, 'UserErrors')) && is_array($list) && $list) {
		$parts = [];
		foreach(array_slice($list, 0, 5) as $e) {
		    $f = is_array($e['field'] ?? null) ? implode('.', $e['field']) : (string)($e['field'] ?? '');
		    $parts[] = ($f !== '' ? "{$f}: " : '') . trim((string)($e['message'] ?? '')) . (!empty($e['code']) ? " ({$e['code']})" : '');
		}
		throw new \RuntimeException(mb_substr(implode('; ', $parts), 0, 1000));
	    }
	}
	return $payload;
    }

    //the nodes of connection $field (the query takes $first and $after), page by page: [ items, truncated ]
    public function connection($query, array $variables, $field, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$after = null;
	while (true) {
	    $d = $this->query($query, [ 'first' => max(1, min((int)$this->pageSize, self::PAGE, $max - count($items))), 'after' => $after ] + $variables);
	    $c = $d[$field] ?? null;
	    if (!is_array($c))
		throw new \RuntimeException("Shopify's answer has no {$field}");
	    foreach((array)($c['nodes'] ?? array_column((array)($c['edges'] ?? []), 'node')) as $node)
		$items[] = self::simplify($node);
	    $more = !empty($c['pageInfo']['hasNextPage']);
	    $after = $c['pageInfo']['endCursor'] ?? null;
	    if (!$more || $after === null || count($items) >= $max)
		return [ array_slice($items, 0, $max), $more || count($items) > $max ];
	}
    }

    //connections as lists, MoneyBags ({ shopMoney }) as their shop money
    public static function simplify($v) {
	if (!is_array($v))
	    return $v;
	if (!array_is_list($v)) {
	    $keys = array_keys($v);
	    if (array_key_exists('nodes', $v) && !array_diff($keys, [ 'nodes', 'pageInfo' ]) && is_array($v['nodes']))
		return array_map([ self::class, 'simplify' ], $v['nodes']);
	    if (array_key_exists('edges', $v) && !array_diff($keys, [ 'edges', 'pageInfo' ]) && is_array($v['edges']))
		return array_map(fn($e) => self::simplify(is_array($e) ? ($e['node'] ?? null) : null), $v['edges']);
	    if ($keys === [ 'shopMoney' ])
		return self::simplify($v['shopMoney']);
	}
	return array_map([ self::class, 'simplify' ], $v);
    }

    //the global ID of a $type object: a number, its GID, an object with id / admin_graphql_api_id (a webhook's payload, a list item), or "#1001" (an order's name: null, looked up by the caller)
    public static function gid($type, $value, $label = null) {
	$label = $label ?? $type;
	if (is_array($value))
	    $value = $value['admin_graphql_api_id'] ?? $value['id'] ?? $value['gid'] ?? '';
	$v = trim(Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (preg_match('/^\d{1,20}$/', $v))
	    return "gid://shopify/{$type}/{$v}";
	if (preg_match(self::GID, $v, $m)) {
	    if ($m[1] !== $type)
		throw new \RuntimeException("{$label}: a {$m[1]}'s ID, not a {$type}'s: `{$v}`");
	    return "gid://shopify/{$type}/{$m[2]}";
	}
	throw new \RuntimeException("{$label}: not a {$type} ID (a number or gid://shopify/{$type}/…): `" . mb_substr($v, 0, 80) . '`');
    }

    //a value in Shopify's search syntax: quoted, its quotes and backslashes escaped
    public static function quoted($v) {
	return '"' . addcslashes((string)$v, '"\\') . '"';
    }

    //a webhook's signature (X-Shopify-Hmac-Sha256): the base64 HMAC-SHA256 of the body with the app's client secret (or the store's webhook signing key)
    public static function verifyHmac($raw, $signature, $secret) {
	$secret = (string)$secret;
	$signature = trim((string)$signature);
	return $secret !== '' && $signature !== '' && hash_equals(base64_encode(hash_hmac('sha256', (string)$raw, $secret, true)), $signature);
    }

    //a webhook topic (orders/create) as the API's enum (ORDERS_CREATE)
    public static function topicEnum($topic) {
	return strtoupper(str_replace([ '/', '-' ], '_', trim((string)$topic)));
    }
}
