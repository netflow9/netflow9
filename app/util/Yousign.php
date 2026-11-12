<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: the client of Yousign's API v3 (Yousign, now also named Youtrust), for the Yousign credential, steps and event trigger:
    https://api.yousign.app/v3 (production) or https://api-sandbox.yousign.app/v3 (the sandbox; its own API key), `Authorization: Bearer <API key>`; JSON in and out, a
    document uploaded as multipart, files (signed documents, audit trails) downloaded as they are. A 429 waits for Retry-After (else 2 s) and is sent again while the step's
    timeout allows. Errors: Yousign's { type, detail, invalid_params: [ { name, reason } ] }, or { message } (405, 429).

    $y = new Yousign($cred, $timeout);
    $y->request($method, $path, $query, $body)   the answer (decoded JSON; [] when empty); $raw: [ body, content type ] sent as they are (multipart)
    $y->all($path, $query, $max)                 the items of a list, page after page (`after` = meta.next_cursor): [ items, more ]
    $y->download($path, $query)                  a file: [ body, content type, filename ]
    Yousign::verify($raw, $header, $secret)      a webhook's X-Yousign-Signature-256 (sha256=<HMAC-SHA256 of the body, hex>) checked
    Yousign::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of Yousign's
*/

namespace util;

class Yousign {
    const PRODUCTION = 'https://api.yousign.app/v3';
    const SANDBOX = 'https://api-sandbox.yousign.app/v3';
    const MAX_ANSWER = 10 * 1024 * 1024;
    const MAX_FILE = 50 * 1024 * 1024;
    const MAX_WAIT = 30;
    const PAGE = 100;
    const PATH = '#^/[A-Za-z0-9/_.\-]*$#';

    public static $testing = null;

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    public function base() {
	return self::$testing ?? (!empty($this->cred->sandbox) ? self::SANDBOX : self::PRODUCTION);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //Yousign's message of an error answer
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$m = trim((string)($d['detail'] ?? $d['message'] ?? $d['title'] ?? ''));
	$v = array_map(fn($p) => trim(($p['name'] ?? '') . ': ' . ($p['reason'] ?? ''), ': '), array_filter((array)($d['invalid_params'] ?? []), 'is_array'));
	return mb_substr($m . ($v ? ' (' . implode('; ', $v) . ')' : ''), 0, 1000);
    }

    //a request's answer as Http gives it (the 429s waited for); $o: Http's options (json, body, sink, maxBytes)
    protected function send($method, $path, array $query, array $o) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	$q = self::query($query);
	$url = $this->base() . $path . ($q !== '' ? "?{$q}" : '');
	while (true) {
	    $r = Http::request($method, $url, $o + [ 'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->apiKey), 'Accept' => 'application/json, application/pdf, application/zip' ],
		'https' => self::$testing === null, 'public' => self::$testing === null, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left() ]);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 2)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Yousign\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . ((int)($o['maxBytes'] ?? self::MAX_ANSWER) >> 20) . ' MB' : 'no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message(json_decode((string)$r['body'], true));
	    $hint = [ 401 => 'the API key is refused (a sandbox key needs Sandbox on, a production key off)', 403 => 'the API key or the plan may not do this', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	return $r;
    }

    //Yousign's filters: [ 'status' => [ 'in' => 'draft,done' ] ] => status[in]=draft,done
    public static function query(array $query) {
	$parts = [];
	foreach($query as $k => $v) {
	    if ($v === null || $v === '' || $v === [])
		continue;
	    if (is_array($v)) {
		foreach($v as $op => $x)
		    if ($x !== null && $x !== '')
			$parts[] = rawurlencode($k) . '[' . rawurlencode((string)$op) . ']=' . rawurlencode(is_array($x) ? implode(',', $x) : (is_bool($x) ? ($x ? 'true' : 'false') : (string)$x));
	    } else
		$parts[] = rawurlencode($k) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
	}
	return implode('&', $parts);
    }

    public function request($method, $path, array $query = [], $body = null, ?array $raw = null) {
	$o = [];
	if ($raw !== null)
	    $o = [ 'body' => $raw[0], 'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->apiKey), 'Accept' => 'application/json', 'Content-Type' => $raw[1] ] ];
	elseif ($body !== null)
	    $o = [ 'json' => $body === [] ? (object)[] : $body ];
	elseif (!in_array(strtoupper($method), [ 'GET', 'DELETE' ], true))
	    $o = [ 'body' => '' ];
	$r = $this->send($method, $path, $query, $o);
	$body = trim((string)$r['body']);
	if ($body === '')
	    return [];
	$d = json_decode($body, true);
	if (!is_array($d))
	    throw new \RuntimeException('the answer is not Yousign\'s JSON');
	return $d;
    }

    public function all($path, array $query = [], $max = 100) {
	$items = [];
	$after = null;
	do {
	    $d = $this->request('GET', $path, $query + [ 'limit' => min(self::PAGE, max(1, $max - count($items))), 'after' => $after ]);
	    foreach((array)($d['data'] ?? (array_is_list($d) ? $d : [])) as $x)
		$items[] = $x;
	    $after = $d['meta']['next_cursor'] ?? null;
	} while ($after && count($items) < $max);
	return [ array_slice($items, 0, $max), $after !== null && $after !== '' || count($items) > $max ];
    }

    public function download($path, array $query = []) {
	$r = $this->send('GET', $path, $query, [ 'maxBytes' => self::MAX_FILE ]);
	$name = '';
	if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', (string)($r['headers']['content-disposition'] ?? ''), $m))
	    $name = basename(rawurldecode(trim($m[1])));
	return [ (string)$r['body'], (string)$r['mime'], $name ];
    }

    public static function verify($raw, $header, $secret) {
	$secret = (string)$secret;
	$header = trim((string)$header);
	if ($secret === '' || !str_starts_with($header, 'sha256='))
	    return false;
	return hash_equals('sha256=' . hash_hmac('sha256', (string)$raw, $secret), strtolower($header));
    }
}
