<?php

//CLD: 2026-10-25T19:30:00+02:00: moved from the Ldap plugin to the core (the LDAP step and credential are core, the plugin is the LDAP sign-in): namespace util (was plugins\Ldap\util)

//CLD: 2026-10-21T10:00:00+03:00: new: a connection to an LDAP server (v3, simple bind), through PHP's ldap extension when it is loaded (LdapExtClient), else the
//plugin's own client (LdapPhpClient: BER over a socket). Both give the same answers and throw LdapException.
//  $client = LdapClient::open([
//	'hosts' => 'dc1.example.com, dc2.example.com:3269',     servers tried in order until one answers (host, host:port, [v6]:port)
//	'encryption' => 'starttls',                              ldaps (TLS from the start, port 636) | starttls (port 389, then TLS) | none (port 389, unencrypted)
//	'port' => null,                                          the hosts' port when they give none (empty: the encryption's)
//	'verifyTls' => true, 'caCert' => '-----BEGIN ...',      check the server's certificate (against the system's CAs + caCert when given)
//	'timeout' => 15,                                         seconds: to connect, and for each operation
//	'driver' => 'auto',                                      auto | ext | php (the check suite runs both)
//  ]);
//  $client->bind($dn, $password);                         \LdapException 49 when refused; an empty password is refused here (RFC 4513: it would be an
//                                                          "unauthenticated" bind that servers accept)
//  $r = $client->search($base, '(uid=a*)', 'sub', [ 'cn', 'mail' ], $sizeLimit, $pageSize);   [ 'entries' => [ [ 'dn' => .., 'attributes' => [ name => [ values ] ] ] ], 'truncated' => bool ]
//  $entry = $client->read($dn, [ 'cn' ]);                  null when there is no such entry
//  $client->add($dn, [ 'objectClass' => [ 'top', 'person' ], 'cn' => [ 'x' ] ]); modify($dn, [ [ 'op' => 'replace'|'add'|'delete', 'attr' => .., 'values' => [ .. ] ] ]);
//  delete($dn); rename($dn, $newRdn, $newParent = null, $deleteOld = true); passwordModify($dn, $old, $new) (RFC 3062: the generated one when $new is null)
//  $client->close();
//  helpers: escapeFilter(), escapeDn(), normalizeDn(), dnEquals(), rdnValue(), values() (an attribute of an entry, its name in any case), plain() (an entry for workflows)

namespace util;

abstract class LdapClient {
    const ENCRYPTION = [ 'starttls' => 'StartTLS (port 389, then encrypted)', 'ldaps' => 'LDAPS (TLS, port 636)', 'none' => 'None (port 389, unencrypted)' ];
    const SCOPES = [ 'sub' => 'Subtree (the base and everything below it)', 'one' => 'One level (the base\'s children)', 'base' => 'Base (the base entry only)' ];
    const DRIVERS = [ 'auto', 'ext', 'php' ];

    //the attributes always given as lists in plain() (many values usual, one possible)
    const LIST_ATTRIBUTES = [ 'memberof', 'member', 'uniquemember', 'memberuid', 'objectclass', 'proxyaddresses', 'othermailbox', 'serviceprincipalname', 'namingcontexts',
	'supportedcontrol', 'supportedextension', 'supportedldapversion', 'supportedsaslmechanisms' ];

    //the options open() was given (normalized); the server connected to
    public $options = [];
    public $host = null;
    public $port = null;
    //the DN of the last successful bind ('' anonymous)
    public $boundAs = '';

    abstract public function driver();
    abstract protected function connect();
    abstract protected function doBind($dn, $password);
    //[ 'entries' => [...], 'truncated' => bool ]
    abstract protected function doSearch($base, $filter, $scope, array $attributes, $sizeLimit, $pageSize);
    abstract public function add($dn, array $attributes);
    abstract public function modify($dn, array $changes);
    abstract public function delete($dn);
    abstract public function rename($dn, $newRdn, $newParent = null, $deleteOld = true);
    abstract public function passwordModify($dn, $old, $new);
    abstract public function close();

    // ------------------------------------------------------------------------------------
    //connecting

    //which driver 'auto' gives here: ext when PHP's ldap extension is loaded
    public static function autoDriver() {
	return function_exists('ldap_connect') ? 'ext' : 'php';
    }

    public static function open(array $options) {
	$options += [ 'hosts' => '', 'encryption' => 'starttls', 'port' => null, 'verifyTls' => true, 'caCert' => '', 'timeout' => 15, 'driver' => 'auto' ];
	if (!isset(self::ENCRYPTION[$options['encryption']]))
	    throw new \InvalidArgumentException('unknown encryption `' . $options['encryption'] . '`');
	$options['timeout'] = max(1, min(300, (int)$options['timeout']));
	$options['hosts'] = self::parseHosts($options['hosts'], (int)$options['port'] ?: ($options['encryption'] === 'ldaps' ? 636 : 389));
	if (!$options['hosts'])
	    throw new \InvalidArgumentException('no LDAP server given');
	$driver = $options['driver'] === 'auto' ? self::autoDriver() : $options['driver'];
	if ($driver === 'ext' && !function_exists('ldap_connect'))
	    throw new \InvalidArgumentException('PHP\'s ldap extension is not loaded');
	$client = $driver === 'ext' ? new LdapExtClient() : new LdapPhpClient();
	$client->options = $options;
	$client->connect();
	return $client;
    }

    //[ [ host, port ], ... ] of 'a.example.com, b.example.com:3269, [2001:db8::1]:636' (separated by commas, semicolons or spaces); an ldap:// / ldaps:// prefix is dropped
    public static function parseHosts($text, $defaultPort) {
	$hosts = [];
	foreach(preg_split('/[\s,;]+/', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY) as $h) {
	    $h = preg_replace('~^ldaps?://~i', '', rtrim($h, '/'));
	    if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/', $h, $m))
		$hosts[] = [ $m[1], (int)($m[2] ?? 0) ?: $defaultPort ];
	    elseif (preg_match('/^([A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', $h, $m))
		$hosts[] = [ $m[1], (int)($m[2] ?? 0) ?: $defaultPort ];
	    else
		throw new \InvalidArgumentException("not a server: `{$h}` (a host name or IP address, optionally :port)");
	}
	return $hosts;
    }

    //the hosts are valid server names (the settings' validation): null, else the error
    public static function hostsError($text) {
	try {
	    return self::parseHosts($text, 389) ? null : 'give at least one server';
	} catch (\InvalidArgumentException $e) {
	    return $e->getMessage();
	}
    }

    //the CA certificate in a private temporary file (removed by the caller: removeFile()); null without one
    protected function caFile() {
	$pem = trim((string)$this->options['caCert']);
	if ($pem === '')
	    return null;
	$file = tempnam(sys_get_temp_dir(), 'ldapca');
	chmod($file, 0600);
	file_put_contents($file, $pem . "\n");
	return $file;
    }

    protected static function removeFile($file) {
	if ($file && is_file($file))
	    @unlink($file);
    }

    // ------------------------------------------------------------------------------------
    //operations

    public function bind($dn, $password) {
	$dn = (string)$dn;
	$password = (string)$password;
	//an unauthenticated bind (a name, no password) "succeeds" on most servers: never a sign-in
	if ($dn !== '' && $password === '')
	    throw new LdapException(LdapException::INVALID_CREDENTIALS, 'invalid credentials (49): an empty password');
	$this->doBind($dn, $password);
	$this->boundAs = $dn;
    }

    //$scope: sub | one | base; $attributes: [] = all the user attributes; $sizeLimit: 0 = the server's; $pageSize: > 0 = the paged results control (RFC 2696:
    //more entries than the server's own limit, e.g. Active Directory's 1000)
    public function search($base, $filter, $scope = 'sub', array $attributes = [], $sizeLimit = 0, $pageSize = 0) {
	if (!isset(self::SCOPES[$scope]))
	    throw new \InvalidArgumentException("unknown scope `{$scope}`");
	//checked here for both drivers: a filter error names its place
	LdapBer::parseFilter($filter);
	return $this->doSearch((string)$base, trim((string)$filter), $scope, array_values(array_filter(array_map('trim', $attributes), fn($a) => $a !== '')), max(0, (int)$sizeLimit), max(0, (int)$pageSize));
    }

    //the entry $dn (its attributes $attributes, [] = all), null when it does not exist
    public function read($dn, array $attributes = []) {
	try {
	    $r = $this->search($dn, '(objectClass=*)', 'base', $attributes, 1);
	} catch (LdapException $e) {
	    if ($e->ldapCode === LdapException::NO_SUCH_OBJECT)
		return null;
	    throw $e;
	}
	return $r['entries'][0] ?? null;
    }

    //the server's root DSE (its naming contexts, versions, extensions), [] when it does not show it
    public function rootDse() {
	try {
	    $entry = $this->read('', [ '*', '+' ]);
	} catch (LdapException $e) {
	    return [];
	}
	return $entry ? $entry['attributes'] : [];
    }

    public function describe() {
	return $this->host . ':' . $this->port . ' (' . $this->options['encryption'] . ', ' . $this->driver() . ' driver)';
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //a value inside a filter (RFC 4515): * ( ) \ NUL escaped
    public static function escapeFilter($value) {
	return preg_replace_callback('/[\\\\*()\x00]/', fn($m) => sprintf('\\%02x', ord($m[0])), (string)$value);
    }

    //a value inside a DN (RFC 4514): , + " \ < > ; = escaped, a leading space or #, a trailing space
    public static function escapeDn($value) {
	$value = preg_replace_callback('/[,+"\\\\<>;=\x00]/', fn($m) => $m[0] === "\x00" ? '\\00' : '\\' . $m[0], (string)$value);
	if ($value !== '' && ($value[0] === ' ' || $value[0] === '#'))
	    $value = '\\' . $value;
	if (strlen($value) > 1 && substr($value, -1) === ' ' && substr($value, -2, 1) !== '\\')
	    $value = substr($value, 0, -1) . '\\ ';
	return $value;
    }

    //the RDNs of a DN: [ [ attr, value ], ... ] (values unescaped; a multi-valued RDN a+b is kept as one, its first part)
    public static function splitDn($dn) {
	$rdns = [];
	$dn = trim((string)$dn);
	if ($dn === '')
	    return [];
	$len = strlen($dn);
	$cur = '';
	$parts = [];
	for ($i = 0; $i < $len; $i++) {
	    $c = $dn[$i];
	    if ($c === '\\' && $i + 1 < $len) {
		$cur .= $c . $dn[++$i];
		continue;
	    }
	    if ($c === ',' || $c === ';') {
		$parts[] = $cur;
		$cur = '';
		continue;
	    }
	    $cur .= $c;
	}
	$parts[] = $cur;
	foreach($parts as $p) {
	    $p = preg_split('/(?<!\\\\)\+/', $p)[0];
	    $eq = strpos($p, '=');
	    if ($eq === false)
		return null;
	    $attr = trim(substr($p, 0, $eq));
	    $value = trim(substr($p, $eq + 1));
	    $value = preg_replace_callback('/\\\\([0-9A-Fa-f]{2}|.)/', fn($m) => strlen($m[1]) === 2 ? chr(hexdec($m[1])) : $m[1], $value);
	    $rdns[] = [ $attr, $value ];
	}
	return $rdns;
    }

    //a DN to compare: attribute names and values lower case, no spaces around , and = (null: not a DN)
    public static function normalizeDn($dn) {
	$rdns = self::splitDn($dn);
	if ($rdns === null)
	    return null;
	return implode(',', array_map(fn($r) => strtolower($r[0]) . '=' . mb_strtolower(self::escapeDn($r[1])), $rdns));
    }

    public static function dnEquals($a, $b) {
	$na = self::normalizeDn($a);
	return $na !== null && $na === self::normalizeDn($b);
    }

    //the value of the first RDN (cn=Sales,ou=Groups,... -> Sales); '' when not a DN
    public static function rdnValue($dn) {
	$rdns = self::splitDn($dn);
	return $rdns ? $rdns[0][1] : '';
    }

    //$dn is under $base (or is $base)
    public static function dnUnder($dn, $base) {
	$d = self::normalizeDn($dn);
	$b = self::normalizeDn($base);
	if ($d === null || $b === null)
	    return false;
	return $b === '' || $d === $b || str_ends_with($d, ',' . $b);
    }

    //the values of attribute $name of $entry (names compared in any case; `name;binary` and other options too): [] when absent
    public static function values($entry, $name) {
	$name = strtolower($name);
	foreach((array)($entry['attributes'] ?? []) as $key => $values) {
	    $k = strtolower((string)$key);
	    if ($k === $name || str_starts_with($k, $name . ';'))
		return array_values((array)$values);
	}
	return [];
    }

    public static function first($entry, $name) {
	$v = self::values($entry, $name);
	return $v ? (string)$v[0] : '';
    }

    //an entry for workflows: { dn, <attribute>: value } - one value as text, several as a list (LIST_ATTRIBUTES always lists); objectGUID / objectSid as their usual
    //text; other binary values as base64 text
    public static function plain($entry) {
	$out = [ 'dn' => (string)$entry['dn'] ];
	foreach((array)$entry['attributes'] as $name => $values) {
	    $lower = strtolower((string)$name);
	    $values = array_map(fn($v) => self::plainValue($lower, (string)$v), array_values((array)$values));
	    $out[(string)$name] = count($values) === 1 && !in_array($lower, self::LIST_ATTRIBUTES, true) ? $values[0] : $values;
	}
	return $out;
    }

    public static function plainValue($lowerName, $value) {
	if ($lowerName === 'objectguid' && strlen($value) === 16) {
	    $h = bin2hex($value);
	    //the first three parts are little-endian
	    $p = fn($s) => implode('', array_reverse(str_split($s, 2)));
	    return $p(substr($h, 0, 8)) . '-' . $p(substr($h, 8, 4)) . '-' . $p(substr($h, 12, 4)) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
	}
	if ($lowerName === 'objectsid' && strlen($value) >= 8)
	    return self::sid($value);
	if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0e-\x1f]/', $value))
	    return base64_encode($value);
	return $value;
    }

    //a binary SID as S-1-5-21-...
    public static function sid($bin) {
	$rev = ord($bin[0]);
	$count = ord($bin[1]);
	$auth = 0;
	for ($i = 2; $i < 8; $i++)
	    $auth = ($auth << 8) | ord($bin[$i]);
	$sid = "S-{$rev}-{$auth}";
	for ($i = 0; $i < $count && 8 + $i * 4 + 4 <= strlen($bin); $i++)
	    $sid .= '-' . unpack('V', substr($bin, 8 + $i * 4, 4))[1];
	return $sid;
    }
}
