<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: the client of HubSpot's API (https://api.hubapi.com), for the HubSpot credential, steps and record trigger. A private app's access token
    (Settings > Integrations > Private apps; pat-…) as Bearer on every request; what it may do is the scopes chosen for the app (crm.objects.contacts.read, …).
    HubSpot's limits: requests per 10 seconds per app, and the search endpoints 5 per second: a 429 waits (Retry-After, else 1 s) and is sent again while the step's timeout
    allows. Errors: HubSpot's message (+ its category, the errors' messages). The account's details (portalId, uiDomain) are read once per process, for the records' addresses.

    $h = new HubSpot($cred, $aid, $timeout);
    $h->request($method, $path, $query, $body)        the answer (decoded JSON; null when empty); \RuntimeException with HubSpot's message
    $h->search($type, $filters, $properties, $sorts, $max, $text)   the records a CRM search finds (POST /crm/v3/objects/<type>/search, paged by after): [ records, total, truncated ]
    $h->account()                                     the account's details (account-info/v3/details)
    $h->recordUrl($type, $id)                          a record's address in HubSpot
    HubSpot::record($raw, $url)                       a record: { id, url, createdAt, updatedAt, archived, <its properties> , properties }
    HubSpot::filters($text)                           filter lines ("amount > 1000", "email contains example.com", "phone has") as a search's filters

    HubSpot::$testUrl: the check suite's local server (http://127.0.0.1:…): no https, no address guard
*/

namespace util;

class HubSpot {
    const API = 'https://api.hubapi.com';
    const MAX_ANSWER = 20 * 1024 * 1024;
    //the most records one search page gives; the most a search reaches (HubSpot's)
    const PAGE = 200;
    const MAX_SEARCH = 10000;
    const MAX_WAIT = 15;
    const PATH = '#^/[A-Za-z0-9/_.,:@%+=-]*$#';
    //the standard objects: the setting's value => [ the API's name, the object type id (record addresses) ]
    const OBJECTS = [ 'contacts' => '0-1', 'companies' => '0-2', 'deals' => '0-3', 'tickets' => '0-5' ];
    //filter operators: written => HubSpot's
    const OPERATORS = [ '=' => 'EQ', '!=' => 'NEQ', '>' => 'GT', '>=' => 'GTE', '<' => 'LT', '<=' => 'LTE', 'contains' => 'CONTAINS_TOKEN', '!contains' => 'NOT_CONTAINS_TOKEN',
	'in' => 'IN', '!in' => 'NOT_IN', 'has' => 'HAS_PROPERTY', '!has' => 'NOT_HAS_PROPERTY' ];

    public static $testUrl = null;
    //the accounts' details in this process: token hash => details
    protected static $accounts = [];

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

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //HubSpot's message in an error answer: message (category), the errors' messages
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$m = trim((string)($d['message'] ?? ''));
	$more = array_values(array_filter(array_map(fn($e) => is_array($e) ? trim((string)($e['message'] ?? '')) : '', (array)($d['errors'] ?? [])), fn($x) => $x !== '' && $x !== $m));
	return mb_substr($m . (!empty($d['category']) ? " ({$d['category']})" : '') . ($more ? ': ' . implode('; ', array_slice($more, 0, 5)) : ''), 0, 1000);
    }

    public function request($method, $path, array $query = [], $body = null) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$url = (self::$testUrl ?? self::API) . $path . ($q !== '' ? "?{$q}" : '');
	$testing = self::$testUrl !== null;
	while (true) {
	    $o = [ 'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->accessToken) ], 'https' => !$testing, 'public' => !$testing, 'maxBytes' => self::MAX_ANSWER,
		'timeout' => $this->left() ];
	    if ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 1)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('HubSpot\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from HubSpot: ' . $r['error']);
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    $hint = [ 401 => 'the access token is refused', 403 => 'the private app lacks a scope for this', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	return trim((string)$r['body']) === '' ? null : $r['data'];
    }

    //the account's details, read once per process: { portalId, uiDomain, timeZone, companyCurrency, dataHostingLocation }
    public function account() {
	$k = sha1((self::$testUrl ?? '') . "\n" . (string)$this->cred->accessToken);
	return self::$accounts[$k] ??= (array)$this->request('GET', '/account-info/v3/details');
    }

    public static function forgetAccounts() {
	self::$accounts = [];
    }

    //a record's address in HubSpot (null when the account's details cannot be read)
    public function recordUrl($type, $id) {
	try {
	    $a = $this->account();
	} catch (\RuntimeException $e) {
	    return null;
	}
	$typeId = self::OBJECTS[$type] ?? $type;
	return 'https://' . ($a['uiDomain'] ?? 'app.hubspot.com') . '/contacts/' . ($a['portalId'] ?? '') . '/record/' . rawurlencode((string)$typeId) . '/' . rawurlencode((string)$id);
    }

    //a record of an answer: its properties at the top (as well as in `properties`), with its id, address, times
    public static function record(array $raw, $url = null) {
	$props = (array)($raw['properties'] ?? []);
	return [ 'id' => (string)($raw['id'] ?? ''), 'url' => $url, 'createdAt' => $raw['createdAt'] ?? null, 'updatedAt' => $raw['updatedAt'] ?? null, 'archived' => (bool)($raw['archived'] ?? false) ]
	    + array_diff_key($props, array_flip([ 'id', 'url', 'createdAt', 'updatedAt', 'archived', 'properties' ])) + [ 'properties' => $props ];
    }

    //a filter's value: a date (2026-11-01, ISO 8601) as ms; else the text
    public static function filterValue($v) {
	$v = trim((string)$v, " \t\"'");
	if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $v)) {
	    $t = strtotime(strlen($v) === 10 ? "{$v} 00:00:00 UTC" : $v);
	    if ($t !== false)
		return (string)($t * 1000);
	}
	return $v;
    }

    //filter lines, all of them to match: "<property> <operator> <value>"; operators =, !=, >, >=, <, <=, contains, !contains, in / !in (values separated by commas), has / !has (no value)
    public static function filters($text) {
	$out = [];
	foreach(preg_split('/\R/', (string)$text) as $line) {
	    $line = trim($line);
	    if ($line === '' || str_starts_with($line, '#'))
		continue;
	    if (!preg_match('/^([A-Za-z0-9_.]+)\s*(>=|<=|!=|=|>|<|!contains\b|contains\b|!in\b|in\b|!has\b|has\b)\s*(.*)$/i', $line, $m))
		throw new \RuntimeException('Filters: not "<property> <operator> <value>": `' . mb_substr($line, 0, 80) . '`');
	    $op = self::OPERATORS[strtolower($m[2])];
	    $f = [ 'propertyName' => $m[1], 'operator' => $op ];
	    if (in_array($op, [ 'IN', 'NOT_IN' ], true))
		$f['values'] = array_values(array_filter(array_map(fn($v) => self::filterValue($v), explode(',', $m[3])), fn($v) => $v !== ''));
	    elseif (!in_array($op, [ 'HAS_PROPERTY', 'NOT_HAS_PROPERTY' ], true)) {
		if (trim($m[3]) === '')
		    throw new \RuntimeException("Filters: no value: `{$line}`");
		$f['value'] = self::filterValue($m[3]);
	    }
	    $out[] = $f;
	}
	if (count($out) > 6)
	    throw new \RuntimeException('Filters: at most 6 (HubSpot\'s limit in one search)');
	return $out;
    }

    //a CRM search: [ the records (raw), total, truncated ]
    public function search($type, array $filters, array $properties, array $sorts, $max, $text = '') {
	$max = max(1, min(self::MAX_SEARCH, (int)$max));
	$records = [];
	$after = null;
	$total = 0;
	while (true) {
	    $body = [ 'limit' => min(self::PAGE, $max - count($records)), 'properties' => array_values($properties) ] + ($filters ? [ 'filterGroups' => [ [ 'filters' => $filters ] ] ] : [])
		+ ($sorts ? [ 'sorts' => $sorts ] : []) + ($text !== '' ? [ 'query' => (string)$text ] : []) + ($after !== null ? [ 'after' => $after ] : []);
	    $d = (array)$this->request('POST', '/crm/v3/objects/' . rawurlencode((string)$type) . '/search', [], $body);
	    $list = (array)($d['results'] ?? []);
	    $total = (int)($d['total'] ?? count($list));
	    foreach($list as $r)
		$records[] = $r;
	    $after = $d['paging']['next']['after'] ?? null;
	    if (!$list || $after === null || count($records) >= $max)
		return [ $records, max($total, count($records)), $total > count($records) ];
	}
    }
}
