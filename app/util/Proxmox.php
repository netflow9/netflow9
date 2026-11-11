<?php

/*
    the client of a Proxmox VE cluster or server (the API of PVE 7 and 8: https://<node>:8006/api2/json/...), for the Proxmox credential, steps and task
    trigger. Signed in with an API token (Authorization: PVEAPIToken=<user>@<realm>!<token id>=<secret>; no ticket, no CSRF token). Answers are { data }; an error is the HTTP status
    with Proxmox's message in the reason phrase (e.g. 500 VM 100 not running) and, for parameters, { errors: { param: message } }. Parameters: GET / DELETE in the query, POST / PUT
    as a form (booleans 1 / 0, lists comma separated). An action runs as a task (its UPID: UPID:<node>:...), followed with waitTask() until it stops. Public addresses only, unless
    the account allows private network addresses (Account allow_private_addresses: Proxmox is usually on-premises; never this server itself); the certificate checked unless the
    credential trusts it (Proxmox's own is self-signed).

    $p = new Proxmox($cred, $aid, $timeout);
    $p->request($method, $path, $params)     Proxmox's data (null when none); \RuntimeException with its message on an error
    $p->waitTask($upid, $what)               a task followed until it stops (within the timeout): its status; failed: \RuntimeException with its exit status and last log lines

    Proxmox::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Proxmox {
    const MAX_ANSWER = 20 * 1024 * 1024;
    //seconds between two reads of a task
    const POLL = 2;
    //a task's id: UPID:<node>:<pid>:<pstart>:<start time>:<type>:<id>:<user>: (the id may hold colons, e.g. a volume's)
    const UPID = '/^UPID:([A-Za-z0-9.-]+):[0-9A-Fa-f]{8}:[0-9A-Fa-f]{8}:[0-9A-Fa-f]{8}:\S*:$/';

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

    //the server's address as given, without a trailing / and without an /api2/... or a #v1:... of the web interface pasted after it
    public static function baseUrl($url) {
	$url = preg_replace('~#.*$~', '', trim((string)$url));
	return preg_replace('~/api2(/.*)?$~i', '', rtrim($url, '/'));
    }

    //the seconds left of the step's timeout
    public function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
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

    //Proxmox's message of an error answer: the reason phrase, the parameters' errors
    public static function message($reason, $data) {
	$m = trim((string)$reason);
	if (is_array($data) && is_array($data['errors'] ?? null)) {
	    $fields = [];
	    foreach($data['errors'] as $k => $v)
		$fields[] = "{$k}: " . trim(is_array($v) ? implode('; ', $v) : (string)$v);
	    if ($fields)
		$m .= ($m !== '' ? '; ' : '') . implode('; ', $fields);
	} elseif (is_array($data) && is_string($data['message'] ?? null) && trim($data['message']) !== '')
	    $m .= ($m !== '' ? '; ' : '') . trim($data['message']);
	return mb_substr($m, 0, 500);
    }

    //parameters as Proxmox reads them: booleans 1 / 0, lists comma separated, empty ones left out
    public static function params(array $params) {
	$out = [];
	foreach($params as $k => $v) {
	    if ($v === null || $v === '' || $v === [])
		continue;
	    $out[$k] = is_bool($v) ? ($v ? 1 : 0) : (is_array($v) ? implode(',', array_map('strval', $v)) : $v);
	}
	return $out;
    }

    public function request($method, $path, array $params = []) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim(preg_replace('~^/?api2/json~', '', (string)$path), '/');
	if (!preg_match('~^/[A-Za-z0-9/_.:@!%=-]*$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `{$path}`");
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$params = self::params($params);
	$url = self::baseUrl($this->cred->serverUrl) . '/api2/json' . $path;
	$o = [ 'headers' => [ 'Authorization' => 'PVEAPIToken=' . trim((string)$this->cred->tokenId) . '=' . trim((string)$this->cred->secret) ], 'timeout' => $left,
	    'maxBytes' => self::MAX_ANSWER ] + $this->options();
	if (in_array($method, [ 'GET', 'DELETE' ], true))
	    $url .= $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
	else
	    $o['form'] = $params;
	$r = Http::json($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['reason'] ?? '', $r['data']);
	    $hint = match(true) {
		$r['status'] === 401 => ': check the API token and its secret',
		$r['status'] === 403 => ': the token\'s user (or the token, with privilege separation) may not do this',
		default => '',
	    };
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : '') . $hint);
	}
	return is_array($r['data']) && array_key_exists('data', $r['data']) ? $r['data']['data'] : $r['data'];
    }

    //the node of a task's id
    public static function taskNode($upid) {
	return preg_match(self::UPID, (string)$upid, $m) ? $m[1] : null;
    }

    //a task followed until it stops, within the step's timeout: its status { status, exitstatus, type, id, node, starttime, ... }; failed: \RuntimeException with its exit status
    //and the last lines of its log
    public function waitTask($upid, $what = 'the task') {
	$upid = (string)$upid;
	$node = self::taskNode($upid);
	if ($node === null)
	    throw new \RuntimeException("{$what}: no task in Proxmox's answer");
	$path = "/nodes/{$node}/tasks/" . rawurlencode($upid);
	while(true) {
	    $st = (array)$this->request('GET', "{$path}/status");
	    if (($st['status'] ?? '') === 'stopped') {
		$exit = (string)($st['exitstatus'] ?? '');
		if ($exit === 'OK' || str_starts_with($exit, 'WARNINGS'))
		    return $st;
		$log = [];
		try {
		    foreach((array)$this->request('GET', "{$path}/log", [ 'start' => 0, 'limit' => 500 ]) as $l)
			$log[] = (string)($l['t'] ?? '');
		} catch (\RuntimeException $e) {
		    //no log: the exit status alone
		}
		$tail = implode(' | ', array_slice(array_filter($log, fn($t) => trim($t) !== '' && $t !== 'TASK ERROR: ' . $exit), -3));
		throw new \RuntimeException("{$what} failed: " . ($exit !== '' ? $exit : 'no exit status') . ($tail !== '' ? " ({$tail})" : ''));
	    }
	    if ($this->left() <= self::POLL + 1)
		throw new \RuntimeException("{$what} is still running ({$upid}): raise the step's Timeout");
	    sleep(self::POLL);
	}
    }
}
