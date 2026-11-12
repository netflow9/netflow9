<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: the client of Zendesk Support's REST API (https://<subdomain>.zendesk.com/api/v2), for the Zendesk credential, steps and ticket trigger.
	auth     an agent's email and an API token (Basic "<email>/token:<token>"; Admin Center > Apps and integrations > Zendesk API), or an OAuth access token (Bearer)
	lists    cursor paging (page[size], meta.has_more, links.next); the search (search.json: per_page / next_page, at most 1000 results)
	errors   Zendesk's { error, description, details: { field: [ { description } ] } } or { error: { title, message } }
    A 429 waits for Retry-After and is sent again while the step's timeout allows.

    $z = new Zendesk($cred, $timeout);
    $z->request($method, $path, $query, $body)    the answer (decoded JSON; [] when empty): a path below /api/v2 (.json added), or a links.next / next_page address
    $z->pages($path, $key, $query, $max)          a list's items ($key of the answer) through cursor pages: [ items, truncated ]
    $z->search($query, $max, $more)               the search's results: [ results, count (Zendesk's), truncated ]
    $z->agentUrl($kind, $id)                      a ticket's / user's / organization's address in the agent workspace
    Zendesk::ticketId($v), Zendesk::quote($v)     a ticket's id of a number, a ticket or its address; a search value, quoted

    Zendesk::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of https://<subdomain>.zendesk.com
*/

namespace util;

class Zendesk {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PAGE = 100;
    const MAX_WAIT = 60;
    //the search's own limit
    const MAX_SEARCH = 1000;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=-]*$#';

    public static $testing = null;

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the subdomain of a setting: acme, acme.zendesk.com or https://acme.zendesk.com/…
    public static function subdomain($v) {
	$v = strtolower(trim((string)$v));
	if (preg_match('~^(?:https?://)?([a-z0-9][a-z0-9-]*)\.zendesk\.com(?:/.*)?$~', $v, $m))
	    return $m[1];
	return $v;
    }

    public function base() {
	return self::$testing ?? 'https://' . self::subdomain($this->cred->subdomain) . '.zendesk.com';
    }

    public function agentUrl($kind, $id) {
	return $this->base() . '/agent/' . [ 'ticket' => 'tickets', 'user' => 'users', 'organization' => 'organizations' ][$kind] . '/' . rawurlencode((string)$id);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	$o = [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left(), 'compressed' => true ];
	if (($this->cred->auth ?? 'token') === 'token')
	    $o['auth'] = [ 'basic', trim((string)$this->cred->email) . '/token', (string)$this->cred->apiToken ];
	return $o;
    }

    protected function authHeaders() {
	return ($this->cred->auth ?? 'token') === 'oauth' ? [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->accessToken) ] : [];
    }

    //Zendesk's message of an error answer
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$e = $d['error'] ?? null;
	if (is_array($e))
	    return mb_substr(trim(($e['title'] ?? '') . ': ' . ($e['message'] ?? ''), ': '), 0, 1000);
	$list = [];
	foreach((array)($d['details'] ?? []) as $field => $items)
	    foreach((array)$items as $i)
		$list[] = is_array($i) ? (string)($i['description'] ?? $i['error'] ?? '') : (string)$i;
	$list = array_values(array_filter($list));
	$m = $list ? implode('; ', array_slice($list, 0, 5)) : trim((string)($d['description'] ?? ''));
	if ($m === '' && isset($d['errors']) && is_array($d['errors']))
	    $m = implode('; ', array_map(fn($x) => is_array($x) ? (string)($x['title'] ?? $x['detail'] ?? json_encode($x)) : (string)$x, array_slice($d['errors'], 0, 5)));
	$code = is_string($e) ? $e : '';
	return mb_substr($m !== '' ? $m . ($code !== '' && $code !== $m ? " ({$code})" : '') : $code, 0, 1000);
    }

    //a path below /api/v2 (.json added), or an address of the account (links.next, next_page)
    public function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, $this->base() . '/api/v2/'))
		throw new \RuntimeException('not an address of the Zendesk account: `' . mb_substr($path, 0, 100) . '`');
	    return $path;
	}
	$path = '/' . ltrim(preg_replace('~^/?api/v2~', '', $path), '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	if (!str_ends_with($path, '.json'))
	    $path .= '.json';
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	return $this->base() . '/api/v2' . $path . ($q !== '' ? "?{$q}" : '');
    }

    //a request: the answer decoded ([] when empty); $raw: [ body, content type ] sent as they are (an upload)
    public function request($method, $path, array $query = [], $body = null, ?array $raw = null) {
	$method = strtoupper((string)$method);
	$url = $this->url($path, $query);
	while (true) {
	    $o = [ 'headers' => $this->authHeaders() ] + $this->options();
	    if ($raw !== null) {
		$o['body'] = $raw[0];
		$o['headers']['Content-Type'] = $raw[1];
	    } elseif ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 10)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Zendesk\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . $this->base() . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    $hint = [ 401 => 'the email and API token (or the access token) are refused', 403 => 'the user may not do this', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	if (trim((string)$r['body']) !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not Zendesk\'s JSON (is the subdomain right?)');
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a list's items ($key) through cursor pages, at most $max: [ items, truncated ]
    public function pages($path, $key, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$d = $this->request('GET', $path, $query + [ 'page[size]' => min(self::PAGE, $max + 1) ]);
	while (true) {
	    foreach((array)($d[$key] ?? []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = !empty($d['meta']['has_more']) ? ($d['links']['next'] ?? null) : null;
	    if (!is_string($next) || $next === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $d = $this->request('GET', $next);
	}
    }

    //the search (search.json), at most $max (and Zendesk's 1000): [ results, count, truncated ]
    public function search($query, $max, array $more = []) {
	$max = max(1, min(self::MAX_SEARCH, (int)$max));
	$results = [];
	$d = $this->request('GET', '/search', [ 'query' => (string)$query, 'per_page' => min(self::PAGE, $max) ] + $more);
	$count = (int)($d['count'] ?? 0);
	while (true) {
	    foreach((array)($d['results'] ?? []) as $r) {
		if (count($results) >= $max)
		    return [ $results, $count, true ];
		$results[] = $r;
	    }
	    $next = $d['next_page'] ?? null;
	    if (!is_string($next) || $next === '')
		return [ $results, $count, false ];
	    if (count($results) >= $max)
		return [ $results, $count, true ];
	    $d = $this->request('GET', $next);
	}
    }

    //a ticket's id: 123, #123, a ticket ({ id }), or its address (…/tickets/123)
    public static function ticketId($value, $label = 'Ticket') {
	if (is_array($value))
	    $value = $value['id'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/tickets/(\d+)~', $v, $m))
	    $v = $m[1];
	$v = ltrim($v, '#');
	if (!preg_match('/^\d{1,15}$/', $v) || (int)$v < 1)
	    throw new \RuntimeException("{$label}: not a ticket id (a number, e.g. 123): `" . mb_substr($v, 0, 60) . '`');
	return (int)$v;
    }

    //a value of the search language, quoted when it has spaces or quotes
    public static function quote($v) {
	$v = (string)$v;
	return preg_match('/[\s"]/', $v) ? '"' . str_replace('"', '', $v) . '"' : $v;
    }
}
