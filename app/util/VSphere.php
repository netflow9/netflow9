<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new: the client of a VMware vCenter Server (vSphere 8; the VMware credential, steps and alarm trigger). Two APIs of the same server:
	- the vSphere Automation REST API (/api/..., vSphere 7.0 U2 and later): VMs, power, guest, inventory. Signed in with POST /api/session (Basic: user and password),
	  the session id (a JSON string) sent as vmware-api-session-id. Errors: { error_type, messages: [ { default_message } ] }
	- the VI/JSON API (/sdk/vim25/8.0.1.0/<type>/<id>/<property or method>, vSphere 8.0 U1 and later): what the REST API lacks (snapshots, triggered alarms, maintenance
	  mode, tasks). Signed in with SessionManager Login ({ userName, password }), the session id from the vmware-api-session-id header. A property is a GET, a method a POST
	  of its parameters; managed objects as { _typeName: ManagedObjectReference, type, value }. Errors: a fault { _typeName, faultMessage: [ { message } ], ... }
    Each session is kept in this process (per credential, user and password), signed in again once when a request is answered 401, and signed out when the process ends
    (vCenter limits its sessions). Public addresses only, unless the account allows private network addresses (Account allow_private_addresses: vCenter is usually
    on-premises; never this server itself); the certificate checked unless the credential trusts it (vCenter's own is self-signed).

    $v = new VSphere($cred, $aid, $timeout);
    $v->request($method, $path, $query, $body)     the REST API's decoded answer (null when empty); \RuntimeException with vCenter's message on an error
    $v->vim($method, $path, $body)                 the VI/JSON answer (GET a property, POST a method)
    $v->waitTask($task, $what)                     a VI/JSON task followed until it ends (within the timeout): its info; an error task: \RuntimeException with its message
    VSphere::moref($id)                            a managed object reference of an id (vm-42 -> VirtualMachine)

    VSphere::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class VSphere {
    //the VI/JSON release in the paths: the first with VI/JSON (newer servers answer it too)
    const RELEASE = '8.0.1.0';
    const MAX_ANSWER = 20 * 1024 * 1024;
    //seconds between two reads of a task
    const POLL = 2;
    //the managed object type of an id's prefix (vCenter's ids: vm-42, host-10, domain-c8, ...)
    const TYPES = [
	'/^vm-\d+$/' => 'VirtualMachine',
	'/^host-\d+$/' => 'HostSystem',
	'/^domain-c\d+$/' => 'ClusterComputeResource',
	'/^domain-s\d+$/' => 'ComputeResource',
	'/^datacenter-\d+$/' => 'Datacenter',
	'/^datastore-\d+$/' => 'Datastore',
	'/^group-p\d+$/' => 'StoragePod',
	'/^group-[a-z]\d+$/' => 'Folder',
	'/^resgroup-v\d+$/' => 'VirtualApp',
	'/^resgroup-\d+$/' => 'ResourcePool',
	'/^network-\d+$/' => 'Network',
	'/^dvportgroup-\d+$/' => 'DistributedVirtualPortgroup',
	'/^dvs-\d+$/' => 'VmwareDistributedVirtualSwitch',
	'/^snapshot-\d+$/' => 'VirtualMachineSnapshot',
	'/^task-\d+$/' => 'Task',
	'/^alarm-\d+$/' => 'Alarm',
    ];
    //an id of vCenter's inventory
    const ID = '/^[a-z]+-[a-z]?\d+$/';

    public static $testing = false;
    //the sessions of this process: key => [ api => token, url, options ]
    protected static $sessions = [];
    protected static $signOut = false;

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

    //the server's address as given, without a trailing / and without an /api, /ui or /sdk pasted after it
    public static function baseUrl($url) {
	return preg_replace('~/(api|ui|sdk|rest)(/.*)?$~i', '', rtrim(trim((string)$url), '/'));
    }

    public function timeout() {
	return $this->timeout;
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

    protected function key($api) {
	return sha1($api . "\n" . (string)$this->cred->_id . "\n" . self::baseUrl($this->cred->serverUrl) . "\n" . (string)$this->cred->username . "\n" . (string)$this->cred->password);
    }

    protected function base() {
	return self::baseUrl($this->cred->serverUrl);
    }

    protected function leftOrFail() {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //forget this process's sessions without signing out (the checks)
    public static function forgetSessions() {
	self::$sessions = [];
    }

    //when the process ends: its sessions signed out (a short request each)
    public static function signOutAll() {
	foreach(self::$sessions as $s) {
	    $url = $s['api'] === 'rest' ? $s['base'] . '/api/session' : $s['base'] . '/sdk/vim25/' . self::RELEASE . '/SessionManager/SessionManager/Logout';
	    Http::request($s['api'] === 'rest' ? 'DELETE' : 'POST', $url, [ 'headers' => [ 'vmware-api-session-id' => $s['token'] ], 'timeout' => 5 ] + $s['options']);
	}
	self::$sessions = [];
    }

    protected function keep($api, $token) {
	self::$sessions[$this->key($api)] = [ 'api' => $api, 'token' => $token, 'base' => $this->base(), 'options' => $this->options() ];
	if (!self::$signOut) {
	    self::$signOut = true;
	    register_shutdown_function([ self::class, 'signOutAll' ]);
	}
	return $token;
    }

    //a session of the REST API ($fresh: always a new sign-in)
    protected function restSession($fresh = false) {
	$s = self::$sessions[$this->key('rest')] ?? null;
	if ($s && !$fresh)
	    return $s['token'];
	$r = Http::json('POST', $this->base() . '/api/session', [ 'headers' => [ 'Authorization' => 'Basic ' . base64_encode((string)$this->cred->username . ':' . (string)$this->cred->password) ],
	    'body' => '', 'timeout' => $this->leftOrFail() ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	if ($r['status'] === 404)
	    throw new \RuntimeException('no vSphere REST API at ' . Http::shown($this->base()) . ' (a vCenter Server 7.0 U2 or later is needed; a host on its own has none)');
	if ($r['status'] >= 400 || !is_string($r['data']) || $r['data'] === '')
	    throw new \RuntimeException('sign-in refused' . (($m = self::message($r['data'])) !== '' ? ": {$m}" : " (HTTP status {$r['status']})") . ($r['status'] === 401 ? ': check the user and password' : ''));
	return $this->keep('rest', $r['data']);
    }

    //a session of the VI/JSON API ($fresh: always a new sign-in)
    protected function vimSession($fresh = false) {
	$s = self::$sessions[$this->key('vim')] ?? null;
	if ($s && !$fresh)
	    return $s['token'];
	$r = Http::json('POST', $this->base() . '/sdk/vim25/' . self::RELEASE . '/SessionManager/SessionManager/Login', [
	    'json' => [ 'userName' => (string)$this->cred->username, 'password' => (string)$this->cred->password ], 'timeout' => $this->leftOrFail() ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	if ($r['status'] === 404)
	    throw new \RuntimeException('no VI/JSON API at ' . Http::shown($this->base()) . ' (snapshots, alarms and maintenance mode need vCenter Server 8.0 U1 or later)');
	$token = (string)($r['headers']['vmware-api-session-id'] ?? '');
	if ($r['status'] >= 400 || $token === '')
	    throw new \RuntimeException('sign-in refused' . (($m = self::message($r['data'])) !== '' ? ": {$m}" : " (HTTP status {$r['status']})"));
	return $this->keep('vim', $token);
    }

    //vCenter's message in an error answer (REST: error_type and messages; VI/JSON: the fault's type and message)
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$texts = [];
	foreach((array)($d['messages'] ?? $d['faultMessage'] ?? []) as $m) {
	    $t = is_array($m) ? (string)($m['default_message'] ?? $m['message'] ?? '') : (string)$m;
	    if (trim($t) !== '')
		$texts[] = trim($t);
	}
	if (!$texts && !empty($d['localizedMessage']))
	    $texts[] = (string)$d['localizedMessage'];
	if (!$texts && !empty($d['msg']))
	    $texts[] = (string)$d['msg'];
	$type = (string)($d['error_type'] ?? $d['_typeName'] ?? '');
	$text = implode('; ', array_unique($texts));
	return mb_substr(trim($text . ($type !== '' ? ($text !== '' ? " ({$type})" : $type) : '')), 0, 500);
    }

    //the type of an error answer (REST error_type, VI/JSON fault)
    public static function errorType($d) {
	return is_array($d) ? (string)($d['error_type'] ?? $d['_typeName'] ?? '') : '';
    }

    //one request with a session; signed in again once after a 401: the Http answer
    protected function send($api, $method, $url, $body) {
	for ($try = 0; ; $try++) {
	    $token = $api === 'rest' ? $this->restSession($try > 0) : $this->vimSession($try > 0);
	    $o = [ 'headers' => [ 'vmware-api-session-id' => $token ], 'timeout' => $this->leftOrFail(), 'maxBytes' => self::MAX_ANSWER ] + $this->options();
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
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	return $r;
    }

    protected function failed(array $r) {
	$m = self::message($r['data']);
	return new VSphereError("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($r['status'] === 403 ? ': the user\'s role may not do this' : '')), self::errorType($r['data']), $r['status']);
    }

    //a request of the REST API (/api/...)
    public function request($method, $path, array $query = [], $body = null) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('~^/api/[A-Za-z0-9/_.:{}-]*$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `{$path}`");
	$r = $this->send('rest', strtoupper($method), $this->base() . $path . ($query ? '?' . self::query($query) : ''), $body);
	if ($r['status'] >= 400)
	    throw $this->failed($r);
	return $r['body'] === '' ? null : $r['data'];
    }

    //a request of the VI/JSON API: $path <type>/<id>/<property or method> (or a full /sdk/vim25/<release>/... path)
    public function vim($method, $path, $body = null) {
	$path = ltrim((string)$path, '/');
	if (preg_match('~^sdk/vim25/[0-9.]+/(.*)$~', $path, $m))
	    $path = $m[1];
	if (!preg_match('~^[A-Za-z]+/[A-Za-z0-9_.:-]+(/[A-Za-z0-9_]+)?$~', $path))
	    throw new \RuntimeException("not a VI/JSON path (<type>/<id>/<property or method>): `{$path}`");
	$r = $this->send('vim', strtoupper($method), $this->base() . '/sdk/vim25/' . self::RELEASE . '/' . $path, $body);
	if ($r['status'] === 404 && self::errorType($r['data']) === '')
	    throw new \RuntimeException('HTTP status 404: no such object or request (snapshots, alarms and maintenance mode need vCenter Server 8.0 U1 or later)');
	if ($r['status'] >= 400)
	    throw $this->failed($r);
	return $r['body'] === '' ? null : $r['data'];
    }

    //a VI/JSON task (a reference, or its id) followed until it ends, within the step's timeout: its info; failed: \RuntimeException with its message
    public function waitTask($task, $what = 'the task') {
	$id = is_array($task) ? (string)($task['value'] ?? '') : (string)$task;
	if (!preg_match('/^task-\d+$/', $id))
	    throw new \RuntimeException("{$what}: no task in vCenter's answer");
	while(true) {
	    $info = $this->vim('GET', "Task/{$id}/info");
	    $state = (string)($info['state'] ?? '');
	    if ($state === 'success')
		return $info;
	    if ($state === 'error')
		throw new \RuntimeException("{$what} failed: " . (self::message($info['error']['fault'] ?? null) ?: (string)($info['error']['localizedMessage'] ?? 'no reason given')));
	    if ($this->left() <= self::POLL + 1)
		throw new \RuntimeException("{$what} is still running ({$id}" . (isset($info['progress']) ? ", {$info['progress']}%" : '') . '): raise the step\'s Timeout');
	    sleep(self::POLL);
	}
    }

    //the query string: lists repeated (names=a&names=b), booleans as true / false
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

    //the managed object type of an id (vm-42: VirtualMachine); null when unknown
    public static function typeOf($id) {
	foreach(self::TYPES as $pattern => $type) {
	    if (preg_match($pattern, (string)$id))
		return $type;
	}
	return null;
    }

    //a managed object reference (VI/JSON) of an id, or of a reference given as { type, value }
    public static function moref($id, $type = null) {
	if (is_array($id))
	    return [ '_typeName' => 'ManagedObjectReference', 'type' => (string)($id['type'] ?? $type ?? ''), 'value' => (string)($id['value'] ?? $id['id'] ?? '') ];
	$type = $type ?? self::typeOf($id);
	if ($type === null)
	    throw new \RuntimeException("not a vCenter id: `" . mb_substr((string)$id, 0, 64) . '`');
	return [ '_typeName' => 'ManagedObjectReference', 'type' => $type, 'value' => (string)$id ];
    }
}

