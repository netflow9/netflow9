<?php

//a small LDAP server in memory, for the check suite's `ldap` checks (commands/CheckCommand::suiteLdap); not for any other use.
//  php LdapTestServer.php <directory.json> [--cert=<pem> --key=<pem>] [--ldaps] [--long-lengths]
//  prints `PORT <n>` (listening on 127.0.0.1, a free port) and serves until it is stopped (or after 10 minutes). With --cert / --key it offers StartTLS, with --ldaps too:
//  TLS from the start.
//  --long-lengths: every length of the answers in 4 bytes (0x84 00 00 00 nn), nested ones too, as Active Directory sends them
//  directory.json: { "entries": [ { "dn": "...", "attributes": { "objectClass": [ ... ], "userPassword": [ "plain" ], ... } } ], "writers": [ dn, ... ], "ad": true|false }
//    - a bind checks userPassword (plain text); a name with an empty password is accepted (an "unauthenticated" bind, as real servers do: the client must refuse it);
//      "ad": refused binds carry Active Directory's diagnostic (data 52e; an entry's x-bind-data: another code, e.g. 775 = locked out)
//    - memberOf is computed from the groups' member values (when an entry has none of its own)
//    - filters: and / or / not / = / >= / <= / ~= / =* / substrings, extensible matches of rules 1.2.840.113556.1.4.1941 (in chain: nested groups), .803 / .804 (bits)
//    - writes (add, modify, delete, modify DN, password modify) only for the binds of `writers`; the paged results control; the size limit (code 4)

require_once __DIR__ . '/../../util/LdapException.php';
require_once __DIR__ . '/../../util/LdapBer.php';

use util\LdapBer as B;

class LdapTestServer {
    protected $entries = [];      //normalized dn => [ 'dn' => .., 'attributes' => [ name => values ] ]
    protected $writers = [];
    protected $ad = false;
    protected $cert = null;
    protected $key = null;
    protected $ldaps = false;
    protected $pages = [];        //cookie => remaining entries

    public function __construct(array $dir, $cert, $key, $ldaps) {
	foreach((array)($dir['entries'] ?? []) as $e)
	    $this->entries[self::norm($e['dn'])] = [ 'dn' => $e['dn'], 'attributes' => (array)$e['attributes'] ];
	$this->writers = array_map([ self::class, 'norm' ], (array)($dir['writers'] ?? []));
	$this->ad = !empty($dir['ad']);
	$this->cert = $cert;
	$this->key = $key;
	$this->ldaps = $ldaps;
    }

    public static function norm($dn) {
	$parts = preg_split('/(?<!\\\\),/', trim((string)$dn));
	return mb_strtolower(implode(',', array_map(fn($p) => preg_replace('/\s*=\s*/', '=', trim($p), 1), array_filter($parts, fn($p) => trim($p) !== ''))));
    }

    public static function parent($ndn) {
	$parts = preg_split('/(?<!\\\\),/', $ndn, 2);
	return $parts[1] ?? '';
    }

    public function serve() {
	$ctx = stream_context_create([ 'ssl' => $this->cert ? [ 'local_cert' => $this->cert, 'local_pk' => $this->key, 'verify_peer' => false, 'allow_self_signed' => true ] : [] ]);
	$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
	if (!$server) {
	    fwrite(STDERR, "cannot listen: {$errstr}\n");
	    exit(1);
	}
	$name = stream_socket_get_name($server, false);
	echo 'PORT ' . substr($name, strrpos($name, ':') + 1) . "\n";
	flush();
	$clients = [];
	$until = time() + 600;
	while (time() < $until) {
	    $read = array_merge([ $server ], array_column($clients, 'sock'));
	    $w = $x = null;
	    if (@stream_select($read, $w, $x, 1) === false)
		break;
	    foreach($read as $s) {
		if ($s === $server) {
		    $c = @stream_socket_accept($server, 5);
		    if (!$c)
			continue;
		    stream_set_timeout($c, 10);
		    if ($this->ldaps && !@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			@fclose($c);
			continue;
		    }
		    $clients[(int)$c] = [ 'sock' => $c, 'buf' => '', 'bound' => '' ];
		    continue;
		}
		$id = (int)$s;
		$chunk = @fread($s, 65536);
		if ($chunk === false || ($chunk === '' && feof($s))) {
		    @fclose($s);
		    unset($clients[$id]);
		    continue;
		}
		$clients[$id]['buf'] .= $chunk;
		try {
		    while (($len = B::messageLength($clients[$id]['buf'])) !== null) {
			$msg = substr($clients[$id]['buf'], 0, $len);
			$clients[$id]['buf'] = (string)substr($clients[$id]['buf'], $len);
			if (!$this->handle($clients[$id], $msg)) {
			    @fclose($s);
			    unset($clients[$id]);
			    break;
			}
		    }
		} catch (\Throwable $e) {
		    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
		    @fclose($s);
		    unset($clients[$id]);
		}
	    }
	}
    }

    //the answers' lengths in 4 bytes (--long-lengths)
    public static $longLengths = false;

    protected static function out(&$client, $id, $op, $controls = '') {
	$msg = B::seq(B::int($id), $op, $controls !== '' ? B::tlv(0xa0, $controls) : '');
	fwrite($client['sock'], self::$longLengths ? self::longLengths($msg) : $msg);
    }

    //BER $ber with each length as 0x84 + 4 bytes, inside constructed elements too (Active Directory's encoding)
    public static function longLengths($ber) {
	$out = '';
	$o = 0;
	while ($o < strlen($ber)) {
	    [ $tag, $content ] = B::read($ber, $o);
	    if ($tag & 0x20)
		$content = self::longLengths($content);
	    $out .= chr($tag) . "\x84" . pack('N', strlen($content)) . $content;
	}
	return $out;
    }

    protected static function result($tag, $code, $diag = '', $extra = '') {
	return B::tlv($tag, B::enum($code) . B::str('') . B::str($diag) . $extra);
    }

    //one message; false = close the connection
    protected function handle(&$client, $msg) {
	$o = 0;
	[ , $content ] = B::read($msg, $o);
	$parts = B::children($content);
	$id = B::toInt($parts[0][1]);
	[ $tag, $op ] = $parts[1];
	$controls = '';
	foreach(array_slice($parts, 2) as [ $t, $c ])
	    if ($t === 0xa0)
		$controls = $c;
	switch($tag) {
	    case 0x42:
		return false;
	    case 0x60:
		self::out($client, $id, $this->bind($client, $op));
		return true;
	    case 0x63:
		$this->search($client, $id, $op, $controls);
		return true;
	    case 0x66:
		self::out($client, $id, $this->write($client, 0x67, fn() => $this->modify($op)));
		return true;
	    case 0x68:
		self::out($client, $id, $this->write($client, 0x69, fn() => $this->add($op)));
		return true;
	    case 0x4a:
		self::out($client, $id, $this->write($client, 0x6b, fn() => $this->delete($op)));
		return true;
	    case 0x6c:
		self::out($client, $id, $this->write($client, 0x6d, fn() => $this->moddn($op)));
		return true;
	    case 0x77:
		return $this->extended($client, $id, $op);
	}
	self::out($client, $id, self::result(0x78, 2, 'unsupported operation'));
	return true;
    }

    protected function bind(&$client, $op) {
	$p = B::children($op);
	$name = $p[1][1] ?? '';
	if (($p[2][0] ?? 0) !== 0x80)
	    return self::result(0x61, 7, 'only simple binds');
	$password = $p[2][1];
	if ($password === '') {
	    //anonymous, or "unauthenticated" (a name without a password): accepted, as real servers do
	    $client['bound'] = '';
	    return self::result(0x61, 0);
	}
	$e = $this->entries[self::norm($name)] ?? null;
	$ok = $e && in_array($password, (array)($this->attr($e, 'userPassword') ?? []), true);
	$data = $e ? ($this->attr($e, 'x-bind-data')[0] ?? null) : null;
	if ($ok && $data === null) {
	    $client['bound'] = self::norm($name);
	    return self::result(0x61, 0);
	}
	$diag = $this->ad ? '80090308: LdapErr: DSID-0C090447, comment: AcceptSecurityContext error, data ' . ($ok ? $data : ($e ? '52e' : '525')) . ', v4563' : '';
	return self::result(0x61, 49, $diag);
    }

    protected function write(&$client, $tag, \Closure $fn) {
	if (!in_array($client['bound'], $this->writers, true))
	    return self::result($tag, 50, 'no write access');
	[ $code, $diag ] = $fn();
	return self::result($tag, $code, $diag);
    }

    // ------------------------------------------------------------------------------------
    //attributes

    protected function attr($e, $name) {
	foreach($e['attributes'] as $k => $v)
	    if (strcasecmp($k, $name) === 0)
		return array_values((array)$v);
	if (strcasecmp($name, 'memberOf') === 0) {
	    $groups = [];
	    $n = self::norm($e['dn']);
	    foreach($this->entries as $g)
		foreach((array)$this->attrOwn($g, 'member') as $m)
		    if (self::norm($m) === $n)
			$groups[] = $g['dn'];
	    return $groups ?: null;
	}
	return null;
    }

    protected function attrOwn($e, $name) {
	foreach($e['attributes'] as $k => $v)
	    if (strcasecmp($k, $name) === 0)
		return array_values((array)$v);
	return null;
    }

    protected static function isDnAttr($name) {
	return in_array(strtolower($name), [ 'member', 'memberof', 'uniquemember', 'manager', 'distinguishedname' ], true);
    }

    protected static function same($attr, $a, $b) {
	return self::isDnAttr($attr) ? self::norm($a) === self::norm($b) : mb_strtolower((string)$a) === mb_strtolower((string)$b);
    }

    protected function matches($e, array $f) {
	switch($f[0]) {
	    case 'and':
		foreach($f[1] as $c)
		    if (!$this->matches($e, $c))
			return false;
		return true;
	    case 'or':
		foreach($f[1] as $c)
		    if ($this->matches($e, $c))
			return true;
		return false;
	    case 'not':
		return !$this->matches($e, $f[1]);
	    case 'present':
		return strcasecmp($f[1], 'objectClass') === 0 || $this->attr($e, $f[1]) !== null;
	    case 'eq':
	    case 'approx':
		if (strcasecmp($f[1], 'distinguishedName') === 0)
		    return self::norm($e['dn']) === self::norm($f[2]);
		foreach((array)$this->attr($e, $f[1]) as $v)
		    if (self::same($f[1], $v, $f[2]))
			return true;
		return false;
	    case 'ge':
	    case 'le':
		foreach((array)$this->attr($e, $f[1]) as $v) {
		    $c = is_numeric($v) && is_numeric($f[2]) ? ($v <=> $f[2]) : strcmp(mb_strtolower($v), mb_strtolower($f[2]));
		    if ($f[0] === 'ge' ? $c >= 0 : $c <= 0)
			return true;
		}
		return false;
	    case 'sub':
		$re = '/^' . ($f[2] !== null ? preg_quote($f[2], '/') : '') . '.*' . implode('.*', array_map(fn($a) => preg_quote($a, '/'), $f[3])) . ($f[3] ? '.*' : '')
		    . ($f[4] !== null ? preg_quote($f[4], '/') : '') . '$/isu';
		foreach((array)$this->attr($e, $f[1]) as $v)
		    if (preg_match($re, $v))
			return true;
		return false;
	    case 'ext':
		[ , $rule, $attr, $value ] = $f;
		if ($rule === '1.2.840.113556.1.4.1941' && $attr !== null) {
		    //member in chain: $e (a group) has $value as a member, directly or through groups; memberOf in chain: $e is in group $value
		    if (strcasecmp($attr, 'member') === 0)
			return $this->inChain(self::norm($e['dn']), self::norm($value), []);
		    if (strcasecmp($attr, 'memberOf') === 0)
			return $this->inChain(self::norm($value), self::norm($e['dn']), []);
		    return false;
		}
		if (($rule === '1.2.840.113556.1.4.803' || $rule === '1.2.840.113556.1.4.804') && $attr !== null) {
		    foreach((array)$this->attr($e, $attr) as $v) {
			$bits = (int)$v & (int)$value;
			if ($rule === '1.2.840.113556.1.4.803' ? $bits === (int)$value : $bits !== 0)
			    return true;
		    }
		    return false;
		}
		if ($rule === null && $attr !== null)
		    return $this->matches($e, [ 'eq', $attr, $value ]);
		return false;
	}
	return false;
    }

    //group $group has $member, directly or through its member groups
    protected function inChain($group, $member, array $seen) {
	if (isset($seen[$group]) || !isset($this->entries[$group]))
	    return false;
	$seen[$group] = true;
	foreach((array)$this->attrOwn($this->entries[$group], 'member') as $m) {
	    $nm = self::norm($m);
	    if ($nm === $member || $this->inChain($nm, $member, $seen))
		return true;
	}
	return false;
    }

    // ------------------------------------------------------------------------------------
    //search

    protected function entryBer($e, array $attrs) {
	$names = [];
	$all = !$attrs || in_array('*', $attrs, true);
	if ($all)
	    $names = array_keys($e['attributes']);
	foreach($attrs as $a)
	    if ($a !== '*' && $a !== '+' && $a !== '1.1')
		$names[] = $a;
	if (!in_array('memberof', array_map('strtolower', $names), true) && ($all || false))
	    $names[] = 'memberOf';
	$items = '';
	$done = [];
	foreach($names as $n) {
	    if (strcasecmp($n, 'userPassword') === 0 || isset($done[strtolower($n)]))
		continue;
	    $done[strtolower($n)] = true;
	    $values = $this->attr($e, $n);
	    if ($values === null)
		continue;
	    $real = $n;
	    foreach(array_keys($e['attributes']) as $k)
		if (strcasecmp($k, $n) === 0)
		    $real = $k;
	    $items .= B::seq(B::str($real), B::set(...array_map(fn($v) => B::str($v), $values)));
	}
	return B::tlv(0x64, B::str($e['dn']) . B::tlv(0x30, $items));
    }

    protected function search(&$client, $id, $op, $controls) {
	$p = B::children($op);
	$base = self::norm($p[0][1]);
	$scope = B::toInt($p[1][1]);
	$sizeLimit = B::toInt($p[3][1]);
	$filter = B::decodeFilter($p[6][0], $p[6][1]);
	$attrs = array_map(fn($a) => $a[1], B::children($p[7][1] ?? ''));
	$paged = null;
	foreach(B::children($controls) as [ , $c ]) {
	    $cp = B::children($c);
	    if (($cp[0][1] ?? '') === '1.2.840.113556.1.4.319') {
		$o = 0;
		[ , $seq ] = B::read(end($cp)[1], $o);
		$v = B::children($seq);
		$paged = [ 'size' => B::toInt($v[0][1]), 'cookie' => $v[1][1] ?? '' ];
	    }
	}
	if ($base === '' && $scope === 0) {
	    $root = [ 'dn' => '', 'attributes' => [ 'namingContexts' => [ 'dc=example,dc=com' ], 'supportedLDAPVersion' => [ '3' ], 'vendorName' => [ 'QSM test server' ],
		'supportedExtension' => [ '1.3.6.1.4.1.1466.20037', '1.3.6.1.4.1.4203.1.11.1' ] ] ];
	    if ($this->matches($root, $filter))
		self::out($client, $id, $this->entryBer($root, $attrs));
	    self::out($client, $id, self::result(0x65, 0));
	    return;
	}
	if (!isset($this->entries[$base])) {
	    self::out($client, $id, self::result(0x65, 32, 'no such base'));
	    return;
	}
	if ($paged && $paged['cookie'] !== '') {
	    $found = $this->pages[$paged['cookie']] ?? null;
	    unset($this->pages[$paged['cookie']]);
	    if ($found === null) {
		self::out($client, $id, self::result(0x65, 2, 'unknown cookie'));
		return;
	    }
	} else {
	    $found = [];
	    foreach($this->entries as $n => $e) {
		$in = match($scope) { 0 => $n === $base, 1 => self::parent($n) === $base, default => $n === $base || str_ends_with($n, ',' . $base) };
		if ($in && $this->matches($e, $filter))
		    $found[] = $n;
	    }
	}
	if ($paged) {
	    if ($paged['size'] === 0) {
		self::out($client, $id, self::result(0x65, 0), B::seq(B::str('1.2.840.113556.1.4.319'), B::str(B::seq(B::int(0), B::str('')))));
		return;
	    }
	    $page = array_slice($found, 0, $paged['size']);
	    $rest = array_slice($found, $paged['size']);
	    foreach($page as $n)
		self::out($client, $id, $this->entryBer($this->entries[$n], $attrs));
	    $cookie = '';
	    if ($rest) {
		$cookie = bin2hex(random_bytes(8));
		$this->pages[$cookie] = $rest;
	    }
	    self::out($client, $id, self::result(0x65, 0), B::seq(B::str('1.2.840.113556.1.4.319'), B::str(B::seq(B::int(count($found)), B::str($cookie)))));
	    return;
	}
	$sent = 0;
	foreach($found as $n) {
	    if ($sizeLimit > 0 && $sent >= $sizeLimit) {
		self::out($client, $id, self::result(0x65, 4));
		return;
	    }
	    self::out($client, $id, $this->entryBer($this->entries[$n], $attrs));
	    $sent++;
	}
	self::out($client, $id, self::result(0x65, 0));
    }

    // ------------------------------------------------------------------------------------
    //writes: [ code, diagnostic ]

    protected function add($op) {
	$p = B::children($op);
	$n = self::norm($p[0][1]);
	if (isset($this->entries[$n]))
	    return [ 68, 'exists' ];
	if (!isset($this->entries[self::parent($n)]))
	    return [ 32, 'no parent' ];
	$attrs = [];
	foreach(B::children($p[1][1]) as [ , $a ]) {
	    $ap = B::children($a);
	    $attrs[$ap[0][1]] = array_map(fn($v) => $v[1], B::children($ap[1][1]));
	}
	if (!$attrs)
	    return [ 65, 'no attributes' ];
	$this->entries[$n] = [ 'dn' => $p[0][1], 'attributes' => $attrs ];
	return [ 0, '' ];
    }

    protected function modify($op) {
	$p = B::children($op);
	$n = self::norm($p[0][1]);
	if (!isset($this->entries[$n]))
	    return [ 32, 'no such entry' ];
	$attrs = $this->entries[$n]['attributes'];
	foreach(B::children($p[1][1]) as [ , $change ]) {
	    $cp = B::children($change);
	    $kind = B::toInt($cp[0][1]);
	    $mp = B::children($cp[1][1]);
	    $name = $mp[0][1];
	    $values = array_map(fn($v) => $v[1], B::children($mp[1][1]));
	    $key = $name;
	    foreach(array_keys($attrs) as $k)
		if (strcasecmp($k, $name) === 0)
		    $key = $k;
	    $cur = $attrs[$key] ?? [];
	    if ($kind === 0) {
		foreach($values as $v) {
		    foreach($cur as $c)
			if (self::same($name, $c, $v))
			    return [ 20, "{$name}: the value exists" ];
		    $cur[] = $v;
		}
		$attrs[$key] = $cur;
	    } elseif ($kind === 1) {
		if (!isset($attrs[$key]))
		    return [ 16, "no attribute {$name}" ];
		if (!$values) {
		    unset($attrs[$key]);
		    continue;
		}
		foreach($values as $v) {
		    $before = count($cur);
		    $cur = array_values(array_filter($cur, fn($c) => !self::same($name, $c, $v)));
		    if (count($cur) === $before)
			return [ 16, "{$name}: no such value" ];
		}
		if ($cur)
		    $attrs[$key] = $cur;
		else
		    unset($attrs[$key]);
	    } else {
		if ($values)
		    $attrs[$key] = $values;
		else
		    unset($attrs[$key]);
	    }
	}
	$this->entries[$n]['attributes'] = $attrs;
	return [ 0, '' ];
    }

    protected function delete($op) {
	$n = self::norm($op);
	if (!isset($this->entries[$n]))
	    return [ 32, 'no such entry' ];
	foreach(array_keys($this->entries) as $k)
	    if (str_ends_with($k, ',' . $n))
		return [ 66, 'has children' ];
	unset($this->entries[$n]);
	return [ 0, '' ];
    }

    protected function moddn($op) {
	$p = B::children($op);
	$n = self::norm($p[0][1]);
	if (!isset($this->entries[$n]))
	    return [ 32, 'no such entry' ];
	$newRdn = $p[1][1];
	$parentDn = isset($p[3]) ? $p[3][1] : substr($this->entries[$n]['dn'], strpos($this->entries[$n]['dn'], ',') + 1);
	if (!isset($this->entries[self::norm($parentDn)]))
	    return [ 32, 'no new parent' ];
	$newDn = $newRdn . ',' . $parentDn;
	$nn = self::norm($newDn);
	if (isset($this->entries[$nn]))
	    return [ 68, 'exists' ];
	$moved = [];
	foreach($this->entries as $k => $e) {
	    if ($k === $n || str_ends_with($k, ',' . $n)) {
		$e['dn'] = $k === $n ? $newDn : substr($e['dn'], 0, strlen($e['dn']) - strlen($this->entries[$n]['dn'])) . $newDn;
		$moved[self::norm($e['dn'])] = $e;
		unset($this->entries[$k]);
	    }
	}
	[ $ra, $rv ] = explode('=', $newRdn, 2);
	$moved[$nn]['attributes'][$ra] = [ $rv ];
	$this->entries += $moved;
	return [ 0, '' ];
    }

    protected function extended(&$client, $id, $op) {
	$name = $value = '';
	foreach(B::children($op) as [ $t, $c ]) {
	    if ($t === 0x80)
		$name = $c;
	    elseif ($t === 0x81)
		$value = $c;
	}
	if ($name === '1.3.6.1.4.1.1466.20037') {
	    if (!$this->cert) {
		self::out($client, $id, self::result(0x78, 2, 'no TLS here'));
		return true;
	    }
	    self::out($client, $id, self::result(0x78, 0, '', B::str($name, 0x8a)));
	    return @stream_socket_enable_crypto($client['sock'], true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
	}
	if ($name === '1.3.6.1.4.1.4203.1.11.1') {
	    $user = $client['bound'];
	    $old = $new = null;
	    if ($value !== '') {
		$o = 0;
		[ , $seq ] = B::read($value, $o);
		foreach(B::children($seq) as [ $t, $c ]) {
		    if ($t === 0x80)
			$user = self::norm($c);
		    elseif ($t === 0x81)
			$old = $c;
		    elseif ($t === 0x82)
			$new = $c;
		}
	    }
	    if ($client['bound'] === '' || ($user !== $client['bound'] && !in_array($client['bound'], $this->writers, true))) {
		self::out($client, $id, self::result(0x78, 50, 'not allowed'));
		return true;
	    }
	    if (!isset($this->entries[$user])) {
		self::out($client, $id, self::result(0x78, 32, 'no such user'));
		return true;
	    }
	    $extra = '';
	    if ($new === null) {
		$new = 'gen-' . bin2hex(random_bytes(4));
		$extra = B::str(B::seq(B::str($new, 0x80)), 0x8b);
	    }
	    $this->entries[$user]['attributes']['userPassword'] = [ $new ];
	    self::out($client, $id, self::result(0x78, 0, '', $extra));
	    return true;
	}
	self::out($client, $id, self::result(0x78, 2, 'unknown extended operation'));
	return true;
    }
}

$args = array_slice($argv, 1);
$file = null;
$cert = $key = null;
$ldaps = false;
foreach($args as $a) {
    if (str_starts_with($a, '--cert='))
	$cert = substr($a, 7);
    elseif (str_starts_with($a, '--key='))
	$key = substr($a, 6);
    elseif ($a === '--ldaps')
	$ldaps = true;
    elseif ($a === '--long-lengths')
	LdapTestServer::$longLengths = true;
    else
	$file = $a;
}
$dir = $file ? json_decode((string)file_get_contents($file), true) : null;
if (!is_array($dir)) {
    fwrite(STDERR, "usage: php LdapTestServer.php <directory.json> [--cert=<pem> --key=<pem>] [--ldaps] [--long-lengths]\n");
    exit(1);
}
(new LdapTestServer($dir, $cert, $key, $ldaps))->serve();
