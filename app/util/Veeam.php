<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new: the client of the Veeam Backup & Replication REST API (v13: x-api-version 1.3-rev1), for the Veeam credential, steps and event trigger.
    https://<server>:9419/api/v1/...; signed in with OAuth2 (POST /api/oauth2/token, form: grant_type=password, username, password; then refresh_token), the access token as
    Bearer. Every request carries x-api-version (the credential's). The token is kept in this process (per credential, user and password) until shortly before it expires,
    renewed with the refresh token, and the user signs in again once when a request is answered 401. Public addresses only, unless the account allows private network addresses
    (Account allow_private_addresses: VBR is usually on-premises; never this server itself); the certificate checked unless the credential trusts it (VBR's is self-signed).

    $v = new Veeam($cred, $aid, $timeout);
    $v->request($method, $path, $query, $body)    the decoded answer (null when empty); \RuntimeException with Veeam's message on an error
    $v->all($path, $query, $max)                  a paged list (skip / limit; { data, pagination: { total } }): [ items, total, truncated ]

    Veeam::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Veeam {
    const DEFAULT_VERSION = '1.3-rev1';
    //CLD: 2026-11-01T05:00:00+02:00: + VBR 12: the REST API version of each release (x-api-version; Veeam's versioning page), newest first; the paths are the same (/api/v1)
    const VERSIONS = [
	'1.3-rev1' => [ 'label' => 'Veeam Backup & Replication 13', 'build' => '13.0' ],
	'1.2-rev1' => [ 'label' => 'Veeam Backup & Replication 12.3.1 or later 12.3', 'build' => '12.3.1' ],
	'1.2-rev0' => [ 'label' => 'Veeam Backup & Replication 12.3', 'build' => '12.3.0' ],
	'1.1-rev2' => [ 'label' => 'Veeam Backup & Replication 12.2', 'build' => '12.2' ],
	'1.1-rev1' => [ 'label' => 'Veeam Backup & Replication 12.1', 'build' => '12.1' ],
	'1.1-rev0' => [ 'label' => 'Veeam Backup & Replication 12.0', 'build' => '12.0' ],
    ];
    //the most items asked for in one page (Veeam's default limit)
    const PAGE = 200;
    const MAX_ANSWER = 20 * 1024 * 1024;
    //a token is renewed this many seconds before it expires
    const MARGIN = 30;

    public static $testing = false;
    //the tokens of this process: key => [ access, refresh, expires (unix) ]
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

    //the server's address as given, without a trailing / and without an /api/... pasted after it
    public static function baseUrl($url) {
	return preg_replace('~/api(/.*)?$~i', '', rtrim(trim((string)$url), '/'));
    }

    public function version() {
	$v = trim((string)$this->cred->apiVersion);
	return preg_match('/^\d+\.\d+-rev\d+$/', $v) ? $v : self::DEFAULT_VERSION;
    }

    //CLD: 2026-11-01T05:00:00+02:00: the API version of a server build (e.g. 12.2.0.334 -> 1.1-rev2): the newest release the build is at least; null for an older build
    public static function versionForBuild($build) {
	$build = trim((string)$build);
	if ($build === '')
	    return null;
	foreach(self::VERSIONS as $version => $v) {
	    if (version_compare($build, $v['build'], '>='))
		return $version;
	}
	return null;
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    protected function key() {
	return sha1((string)$this->cred->_id . "\n" . self::baseUrl($this->cred->serverUrl) . "\n" . (string)$this->cred->username . "\n" . (string)$this->cred->password);
    }

    //forget this process's tokens (the checks)
    public static function forgetTokens() {
	self::$tokens = [];
    }

    //a valid access token: kept, renewed with the refresh token, or a new sign-in ($fresh: always a new sign-in)
    protected function token($fresh = false) {
	$key = $this->key();
	$t = self::$tokens[$key] ?? null;
	if (!$fresh && $t && $t[2] - self::MARGIN > time())
	    return $t[0];
	if (!$fresh && $t && $t[1] !== '') {
	    try {
		return $this->signIn([ 'grant_type' => 'refresh_token', 'refresh_token' => $t[1] ]);
	    } catch (\RuntimeException $e) {
		//an expired refresh token: sign in again
	    }
	}
	return $this->signIn([ 'grant_type' => 'password', 'username' => (string)$this->cred->username, 'password' => (string)$this->cred->password ]);
    }

    protected function signIn(array $form) {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$r = Http::json('POST', self::baseUrl($this->cred->serverUrl) . '/api/oauth2/token', [ 'form' => $form, 'headers' => [ 'x-api-version' => $this->version() ],
	    'timeout' => $left ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	$d = $r['data'];
	if ($r['status'] >= 400 || !is_array($d) || empty($d['access_token']))
	    throw new \RuntimeException('sign-in refused' . (($m = self::message($d)) !== '' ? ": {$m}" : " (HTTP status {$r['status']})")
		. ($form['grant_type'] === 'password' ? ' (only Veeam\'s built-in roles can use the REST API)' : ''));
	self::$tokens[$this->key()] = [ (string)$d['access_token'], (string)($d['refresh_token'] ?? ''), time() + max(60, (int)($d['expires_in'] ?? 900)) ];
	return (string)$d['access_token'];
    }

    //Veeam's message in an error answer
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$m = (string)($d['message'] ?? $d['error_description'] ?? $d['error'] ?? '');
	if (isset($d['errorCode']) && $d['errorCode'] !== '')
	    $m .= " ({$d['errorCode']})";
	return mb_substr(trim($m), 0, 500);
    }

    public function request($method, $path, array $query = [], $body = null) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('~^/api/[A-Za-z0-9/_.{}-]*$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `{$path}`");
	$url = self::baseUrl($this->cred->serverUrl) . $path . ($query ? '?' . self::query($query) : '');
	for ($try = 0; ; $try++) {
	    $left = $this->left();
	    if ($left < 1)
		throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	    $o = [ 'headers' => [ 'x-api-version' => $this->version(), 'Authorization' => 'Bearer ' . $this->token($try > 0) ], 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ] + $this->options();
	    if ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif ($method !== 'GET')
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 401 && $try === 0)
		continue;
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    //CLD: 2026-11-01T05:00:00+02:00: + a 404 / 400 that may come from an older release (VBR 12) without this request
	    $hint = in_array($r['status'], [ 400, 404 ], true) && version_compare(explode('-', $this->version())[0], explode('-', self::DEFAULT_VERSION)[0], '<')
		? ' (with API version ' . $this->version() . ': the server\'s release may not have this request)' : '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($r['status'] === 403 ? ': the user\'s role may not do this' : '')) . $hint);
	}
	return $r['body'] === '' ? null : $r['data'];
    }

    //the query string: lists repeated (resultFilter=Failed&resultFilter=Warning), booleans as true / false
    public static function query(array $query) {
	$parts = [];
	foreach($query as $k => $v) {
	    foreach(is_array($v) ? $v : [ $v ] as $one) {
		if ($one === null || $one === '')
		    continue;
		$parts[] = rawurlencode((string)$k) . '=' . rawurlencode(is_bool($one) ? ($one ? 'true' : 'false') : (string)$one);
	    }
	}
	return implode('&', $parts);
    }

    //a paged list: pages of PAGE (or fewer for the last) until $max items or the last page
    public function all($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$total = 0;
	for ($skip = 0; ; ) {
	    $r = $this->request('GET', $path, $query + [ 'skip' => $skip, 'limit' => min(self::PAGE, $max - count($items)) ]);
	    $list = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) && array_is_list($r) ? $r : []);
	    $total = (int)($r['pagination']['total'] ?? count($list));
	    foreach($list as $item)
		$items[] = $item;
	    $skip += count($list);
	    if (!$list || $skip >= $total || count($items) >= $max)
		break;
	}
	return [ $items, max($total, count($items)), $total > count($items) ];
    }
}
