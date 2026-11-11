<?php

/*
    the client of the Checkmk REST API (2.2 and later), for the Checkmk credential, steps and problem trigger.
    https://<server>/<site>/check_mk/api/1.0/...; `Authorization: Bearer <automation user> <its secret>`. Answers: JSON (collections: { value: [ { id, title, extensions } ] });
    errors: application/problem+json { title, status, detail, fields } made a \RuntimeException with the title, the detail and the fields' messages. Monitoring status is read
    with Livestatus queries (query: a JSON filter, columns repeated). Public addresses only, unless the account allows private network addresses (a monitoring server is usually
    on the internal network); the certificate checked unless the credential trusts it.

    $c = new Checkmk($cred, $aid, $timeout);
    $c->request($method, $path, $query, $body, $headers)    the decoded answer (null when empty)
    $c->rows($table, $filter, $columns, $max)              the rows of a Livestatus table (host / service) matching $filter: [ rows (their columns), truncated ]
    Checkmk::filter(...)                                    a Livestatus filter: and(), eq(), gt() ...

    Checkmk::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Checkmk {
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

    //https://<server>/<site>/check_mk/api/1.0: the server as given (a trailing /, a pasted /<site>/check_mk/... dropped) and the site
    public static function apiUrl($server, $site) {
	$server = preg_replace('~/[^/]+/check_mk(/.*)?$~i', '', rtrim(trim((string)$server), '/'));
	return $server . '/' . rawurlencode(trim((string)$site)) . '/check_mk/api/1.0';
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->username) . ' ' . trim((string)$this->cred->secret) ],
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    //the query string: lists repeated (columns=a&columns=b), booleans as true / false, an array value of `query` as JSON
    public static function query(array $query) {
	$parts = [];
	foreach($query as $k => $v) {
	    if ($k === 'query' && is_array($v))
		$v = JSON::encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	    foreach(is_array($v) ? $v : [ $v ] as $one) {
		if ($one === null || $one === '')
		    continue;
		$parts[] = rawurlencode((string)$k) . '=' . rawurlencode(is_bool($one) ? ($one ? 'true' : 'false') : (string)$one);
	    }
	}
	return implode('&', $parts);
    }

    public function request($method, $path, array $query = [], $body = null, array $headers = []) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('#^/[A-Za-z0-9/_.%~-]*$#', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not a Checkmk API path: `{$path}`");
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$o = $this->options();
	$o['headers'] = $headers + $o['headers'];
	$o += [ 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ];
	if ($body !== null)
	    $o['json'] = $body === [] ? (object)[] : $body;
	elseif ($method !== 'GET')
	    $o['body'] = '';
	$url = self::apiUrl($this->cred->serverUrl, $this->cred->site) . $path . ($query ? '?' . self::query($query) : '');
	$r = Http::json($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	if ($r['status'] >= 400)
	    throw new \RuntimeException(self::errorText($r['data'], $r['status']));
	if ($r['body'] !== '' && $r['data'] === null && $r['mime'] !== 'application/json' && !str_ends_with($r['mime'], '+json'))
	    throw new \RuntimeException('the answer is not Checkmk JSON (are the server and the site right?)');
	return $r['body'] === '' ? null : $r['data'];
    }

    //Checkmk's problem: title, detail, the fields' messages
    public static function errorText($d, $status) {
	$text = "HTTP status {$status}";
	if ($status === 401)
	    return $text . ': the automation user or its secret is refused';
	if (!is_array($d))
	    return $text . ($status === 404 ? ': not found (is the site right?)' : '');
	$parts = array_filter([ (string)($d['title'] ?? ''), (string)($d['detail'] ?? '') ]);
	foreach((array)($d['fields'] ?? []) as $field => $msgs)
	    $parts[] = $field . ': ' . implode(' ', array_map('strval', (array)$msgs));
	return mb_substr($text . ($parts ? ': ' . implode('; ', array_unique($parts)) : ''), 0, 600);
    }

    //the rows of a collection's answer: each item's extensions (+ id, title)
    public static function items($answer) {
	$out = [];
	foreach((array)($answer['value'] ?? []) as $v) {
	    if (!is_array($v))
		continue;
	    $out[] = (array)($v['extensions'] ?? []) + array_filter([ 'id' => $v['id'] ?? null, 'title' => $v['title'] ?? null ], fn($x) => $x !== null);
	}
	return $out;
    }

    //the rows of Livestatus table $table (host, service) matching $filter (null: all), with $columns: [ rows, truncated ]
    public function rows($table, $filter, array $columns, $max) {
	$q = [ 'columns' => $columns ];
	if ($filter)
	    $q['query'] = $filter;
	$rows = self::items($this->request('GET', "/domain-types/{$table}/collections/all", $q));
	return [ array_slice($rows, 0, max(1, (int)$max)), count($rows) > $max ];
    }

    //Livestatus filters
    public static function eq($column, $value) {
	return [ 'op' => '=', 'left' => $column, 'right' => $value ];
    }

    public static function cmp($op, $column, $value) {
	return [ 'op' => $op, 'left' => $column, 'right' => $value ];
    }

    public static function all(array $filters) {
	$filters = array_values(array_filter($filters));
	return count($filters) > 1 ? [ 'op' => 'and', 'expr' => $filters ] : ($filters[0] ?? null);
    }
}
