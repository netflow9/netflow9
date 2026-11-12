<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: the client of Microsoft Graph for Microsoft Intune, for the Intune credential, steps and device trigger. App-only: an app of the tenant (Entra admin
    center > App registrations) with a client secret and Graph application permissions an administrator consented to (DeviceManagementManagedDevices.ReadWrite.All,
    DeviceManagementManagedDevices.PrivilegedOperations.All, DeviceManagementConfiguration.Read.All, DeviceManagementApps.ReadWrite.All, DeviceManagementServiceConfig.ReadWrite.All,
    as the steps used need). The token (client credentials, scope https://graph.microsoft.com/.default) kept in this process until shortly before it expires, asked again once
    on a 401. A 429 / 503 waits for Retry-After and is sent again while the step's timeout allows. Errors: Graph's { error: { code, message } } (Intune's message is often
    JSON itself: its Message is shown).

    $g = new Intune($cred, $timeout);
    $g->request($method, $path, $query, $body)     the answer (decoded JSON; [] when empty): $path from /v1.0/ or /beta/ (else below /v1.0), or an @odata.nextLink
    $g->pages($path, $query, $max)                 a list's items through @odata.nextLink: [ items, truncated ]
    Intune::quote($v)                              an OData string literal
    Intune::id($v, $label)                         an id (GUID; or an item { id }) of a text

    Intune::$testing: the check suite's local server (http://127.0.0.1) for both the sign-in and Graph
*/

namespace util;

class Intune {
    const GRAPH = 'https://graph.microsoft.com';
    const LOGIN = 'https://login.microsoftonline.com';
    const MAX_ANSWER = 20 * 1024 * 1024;
    const MAX_WAIT = 30;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=()\'$-]*$#';
    const GUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    //the check suite's server: its address replaces both GRAPH and LOGIN
    public static $testing = null;
    //the tokens of this process: key => [ token, expires (unix time) ]
    protected static $tokens = [];

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    public static function graph() {
	return self::$testing ?? self::GRAPH;
    }

    public static function forgetTokens() {
	self::$tokens = [];
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	return [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left(), 'compressed' => true ];
    }

    //the access token: kept, or asked (client credentials)
    protected function token($fresh = false) {
	$tenant = trim((string)$this->cred->tenant);
	$key = sha1((string)$this->cred->_id . "\n{$tenant}\n" . (string)$this->cred->clientId . "\n" . (string)$this->cred->clientSecret);
	if (!$fresh && isset(self::$tokens[$key]) && self::$tokens[$key][1] > time() + 60)
	    return self::$tokens[$key][0];
	$r = Http::json('POST', (self::$testing ?? self::LOGIN) . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [ 'form' => [ 'grant_type' => 'client_credentials',
	    'client_id' => trim((string)$this->cred->clientId), 'client_secret' => (string)$this->cred->clientSecret, 'scope' => self::GRAPH . '/.default' ] ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException("no answer from Microsoft's sign-in: {$r['error']}");
	$token = (string)($r['data']['access_token'] ?? '');
	if ($r['status'] >= 400 || $token === '') {
	    $m = trim(preg_replace('/\s*Trace ID:.*$/s', '', (string)($r['data']['error_description'] ?? $r['data']['error'] ?? '')));
	    throw new \RuntimeException('Microsoft refused the sign-in' . ($m !== '' ? ': ' . mb_substr($m, 0, 300) : " (HTTP status {$r['status']})"));
	}
	self::$tokens[$key] = [ $token, time() + max(60, (int)($r['data']['expires_in'] ?? 3600)) ];
	return $token;
    }

    //Graph's message of an error answer: { error: { code, message } }; Intune's message often JSON with a Message
    public static function message($d) {
	$e = is_array($d) ? ($d['error'] ?? null) : null;
	if (!is_array($e))
	    return '';
	$m = trim((string)($e['message'] ?? ''));
	$inner = $m !== '' && $m[0] === '{' ? json_decode($m, true) : null;
	if (is_array($inner) && isset($inner['Message']))
	    $m = trim(preg_replace('/\s*-\s*Operation ID.*$/is', '', (string)$inner['Message']));
	$code = trim((string)($e['code'] ?? ''));
	return mb_substr($m !== '' ? $m . ($code !== '' ? " ({$code})" : '') : $code, 0, 1000);
    }

    //a path from /v1.0/ or /beta/ (else below /v1.0), or a full address of Graph (an @odata.nextLink)
    public static function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, self::graph() . '/'))
		throw new \RuntimeException('not a Microsoft Graph address: `' . mb_substr($path, 0, 100) . '`');
	    return $path;
	}
	$path = '/' . ltrim($path, '/');
	if (!preg_match('~^/(v1\.0|beta)/~', $path))
	    $path = '/v1.0' . $path;
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	$parts = [];
	foreach($query as $k => $v) {
	    if ($v === null || $v === '')
		continue;
	    $parts[] = str_replace('%24', '$', rawurlencode((string)$k)) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
	}
	return self::graph() . $path . ($parts ? '?' . implode('&', $parts) : '');
    }

    //a request of Graph: the answer decoded ([] when empty: 204)
    public function request($method, $path, array $query = [], $body = null, array $headers = []) {
	$method = strtoupper((string)$method);
	$url = self::url($path, $query);
	$fresh = false;
	while (true) {
	    $o = [ 'headers' => $headers + [ 'Authorization' => 'Bearer ' . $this->token($fresh) ] ] + $this->options();
	    if ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 401 && !$fresh && $r['error'] === null) {
		$fresh = true;
		continue;
	    }
	    if (in_array($r['status'], [ 429, 503 ], true)) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 5)));
		if ($wait >= $this->left())
		    throw new \RuntimeException("Microsoft Graph is busy (HTTP status {$r['status']}), and the step's timeout does not leave time to wait");
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : "no answer from Microsoft Graph: {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    $hint = [ 401 => 'the app\'s token is refused', 403 => 'the app lacks a permission (an administrator\'s consent to it?) or an Intune license', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')) . ($r['status'] === 403 && $m !== '' ? " ({$hint})" : ''));
	}
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a list's items (value) through @odata.nextLink, at most $max: [ items, truncated ]
    public function pages($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$d = $this->request('GET', $path, $query);
	while (true) {
	    foreach((array)($d['value'] ?? []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = $d['@odata.nextLink'] ?? null;
	    if (!is_string($next) || $next === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $d = $this->request('GET', $next);
	}
    }

    //an OData string literal: 'it''s'
    public static function quote($v) {
	return "'" . str_replace("'", "''", (string)$v) . "'";
    }

    //an id: a GUID, or an item ({ id }) of a list, a step or a trigger
    public static function id($value, $label = 'Device') {
	if (is_array($value))
	    $value = $value['id'] ?? '';
	$v = trim(Expression::toText($value));
	if (!preg_match(self::GUID, $v) && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $v))
	    throw new \RuntimeException("{$label}: not an id: `" . mb_substr($v, 0, 60) . '`');
	return $v;
    }
}
