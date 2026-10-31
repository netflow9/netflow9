<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new: the client of the FortiOS REST API (FortiGate 7.x), for the FortiGate credential, steps and trigger setup.
    https://<firewall>[:port]/api/v2/{cmdb | monitor | log}/...; the REST API administrator's token as `Authorization: Bearer <token>`; the VDOM as ?vdom= (the credential's,
    when set). Answers: { results, status: success | error, http_status, error (a number), vdom, version, build, serial }: results given back, an error made a
    \RuntimeException (FortiOS's error codes, e.g. -3 the object does not exist, named). Public addresses only, unless the account allows private network addresses (a firewall
    is usually reached on its management address); the certificate checked unless the credential trusts it (FortiGate's default certificate is self-signed).

    $f = new FortiGate($cred, $aid, $timeout);
    $f->request($method, $path, $query, $body)     the answer's `results` (the whole answer: $whole); \RuntimeException on an error
    $f->raw($method, $path, $query, $body, $sink)  a file (the configuration backup) into the stream $sink: util\Http's answer

    FortiGate::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class FortiGate {
    const MAX_ANSWER = 20 * 1024 * 1024;
    //FortiOS's error numbers (the answer's `error`) and what they mean
    const ERRORS = [
	-1 => 'invalid length of value', -2 => 'value out of range', -3 => 'the object does not exist', -4 => 'the maximum number of entries was reached',
	-5 => 'an object of that name already exists', -6 => 'incomplete or wrong syntax', -7 => 'the object is in use', -8 => 'invalid IP address',
	-9 => 'invalid IPv6 address', -14 => 'the object cannot be deleted', -15 => 'duplicate entry', -23 => 'the object is in use by other objects',
	-37 => 'no permission', -651 => 'invalid value',
    ];

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

    //the firewall's address as given, without a trailing / and without an /api/... pasted after it
    public static function baseUrl($url) {
	return preg_replace('~/api(/.*)?$~i', '', rtrim(trim((string)$url), '/'));
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'headers' => [ 'Authorization' => 'Bearer ' . (string)$this->cred->apiToken ],
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    //the address of $path with $query (+ the credential's VDOM)
    protected function url($path, array $query) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('~^/api/v2/(cmdb|monitor|log)/[A-Za-z0-9/_.%-]*$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not a FortiOS API path: `{$path}`");
	$vdom = trim((string)$this->cred->vdom);
	if ($vdom !== '' && !array_key_exists('vdom', $query))
	    $query['vdom'] = $vdom;
	$q = [];
	foreach($query as $k => $v) {
	    foreach(is_array($v) ? $v : [ $v ] as $one) {
		if ($one !== null && $one !== '')
		    $q[] = rawurlencode((string)$k) . '=' . rawurlencode(is_bool($one) ? ($one ? 'true' : 'false') : (string)$one);
	    }
	}
	return self::baseUrl($this->cred->serverUrl) . $path . ($q ? '?' . implode('&', $q) : '');
    }

    //an object's name in a path (cmdb/firewall/address/<name>): encoded, / included
    public static function name($name) {
	return rawurlencode((string)$name);
    }

    public function request($method, $path, array $query = [], $body = null, $whole = false) {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$o = [ 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ] + $this->options();
	if ($body !== null)
	    $o['json'] = $body === [] ? (object)[] : $body;
	elseif ($method !== 'GET')
	    $o['body'] = '';
	$url = $this->url($path, $query);
	$r = Http::json($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	$d = $r['data'];
	if ($r['status'] === 401)
	    throw new \RuntimeException('HTTP status 401: the API token is refused (wrong, expired, or this address is not a trusted host of the REST API administrator)');
	if ($r['status'] === 403)
	    throw new \RuntimeException('HTTP status 403: the REST API administrator\'s profile may not do this');
	if ($r['status'] >= 400 || (is_array($d) && ($d['status'] ?? '') === 'error'))
	    throw new \RuntimeException(self::errorText($d, $r['status']));
	if (!is_array($d))
	    throw new \RuntimeException('the answer is not FortiOS JSON (is the address right?)');
	return $whole ? $d : ($d['results'] ?? $d);
    }

    //FortiOS's error: its number named, the HTTP status
    public static function errorText($d, $status) {
	$code = is_array($d) ? ($d['error'] ?? null) : null;
	$text = 'HTTP status ' . (is_array($d) && isset($d['http_status']) ? $d['http_status'] : $status);
	if (is_numeric($code))
	    $text .= ': ' . (self::ERRORS[(int)$code] ?? "error {$code}") . " ({$code})";
	elseif (is_array($d) && is_string($d['cli_error'] ?? null) && $d['cli_error'] !== '')
	    $text .= ': ' . mb_substr(trim($d['cli_error']), 0, 300);
	elseif ((int)$status === 404)
	    $text .= ': not found (the object, or this FortiOS version has no such request)';
	return $text;
    }

    //a request whose answer is a file (the configuration backup), into $sink
    public function raw($method, $path, array $query, $body, $sink, $maxBytes) {
	$o = [ 'timeout' => max(1, $this->left()), 'maxBytes' => $maxBytes, 'sink' => $sink ] + $this->options();
	if ($body !== null)
	    $o['json'] = $body;
	elseif ($method !== 'GET')
	    $o['body'] = '';
	$r = Http::request($method, $this->url($path, $query), $o);
	if ($r['tooLarge'])
	    throw new \RuntimeException('the file is larger than ' . (int)($maxBytes / 1048576) . ' MB');
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer: ' . $r['error']);
	return $r;
    }
}
