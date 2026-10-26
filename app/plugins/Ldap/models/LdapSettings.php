<?php

//CLD: 2026-10-21T10:00:00+03:00: new: the LDAP sign-in settings of an account, one document per account (the settings page: LdapController::settings). With `enabled`, the people of
//the account sign in with their directory password (util\LdapAuth):
//  - the server (LdapServerTrait: servers, encryption, bind DN, base DN, ...)
//  - finding a person: userFilter ({login} = the name typed, escaped), the attributes of the name, email, first and last name (empty: the directory's usual ones: defaults())
//  - the groups (groupSource: the person's memberOf, a search of the groups, or Active Directory's nested groups) and the role they give (groupMap: the first row whose group
//    the person is in; else defaultRole; none: the sign-in is refused)
//  - who: an LDAP user of the account (LdapLink) signs in only with LDAP; provision = people found in the directory get a user on their first sign-in; linkExisting = a user
//    of the account with the person's email becomes an LDAP user on a directory sign-in; domains / bareLogins = which account a name without a user is looked up in

namespace plugins\Ldap\models;

//CLD: 2026-10-25T19:30:00+02:00: the LDAP client and the server's settings are core (were plugins\Ldap\util, plugins\Ldap\models)
use util\LdapClient;
use models\credential\LdapServerTrait;

class LdapSettings extends \sys\BaseModel {
    //CLD: 2026-10-26T07:23:58+02:00: the role choices are read from the database: not cached (BaseModel::attachSchema)
    protected static $cacheSchema = false;

    use LdapServerTrait;

    protected static $collection = 'ldap_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const GROUP_SOURCES = [
	'memberof' => 'The person\'s memberOf attribute',
	'search' => 'Search the groups (Group filter)',
	'nested' => 'Active Directory: nested groups too (groups of groups)',
    ];

    //the usual names by directory: login, email, first name, last name, the user filter, the group filter (memberOf is read on both)
    const DEFAULTS = [
	'ad' => [
	    'attrLogin' => 'sAMAccountName', 'attrEmail' => 'mail', 'attrFirstname' => 'givenName', 'attrLastname' => 'sn',
	    'userFilter' => '(&(objectCategory=person)(objectClass=user)(|(sAMAccountName={login})(userPrincipalName={login})(mail={login}))(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
	    'groupFilter' => '(&(objectClass=group)(member={dn}))',
	],
	'openldap' => [
	    'attrLogin' => 'uid', 'attrEmail' => 'mail', 'attrFirstname' => 'givenName', 'attrLastname' => 'sn',
	    'userFilter' => '(&(objectClass=person)(|(uid={login})(mail={login})))',
	    'groupFilter' => '(|(&(objectClass=groupOfNames)(member={dn}))(&(objectClass=groupOfUniqueNames)(uniqueMember={dn}))(&(objectClass=posixGroup)(memberUid={login})))',
	],
    ];

    public function getSchema() {
	$roles = self::roleChoices();
	$fields = array_merge([
	    [
		'key' => 'enabled',
		'type' => 'switch',
		'label' => 'Sign in with LDAP',
		'default' => false,
		'help' => 'On: the account\'s LDAP users sign in with their directory password; other users keep their own password',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	], $this->serverSchema(), [
	    [
		'key' => 'userFilter',
		'type' => 'text',
		'label' => 'User filter',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Finds the person by the name typed at sign-in: {login}. Empty: the directory\'s usual one (shown)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [ 'key' => 'attrLogin', 'type' => 'text', 'label' => 'Name attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: sAMAccountName / uid',
		'perm:read' => 'everybody', 'perm:update' => 'everybody', 'ui' => [ 'width' => 3 ] ],
	    [ 'key' => 'attrEmail', 'type' => 'text', 'label' => 'Email attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: mail',
		'perm:read' => 'everybody', 'perm:update' => 'everybody', 'ui' => [ 'width' => 3 ] ],
	    [ 'key' => 'attrFirstname', 'type' => 'text', 'label' => 'First name attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: givenName',
		'perm:read' => 'everybody', 'perm:update' => 'everybody', 'ui' => [ 'width' => 3 ] ],
	    [ 'key' => 'attrLastname', 'type' => 'text', 'label' => 'Last name attribute', 'default' => '', 'transform' => 'trim', 'help' => 'Empty: sn',
		'perm:read' => 'everybody', 'perm:update' => 'everybody', 'ui' => [ 'width' => 3 ] ],
	    [
		'key' => 'groupSource',
		'type' => 'select',
		'label' => 'Groups from',
		'required' => true,
		'default' => 'memberof',
		'choices' => self::GROUP_SOURCES,
		'help' => 'OpenLDAP shows memberOf only with its memberof overlay: else search the groups',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [
		'key' => 'groupBaseDn',
		'type' => 'text',
		'label' => 'Group base DN',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: the Base DN',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'groupSource', 'operator' => 'in', 'value' => [ 'search', 'nested' ] ] ],
	    ],
	    [
		'key' => 'groupFilter',
		'type' => 'text',
		'label' => 'Group filter',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The groups of the person: {dn} = the person\'s DN, {login} = the name attribute\'s value. Empty: the directory\'s usual one',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'groupSource', 'operator' => 'eq', 'value' => 'search' ] ],
	    ],
	    [
		'key' => 'groupMap',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Groups and roles',
		'itemLabel' => 'Row',
		'default' => [],
		'help' => 'The role of a person is the one of the first row whose group the person is in (checked at each sign-in)',
		'fields' => [
		    [ 'key' => 'group', 'type' => 'text', 'label' => 'Group', 'required' => true, 'help' => false, 'transform' => 'trim',
			'ui' => [ 'width' => 8, 'placeholder' => 'CN=NetFlow Admins,OU=Groups,DC=example,DC=com, or its name: NetFlow Admins' ] ],
		    [ 'key' => 'role', 'type' => 'select', 'label' => 'Role', 'required' => true, 'help' => false, 'choices' => $roles, 'ui' => [ 'width' => 4 ] ],
		],
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'addLabel' => 'Add a group', 'compact' => true, 'maxRows' => 50 ],
	    ],
	    [
		'key' => 'defaultRole',
		'type' => 'select',
		'label' => 'Others',
		'default' => '',
		'choices' => [ '' => 'Refuse the sign-in' ] + $roles,
		'help' => 'The role of people in none of the groups above; empty: their sign-in is refused',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [
		'key' => 'provision',
		'type' => 'switch',
		'label' => 'Create users on their first sign-in',
		'default' => true,
		'help' => 'Off: only users already linked to the directory (or found by Link existing users) sign in',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [
		'key' => 'linkExisting',
		'type' => 'switch',
		'label' => 'Link existing users',
		'default' => false,
		'help' => 'A user of the account whose email is a directory person\'s signs in with LDAP from then on (no longer with the password here)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    [
		'key' => 'domains',
		'type' => 'text',
		'label' => 'Email domains',
		'default' => '',
		'transform' => 'tolower',
		'help' => 'Someone signing in as name@domain, without a user yet, is looked up in this directory when the domain is one of these (commas between them)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'ui' => [ 'placeholder' => 'example.com, example.ro' ],
	    ],
	    [
		'key' => 'bareLogins',
		'type' => 'switch',
		'label' => 'Names without @domain are this directory\'s',
		'default' => false,
		'help' => 'A name such as jdoe (no user yet) is looked up in this directory. One account of the installation at most',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	]);
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

    //the setting $key, else the directory's usual value
    public function get($key) {
	$v = trim((string)$this->$key);
	return $v !== '' ? $v : (self::DEFAULTS[$this->directory === 'ad' ? 'ad' : 'openldap'][$key] ?? '');
    }

    public function domainList() {
	return array_values(array_filter(array_map(fn($d) => ltrim(trim($d), '@'), preg_split('/[\s,;]+/', (string)$this->domains)), fn($d) => $d !== ''));
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (!$this->enabled)
	    return $valid;
	$valid = $this->serverValidates() && $valid;
	if (trim((string)$this->baseDn) === '') {
	    $this->errors('baseDn', 'required');
	    $valid = false;
	}
	foreach([ 'userFilter' => '{login}', 'groupFilter' => null ] as $key => $needs) {
	    $f = trim((string)$this->$key);
	    if ($f === '')
		continue;
	    if ($needs !== null && strpos($f, $needs) === false) {
		$this->errors($key, "needs {$needs} (where the name typed goes)");
		$valid = false;
		continue;
	    }
	    try {
		\util\LdapBer::parseFilter(str_replace([ '{login}', '{dn}' ], 'x', $f));
	    } catch (\InvalidArgumentException $e) {
		$this->errors($key, $e->getMessage());
		$valid = false;
	    }
	}
	if ($this->groupSource === 'nested' && $this->directory !== 'ad') {
	    $this->errors('groupSource', 'nested groups: Active Directory only (search the groups for other directories)');
	    $valid = false;
	}
	foreach($this->domainList() as $d) {
	    if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
		$this->errors('domains', "not a domain: `{$d}`");
		$valid = false;
	    }
	}
	//a domain, or the names without @domain, belong to one account of the installation
	//CLD: 2026-10-21T20:10:00+03:00: the other accounts' settings: across the accounts (ObjectManager account guard)
	$others = \V::app()->om->acrossAccounts(fn() => iterator_to_array(\V::app()->om->find('LdapSettings', [ 'aid' => [ '$ne' => $this->aid ], 'enabled' => true ]), false));
	foreach($others as $o) {
	    if ($this->bareLogins && $o->bareLogins) {
		$this->errors('bareLogins', 'another account already has this on');
		$valid = false;
	    }
	    if ($taken = array_intersect($this->domainList(), $o->domainList())) {
		$this->errors('domains', 'already used by another account: ' . implode(', ', $taken));
		$valid = false;
	    }
	}
	return $valid;
    }

    //the role of a person in groups $groups (DNs): [ role id, the group row matched (null: the default role) ]; [ null, null ] = refused
    public function roleFor(array $groups) {
	$dns = array_map(fn($g) => LdapClient::normalizeDn($g), $groups);
	$names = array_map(fn($g) => mb_strtolower(LdapClient::rdnValue($g)), $groups);
	foreach((array)$this->groupMap as $row) {
	    $group = trim((string)($row['group'] ?? ''));
	    if ($group === '' || empty($row['role']))
		continue;
	    $hit = strpos($group, '=') !== false ? in_array(LdapClient::normalizeDn($group), $dns, true) : in_array(mb_strtolower($group), $names, true);
	    if ($hit)
		return [ (string)$row['role'], $group ];
	}
	$default = (string)$this->defaultRole;
	return [ $default !== '' ? $default : null, null ];
    }
}
