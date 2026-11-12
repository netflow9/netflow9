<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: the client of Infobip's API, for the Infobip credential, steps and message trigger: the account's own base URL (https://<xxxxx>.api.infobip.com,
    Infobip's portal: the home page's API panel), `Authorization: App <API key>`; JSON in and out (Email: multipart). A 429 waits for Retry-After (else 2 s) and is sent again while
    the step's timeout allows. Errors: Infobip's { requestError: { serviceException: { messageId, text, validationErrors } } }, or the newer { errorCode, description, violations }.

    $i = new Infobip($cred, $timeout);
    $i->request($method, $path, $query, $body)   the answer (decoded JSON; [] when empty); $raw: [ body, content type ] sent as they are (multipart)
    Infobip::number($v, $label)                  a phone number as Infobip takes it: international, digits only (+40 722-123-456, 0040… => 40722123456)
    Infobip::numbers($v, $label)                 several (a list, or separated by commas / semicolons / new lines)

    Infobip::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of the credential's base URL
*/

namespace util;

class Infobip {
    const MAX_ANSWER = 10 * 1024 * 1024;
    const MAX_WAIT = 30;
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

    //the base URL of a setting: xxxxx.api.infobip.com or https://xxxxx.api.infobip.com[/]
    public static function baseUrl($v) {
	$v = rtrim(trim((string)$v), '/');
	return $v !== '' && !preg_match('~^https?://~i', $v) ? "https://{$v}" : $v;
    }

    public function base() {
	return self::$testing ?? self::baseUrl($this->cred->baseUrl);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //Infobip's message of an error answer
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$e = $d['requestError']['serviceException'] ?? $d['requestError']['policyException'] ?? null;
	if (is_array($e)) {
	    $m = trim((string)($e['text'] ?? ''));
	    $v = [];
	    foreach((array)($e['validationErrors'] ?? []) as $field => $msgs)
		$v[] = "{$field}: " . implode(', ', array_map('strval', (array)$msgs));
	    $code = trim((string)($e['messageId'] ?? ''));
	    return mb_substr(trim($m . ($v ? ' (' . implode('; ', $v) . ')' : '')) . ($code !== '' ? " [{$code}]" : ''), 0, 1000);
	}
	if (isset($d['errorCode']) || isset($d['description'])) {
	    $v = array_map(fn($x) => trim(($x['property'] ?? '') . ': ' . ($x['violation'] ?? ''), ': '), (array)($d['violations'] ?? []));
	    return mb_substr(trim((string)($d['description'] ?? '')) . ($v ? ' (' . implode('; ', $v) . ')' : '') . (isset($d['errorCode']) ? " [{$d['errorCode']}]" : ''), 0, 1000);
	}
	return '';
    }

    //a request: the answer decoded ([] when empty)
    public function request($method, $path, array $query = [], $body = null, ?array $raw = null) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$url = $this->base() . $path . ($q !== '' ? "?{$q}" : '');
	while (true) {
	    $o = [ 'headers' => [ 'Authorization' => 'App ' . trim((string)$this->cred->apiKey) ], 'https' => self::$testing === null, 'public' => self::$testing === null,
		'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left() ];
	    if ($raw !== null) {
		$o['body'] = $raw[0];
		$o['headers']['Content-Type'] = $raw[1];
	    } elseif ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 2)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Infobip\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    $hint = [ 401 => 'the API key is refused', 403 => 'the API key may not do this (its scopes)', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	if (trim((string)$r['body']) !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not Infobip\'s JSON (is the base URL your account\'s?)');
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a phone number, international, digits only
    public static function number($v, $label = 'To') {
	$t = preg_replace('/[\s().\-\/]/', '', trim(Expression::toText($v)));
	$t = preg_replace('/^(\+|00)/', '', $t);
	if (!preg_match('/^\d{6,15}$/', $t))
	    throw new \RuntimeException("{$label}: not a phone number in international format (e.g. 40722123456 or +40 722 123 456): `" . mb_substr(Expression::toText($v), 0, 40) . '`');
	return $t;
    }

    //several numbers: a list (of numbers, or of objects with a phone / to / number), or separated by commas, semicolons or new lines
    public static function numbers($value, $label = 'To') {
	$parts = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : preg_split('/[,;\r\n]+/', Expression::toText($value));
	$out = [];
	foreach($parts as $p) {
	    if (is_array($p))
		$p = $p['phone'] ?? $p['to'] ?? $p['number'] ?? '';
	    if (trim(Expression::toText($p)) !== '')
		$out[] = self::number($p, $label);
	}
	return array_values(array_unique($out));
    }
}
