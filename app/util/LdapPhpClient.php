<?php

//CLD: 2026-10-25T19:30:00+02:00: moved from the Ldap plugin to the core (the LDAP step and credential are core, the plugin is the LDAP sign-in): namespace util (was plugins\Ldap\util)

//CLD: 2026-10-21T10:00:00+03:00: new: the LDAP client without PHP's ldap extension (LdapClient::open picks it when the extension is not loaded): LDAPv3 messages (RFC 4511) in
//BER (LdapBer) over a TCP socket; LDAPS (ssl://) or StartTLS (the extended operation 1.3.6.1.4.1.1466.20037, then TLS on the same socket), certificates checked by
//PHP's OpenSSL streams. Operations: simple bind, search (+ the paged results control), add, modify, delete, modify DN, password modify (RFC 3062), unbind.
//Referrals and search references are not followed (left out: as the ext driver with LDAP_OPT_REFERRALS off).

namespace util;

class LdapPhpClient extends LdapClient {
    //protocol ops (RFC 4511 4.2 ...: [APPLICATION n], constructed unless noted)
    const BIND_REQUEST = 0x60;
    const BIND_RESPONSE = 0x61;
    const UNBIND_REQUEST = 0x42;      //primitive
    const SEARCH_REQUEST = 0x63;
    const SEARCH_ENTRY = 0x64;
    const SEARCH_DONE = 0x65;
    const SEARCH_REFERENCE = 0x73;
    const MODIFY_REQUEST = 0x66;
    const MODIFY_RESPONSE = 0x67;
    const ADD_REQUEST = 0x68;
    const ADD_RESPONSE = 0x69;
    const DEL_REQUEST = 0x4a;         //primitive
    const DEL_RESPONSE = 0x6b;
    const MODDN_REQUEST = 0x6c;
    const MODDN_RESPONSE = 0x6d;
    const EXTENDED_REQUEST = 0x77;
    const EXTENDED_RESPONSE = 0x78;
    const CONTROLS = 0xa0;

    const OID_STARTTLS = '1.3.6.1.4.1.1466.20037';
    const OID_PASSWORD_MODIFY = '1.3.6.1.4.1.4203.1.11.1';
    const OID_PAGED_RESULTS = '1.2.840.113556.1.4.319';

    protected $socket = null;
    protected $buffer = '';
    protected $messageId = 0;

    public function driver() {
	return 'php';
    }

    // ------------------------------------------------------------------------------------
    //the connection

    protected function connect() {
	$errors = [];
	foreach($this->options['hosts'] as [ $host, $port ]) {
	    try {
		$this->connectTo($host, $port);
		$this->host = $host;
		$this->port = $port;
		return;
	    } catch (LdapException $e) {
		$this->closeSocket();
		$errors[] = $e->getMessage();
	    }
	}
	throw new LdapException(LdapException::NO_CONNECTION, implode('; ', $errors));
    }

    protected function connectTo($host, $port) {
	$caFile = $this->caFile();
	try {
	    $verify = $this->options['verifyTls'] !== false;
	    $ssl = [ 'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'peer_name' => $host, 'SNI_enabled' => true ];
	    if ($caFile)
		$ssl['cafile'] = $caFile;
	    $context = stream_context_create([ 'ssl' => $ssl ]);
	    $address = (strpos($host, ':') !== false ? "[{$host}]" : $host) . ':' . $port;
	    $ldaps = $this->options['encryption'] === 'ldaps';
	    $errno = 0;
	    $errstr = '';
	    $socket = @stream_socket_client(($ldaps ? 'ssl://' : 'tcp://') . $address, $errno, $errstr, $this->options['timeout'], STREAM_CLIENT_CONNECT, $context);
	    if (!$socket)
		throw new LdapException(LdapException::NO_CONNECTION, "cannot connect to {$address}: " . ($errstr !== '' ? $errstr : self::lastError('no answer')));
	    $this->socket = $socket;
	    stream_set_timeout($socket, $this->options['timeout']);
	    if ($this->options['encryption'] === 'starttls') {
		$r = $this->request(LdapBer::tlv(self::EXTENDED_REQUEST, LdapBer::str(self::OID_STARTTLS, 0x80)), self::EXTENDED_RESPONSE);
		if ($r['code'] !== 0)
		    throw new LdapException(LdapException::NO_CONNECTION, "{$address}: the server refused StartTLS: " . (new LdapException($r['code'], '', $r['diagnostic']))->getMessage());
		$method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
		if (@stream_socket_enable_crypto($socket, true, $method) !== true)
		    throw new LdapException(LdapException::NO_CONNECTION, "{$address}: TLS failed after StartTLS: " . self::lastError('the certificate was refused?'));
	    }
	} finally {
	    self::removeFile($caFile);
	}
    }

    protected static function lastError($fallback) {
	$e = error_get_last();
	$m = $e ? preg_replace('/^[a-z_]+\(\):\s*/i', '', (string)$e['message']) : '';
	return $m !== '' ? $m : $fallback;
    }

    public function close() {
	if ($this->socket) {
	    //unbind: no answer
	    try {
		$this->send(LdapBer::tlv(self::UNBIND_REQUEST, ''));
	    } catch (\Throwable $e) {
	    }
	}
	$this->closeSocket();
    }

    protected function closeSocket() {
	if ($this->socket)
	    @fclose($this->socket);
	$this->socket = null;
	$this->buffer = '';
    }

    public function __destruct() {
	$this->closeSocket();
    }

    // ------------------------------------------------------------------------------------
    //messages

    //sends op $op (+ controls, BER of [0] Controls' content): its message id
    protected function send($op, $controls = '') {
	if (!$this->socket)
	    throw new LdapException(LdapException::NO_CONNECTION, 'not connected');
	$id = ++$this->messageId;
	$msg = LdapBer::seq(LdapBer::int($id), $op, $controls !== '' ? LdapBer::tlv(self::CONTROLS, $controls) : '');
	for ($sent = 0, $len = strlen($msg); $sent < $len; $sent += $n) {
	    $n = @fwrite($this->socket, substr($msg, $sent));
	    if ($n === false || $n === 0)
		throw new LdapException(LdapException::NO_CONNECTION, 'the connection to ' . $this->host . ' was closed');
	}
	return $id;
    }

    //the next message: [ id, op tag, op content, controls content ]
    protected function receive() {
	$deadline = microtime(true) + $this->options['timeout'];
	while (($len = LdapBer::messageLength($this->buffer)) === null) {
	    if (microtime(true) > $deadline)
		throw new LdapException(LdapException::NO_CONNECTION, 'no answer from ' . $this->host . ' in ' . $this->options['timeout'] . ' s');
	    $chunk = @fread($this->socket, 65536);
	    if ($chunk === false || ($chunk === '' && feof($this->socket)))
		throw new LdapException(LdapException::NO_CONNECTION, 'the connection to ' . $this->host . ' was closed');
	    if ($chunk === '' && stream_get_meta_data($this->socket)['timed_out'])
		throw new LdapException(LdapException::NO_CONNECTION, 'no answer from ' . $this->host . ' in ' . $this->options['timeout'] . ' s');
	    $this->buffer .= $chunk;
	}
	$message = substr($this->buffer, 0, $len);
	$this->buffer = (string)substr($this->buffer, $len);
	$offset = 0;
	[ $tag, $content ] = LdapBer::read($message, $offset);
	$parts = LdapBer::children($content);
	if ($tag !== LdapBer::SEQUENCE || count($parts) < 2 || $parts[0][0] !== LdapBer::INTEGER)
	    throw new LdapException(LdapException::NO_CONNECTION, 'a broken answer from the server (not an LDAP message)');
	$controls = '';
	foreach(array_slice($parts, 2) as [ $t, $c ]) {
	    if ($t === self::CONTROLS)
		$controls = $c;
	}
	return [ LdapBer::toInt($parts[0][1]), $parts[1][0], $parts[1][1], $controls ];
    }

    //the answer of message $id: notices of disconnection (id 0) end the connection
    protected function receiveFor($id) {
	while (true) {
	    [ $mid, $tag, $content, $controls ] = $this->receive();
	    if ($mid === $id)
		return [ $tag, $content, $controls ];
	    if ($mid === 0) {
		$r = self::result($content);
		$this->closeSocket();
		throw new LdapException($r['code'] ?: LdapException::NO_CONNECTION, 'the server ended the connection', $r['diagnostic']);
	    }
	}
    }

    //sends $op, the result of its answer (of tag $responseTag)
    protected function request($op, $responseTag, $controls = '') {
	$id = $this->send($op, $controls);
	[ $tag, $content, $rcontrols ] = $this->receiveFor($id);
	if ($tag !== $responseTag)
	    throw new LdapException(LdapException::NO_CONNECTION, sprintf('an unexpected answer from the server (0x%02x)', $tag));
	return self::result($content) + [ 'controls' => $rcontrols ];
    }

    //an LDAPResult: [ code, matchedDn, diagnostic, (+ extended: name, value) ]
    protected static function result($content) {
	$parts = LdapBer::children($content);
	$r = [ 'code' => isset($parts[0]) ? LdapBer::toInt($parts[0][1]) : LdapException::NO_CONNECTION, 'matchedDn' => $parts[1][1] ?? '', 'diagnostic' => $parts[2][1] ?? '',
	    'name' => null, 'value' => null ];
	foreach(array_slice($parts, 3) as [ $t, $c ]) {
	    if ($t === 0x8a)
		$r['name'] = $c;
	    elseif ($t === 0x8b)
		$r['value'] = $c;
	}
	return $r;
    }

    protected static function check(array $r) {
	if ($r['code'] !== 0)
	    throw new LdapException($r['code'], '', $r['diagnostic']);
	return $r;
    }

    // ------------------------------------------------------------------------------------
    //operations

    protected function doBind($dn, $password) {
	self::check($this->request(LdapBer::tlv(self::BIND_REQUEST, LdapBer::int(3) . LdapBer::str($dn) . LdapBer::str($password, 0x80)), self::BIND_RESPONSE));
    }

    protected function doSearch($base, $filter, $scope, array $attributes, $sizeLimit, $pageSize) {
	$filterBer = LdapBer::filter($filter);
	$scopeCode = [ 'base' => 0, 'one' => 1, 'sub' => 2 ][$scope];
	$attrs = LdapBer::seq(...array_map(fn($a) => LdapBer::str($a), $attributes));
	$entries = [];
	$truncated = false;
	$cookie = '';
	do {
	    //the size limit is of the whole search: what is left of it
	    $limit = $sizeLimit > 0 ? $sizeLimit - count($entries) : 0;
	    $op = LdapBer::tlv(self::SEARCH_REQUEST, LdapBer::str($base) . LdapBer::enum($scopeCode) . LdapBer::enum(0) . LdapBer::int($pageSize > 0 ? 0 : $limit)
		. LdapBer::int($this->options['timeout']) . LdapBer::bool(false) . $filterBer . $attrs);
	    $controls = '';
	    if ($pageSize > 0) {
		$size = $limit > 0 ? min($pageSize, $limit) : $pageSize;
		$controls = LdapBer::seq(LdapBer::str(self::OID_PAGED_RESULTS), LdapBer::bool(false), LdapBer::str(LdapBer::seq(LdapBer::int($size), LdapBer::str($cookie))));
	    }
	    $id = $this->send($op, $controls);
	    while (true) {
		[ $tag, $content, $rcontrols ] = $this->receiveFor($id);
		if ($tag === self::SEARCH_ENTRY) {
		    if ($sizeLimit > 0 && count($entries) >= $sizeLimit) {
			$truncated = true;
			continue;
		    }
		    $entries[] = self::entry($content);
		    continue;
		}
		if ($tag === self::SEARCH_REFERENCE)
		    continue;
		if ($tag !== self::SEARCH_DONE)
		    throw new LdapException(LdapException::NO_CONNECTION, sprintf('an unexpected answer from the server (0x%02x)', $tag));
		$r = self::result($content);
		if ($r['code'] === LdapException::SIZE_LIMIT) {
		    $truncated = true;
		    $cookie = '';
		    break;
		}
		self::check($r);
		$cookie = $pageSize > 0 ? self::pagedCookie($rcontrols) : '';
		break;
	    }
	    if ($sizeLimit > 0 && count($entries) >= $sizeLimit) {
		if ($cookie !== '') {
		    $truncated = true;
		    //the server forgets the search (a page of size 0)
		    $this->abandonPaged($op, $cookie);
		}
		break;
	    }
	} while ($cookie !== '');
	return [ 'entries' => $entries, 'truncated' => $truncated ];
    }

    //the cookie of the paged results control in a SearchResultDone's controls ('' = no more pages)
    protected static function pagedCookie($controls) {
	foreach(LdapBer::children($controls) as [ $t, $c ]) {
	    $parts = LdapBer::children($c);
	    if (($parts[0][1] ?? '') !== self::OID_PAGED_RESULTS)
		continue;
	    $value = end($parts)[1] ?? '';
	    $offset = 0;
	    [ , $seq ] = LdapBer::read($value, $offset);
	    return LdapBer::children($seq)[1][1] ?? '';
	}
	return '';
    }

    protected function abandonPaged($op, $cookie) {
	try {
	    $controls = LdapBer::seq(LdapBer::str(self::OID_PAGED_RESULTS), LdapBer::bool(false), LdapBer::str(LdapBer::seq(LdapBer::int(0), LdapBer::str($cookie))));
	    $id = $this->send($op, $controls);
	    do {
		[ $tag ] = $this->receiveFor($id);
	    } while ($tag !== self::SEARCH_DONE);
	} catch (LdapException $e) {
	}
    }

    //a SearchResultEntry: [ dn, attributes => [ name => [ values ] ] ]
    protected static function entry($content) {
	$parts = LdapBer::children($content);
	$attributes = [];
	foreach(LdapBer::children($parts[1][1] ?? '') as [ , $attr ]) {
	    $a = LdapBer::children($attr);
	    $name = $a[0][1] ?? '';
	    $attributes[$name] = array_map(fn($v) => $v[1], LdapBer::children($a[1][1] ?? ''));
	}
	return [ 'dn' => $parts[0][1] ?? '', 'attributes' => $attributes ];
    }

    protected static function attributeList(array $attributes) {
	$items = [];
	foreach($attributes as $name => $values)
	    $items[] = LdapBer::seq(LdapBer::str($name), LdapBer::set(...array_map(fn($v) => LdapBer::str($v), array_values((array)$values))));
	return LdapBer::seq(...$items);
    }

    public function add($dn, array $attributes) {
	self::check($this->request(LdapBer::tlv(self::ADD_REQUEST, LdapBer::str($dn) . self::attributeList($attributes)), self::ADD_RESPONSE));
    }

    public function modify($dn, array $changes) {
	$items = [];
	foreach($changes as $c) {
	    $op = [ 'add' => 0, 'delete' => 1, 'replace' => 2 ][$c['op']] ?? null;
	    if ($op === null)
		throw new \InvalidArgumentException('unknown change `' . $c['op'] . '`');
	    $items[] = LdapBer::seq(LdapBer::enum($op), LdapBer::seq(LdapBer::str($c['attr']), LdapBer::set(...array_map(fn($v) => LdapBer::str($v), array_values((array)($c['values'] ?? []))))));
	}
	self::check($this->request(LdapBer::tlv(self::MODIFY_REQUEST, LdapBer::str($dn) . LdapBer::seq(...$items)), self::MODIFY_RESPONSE));
    }

    public function delete($dn) {
	self::check($this->request(LdapBer::tlv(self::DEL_REQUEST, (string)$dn), self::DEL_RESPONSE));
    }

    public function rename($dn, $newRdn, $newParent = null, $deleteOld = true) {
	$content = LdapBer::str($dn) . LdapBer::str($newRdn) . LdapBer::bool($deleteOld) . ((string)$newParent !== '' ? LdapBer::str($newParent, 0x80) : '');
	self::check($this->request(LdapBer::tlv(self::MODDN_REQUEST, $content), self::MODDN_RESPONSE));
    }

    //RFC 3062; $old null = not given (an administrator's reset), $new null = the server generates one (returned)
    public function passwordModify($dn, $old, $new) {
	$value = LdapBer::seq(LdapBer::str($dn, 0x80) . ($old !== null ? LdapBer::str($old, 0x81) : '') . ($new !== null ? LdapBer::str($new, 0x82) : ''));
	$r = self::check($this->request(LdapBer::tlv(self::EXTENDED_REQUEST, LdapBer::str(self::OID_PASSWORD_MODIFY, 0x80) . LdapBer::str($value, 0x81)), self::EXTENDED_RESPONSE));
	if ($new === null && ($r['value'] ?? '') !== '') {
	    $offset = 0;
	    [ , $seq ] = LdapBer::read($r['value'], $offset);
	    foreach(LdapBer::children($seq) as [ $t, $c ]) {
		if ($t === 0x80)
		    return $c;
	    }
	}
	return null;
    }
}
