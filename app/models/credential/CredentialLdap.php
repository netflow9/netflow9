<?php

//CLD: 2026-10-25T19:30:00+02:00: moved from the Ldap plugin to the core (the LDAP step and credential are core, the plugin is the LDAP sign-in): namespace models\credential (was plugins\Ldap\models); registered in data\SysObjects, for every account (no plan feature)

//CLD: 2026-10-21T10:00:00+03:00: new: an LDAP server (Active Directory, OpenLDAP, ...) for the LDAP step (NodeAppLdap). Provides `ldap`. The server's settings are
//LdapServerTrait's (the same as the account's LDAP sign-in); the bind DN is the account the step works as (its rights in the directory are the step's).
//test() binds and reads the Base DN

namespace models\credential;

use util\LdapClient;

class CredentialLdap extends \models\Credential {
    use LdapServerTrait;

    public static $label = 'LDAP server';
    public static $icon = 'bi-diagram-3';
    public static $group = 'Directories';
    public static $provides = [ 'ldap' ];
    public static $testable = true;

    public function getSchema() {
	$fields = $this->serverSchema();
	foreach($fields as &$f) {
	    //a credential's fields are the family's: the permission is the credential's (Credential::checkAccess)
	    unset($f['perm:read'], $f['perm:update']);
	    if ($f['key'] === 'hosts' || $f['key'] === 'baseDn')
		$f['required'] = true;
	    if ($f['key'] === 'bindDn')
		$f['help'] = 'The account the LDAP steps work as (its rights in the directory are theirs), e.g. CN=svc-netflow,OU=Service,DC=example,DC=com; empty: anonymous';
	    if ($f['key'] === 'baseDn')
		$f['help'] = 'Where the steps\' searches start when they give no base, e.g. DC=example,DC=com';
	}
	unset($f);
	return array_merge(parent::getSchema(), $fields);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	return $this->serverValidates() && $valid;
    }

    public function test() {
	return $this->withClient(function(LdapClient $client) {
	    $base = trim((string)$this->baseDn);
	    $entry = $client->read($base, [ '1.1' ]);
	    if (!$entry)
		throw new \Exception('connected to ' . $client->describe() . ', but the Base DN ' . $base . ' was not found (or the bind DN cannot read it)');
	    return 'connected to ' . $client->describe() . ', ' . (trim((string)$this->bindDn) !== '' ? 'signed in as ' . $this->bindDn : 'anonymous') . ', the Base DN was found';
	});
    }
}
