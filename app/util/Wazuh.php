<?php

/*
    the client of Wazuh (4.8 and later), for the Wazuh credential, steps and alert trigger. Wazuh has two APIs, on two addresses:
	the Server API (https://<manager>:55000): agents, groups, active response, scans, inventory, rules. POST /security/user/authenticate with Basic gives a JWT, sent as Bearer;
	    it is kept in this process until shortly before it expires (auth_token_exp_timeout, 900 s by default) and the user signs in again once when a request is answered 401
	the Wazuh indexer (OpenSearch, https://<indexer>:9200): the alerts and the vulnerabilities live only there; its own user, as Basic on each request
    Public addresses only, unless the account allows private network addresses (Account allow_private_addresses; never this server itself); the certificate checked unless the
    credential trusts it (a default installation's are self-signed).

    $w = new Wazuh($cred, $aid, $timeout);
    $w->request($method, $path, $query, $body)    the Server API's answer (null when empty); \RuntimeException with Wazuh's message on an error
    $w->rows($path, $query, $max)                 a paged list (offset / limit; data.affected_items): [ items, total, truncated ]
    $w->indexer($method, $path, $query, $body)    the indexer's answer; \RuntimeException with its reason on an error, or when the credential has no indexer
    $w->search($index, $body)                     a search of the indexer: [ hits as { _id, _index, ...source }, total, aggregations ]

    Wazuh::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Wazuh {
    const ALERTS = 'wazuh-alerts-*';
    const VULNERABILITIES = 'wazuh-states-vulnerabilities-*';
    //the most items asked for in one page, and the largest window a search of the indexer gives (its index.max_result_window)
    const PAGE = 500;
    const MAX_HITS = 10000;
    const MAX_ANSWER = 20 * 1024 * 1024;
    //a token is renewed this many seconds before it expires
    const MARGIN = 30;
    const PATH = '~^/[A-Za-z0-9/_.,*:%-]*$~';

    public static $testing = false;
    //the tokens of this process: key => [ token, expires (unix) ]
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

    public function hasIndexer() {
	return self::baseUrl($this->cred->indexerUrl) !== '';
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'maxBytes' => self::MAX_ANSWER,
	];
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function key() {
	return sha1((string)$this->cred->_id . "\n" . self::baseUrl($this->cred->serverUrl) . "\n" . (string)$this->cred->username . "\n" . (string)$this->cred->password);
    }

    //forget this process's tokens (the checks)
    public static function forgetTokens() {
	self::$tokens = [];
    }

    //when a JWT expires (its `exp`); the default lifetime when it cannot be read
    public static function expiryOf($jwt) {
	$parts = explode('.', (string)$jwt);
	$claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
	return (int)($claims['exp'] ?? 0) ?: time() + 900;
    }

    protected function token($fresh = false) {
	$key = $this->key();
	$t = self::$tokens[$key] ?? null;
	if (!$fresh && $t && $t[1] - self::MARGIN > time())
	    return $t[0];
	$base = self::baseUrl($this->cred->serverUrl);
	$r = Http::json('POST', "{$base}/security/user/authenticate", [ 'auth' => [ 'basic', (string)$this->cred->username, (string)$this->cred->password ], 'body' => '', 'timeout' => $this->left() ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($base) . ": {$r['error']}");
	$token = (string)($r['data']['data']['token'] ?? '');
	if ($r['status'] >= 400 || $token === '')
	    throw new \RuntimeException('sign-in refused' . (($m = self::message($r['data'])) !== '' ? ": {$m}" : " (HTTP status {$r['status']})"));
	self::$tokens[$key] = [ $token, self::expiryOf($token) ];
	return $token;
    }

    //Wazuh's message in an error answer: what is wrong, its code and what to do
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$m = trim((string)($d['detail'] ?? $d['title'] ?? $d['message'] ?? ''));
	if (!empty($d['error']) && is_scalar($d['error']))
	    $m .= " (error {$d['error']})";
	if (!empty($d['remediation']) && is_string($d['remediation']))
	    $m .= ": {$d['remediation']}";
	return mb_substr($m, 0, 500);
    }

    protected static function checkPath($path) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `{$path}`");
	return $path;
    }

    //the query string: lists separated by commas (as Wazuh takes them), booleans as true / false
    public static function query(array $query) {
	$parts = [];
	foreach($query as $k => $v) {
	    if (is_array($v))
		$v = implode(',', array_map('strval', $v));
	    if ($v === null || $v === '')
		continue;
	    $parts[] = rawurlencode((string)$k) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
	}
	return implode('&', $parts);
    }

    protected static function bodyOption($method, $body) {
	if ($body !== null)
	    return [ 'json' => $body === [] ? (object)[] : $body ];
	return $method === 'GET' ? [] : [ 'body' => '' ];
    }

    public function request($method, $path, array $query = [], $body = null) {
	$base = self::baseUrl($this->cred->serverUrl);
	$q = self::query($query);
	$url = $base . self::checkPath($path) . ($q !== '' ? "?{$q}" : '');
	for ($try = 0; ; $try++) {
	    $token = $this->token($try > 0);
	    $r = Http::json($method, $url, [ 'headers' => [ 'Authorization' => "Bearer {$token}" ], 'timeout' => $this->left() ] + self::bodyOption($method, $body) + $this->options());
	    if ($r['status'] === 401 && $try === 0)
		continue;
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($base) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ''));
	}
	return $r['body'] === '' ? null : $r['data'];
    }

    //a paged list: pages of PAGE (or fewer for the last) until $max items or the last page
    public function rows($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$total = 0;
	for ($offset = 0; ; ) {
	    $r = $this->request('GET', $path, $query + [ 'offset' => $offset, 'limit' => min(self::PAGE, $max - count($items)) ]);
	    $list = (array)($r['data']['affected_items'] ?? []);
	    $total = (int)($r['data']['total_affected_items'] ?? count($list));
	    foreach($list as $item)
		$items[] = $item;
	    $offset += count($list);
	    if (!$list || $offset >= $total || count($items) >= $max)
		break;
	}
	return [ $items, max($total, count($items)), $total > count($items) ];
    }

    public function indexer($method, $path, array $query = [], $body = null) {
	$base = self::baseUrl($this->cred->indexerUrl);
	if ($base === '')
	    throw new \RuntimeException('the alerts and the vulnerabilities are in the Wazuh indexer: add its address and user to the credential');
	$q = self::query($query);
	$url = $base . self::checkPath($path) . ($q !== '' ? "?{$q}" : '');
	$r = Http::json($method, $url, [ 'auth' => [ 'basic', (string)$this->cred->indexerUsername, (string)$this->cred->indexerPassword ], 'timeout' => $this->left() ]
	    + self::bodyOption($method, $body) + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from the indexer at ' . Http::shown($base) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::indexerMessage($r['data']);
	    throw new \RuntimeException("the indexer answered HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($r['status'] === 401 ? ': the indexer user or password is refused' : '')));
	}
	return $r['body'] === '' ? null : $r['data'];
    }

    //the indexer's reason in an error answer ({ error: { type, reason, root_cause } }, or a text)
    public static function indexerMessage($d) {
	if (!is_array($d))
	    return '';
	$e = $d['error'] ?? $d['message'] ?? '';
	if (is_array($e)) {
	    $reason = (string)($e['root_cause'][0]['reason'] ?? $e['reason'] ?? '');
	    $type = (string)($e['root_cause'][0]['type'] ?? $e['type'] ?? '');
	    $e = trim($type . ($type !== '' && $reason !== '' ? ': ' : '') . $reason);
	}
	return mb_substr(trim((string)$e), 0, 500);
    }

    //a search: [ the hits, each its source with _id and _index, the total, the aggregations ]; shards that failed are an error: a partial answer would look like fewer alerts
    public function search($index, array $body) {
	$d = (array)$this->indexer('POST', '/' . $index . '/_search', [], $body);
	if (!empty($d['_shards']['failed']))
	    throw new \RuntimeException('the indexer could not read ' . $d['_shards']['failed'] . ' of its shards' . (($m = (string)($d['_shards']['failures'][0]['reason']['reason'] ?? '')) !== '' ? ": {$m}" : ''));
	$hits = [];
	foreach((array)($d['hits']['hits'] ?? []) as $h)
	    $hits[] = [ '_id' => (string)($h['_id'] ?? ''), '_index' => (string)($h['_index'] ?? '') ] + (array)($h['_source'] ?? []);
	$total = $d['hits']['total'] ?? count($hits);
	return [ $hits, is_array($total) ? (int)($total['value'] ?? count($hits)) : (int)$total, (array)($d['aggregations'] ?? []) ];
    }
}
