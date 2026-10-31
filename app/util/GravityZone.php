<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new: the client of the Bitdefender GravityZone Public API (cloud or on-premises console), for the GravityZone credential, steps and trigger.
    JSON-RPC 2.0 over HTTPS: POST <access URL>/v<version>/jsonrpc/<area> (area: network, incidents, quarantine/computers, reports, push, general, ...), the key as HTTP Basic
    (a personal API key: the key as username, no password) or in X-API-Key (a service API key). Public addresses only, unless the account allows private network addresses
    (Account allow_private_addresses: an on-premises console; never this server itself); the certificate checked unless the credential trusts it (self-signed).

    $gz = new GravityZone($cred, $aid, $timeout);
    $gz->call($area, $method, $params, $version)       the call's `result`; \RuntimeException with GravityZone's message on an error
	HTTP 429 (more than the key's requests per second): waited for Retry-After (at most RETRY_WAIT s, within the timeout) and tried once more
    $gz->all($area, $method, $params, $max)             a paged list (page / perPage): [ items, total, truncated ]: the pages read until $max items
    $gz->download($url, $sink, $maxBytes)               a file of the console (a report's download link) into the stream $sink: util\Http's answer

    GravityZone::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class GravityZone {
    //the most items asked for in one page (GravityZone's perPage limit)
    const PER_PAGE = 100;
    //the longest wait for Retry-After (seconds)
    const RETRY_WAIT = 10;
    const MAX_ANSWER = 20 * 1024 * 1024;

    public static $testing = false;

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

    //the access URL as given (up to /api), without a trailing / and without a /v1.0/jsonrpc/... pasted after it
    public static function baseUrl($url) {
	$url = rtrim(trim((string)$url), '/');
	return preg_replace('~/v\d+(\.\d+)?/jsonrpc(/.*)?$~i', '', $url);
    }

    //the request options: authentication, the address guard, the certificate
    public function options() {
	$key = (string)$this->cred->apiKey;
	$headers = $this->cred->keyType === 'service' ? [ 'X-API-Key' => $key ] : [ 'Authorization' => 'Basic ' . base64_encode($key . ':') ];
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'headers' => $headers,
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    //seconds left of the timeout
    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    public function call($area, $method, array $params = [], $version = '1.0') {
	$area = trim((string)$area, '/');
	if (!preg_match('~^[A-Za-z][A-Za-z0-9]*(/[A-Za-z][A-Za-z0-9]*)?$~', $area))
	    throw new \RuntimeException("not an API name: `{$area}`");
	if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', (string)$method))
	    throw new \RuntimeException("not a method name: `{$method}`");
	if (!preg_match('/^\d+\.\d+$/', (string)$version))
	    throw new \RuntimeException("not an API version: `{$version}`");
	$url = self::baseUrl($this->cred->accessUrl) . "/v{$version}/jsonrpc/{$area}";
	$body = [ 'id' => bin2hex(random_bytes(8)), 'jsonrpc' => '2.0', 'method' => (string)$method, 'params' => (object)$params ];

	for ($try = 0; ; $try++) {
	    $left = $this->left();
	    if ($left < 1)
		throw new \RuntimeException("{$method}: the step's timeout ({$this->timeout} s) passed");
	    $r = Http::json('POST', $url, [ 'json' => $body, 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ] + $this->options());
	    if ($r['status'] === 429 && $try === 0) {
		$wait = max(1, (int)($r['headers']['retry-after'] ?? 1));
		if ($wait <= self::RETRY_WAIT && $wait < $this->left()) {
		    sleep($wait);
		    continue;
		}
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException("{$method}: " . ($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}"));
	$data = $r['data'];
	if (is_array($data) && isset($data['error']))
	    throw new \RuntimeException("{$method}: " . self::errorText($data['error'], $r['status']));
	if ($r['status'] === 429)
	    throw new \RuntimeException("{$method}: too many requests (the API key's limit), try again later");
	if ($r['status'] === 401 || $r['status'] === 403)
	    throw new \RuntimeException("{$method}: HTTP status {$r['status']}: the API key is refused, or this API is not enabled for it");
	if ($r['status'] >= 400)
	    throw new \RuntimeException("{$method}: HTTP status {$r['status']}");
	if (!is_array($data) || !array_key_exists('result', $data))
	    throw new \RuntimeException("{$method}: the answer is not a JSON-RPC answer (is the access URL right?)");
	return $data['result'];
    }

    //GravityZone's error: its message and details (error.data.details)
    public static function errorText($error, $status = 0) {
	if (!is_array($error))
	    return 'error' . ($status ? " (HTTP status {$status})" : '');
	$text = (string)($error['message'] ?? 'error');
	$details = $error['data']['details'] ?? null;
	if (is_string($details) && $details !== '' && $details !== $text)
	    $text .= ': ' . $details;
	return mb_substr($text . (isset($error['code']) ? " (code {$error['code']})" : ''), 0, 500);
    }

    //a paged list: the pages read (perPage PER_PAGE, or fewer for the last) until $max items or the last page
    public function all($area, $method, array $params, $max, $version = '1.0') {
	$max = max(1, (int)$max);
	$items = [];
	$total = 0;
	$truncated = false;
	for ($page = 1; ; $page++) {
	    $result = $this->call($area, $method, $params + [ 'page' => $page, 'perPage' => min(self::PER_PAGE, $max) ], $version);
	    $list = is_array($result['items'] ?? null) ? $result['items'] : (is_array($result) && array_is_list($result) ? $result : []);
	    $total = (int)($result['total'] ?? count($list));
	    foreach($list as $item) {
		if (count($items) >= $max) {
		    $truncated = true;
		    break 2;
		}
		$items[] = $item;
	    }
	    $pages = (int)($result['pagesCount'] ?? 1);
	    if ($page >= $pages || !$list)
		break;
	    if (count($items) >= $max) {
		$truncated = $total > count($items);
		break;
	    }
	}
	return [ $items, max($total, count($items)), $truncated ];
    }

    //a file of the console (e.g. a report's download link), with the key, into $sink
    public function download($url, $sink, $maxBytes) {
	$url = (string)$url;
	if (!preg_match('~^https?://~i', $url))
	    throw new \RuntimeException('not a download address');
	//the key goes only to the console's own host
	$o = $this->options();
	if (strcasecmp((string)parse_url($url, PHP_URL_HOST), (string)parse_url(self::baseUrl($this->cred->accessUrl), PHP_URL_HOST)) !== 0)
	    $o['headers'] = [];
	$r = Http::request('GET', $url, [ 'sink' => $sink, 'maxBytes' => $maxBytes, 'timeout' => max(1, $this->left()), 'redirects' => 3 ] + $o);
	if ($r['tooLarge'])
	    throw new \RuntimeException('the file is larger than ' . (int)($maxBytes / 1048576) . ' MB');
	if ($r['error'] !== null)
	    throw new \RuntimeException('download: ' . $r['error']);
	if ($r['status'] >= 400)
	    throw new \RuntimeException("download: HTTP status {$r['status']}");
	return $r;
    }
}
