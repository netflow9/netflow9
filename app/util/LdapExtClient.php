<?php

//CLD: 2026-10-25T19:30:00+02:00: moved from the Ldap plugin to the core (the LDAP step and credential are core, the plugin is the LDAP sign-in): namespace util (was plugins\Ldap\util)

//CLD: 2026-10-21T10:00:00+03:00: new: the LDAP client through PHP's ldap extension (libldap; LdapClient::open picks it when the extension is loaded). The TLS options are set
//on the connection (LDAP_OPT_X_TLS_NEWCTX: its own TLS context, so a CA file or "do not check" of one server does not change another's); referrals are not followed
//(Active Directory answers searches at a domain's root with references to its other partitions: followed, they would bind there anonymously)

namespace util;

class LdapExtClient extends LdapClient {
    protected $link = null;
    protected $caFile = null;

    public function driver() {
	return 'ext';
    }

    protected function connect() {
	$errors = [];
	foreach($this->options['hosts'] as [ $host, $port ]) {
	    try {
		$this->connectTo($host, $port);
		$this->host = $host;
		$this->port = $port;
		return;
	    } catch (LdapException $e) {
		$this->close();
		$errors[] = $e->getMessage();
	    }
	}
	throw new LdapException(LdapException::NO_CONNECTION, implode('; ', $errors));
    }

    protected function connectTo($host, $port) {
	$address = (strpos($host, ':') !== false ? "[{$host}]" : $host) . ':' . $port;
	$link = @ldap_connect(($this->options['encryption'] === 'ldaps' ? 'ldaps://' : 'ldap://') . $address);
	if (!$link)
	    throw new LdapException(LdapException::NO_CONNECTION, "{$address}: not a server address");
	$this->link = $link;
	$timeout = $this->options['timeout'];
	ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
	ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
	ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
	ldap_set_option($link, LDAP_OPT_TIMELIMIT, $timeout);
	if (defined('LDAP_OPT_TIMEOUT'))
	    ldap_set_option($link, LDAP_OPT_TIMEOUT, $timeout);
	if ($this->options['encryption'] !== 'none') {
	    ldap_set_option($link, LDAP_OPT_X_TLS_REQUIRE_CERT, $this->options['verifyTls'] !== false ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);
	    if ($this->caFile = $this->caFile())
		ldap_set_option($link, LDAP_OPT_X_TLS_CACERTFILE, $this->caFile);
	    if (defined('LDAP_OPT_X_TLS_NEWCTX'))
		ldap_set_option($link, LDAP_OPT_X_TLS_NEWCTX, 0);
	}
	if ($this->options['encryption'] === 'starttls' && !@ldap_start_tls($link))
	    throw new LdapException(LdapException::NO_CONNECTION, "{$address}: StartTLS failed: " . $this->errorText());
	//libldap connects on the first operation: an anonymous read of the root DSE shows whether the server answers (a server refusing anonymous reads still answers)
	if ($this->options['encryption'] !== 'starttls') {
	    $r = @ldap_read($link, '', '(objectClass=*)', [ 'supportedLDAPVersion' ], 0, 1, $timeout);
	    $code = ldap_errno($link);
	    if ($r === false && in_array($code, [ -1, 81, 85, 91 ], true))
		throw new LdapException(LdapException::NO_CONNECTION, "cannot connect to {$address}: " . $this->errorText());
	}
    }

    //the connection's last error text (+ the server's diagnostic)
    protected function errorText() {
	$text = ldap_error($this->link);
	$diag = '';
	if (@ldap_get_option($this->link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag) && is_string($diag) && trim($diag) !== '' && $diag !== $text)
	    $text .= ' (' . trim($diag) . ')';
	return $text;
    }

    //the last error as an LdapException (codes below 0 / the client's own 81-97: no connection)
    protected function fail() {
	$code = ldap_errno($this->link);
	$diag = '';
	@ldap_get_option($this->link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
	if ($code < 0 || ($code >= 81 && $code <= 97))
	    throw new LdapException(LdapException::NO_CONNECTION, 'the server ' . $this->host . ' did not answer: ' . $this->errorText());
	throw new LdapException($code, '', is_string($diag) ? $diag : '');
    }

    public function close() {
	if ($this->link)
	    @ldap_unbind($this->link);
	$this->link = null;
	self::removeFile($this->caFile);
	$this->caFile = null;
    }

    public function __destruct() {
	$this->close();
    }

    protected function doBind($dn, $password) {
	if (!@ldap_bind($this->link, $dn !== '' ? $dn : null, $dn !== '' ? $password : null))
	    $this->fail();
    }

    protected function doSearch($base, $filter, $scope, array $attributes, $sizeLimit, $pageSize) {
	$fn = [ 'base' => 'ldap_read', 'one' => 'ldap_list', 'sub' => 'ldap_search' ][$scope];
	$entries = [];
	$truncated = false;
	$cookie = '';
	do {
	    $limit = $sizeLimit > 0 ? $sizeLimit - count($entries) : 0;
	    $controls = null;
	    if ($pageSize > 0)
		$controls = [ [ 'oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => [ 'size' => $limit > 0 ? min($pageSize, $limit) : $pageSize, 'cookie' => $cookie ] ] ];
	    $result = @$fn($this->link, $base, $filter, $attributes, 0, $pageSize > 0 ? 0 : ($limit > 0 ? $limit : -1), $this->options['timeout'], LDAP_DEREF_NEVER, $controls);
	    if ($result === false)
		$this->fail();
	    $code = 0;
	    $matched = $message = '';
	    $referrals = $rcontrols = [];
	    @ldap_parse_result($this->link, $result, $code, $matched, $message, $referrals, $rcontrols);
	    if ($code !== 0 && $code !== LdapException::SIZE_LIMIT)
		throw new LdapException($code, '', $message);
	    for ($e = ldap_first_entry($this->link, $result); $e !== false; $e = ldap_next_entry($this->link, $e)) {
		if ($sizeLimit > 0 && count($entries) >= $sizeLimit) {
		    $truncated = true;
		    break;
		}
		$entries[] = $this->entry($e);
	    }
	    if ($code === LdapException::SIZE_LIMIT) {
		$truncated = true;
		break;
	    }
	    $cookie = $pageSize > 0 ? (string)($rcontrols[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '') : '';
	    if ($sizeLimit > 0 && count($entries) >= $sizeLimit) {
		if ($cookie !== '')
		    $truncated = true;
		break;
	    }
	} while ($cookie !== '');
	return [ 'entries' => $entries, 'truncated' => $truncated ];
    }

    //an entry, its values as bytes (ldap_get_values_len: binary attributes too)
    protected function entry($e) {
	$attributes = [];
	for ($a = ldap_first_attribute($this->link, $e); $a !== false; $a = ldap_next_attribute($this->link, $e)) {
	    $values = @ldap_get_values_len($this->link, $e, $a) ?: [ 'count' => 0 ];
	    unset($values['count']);
	    $attributes[$a] = array_values($values);
	}
	return [ 'dn' => (string)ldap_get_dn($this->link, $e), 'attributes' => $attributes ];
    }

    public function add($dn, array $attributes) {
	$entry = [];
	foreach($attributes as $name => $values)
	    $entry[$name] = array_values((array)$values);
	if (!@ldap_add($this->link, $dn, $entry))
	    $this->fail();
    }

    public function modify($dn, array $changes) {
	$batch = [];
	foreach($changes as $c) {
	    $values = array_values((array)($c['values'] ?? []));
	    switch($c['op']) {
		case 'add':
		    $batch[] = [ 'attrib' => $c['attr'], 'modtype' => LDAP_MODIFY_BATCH_ADD, 'values' => $values ];
		    break;
		case 'delete':
		    $batch[] = $values ? [ 'attrib' => $c['attr'], 'modtype' => LDAP_MODIFY_BATCH_REMOVE, 'values' => $values ] : [ 'attrib' => $c['attr'], 'modtype' => LDAP_MODIFY_BATCH_REMOVE_ALL ];
		    break;
		case 'replace':
		    $batch[] = $values ? [ 'attrib' => $c['attr'], 'modtype' => LDAP_MODIFY_BATCH_REPLACE, 'values' => $values ] : [ 'attrib' => $c['attr'], 'modtype' => LDAP_MODIFY_BATCH_REMOVE_ALL ];
		    break;
		default:
		    throw new \InvalidArgumentException('unknown change `' . $c['op'] . '`');
	    }
	}
	if (!@ldap_modify_batch($this->link, $dn, $batch))
	    $this->fail();
    }

    public function delete($dn) {
	if (!@ldap_delete($this->link, $dn))
	    $this->fail();
    }

    public function rename($dn, $newRdn, $newParent = null, $deleteOld = true) {
	if (!@ldap_rename($this->link, $dn, $newRdn, (string)$newParent, (bool)$deleteOld))
	    $this->fail();
    }

    public function passwordModify($dn, $old, $new) {
	$r = @ldap_exop_passwd($this->link, $dn, (string)$old, (string)$new);
	if ($r === false)
	    $this->fail();
	return is_string($r) && $r !== '' ? $r : null;
    }
}
