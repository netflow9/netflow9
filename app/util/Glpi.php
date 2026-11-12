<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: the client of GLPI's REST API (apirest.php, GLPI 9.5 to 11), for the GLPI credential, steps and ticket trigger.
	session   GET /initSession with the user's API token (Authorization: user_token …; My settings > Remote access keys) or the login and password (Basic), plus the API
	          client's App-Token when Setup > General > API asks for one; then the Session-Token header. Opened at the first request, closed by close() (killSession);
	          an expired session (ERROR_SESSION_TOKEN_INVALID) opened again once. The credential's profile and entity applied after it opens (changeActiveProfile / Entities)
	items     GET /<itemtype> paged by range=a-b (Content-Range: a-b/total); the search engine GET /search/<itemtype> with criteria[] (fields by search option number)
	errors    GLPI's [ "ERROR_…", "message" ]: "<message> (ERROR_…)"; a multi-status answer ([ { "<id>": false, message } ]) is an error too
    GLPI is mostly on premises: a server on a private address needs the account's "Integrations may connect to private network addresses"; its certificate checked unless
    trusted (self-signed). HTTPS only.

    $g = new Glpi($cred, $aid, $timeout);
    $g->request($method, $path, $query, $body)        the answer (decoded JSON); \RuntimeException with GLPI's message ($g->lastCode: its ERROR_… code)
    $g->items($itemtype, $query, $max)                the items of a list: [ items, truncated ]
    $g->search($itemtype, $criteria, $max, $more)     the rows of the search engine: [ rows (by search option number), total ]
    $g->many($itemtype, $ids, $query)                 the items of these ids (getMultipleItems), in their order
    $g->formUrl($itemtype, $id)                       an item's address in the browser
    $g->close()                                       the session closed
    Glpi::html($v), Glpi::plain($html)                a text as GLPI's HTML (a text with tags stays as it is); GLPI's HTML as plain text
    Glpi::itemId($v)                                  an item's id of a number, an item ({ id }) or its address (…?id=12)

    Glpi::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Glpi {
    const MAX_ANSWER = 20 * 1024 * 1024;
    //items per page (GLPI's api_max_range is 1000 by default)
    const PAGE = 100;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=-]*$#';
    const ITEMTYPE = '/^[A-Za-z][A-Za-z0-9_]{0,99}$/';

    public static $testing = false;

    public $lastCode = null;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;
    protected $session = null;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the server's address, without /apirest.php
    public static function baseUrl($url) {
	return preg_replace('~/apirest\.php$~i', '', rtrim(trim((string)$url), '/'));
    }

    public function apiUrl() {
	return self::baseUrl($this->cred->url) . '/apirest.php';
    }

    //an item's form in the browser: Ticket 12 => <base>/front/ticket.form.php?id=12
    public function formUrl($itemtype, $id) {
	return self::baseUrl($this->cred->url) . '/front/' . strtolower((string)$itemtype) . '.form.php?id=' . (int)$id;
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'maxBytes' => self::MAX_ANSWER,
	    'timeout' => $this->left(),
	];
    }

    protected function appHeader() {
	$app = trim((string)$this->cred->appToken);
	return $app !== '' ? [ 'App-Token' => $app ] : [];
    }

    //GLPI's message of an error answer: [ "ERROR_…", "message" ] (the link to the documentation left out)
    public static function message($d) {
	if (is_array($d) && array_is_list($d) && isset($d[0]) && is_string($d[0])) {
	    $m = trim(preg_replace('/;?\s*view documentation in your browser at .*$/is', '', is_string($d[1] ?? null) ? $d[1] : ''));
	    return mb_substr($m !== '' && $m !== $d[0] ? "{$m} ({$d[0]})" : $d[0], 0, 1000);
	}
	if (is_array($d) && isset($d['message']) && is_string($d['message']))
	    return mb_substr($d['message'], 0, 1000);
	return '';
    }

    protected static function checkPath($path) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `" . mb_substr($path, 0, 100) . '`');
	return $path;
    }

    protected function call($method, $path, array $query, $body, array $headers, ?array $raw) {
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$o = [ 'headers' => $headers + $this->appHeader() ] + $this->options();
	if ($raw !== null) {
	    $o['body'] = $raw[0];
	    $o['headers']['Content-Type'] = $raw[1];
	} elseif ($body !== null)
	    $o['json'] = $body === [] ? (object)[] : $body;
	elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
	    $o['body'] = '';
	$r = Http::json($method, $this->apiUrl() . self::checkPath($path) . ($q !== '' ? "?{$q}" : ''), $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($this->apiUrl()) . ": {$r['error']}");
	return $r;
    }

    protected function fail(array $r) {
	$d = $r['data'];
	$this->lastCode = is_array($d) && array_is_list($d) && is_string($d[0] ?? null) ? $d[0] : null;
	$m = self::message($d);
	$hint = [ 401 => 'the API token (or the login and password) or the App-Token is refused', 403 => 'the user\'s profile may not do this', 404 => 'not found' ][$r['status']] ?? '';
	throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
    }

    //the session: initSession, then the credential's profile and entity
    public function open() {
	if ($this->session !== null)
	    return $this->session;
	$auth = ($this->cred->auth ?? 'token') === 'password'
	    ? 'Basic ' . base64_encode(trim((string)$this->cred->login) . ':' . (string)$this->cred->password)
	    : 'user_token ' . trim((string)$this->cred->userToken);
	$r = $this->call('GET', '/initSession', [], null, [ 'Authorization' => $auth ], null);
	if ($r['status'] >= 400)
	    $this->fail($r);
	$token = is_array($r['data']) ? (string)($r['data']['session_token'] ?? '') : '';
	if ($token === '')
	    throw new \RuntimeException('the answer is not GLPI\'s (is the address the server\'s, and its API enabled in Setup > General > API?)');
	$this->session = $token;
	if (($p = trim((string)$this->cred->profileId)) !== '')
	    $this->request('POST', '/changeActiveProfile', [], [ 'profiles_id' => (int)$p ]);
	if (($e = trim((string)$this->cred->entityId)) !== '')
	    $this->request('POST', '/changeActiveEntities', [], [ 'entities_id' => (int)$e, 'is_recursive' => (bool)$this->cred->entityRecursive ]);
	return $this->session;
    }

    //the session closed (killSession); its errors not said
    public function close() {
	if ($this->session === null)
	    return;
	try {
	    $this->start = microtime(true);
	    $this->call('GET', '/killSession', [], null, [ 'Session-Token' => $this->session ], null);
	} catch (\RuntimeException $e) {
	}
	$this->session = null;
    }

    //a request of the API (a path after apirest.php): the answer decoded; $raw: [ body, content type ] sent as they are (a document's multipart); $full: request()'s answer whole
    public function request($method, $path, array $query = [], $body = null, ?array $raw = null, $full = false) {
	$method = strtoupper((string)$method);
	$this->lastCode = null;
	for ($again = 1; ; $again--) {
	    $r = $this->call($method, $path, $query, $body, [ 'Session-Token' => $this->open() ], $raw);
	    if ($r['status'] === 401 && $again > 0 && is_array($r['data']) && ($r['data'][0] ?? null) === 'ERROR_SESSION_TOKEN_INVALID') {
		$this->session = null;
		continue;
	    }
	    break;
	}
	if ($r['status'] >= 400)
	    $this->fail($r);
	if (trim((string)$r['body']) !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not GLPI\'s JSON (is the address the server\'s?)');
	//update / delete: [ { "<id>": true|false, "message": "…" } ] (207 when one is false)
	if (in_array($method, [ 'PUT', 'PATCH', 'DELETE' ], true) && is_array($r['data']) && array_is_list($r['data']))
	    foreach($r['data'] as $one)
		if (is_array($one) && in_array(false, array_filter($one, fn($v, $k) => $k !== 'message', ARRAY_FILTER_USE_BOTH), true))
		    throw new \RuntimeException(trim((string)($one['message'] ?? '')) !== '' ? strip_tags((string)$one['message']) : 'GLPI did not do it (the user\'s rights?)');
	if (in_array($method, [ 'POST' ], true) && is_array($r['data']) && array_key_exists('id', $r['data']) && $r['data']['id'] === false)
	    throw new \RuntimeException(trim((string)($r['data']['message'] ?? '')) !== '' ? strip_tags((string)$r['data']['message']) : 'GLPI did not add it (the user\'s rights?)');
	return $full ? $r : $r['data'];
    }

    //the items of a list (GET /<path>), paged by range, at most $max: [ items, truncated ]
    public function items($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	while (true) {
	    $from = count($items);
	    $page = min(self::PAGE, $max - $from + 1);
	    try {
		$r = $this->request('GET', $path, [ 'range' => $from . '-' . ($from + $page - 1) ] + $query, null, null, true);
	    } catch (\RuntimeException $e) {
		if ($this->lastCode === 'ERROR_RANGE_EXCEED_TOTAL')
		    return [ array_slice($items, 0, $max), count($items) > $max ];
		throw $e;
	    }
	    $list = is_array($r['data']) && array_is_list($r['data']) ? $r['data'] : [];
	    $total = preg_match('~/(\d+)\s*$~', (string)($r['headers']['content-range'] ?? ''), $m) ? (int)$m[1] : null;
	    foreach($list as $i)
		$items[] = $i;
	    $last = count($list) < $page || ($total !== null && count($items) >= $total);
	    if ($last || count($items) > $max)
		return [ array_slice($items, 0, $max), count($items) > $max ];
	}
    }

    //the search engine (GET /search/<itemtype>): its rows, at most $max from row $start: [ rows, total ]; $more: forcedisplay, sort, order, …
    public function search($itemtype, array $criteria, $max, array $more = [], $start = 0) {
	$itemtype = self::itemtype($itemtype);
	$max = max(1, (int)$max);
	$rows = [];
	$total = 0;
	while (true) {
	    $from = (int)$start + count($rows);
	    $page = min(self::PAGE, $max - count($rows));
	    try {
		$d = (array)$this->request('GET', "/search/{$itemtype}", [ 'criteria' => $criteria ?: null, 'range' => $from . '-' . ($from + $page - 1) ] + $more);
	    } catch (\RuntimeException $e) {
		if ($this->lastCode === 'ERROR_RANGE_EXCEED_TOTAL')
		    return [ $rows, $total ];
		throw $e;
	    }
	    $total = (int)($d['totalcount'] ?? 0);
	    $list = array_values((array)($d['data'] ?? []));
	    foreach($list as $row)
		$rows[] = (array)$row;
	    if (!$list || count($list) < $page || $from + count($list) >= $total || count($rows) >= $max)
		return [ $rows, $total ];
	}
    }

    //the items of these ids, in their order (GET /getMultipleItems, by pages); those not found left out
    public function many($itemtype, array $ids, array $query = []) {
	$itemtype = self::itemtype($itemtype);
	$byId = [];
	foreach(array_chunk(array_values(array_unique(array_map('intval', $ids))), self::PAGE) as $chunk) {
	    $d = (array)$this->request('GET', '/getMultipleItems', [ 'items' => array_map(fn($id) => [ 'itemtype' => $itemtype, 'items_id' => $id ], $chunk) ] + $query);
	    foreach($d as $i)
		if (is_array($i) && isset($i['id']))
		    $byId[(int)$i['id']] = $i;
	}
	$out = [];
	foreach($ids as $id)
	    if (isset($byId[(int)$id]))
		$out[] = $byId[(int)$id];
	return $out;
    }

    //an item type's name as GLPI writes it: Computer, NetworkEquipment, ITILCategory
    public static function itemtype($v, $label = 'Item type') {
	$t = trim((string)$v);
	if (!preg_match(self::ITEMTYPE, $t))
	    throw new \RuntimeException("{$label}: not an item type (Computer, Printer, …): `" . mb_substr($t, 0, 60) . '`');
	return $t;
    }

    //an item's id: 12, an item ({ id }) of a list, a search or a trigger, or its address (…form.php?id=12)
    public static function itemId($value, $label = 'Ticket') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['2'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~[?&]id=(\d+)~', $v, $m))
	    $v = $m[1];
	$v = ltrim($v, '#');
	if (!preg_match('/^\d{1,12}$/', $v) || (int)$v < 1)
	    throw new \RuntimeException("{$label}: not an id (a number, e.g. 12): `" . mb_substr($v, 0, 60) . '`');
	return (int)$v;
    }

    //a text as GLPI's HTML: paragraphs at blank lines, line breaks kept, escaped; a text with HTML tags stays as it is
    public static function html($v) {
	$t = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : Expression::toText($v);
	if (preg_match('~</?(p|div|br|ul|ol|li|table|tr|td|strong|b|i|em|a|h[1-6]|span|pre|code|img)\b[^>]*>~i', $t))
	    return $t;
	$out = [];
	foreach(preg_split('/\R{2,}/', trim(str_replace("\r\n", "\n", $t))) as $p)
	    if (trim($p) !== '')
		$out[] = '<p>' . str_replace("\n", '<br>', htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
	return implode("\n", $out);
    }

    //GLPI's HTML (stored encoded by GLPI 9.5 / 10: &lt;p&gt;) as plain text: blocks separated by blank lines, list items by new lines
    public static function plain($html) {
	$h = (string)$html;
	if (preg_match('/&(lt|#60|#x3c);/i', $h) && !preg_match('/<[a-z]/i', $h))
	    $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$h = preg_replace('~<br\s*/?>~i', "\n", $h);
	$h = preg_replace('~<li\b[^>]*>~i', "\n- ", $h);
	$h = preg_replace('~</(p|div|h[1-6]|ul|ol|table|pre|blockquote)>~i', "\n\n", $h);
	$h = preg_replace('~</tr>~i', "\n", $h);
	$t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$t = preg_replace("/[ \t]+\n/", "\n", str_replace("\u{a0}", ' ', $t));
	return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }
}
