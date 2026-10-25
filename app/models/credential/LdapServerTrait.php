<?php

//CLD: 2026-10-25T19:30:00+02:00: moved from the Ldap plugin to the core (the LDAP step and credential are core, the plugin is the LDAP sign-in): namespace models\credential (was plugins\Ldap\models); also the LDAP sign-in's settings (plugins\Ldap\models\LdapSettings)

//CLD: 2026-10-21T10:00:00+03:00: new: the settings of an LDAP server, shared by the account's sign-in settings (LdapSettings) and the LDAP server credential (CredentialLdap):
//  serverSchema()        the fields: directory (Active Directory / other), servers, encryption, port, certificate check, CA certificate, timeout, bind DN + password, base DN
//  serverValidates()     the servers given are host names / addresses
//  client()              a connected util\LdapClient, bound with the bind DN (anonymous without one); close() it when done
//  withClient($fn)       $fn($client) on a bound client, closed afterwards
//  $ldapDriver           auto | ext | php (the check suite runs both drivers)

namespace models\credential;

use util\LdapClient;

trait LdapServerTrait {
    public $ldapDriver = 'auto';

    public static $directories = [ 'ad' => 'Active Directory', 'openldap' => 'OpenLDAP, 389 Directory Server or another LDAP server' ];

    protected function serverSchema() {
	$enc = fn($values) => [ 'key' => 'encryption', 'operator' => 'in', 'value' => $values ];
	return [
	    [
		'key' => 'directory',
		'type' => 'select',
		'label' => 'Directory',
		'required' => true,
		'default' => 'ad',
		'choices' => self::$directories,
		'help' => 'Sets the usual attribute names and filters (each can be changed)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [
		'key' => 'hosts',
		'type' => 'text',
		'label' => 'Servers',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Host names or IP addresses, optionally :port, separated by commas: tried in order until one answers',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 8, 'placeholder' => 'dc1.example.com, dc2.example.com' ],
	    ],
	    [
		'key' => 'port',
		'type' => 'number',
		'label' => 'Port',
		'default' => null,
		'help' => 'Empty: 636 for LDAPS, else 389',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ] ],
		'ui' => [ 'width' => 4, 'step' => 1 ],
	    ],
	    [
		'key' => 'encryption',
		'type' => 'select',
		'label' => 'Encryption',
		'required' => true,
		'default' => 'starttls',
		'choices' => LdapClient::ENCRYPTION,
		'help' => 'Passwords are sent to the server: use None only on a trusted network',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 8 ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 10,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 120, 'skipEmpty' => true, 'message' => 'from 1 to 120' ] ],
		'ui' => [ 'width' => 4, 'step' => 1 ],
	    ],
	    [
		'key' => 'verifyTls',
		'type' => 'switch',
		'label' => 'Check the server\'s certificate',
		'default' => true,
		'help' => 'Off only for a test server: without the check, anyone on the way can read the passwords',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'showIf' => $enc([ 'starttls', 'ldaps' ]) ],
	    ],
	    [
		'key' => 'caCert',
		'type' => 'textarea',
		'label' => 'CA certificate',
		'default' => '',
		'help' => 'Optional: the certificate (PEM) of the authority that signed the server\'s, when it is not a public one (e.g. your company\'s CA)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'validate' => [ [ 'pemCertificate', 'skipEmpty' => true, 'message' => 'not a certificate (-----BEGIN CERTIFICATE-----)' ] ],
		'ui' => [ 'rows' => 4, 'placeholder' => '-----BEGIN CERTIFICATE-----', 'showIf' => $enc([ 'starttls', 'ldaps' ]) ],
	    ],
	    [
		'key' => 'bindDn',
		'type' => 'text',
		'label' => 'Bind DN',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The account that reads the directory, e.g. CN=svc-netflow,OU=Service,DC=example,DC=com (Active Directory also takes svc-netflow@example.com); empty: anonymous',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'bindPassword',
		'type' => 'password',
		'label' => 'Bind password',
		'encrypt' => true,
		'default' => '',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'baseDn',
		'type' => 'text',
		'label' => 'Base DN',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Where the searches start, e.g. DC=example,DC=com or OU=People,DC=example,DC=com',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'placeholder' => 'DC=example,DC=com' ],
	    ],
	];
    }

    //the servers are host names / addresses; a bind DN has its password
    protected function serverValidates() {
	$valid = true;
	if (trim((string)$this->hosts) === '') {
	    $this->errors('hosts', 'required');
	    $valid = false;
	} elseif (($error = LdapClient::hostsError($this->hosts)) !== null) {
	    $this->errors('hosts', $error);
	    $valid = false;
	}
	if (trim((string)$this->bindDn) !== '' && (string)$this->bindPassword === '') {
	    $this->errors('bindPassword', 'required with a bind DN');
	    $valid = false;
	}
	return $valid;
    }

    public function isAd() {
	return $this->directory === 'ad';
    }

    //a connected client (not bound)
    public function connect() {
	return LdapClient::open([
	    'hosts' => (string)$this->hosts,
	    'encryption' => (string)$this->encryption ?: 'starttls',
	    'port' => (int)$this->port ?: null,
	    'verifyTls' => $this->verifyTls !== false,
	    'caCert' => (string)$this->caCert,
	    'timeout' => (int)$this->timeout ?: 10,
	    'driver' => $this->ldapDriver,
	]);
    }

    //a client bound as the bind DN (anonymous without one)
    public function client() {
	$client = $this->connect();
	try {
	    if (trim((string)$this->bindDn) !== '')
		$client->bind(trim((string)$this->bindDn), (string)$this->bindPassword);
	} catch (\Throwable $e) {
	    $client->close();
	    throw $e;
	}
	return $client;
    }

    public function withClient(callable $fn) {
	$client = $this->client();
	try {
	    return $fn($client);
	} finally {
	    $client->close();
	}
    }
}
