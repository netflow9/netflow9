<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: the client of a Salesforce org's REST API, for the Salesforce credential, steps and record trigger. Sign-in: OAuth 2.0 client credentials
    (an External Client App or Connected App with the client credentials flow enabled and a run-as user): POST <My Domain>/services/oauth2/token gives an access token and the
    instance's address; the token is kept in this process and asked again once when a request is answered 401 (Salesforce gives no expiry: the session's timeout applies).
    Requests: <instance>/services/data/v<version>/…; errors: Salesforce's [ { message, errorCode, fields } ].

    $s = new Salesforce($cred, $aid, $timeout);
    $s->request($method, $path, $query, $body)     the answer (decoded JSON; null when empty): a path from /services/, or relative to /services/data/v<version>
    $s->query($soql, $max)                         a SOQL query's records, following nextRecordsUrl: [ records (raw), totalSize, truncated ]
    $s->instance()                                 the instance's address (of the token)
    Salesforce::record($raw, $instance)            a record without its `attributes`: { Id, …, type, url (Lightning) }; related records the same
    Salesforce::quote($v)                          a SOQL string literal
    Salesforce::recordId($v)                       a record's ID (15 or 18 characters) of a text or a record ({ Id })

    Salesforce::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Salesforce {
    const DEFAULT_VERSION = '64.0';
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PATH = '#^/[A-Za-z0-9/_.,:@%+=\-]*$#';
    const ID = '/^[a-zA-Z0-9]{15}([a-zA-Z0-9]{3})?$/';
    const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,79}$/';

    public static $testing = false;
    //the tokens of this process: key => [ token, instance ]
    protected static $tokens = [];

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

    public static function baseUrl($url) {
	return rtrim(trim((string)$url), '/');
    }

    public function version() {
	$v = ltrim(trim((string)$this->cred->apiVersion), 'vV');
	return preg_match('/^\d{2,3}\.0$/', $v) ? $v : self::DEFAULT_VERSION;
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	return [ 'https' => !self::$testing, 'public' => !self::$testing, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left() ];
    }

    public static function forgetTokens() {
	self::$tokens = [];
    }

    //[ access token, instance address ]: kept, or asked (client credentials)
    protected function token($fresh = false) {
	$base = self::baseUrl($this->cred->domainUrl);
	$key = sha1((string)$this->cred->_id . "\n{$base}\n" . (string)$this->cred->clientId . "\n" . (string)$this->cred->clientSecret);
	if (!$fresh && isset(self::$tokens[$key]))
	    return self::$tokens[$key];
	$r = Http::json('POST', "{$base}/services/oauth2/token", [ 'form' => [ 'grant_type' => 'client_credentials', 'client_id' => trim((string)$this->cred->clientId),
	    'client_secret' => (string)$this->cred->clientSecret ] ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($base) . ": {$r['error']}");
	$token = (string)($r['data']['access_token'] ?? '');
	if ($r['status'] >= 400 || $token === '') {
	    $m = trim((string)($r['data']['error_description'] ?? $r['data']['error'] ?? ''));
	    $hint = [ 'invalid_client_id' => 'the consumer key is wrong', 'invalid_client' => 'the consumer secret is wrong',
		'invalid_grant' => 'the client credentials flow is not enabled for the app, or it has no run-as user', 'unsupported_grant_type' => 'the client credentials flow is not enabled for the app' ];
	    throw new \RuntimeException('Salesforce refused the sign-in' . ($m !== '' ? ": {$m}" : " (HTTP status {$r['status']})")
		. (isset($hint[$r['data']['error'] ?? '']) ? ' (' . $hint[$r['data']['error']] . ')' : ''));
	}
	$instance = self::baseUrl($r['data']['instance_url'] ?? $base);
	if (!self::$testing && !preg_match('~^https://[a-z0-9.-]+\.(salesforce|force|salesforce-setup|my\.salesforce)\.com$~i', $instance))
	    $instance = $base;
	return self::$tokens[$key] = [ $token, $instance ];
    }

    public function instance() {
	return $this->token()[1];
    }

    //Salesforce's messages in an error answer: [ { message, errorCode, fields } ], or { error, error_description }
    public static function message($d) {
	if (!is_array($d))
	    return '';
	if (isset($d['error']) && is_string($d['error']))
	    return mb_substr(trim($d['error'] . ': ' . ($d['error_description'] ?? ''), ': '), 0, 1000);
	$list = [];
	foreach(array_is_list($d) ? $d : [ $d ] as $e) {
	    if (!is_array($e))
		continue;
	    $f = (array)($e['fields'] ?? []);
	    $list[] = trim((string)($e['message'] ?? '')) . (!empty($e['errorCode']) ? " ({$e['errorCode']})" : '') . ($f ? ' [' . implode(', ', $f) . ']' : '');
	}
	return mb_substr(implode('; ', array_slice(array_filter($list), 0, 5)), 0, 1000);
    }

    //a path from /services/ as it is; else below /services/data/v<version>
    public function apiPath($path) {
	$path = '/' . ltrim((string)$path, '/');
	if (!str_starts_with($path, '/services/'))
	    $path = '/services/data/v' . $this->version() . $path;
	return $path;
    }

    public function request($method, $path, array $query = [], $body = null) {
	$method = strtoupper((string)$method);
	[ $p, $qs ] = array_pad(explode('?', (string)$path, 2), 2, '');
	$p = $this->apiPath($p);
	if (!preg_match(self::PATH, $p) || str_contains($p, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($p, 0, 100) . '`');
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$q = implode('&', array_filter([ $qs, $q ], fn($x) => $x !== ''));
	for ($try = 0; ; $try++) {
	    [ $token, $instance ] = $this->token($try > 0);
	    $o = [ 'headers' => [ 'Authorization' => "Bearer {$token}" ] ] + $this->options();
	    if ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $instance . $p . ($q !== '' ? "?{$q}" : ''), $o);
	    if ($r['status'] === 401 && $try === 0)
		continue;
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from Salesforce: ' . $r['error']);
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ([ 401 => ': the session is refused', 403 => ': not allowed', 404 => ': not found' ][$r['status']] ?? '')));
	}
	return trim((string)$r['body']) === '' ? null : $r['data'];
    }

    //a SOQL query: [ records, totalSize, truncated ]
    public function query($soql, $max) {
	$max = max(1, (int)$max);
	$d = (array)$this->request('GET', '/query', [ 'q' => (string)$soql ]);
	$records = (array)($d['records'] ?? []);
	$total = (int)($d['totalSize'] ?? count($records));
	while (empty($d['done']) && !empty($d['nextRecordsUrl']) && count($records) < $max) {
	    $d = (array)$this->request('GET', (string)$d['nextRecordsUrl']);
	    $records = array_merge($records, (array)($d['records'] ?? []));
	}
	return [ array_slice($records, 0, $max), $total, $total > min($max, count($records)) ];
    }

    //a record without `attributes`, with its type and Lightning address; related records (Account.Name) and subqueries the same
    public static function record($raw, $instance = null) {
	if (!is_array($raw))
	    return $raw;
	if (isset($raw['records']) && array_key_exists('totalSize', $raw))
	    return array_map(fn($r) => self::record($r, $instance), (array)$raw['records']);
	$type = $raw['attributes']['type'] ?? null;
	unset($raw['attributes']);
	foreach($raw as $k => $v)
	    if (is_array($v))
		$raw[$k] = self::record($v, $instance);
	if ($type !== null) {
	    $raw['type'] = $type;
	    if ($instance && isset($raw['Id']))
		$raw['url'] = preg_replace('~\.my\.salesforce\.com$~', '.lightning.force.com', $instance) . '/lightning/r/' . rawurlencode($type) . '/' . rawurlencode((string)$raw['Id']) . '/view';
	}
	return $raw;
    }

    public static function quote($v) {
	return "'" . addcslashes((string)$v, "'\\\n\r\t\"") . "'";
    }

    public static function recordId($value, $label = 'Record') {
	if (is_array($value))
	    $value = $value['Id'] ?? $value['id'] ?? '';
	$v = trim(Expression::toText($value));
	if (!preg_match(self::ID, $v))
	    throw new \RuntimeException("{$label}: not a record ID (15 or 18 characters): `" . mb_substr($v, 0, 40) . '`');
	return $v;
    }

    //an object's or a field's API name (Account, Invoice__c, Contact.Email: $dots)
    public static function name($v, $label, $dots = false) {
	$v = trim((string)$v);
	foreach($dots ? explode('.', $v) : [ $v ] as $part)
	    if (!preg_match(self::NAME, $part))
		throw new \RuntimeException("{$label}: not an API name: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //a SOQL datetime literal (UTC, whole seconds: SOQL takes no fraction) of ms, rounded down
    public static function datetime($ms) {
	return gmdate('Y-m-d\TH:i:s\Z', (int)floor($ms / 1000));
    }
}
