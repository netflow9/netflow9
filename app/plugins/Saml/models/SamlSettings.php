<?php

//CLD: 2026-10-23T12:00:00+03:00: new: the SAML single sign-on settings of an account, one document per account (the settings page: SamlController::settings). With `enabled`,
//the people of the account sign in at the account's identity provider (IdP: Microsoft Entra ID, Okta, Google Workspace, ADFS, Keycloak, ...), util\SamlAuth:
//  - the IdP: entity ID, single sign-on URL (HTTP-Redirect binding), signing certificates (PEM, one or more: during a rollover both); filled by importing its metadata
//  - this service provider (SP): entity ID (empty: its metadata URL), the NameID format asked, signed requests, ForceAuthn, unsolicited (IdP-initiated) responses,
//    assertions required encrypted. Its key pair (spKey, encrypted; spCert) is made at the first save (keys()), renewed by the settings page
//  - the person: the attributes of the email, first and last name, groups (empty: the usual claim names: ATTRIBUTES; the email also from an email NameID)
//  - the role: groupMap (the first row whose group the person has), else defaultRole (none: the sign-in is refused)
//  - who: an SSO user of the account (SamlLink) signs in only with the IdP; provision = people get a user on their first sign-in; linkExisting = a user of the account with
//    the person's email becomes an SSO user; domains = the email domains whose people "Sign in with SSO" sends to this IdP; askTfa = this application's 2FA after the IdP's

namespace plugins\Saml\models;

use plugins\Saml\util\SamlXml;

class SamlSettings extends \sys\BaseModel {
    //CLD: 2026-10-26T07:23:58+02:00: the role choices are read from the database: not cached (BaseModel::attachSchema)
    protected static $cacheSchema = false;

    protected static $collection = 'saml_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const NAMEID_FORMATS = [
	'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => 'Email address',
	'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => 'Persistent (an ID of the person)',
	'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' => 'Unspecified (the IdP chooses)',
	'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => 'Transient (a new one each time: users found by email)',
    ];
    const TRANSIENT = 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient';

    //the usual names of the attributes (Microsoft Entra ID / ADFS claims, Okta, Google, Keycloak, the urn:oid names of Shibboleth), tried in order when the setting is empty
    const ATTRIBUTES = [
	'attrEmail' => [ 'email', 'mail', 'emailAddress', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', 'urn:oid:0.9.2342.19200300.100.1.3', 'User.Email' ],
	'attrFirstname' => [ 'firstName', 'givenName', 'first_name', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'urn:oid:2.5.4.42', 'User.FirstName' ],
	'attrLastname' => [ 'lastName', 'sn', 'surname', 'last_name', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'urn:oid:2.5.4.4', 'User.LastName' ],
	'attrGroups' => [ 'groups', 'memberOf', 'Role', 'roles', 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
	    'http://schemas.microsoft.com/ws/2008/06/identity/claims/role', 'urn:oid:1.3.6.1.4.1.5923.1.5.1.1' ],
	//no setting: the full name, when the first or last name is missing
	'displayName' => [ 'displayName', 'name', 'http://schemas.microsoft.com/identity/claims/displayname', 'urn:oid:2.16.840.1.113730.3.1.241' ],
    ];

    public function getSchema() {
	$roles = self::roleChoices();
	$field = fn($def) => $def + [ 'perm:read' => 'everybody', 'perm:update' => 'everybody' ];
	$fields = [
	    $field([
		'key' => 'enabled',
		'type' => 'switch',
		'label' => 'Sign in with SAML single sign-on',
		'default' => false,
		'help' => 'On: the account\'s SSO users sign in at the identity provider; other users keep their own password',
	    ]),
	    $field([
		'key' => 'idpMetadataUrl',
		'type' => 'text',
		'label' => 'IdP metadata URL',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: where Import reads the IdP\'s settings (https)',
		'ui' => [ 'placeholder' => 'https://login.microsoftonline.com/<tenant>/federationmetadata/2007-06/federationmetadata.xml' ],
	    ]),
	    $field([
		'key' => 'idpEntityId',
		'type' => 'text',
		'label' => 'IdP entity ID (Issuer)',
		'default' => '',
		'transform' => 'trim',
	    ]),
	    $field([
		'key' => 'idpSsoUrl',
		'type' => 'text',
		'label' => 'IdP single sign-on URL',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its HTTP-Redirect binding',
	    ]),
	    $field([
		'key' => 'idpCerts',
		'type' => 'textarea',
		'label' => 'IdP signing certificates',
		'default' => '',
		'help' => 'PEM; several during a certificate rollover. The responses must be signed by one of them',
		'ui' => [ 'rows' => 5, 'placeholder' => '-----BEGIN CERTIFICATE-----', 'spellcheck' => false ],
	    ]),
	    $field([
		'key' => 'spEntityId',
		'type' => 'text',
		'label' => 'Service provider entity ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: the metadata URL of this application (shown on the right)',
	    ]),
	    $field([
		'key' => 'nameIdFormat',
		'type' => 'select',
		'label' => 'NameID format',
		'required' => true,
		'default' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
		'choices' => self::NAMEID_FORMATS,
		'help' => 'What identifies a person (the NameID); its users are found by it, then by email',
		'ui' => [ 'width' => 6 ],
	    ]),
	    $field([
		'key' => 'signRequests',
		'type' => 'switch',
		'label' => 'Sign the requests',
		'default' => true,
		'help' => 'The sign-in requests are signed with this application\'s certificate',
		'ui' => [ 'width' => 6 ],
	    ]),
	    $field([
		'key' => 'requireEncryption',
		'type' => 'switch',
		'label' => 'Assertions must be encrypted',
		'default' => false,
		'help' => 'With this application\'s certificate (in its metadata)',
		'ui' => [ 'width' => 6 ],
	    ]),
	    $field([
		'key' => 'forceAuthn',
		'type' => 'switch',
		'label' => 'Ask the IdP to sign in again',
		'default' => false,
		'help' => 'ForceAuthn: no single sign-on with the IdP\'s session',
		'ui' => [ 'width' => 6 ],
	    ]),
	    $field([
		'key' => 'allowIdpInitiated',
		'type' => 'switch',
		'label' => 'Accept IdP-initiated sign-ins',
		'default' => false,
		'help' => 'From the IdP\'s portal (an app tile), without a request from here. Less safe: a response is not tied to the browser that asked',
	    ]),
	    $field([ 'key' => 'attrEmail', 'type' => 'text', 'label' => 'Email attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: email, mail, ... or an email NameID',
		'ui' => [ 'width' => 6 ] ]),
	    $field([ 'key' => 'attrGroups', 'type' => 'text', 'label' => 'Groups attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: groups, memberOf, Role, ...',
		'ui' => [ 'width' => 6 ] ]),
	    $field([ 'key' => 'attrFirstname', 'type' => 'text', 'label' => 'First name attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: firstName, givenName, ...',
		'ui' => [ 'width' => 6 ] ]),
	    $field([ 'key' => 'attrLastname', 'type' => 'text', 'label' => 'Last name attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: lastName, sn, surname, ...',
		'ui' => [ 'width' => 6 ] ]),
	    $field([
		'key' => 'groupMap',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Groups and roles',
		'itemLabel' => 'Row',
		'default' => [],
		'help' => 'The role of a person is the one of the first row whose group the person has (checked at each sign-in); a group as the IdP sends it (a name, an ID, a DN)',
		'fields' => [
		    [ 'key' => 'group', 'type' => 'text', 'label' => 'Group', 'required' => true, 'help' => false, 'transform' => 'trim',
			'ui' => [ 'width' => 8, 'placeholder' => 'NetFlow Admins, or Entra ID\'s group object ID' ] ],
		    [ 'key' => 'role', 'type' => 'select', 'label' => 'Role', 'required' => true, 'help' => false, 'choices' => $roles, 'ui' => [ 'width' => 4 ] ],
		],
		'ui' => [ 'addLabel' => 'Add a group', 'compact' => true, 'maxRows' => 50 ],
	    ]),
	    $field([
		'key' => 'defaultRole',
		'type' => 'select',
		'label' => 'Others',
		'default' => '',
		'choices' => [ '' => 'Refuse the sign-in' ] + $roles,
		'help' => 'The role of people in none of the groups above; empty: their sign-in is refused',
	    ]),
	    $field([
		'key' => 'provision',
		'type' => 'switch',
		'label' => 'Create users on their first sign-in',
		'default' => true,
		'help' => 'Off: only SSO users already linked (or found by Link existing users) sign in',
	    ]),
	    $field([
		'key' => 'linkExisting',
		'type' => 'switch',
		'label' => 'Link existing users',
		'default' => false,
		'help' => 'A user of the account with the person\'s email becomes an SSO user: from then on signs in only at the IdP (the password here is removed)',
	    ]),
	    $field([
		'key' => 'domains',
		'type' => 'text',
		'label' => 'Email domains',
		'default' => '',
		'transform' => 'tolower',
		'help' => '"Sign in with SSO" sends people with an email of these domains to this IdP (commas between them)',
		'ui' => [ 'placeholder' => 'example.com, example.ro' ],
	    ]),
	    $field([
		'key' => 'askTfa',
		'type' => 'switch',
		'label' => 'Ask this application\'s 2FA too',
		'default' => false,
		'help' => 'Off: the IdP\'s sign-in is enough (it usually asks its own MFA); on: users with 2FA here give its code too',
	    ]),
	    //this service provider's key pair (keys())
	    [ 'key' => 'spCert', 'type' => 'textarea', 'label' => 'SP certificate', 'default' => '', 'showInForms' => false, 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'spKey', 'type' => 'textarea', 'label' => 'SP private key', 'default' => '', 'encrypt' => true, 'showInForms' => false, 'perm:read' => 'nobody', 'perm:update' => 'nobody' ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //the account's roles: [ id => name ] (rt.account)
    public static function roleChoices() {
	$aid = \V::app()->config('rt.account')->_id ?? null;
	if (empty($aid))
	    return [];
	$choices = [];
	foreach(\V::app()->om->find('Role', [ 'aid' => $aid ], [ 'sort' => [ 'name' => 1 ] ]) as $r)
	    $choices[(string)$r->_id] = (string)$r->name;
	return $choices;
    }

    // ------------------------------------------------------------------------------------
    //this service provider

    public function metadataUrl() {
	return \V::app()->createAbsoluteUrl('/Saml/sso/metadata/' . $this->aid);
    }

    public function acsUrl() {
	return \V::app()->createAbsoluteUrl('/Saml/sso/acs/' . $this->aid);
    }

    //the link that starts a sign-in at the IdP (e.g. a bookmark of the account's people)
    public function loginUrl() {
	return \V::app()->createAbsoluteUrl('/Saml/sso/start/' . $this->aid);
    }

    public function spEntityId() {
	$id = trim((string)$this->spEntityId);
	return $id !== '' ? $id : $this->metadataUrl();
    }

    //this service provider's key pair, made when there is none (a self-signed certificate for 10 years: the IdPs only compare it with the metadata's)
    public function keys($renew = false) {
	if (!$renew && trim((string)$this->spCert) !== '' && trim((string)$this->spKey) !== '')
	    return;
	$key = openssl_pkey_new([ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ]);
	$host = parse_url(\V::app()->createAbsoluteUrl('/'), PHP_URL_HOST) ?: 'localhost';
	$csr = $key ? openssl_csr_new([ 'commonName' => mb_substr($host, 0, 64), 'organizationName' => mb_substr((string)\V::app()->config('app.name'), 0, 64) ?: 'SAML' ], $key,
	    [ 'digest_alg' => 'sha256' ]) : false;
	$cert = $csr ? openssl_csr_sign($csr, null, $key, 3650, [ 'digest_alg' => 'sha256' ], random_int(1, PHP_INT_MAX)) : false;
	if (!$cert || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem))
	    throw new \ErrorException('SAML: the service provider\'s key pair was not made: ' . openssl_error_string());
	$this->spCert = $certPem;
	$this->spKey = $keyPem;
    }

    //the expiry of this service provider's certificate (a timestamp), or null
    public function spCertExpires() {
	$info = trim((string)$this->spCert) !== '' ? openssl_x509_parse((string)$this->spCert) : false;
	return $info['validTo_time_t'] ?? null;
    }

    // ------------------------------------------------------------------------------------
    //the IdP, the person

    //the IdP's certificates (PEM)
    public function idpCertList() {
	preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string)$this->idpCerts, $m);
	$certs = [];
	foreach($m[0] as $pem)
	    $certs[] = SamlXml::certPem(SamlXml::certBody($pem));
	//a bare base64 body (copied from the metadata's X509Certificate)
	if (!$certs && preg_match('/^[A-Za-z0-9+\/=\s]{100,}$/', trim((string)$this->idpCerts)))
	    $certs[] = SamlXml::certPem(trim((string)$this->idpCerts));
	return $certs;
    }

    //the attribute names tried for $key (attrEmail, ...): the setting, else the usual ones
    public function attributeNames($key) {
	$v = array_key_exists($key, $this->schema) ? trim((string)$this->$key) : '';
	return $v !== '' ? [ $v ] : self::ATTRIBUTES[$key];
    }

    public function isTransient() {
	return $this->nameIdFormat === self::TRANSIENT;
    }

    public function domainList() {
	return array_values(array_filter(array_map(fn($d) => ltrim(trim($d), '@'), preg_split('/[\s,;]+/', (string)$this->domains)), fn($d) => $d !== ''));
    }

    //the role of a person with groups $groups: [ role id, the group row matched (null: the default role) ]; [ null, null ] = refused. A row's group matches a value the
    //same (any case), or the first name of a DN value (CN=NetFlow Admins,OU=... matches NetFlow Admins)
    public function roleFor(array $groups) {
	$values = [];
	foreach($groups as $g) {
	    $g = mb_strtolower(trim((string)$g));
	    $values[] = preg_replace('/\s*,\s*/', ',', $g);
	    if (preg_match('/^[a-z0-9.-]+=((?:[^,\\\\]|\\\\.)+),/', $g, $m))
		$values[] = trim(stripslashes($m[1]));
	}
	foreach((array)$this->groupMap as $row) {
	    $group = preg_replace('/\s*,\s*/', ',', mb_strtolower(trim((string)($row['group'] ?? ''))));
	    if ($group !== '' && !empty($row['role']) && in_array($group, $values, true))
		return [ (string)$row['role'], (string)$row['group'] ];
	}
	$default = (string)$this->defaultRole;
	return [ $default !== '' ? $default : null, null ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	foreach([ 'idpMetadataUrl', 'idpSsoUrl' ] as $key) {
	    $url = trim((string)$this->$key);
	    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
		$this->errors($key, 'not a web address (https://...)');
		$valid = false;
	    }
	}
	if (trim((string)$this->idpCerts) !== '') {
	    $certs = $this->idpCertList();
	    if (!$certs) {
		$this->errors('idpCerts', 'no certificate (-----BEGIN CERTIFICATE-----)');
		$valid = false;
	    }
	    foreach($certs as $i => $pem) {
		if (!@openssl_pkey_get_public($pem)) {
		    $this->errors('idpCerts', 'certificate ' . ($i + 1) . ' is not readable');
		    $valid = false;
		}
	    }
	}
	foreach($this->domainList() as $d) {
	    if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
		$this->errors('domains', "not a domain: `{$d}`");
		$valid = false;
	    }
	}
	if (!$this->enabled)
	    return $valid;
	foreach([ 'idpEntityId', 'idpSsoUrl', 'idpCerts' ] as $key) {
	    if (trim((string)$this->$key) === '') {
		$this->errors($key, 'required');
		$valid = false;
	    }
	}
	//a domain belongs to one account of the installation
	$others = \V::app()->om->acrossAccounts(fn() => iterator_to_array(\V::app()->om->find('SamlSettings', [ 'aid' => [ '$ne' => $this->aid ], 'enabled' => true ]), false));
	foreach($others as $o) {
	    if ($taken = array_intersect($this->domainList(), $o->domainList())) {
		$this->errors('domains', 'already used by another account: ' . implode(', ', $taken));
		$valid = false;
	    }
	}
	return $valid;
    }
}
