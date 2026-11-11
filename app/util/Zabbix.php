<?php

/*
    the client of the Zabbix API (JSON-RPC 2.0, Zabbix 6.0 LTS and later), for the Zabbix credential, steps and problem trigger.
    POST <server>/api_jsonrpc.php { jsonrpc: "2.0", method, params, id }; an API token (Users > API tokens): `Authorization: Bearer <token>` from Zabbix 6.4, the `auth` member before
    (removed in 7.2): the version is read first (apiinfo.version, without the token) and kept. Errors: { error: { code, message, data } } made a \RuntimeException with the message and
    the data. Public addresses only, unless the account allows private network addresses (a monitoring server is usually on the internal network); the certificate checked unless
    the credential trusts it.

    $z = new Zabbix($cred, $aid, $timeout);
    $z->call($method, $params)       the result
    $z->version()                    e.g. 7.0.5
    $z->atLeast('6.2')               whether the server's version is that or later (e.g. selectHostGroups from 6.2, selectGroups before)
    $z->hostIds($names) / groupIds($names) / templateIds($names)   ids by technical name (host) / name; an unknown one: an error

    Zabbix::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Zabbix {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const SEVERITIES = [ 0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster' ];

    public static $testing = false;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;
    protected $version = null;
    protected $id = 0;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //<server>/api_jsonrpc.php: the server as given (a trailing /, a pasted /api_jsonrpc.php or /zabbix.php... dropped)
    public static function apiUrl($server) {
	$server = preg_replace('~/(api_jsonrpc|zabbix|index)\.php.*$~i', '', rtrim(trim((string)$server), '/'));
	return rtrim($server, '/') . '/api_jsonrpc.php';
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    //the version of the server (apiinfo.version: called without the token, as Zabbix requires)
    public function version() {
	if ($this->version === null)
	    $this->version = (string)$this->send('apiinfo.version', [], false);
	return $this->version;
    }

    public function atLeast($version) {
	return version_compare($this->version(), $version, '>=');
    }

    public function call($method, $params = []) {
	return $this->send($method, $params, true);
    }

    protected function send($method, $params, $auth) {
	if (!preg_match('/^[a-z]+\.[a-zA-Z]+$/', (string)$method))
	    throw new \RuntimeException("not a Zabbix API method: `" . mb_substr((string)$method, 0, 64) . '`');
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$token = trim((string)$this->cred->apiToken);
	$req = [ 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params === [] ? (object)[] : $params, 'id' => ++$this->id ];
	$headers = [];
	if ($auth) {
	    if ($this->atLeast('6.4'))
		$headers['Authorization'] = 'Bearer ' . $token;
	    else
		$req['auth'] = $token;
	}
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	$url = self::apiUrl($this->cred->serverUrl);
	$r = Http::json('POST', $url, [
	    'headers' => $headers,
	    'json' => $req,
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'timeout' => $left,
	    'maxBytes' => self::MAX_ANSWER,
	]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	$d = $r['data'];
	if (!is_array($d) || (!array_key_exists('result', $d) && !isset($d['error'])))
	    throw new \RuntimeException("HTTP status {$r['status']}: the answer is not the Zabbix API's (is the server's address right?)");
	if (isset($d['error']))
	    throw new \RuntimeException(self::errorText((array)$d['error']));
	return $d['result'];
    }

    //Zabbix's error: its message and data; a refused token said so
    public static function errorText(array $e) {
	$message = trim((string)($e['message'] ?? 'error'));
	$data = trim((string)($e['data'] ?? ''));
	if (preg_match('/not authori[sz]ed|session terminated|re-login|api token expired|invalid.*token/i', $data . ' ' . $message))
	    return 'the API token is refused (expired, disabled, or its user may not use the API)' . ($data !== '' ? ": {$data}" : '');
	return mb_substr($message . ($data !== '' && $data !== $message ? ": {$data}" : ''), 0, 600);
    }

    //ids by name: hosts by their technical name (host), groups and templates by name; an unknown name: an error
    public function hostIds(array $names) {
	return $this->idsOf('host.get', 'hostid', 'host', $names, 'Host');
    }

    public function groupIds(array $names) {
	return $this->idsOf('hostgroup.get', 'groupid', 'name', $names, 'Host group');
    }

    public function templateIds(array $names) {
	return $this->idsOf('template.get', 'templateid', 'host', $names, 'Template');
    }

    protected function idsOf($method, $idKey, $nameKey, array $names, $label) {
	$names = array_values(array_unique(array_filter(array_map('strval', $names), fn($n) => $n !== '')));
	if (!$names)
	    return [];
	$found = [];
	foreach((array)$this->call($method, [ 'output' => [ $idKey, $nameKey ], 'filter' => [ $nameKey => $names ] ]) as $row)
	    $found[(string)$row[$nameKey]] = (string)$row[$idKey];
	$missing = array_diff($names, array_keys($found));
	if ($missing)
	    throw new \RuntimeException("{$label}: Zabbix has no " . strtolower($label) . ' `' . implode('`, `', array_slice($missing, 0, 5)) . '`');
	return array_values(array_map(fn($n) => $found[$n], $names));
    }

    //an ISO 8601 date (UTC) of a Unix time; null for 0
    public static function iso($clock) {
	return (int)$clock > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int)$clock) : null;
    }
}
