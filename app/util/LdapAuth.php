<?php

//CLD: 2026-10-21T10:00:00+03:00: new: the sign-in with LDAP (hook identity:authenticate of sys\Identity::authenticate), by the accounts' settings (models\LdapSettings).
//  1. The name typed is an LDAP user's (a user linked to the directory: models\LdapLink, by the user's email or the directory name) of an account with LDAP sign-in on: the
//     directory decides (the person found by the user filter, else by the link's DN; the password checked by a bind as the person); refused there = refused, never the
//     password stored here. The directory not answering refuses too ("try again later").
//  2. Else the accounts whose directory may know the name: the account of a user with that email when Link existing users is on, the account whose Email domains has the
//     name's domain, the account taking names without @domain (bareLogins). The person found and the password right: an existing user with the person's email is linked
//     (linkExisting), else a user is created (provision), else refused. Not found in any of them (or a directory not answering): the local sign-in goes on (null).
//  The user is refreshed from the directory at each sign-in: first / last name, email (when no other user of the account has it), the role from the groups (groupMap, else
//  defaultRole: none = refused); the last Owner of an account keeps that role. The 2FA of the application still applies after this (SiteController::signIn).
//  attempt($login, $password)          [ 'user' => User|null, 'reason' => text|null ], or null: not an LDAP sign-in (the local password applies)
//  trial($settings, $login, $password) what a sign-in would do (the settings page's Try): [ 'ok' => bool, 'lines' => [ texts ] ] - nothing is saved
//  passwordSource($user)               'LDAP' for an LDAP user (hook identity:password-source: no password reset / change here)

namespace util;

use models\LdapSettings;
use models\LdapLink;

class LdapAuth {
    const GENERIC = 'User or password is incorrect';

    //the enabled settings by account id (one process)
    protected static $settings = [];

    // ------------------------------------------------------------------------------------
    //hooks

    public static function onAuthenticate(\ArrayObject $ctx) {
	//CLD: 2026-10-21T20:10:00+03:00: the sign-in looks at the users and directories of every account (ObjectManager account guard)
	$r = \V::app()->om->acrossAccounts(fn() => self::attempt((string)$ctx['login'], (string)$ctx['password']));
	if ($r === null)
	    return;
	$ctx['handled'] = true;
	$ctx['user'] = $r['user'];
	$ctx['reason'] = $r['reason'];
    }

    public static function onPasswordSource(\ArrayObject $ctx) {
	if (!empty($ctx['user']) && self::passwordSource($ctx['user']) !== null)
	    $ctx['source'] = 'LDAP';
    }

    public static function passwordSource($user) {
	if (empty($user->_id) || !self::enabledFor($user->aid))
	    return null;
	return LdapLink::forUser($user) ? 'LDAP' : null;
    }

    //the settings of account $aid when its LDAP sign-in is on, else null
    public static function enabledFor($aid) {
	$key = (string)$aid;
	if ($key === '')
	    return null;
	if (!array_key_exists($key, self::$settings)) {
	    $s = \V::app()->om->findOne('LdapSettings', [ 'aid' => \V::app()->om->to_oid($aid), 'enabled' => true ]);
	    self::$settings[$key] = $s ?: null;
	}
	return self::$settings[$key];
    }

    //forget the settings read (they were changed; the check suite)
    public static function reset() {
	self::$settings = [];
    }

    // ------------------------------------------------------------------------------------
    //the sign-in

    public static function attempt($login, $password) {
	$login = trim((string)$login);
	$password = (string)$password;
	if ($login === '' || $password === '')
	    return null;
	$om = \V::app()->om;
	$lower = mb_strtolower($login);

	//the users the name may be: by email (or username), and the LDAP users of that directory name
	$users = [];
	foreach($om->find('User', [ '$or' => [ [ 'username' => $login ], [ 'email' => $lower ] ] ]) as $u)
	    $users[(string)$u->_id] = $u;
	foreach($om->find('LdapLink', [ 'login' => $lower ]) as $l) {
	    if (!isset($users[(string)$l->user]) && ($u = $om->findOne('User', [ '_id' => $l->user, 'aid' => $l->aid ])))
		$users[(string)$u->_id] = $u;
	}

	//1. LDAP users of accounts with the LDAP sign-in on
	$linked = [];
	$unlinked = [];
	foreach($users as $u) {
	    $s = self::enabledFor($u->aid);
	    if (!$s)
		continue;
	    if ($link = LdapLink::forUser($u))
		$linked[] = [ $s, $u, $link ];
	    else
		$unlinked[] = [ $s, $u ];
	}
	if ($linked) {
	    $reason = null;
	    foreach($linked as [ $s, $u, $link ]) {
		$r = self::signIn($s, $login, $password, $u, $link);
		if (!empty($r['user']))
		    return $r;
		$reason = $reason ?? $r['reason'];
	    }
	    return [ 'user' => null, 'reason' => $reason ?? self::GENERIC ];
	}

	//2. the directories that may know the name; not the directory of an account where the name is a local user's (Link existing users off: that user's password here)
	$accounts = [];
	$local = [];
	foreach($unlinked as [ $s, $u ]) {
	    if ($s->linkExisting)
		$accounts[(string)$s->aid] = $s;
	    else
		$local[(string)$s->aid] = true;
	}
	$at = strrpos($lower, '@');
	foreach($om->find('LdapSettings', [ 'enabled' => true ]) as $s) {
	    if (isset($local[(string)$s->aid]))
		continue;
	    if ($at !== false ? in_array(substr($lower, $at + 1), $s->domainList(), true) : (bool)$s->bareLogins)
		$accounts[(string)$s->aid] = self::enabledFor($s->aid) ?? $s;
	}
	foreach($accounts as $s) {
	    $r = self::signIn($s, $login, $password, null, null);
	    if ($r !== null)
		return $r;
	}
	return null;
    }

    //what a sign-in of $login would do with settings $s (not saved: the form's values), without signing in or saving anything
    public static function trial(LdapSettings $s, $login, $password) {
	$lines = [];
	$om = \V::app()->om;
	$user = $om->findOne('User', [ 'aid' => $s->aid, 'email' => mb_strtolower(trim((string)$login)) ]);
	$link = $user ? LdapLink::forUser($user) : null;
	if (!$link) {
	    $l = $om->findOne('LdapLink', [ 'aid' => $s->aid, 'login' => mb_strtolower(trim((string)$login)) ]);
	    if ($l && ($u = $om->findOne('User', [ '_id' => $l->user, 'aid' => $s->aid ]))) {
		$link = $l;
		$user = $u;
	    }
	}
	if ($user)
	    $lines[] = 'User of the account: ' . $user . ' <' . $user->email . '>' . ($link ? ', an LDAP user (' . $link->dn . ')' : ', not an LDAP user yet');
	$r = self::signIn($s, $login, $password, $link ? $user : null, $link, [ 'dry' => true, 'trace' => &$lines ]);
	if ($r === null) {
	    $lines[] = 'Result: not an LDAP sign-in: ' . ($user ? 'the user\'s password stored here would be checked' : 'refused (no such user)');
	    return [ 'ok' => false, 'lines' => $lines ];
	}
	$lines[] = $r['user'] !== null ? 'Result: signed in as ' . $r['user'] . ' <' . $r['user']->email . '>' : 'Result: refused: ' . $r['reason'];
	return [ 'ok' => $r['user'] !== null, 'lines' => $lines ];
    }

    protected static function refuse($reason = self::GENERIC) {
	return [ 'user' => null, 'reason' => $reason ];
    }

    //the sign-in of $login in the directory of $s: $user / $link = an LDAP user (null: none yet). [ user, reason ], or null (an unlinked name: not found in the directory, or it does
    //not answer). $opt: dry (nothing saved: the user to create is made, not saved), trace (the steps, as text)
    protected static function signIn(LdapSettings $s, $login, $password, $user, $link, array $opt = []) {
	$dry = !empty($opt['dry']);
	$trace = [];
	if (array_key_exists('trace', $opt))
	    $trace = &$opt['trace'];
	$linked = $user !== null;
	try {
	    $client = $s->client();
	} catch (\Throwable $e) {
	    $trace[] = 'Connect: ' . $e->getMessage();
	    \V::app()->log->error('LDAP sign-in: account ' . $s->aid . ': ' . $e->getMessage());
	    return $linked ? self::refuse('The directory cannot be reached: try again later') : null;
	}
	try {
	    $trace[] = 'Connected to ' . $client->describe() . (trim((string)$s->bindDn) !== '' ? ', bound as ' . $s->bindDn : ', anonymous');

	    //the person
	    $found = self::findPerson($client, $s, $login);
	    if (count($found) > 1) {
		$trace[] = 'Found ' . count($found) . ' entries for this name: ' . implode('; ', array_map(fn($e) => $e['dn'], $found));
		return self::refuse('Several directory entries have this name: ask the account owner');
	    }
	    $entry = $found[0] ?? null;
	    if ($linked && $entry && !LdapClient::dnEquals($entry['dn'], $link->dn)
		&& mb_strtolower(LdapClient::first($entry, $s->get('attrLogin'))) !== (string)$link->login)
		$entry = null;
	    if ($linked && !$entry)
		$entry = $client->read((string)$link->dn, self::personAttributes($s));
	    if (!$entry) {
		$trace[] = 'Not found in the directory (user filter ' . $s->get('userFilter') . ' under ' . $s->baseDn . ')';
		return $linked ? self::refuse() : null;
	    }
	    $trace[] = 'Found: ' . $entry['dn'];

	    //the password
	    try {
		$client->bind($entry['dn'], $password);
	    } catch (LdapException $e) {
		if (!$e->isInvalidCredentials())
		    throw $e;
		$trace[] = 'Password: refused (' . $e->getMessage() . ')';
		$ad = $e->adReason();
		return self::refuse($ad !== null && !in_array($ad, [ 'invalid credentials', 'user not found' ], true) ? 'The directory refused the sign-in: ' . $ad : self::GENERIC);
	    }
	    $trace[] = 'Password: right';
	    //the groups as the bind DN (a person may not read the groups)
	    if (trim((string)$s->bindDn) !== '')
		$client->bind(trim((string)$s->bindDn), (string)$s->bindPassword);

	    $groups = self::groupsOf($client, $s, $entry);
	    $trace[] = 'Groups: ' . ($groups ? implode('; ', $groups) : 'none');
	    [ $roleId, $row ] = $s->roleFor($groups);
	    $role = $roleId ? \V::app()->om->findOne('Role', [ '_id' => $roleId, 'aid' => $s->aid ]) : null;
	    if (!$role) {
		$trace[] = $roleId ? 'Role: the role chosen no longer exists' : 'Role: none (in none of the groups, and Others is "Refuse the sign-in")';
		return self::refuse('Your directory groups do not give you access to ' . \V::app()->config('app.name'));
	    }
	    $trace[] = 'Role: ' . $role->name . ($row !== null ? " (group {$row})" : ' (Others)');

	    $profile = self::profile($s, $entry, $login);
	    $account = \V::app()->om->findById('Account', (string)$s->aid);
	    \V::app()->var('rt.account', $account);
	    if (!$user) {
		$existing = $profile['email'] !== '' ? \V::app()->om->findOne('User', [ 'aid' => $s->aid, 'email' => $profile['email'] ]) : null;
		if ($existing) {
		    if (!$s->linkExisting) {
			//a user of the account who is not an LDAP user: the password stored here
			$trace[] = 'User: ' . $existing . ' has this email; Link existing users is off: not linked';
			return null;
		    }
		    $user = $existing;
		    $trace[] = 'User: ' . $existing . ' (linked to the directory now)';
		} elseif ($s->provision) {
		    if ($profile['email'] === '') {
			$trace[] = 'User: cannot be created: the entry has no email (' . $s->get('attrEmail') . ')';
			return self::refuse('Your directory entry has no email address: ask the account owner');
		    }
		    $user = \models\User::create([ 'aid' => $s->aid, 'email' => $profile['email'], 'firstname' => $profile['firstname'], 'lastname' => $profile['lastname'],
			'state' => 'active', 'role' => $role->_id ]);
		    $trace[] = 'User: created: ' . $user . ' <' . $profile['email'] . '>';
		    if (!$dry) {
			if (!$user->validates() || !$user->save()) {
			    \V::app()->log->error('LDAP sign-in: the user was not saved: ' . json_encode($user->errors()));
			    return self::refuse('Your user could not be created: ask the account owner');
			}
			\models\AuditLog::record('ldap.provision', $user, 'created at the first LDAP sign-in: ' . $entry['dn'], [], null, $user);
		    }
		} else {
		    $trace[] = 'User: none in the account, and Create users is off';
		    return self::refuse('You have no user in this account: ask the account owner');
		}
	    }
	    if (!$dry)
		self::sync($s, $user, $link, $entry, $profile, $role);
	    return [ 'user' => $user, 'reason' => null ];
	} catch (\Throwable $e) {
	    $trace[] = 'Error: ' . $e->getMessage();
	    \V::app()->log->error('LDAP sign-in: account ' . $s->aid . ': ' . $e->getMessage());
	    return $linked ? self::refuse('The directory cannot be reached: try again later') : null;
	} finally {
	    $client->close();
	}
    }

    public static function personAttributes(LdapSettings $s) {
	return array_values(array_unique([ $s->get('attrLogin'), $s->get('attrEmail'), $s->get('attrFirstname'), $s->get('attrLastname'), 'cn', 'displayName', 'memberOf',
	    'userPrincipalName' ]));
    }

    //the entries of the user filter for $login (at most 2: more is ambiguous)
    public static function findPerson(LdapClient $client, LdapSettings $s, $login) {
	$filter = str_replace('{login}', LdapClient::escapeFilter(trim((string)$login)), $s->get('userFilter'));
	return $client->search((string)$s->baseDn, $filter, 'sub', self::personAttributes($s), 2)['entries'];
    }

    //the DNs of the person's groups
    public static function groupsOf(LdapClient $client, LdapSettings $s, array $entry) {
	$base = trim((string)$s->groupBaseDn) !== '' ? trim((string)$s->groupBaseDn) : (string)$s->baseDn;
	switch($s->groupSource) {
	    case 'search':
		$filter = str_replace([ '{dn}', '{login}' ], [ LdapClient::escapeFilter($entry['dn']), LdapClient::escapeFilter(LdapClient::first($entry, $s->get('attrLogin'))) ], $s->get('groupFilter'));
		return array_map(fn($e) => $e['dn'], $client->search($base, $filter, 'sub', [ '1.1' ], 0, 500)['entries']);
	    case 'nested':
		$filter = '(member:1.2.840.113556.1.4.1941:=' . LdapClient::escapeFilter($entry['dn']) . ')';
		return array_map(fn($e) => $e['dn'], $client->search($base, $filter, 'sub', [ '1.1' ], 0, 500)['entries']);
	}
	return array_map('strval', LdapClient::values($entry, 'memberOf'));
    }

    //email (lower case), first name, last name, directory name of an entry
    public static function profile(LdapSettings $s, array $entry, $login) {
	$email = mb_strtolower(LdapClient::first($entry, $s->get('attrEmail')));
	if ($email === '' && $s->isAd())
	    $email = mb_strtolower(LdapClient::first($entry, 'userPrincipalName'));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL))
	    $email = '';
	$first = trim(LdapClient::first($entry, $s->get('attrFirstname')));
	$last = trim(LdapClient::first($entry, $s->get('attrLastname')));
	if ($first === '' || $last === '') {
	    $full = preg_split('/\s+/', trim(LdapClient::first($entry, 'displayName') ?: LdapClient::first($entry, 'cn')), 2);
	    $first = $first !== '' ? $first : ($full[0] ?? '');
	    $last = $last !== '' ? $last : ($full[1] ?? '');
	}
	$name = mb_strtolower(LdapClient::first($entry, $s->get('attrLogin'))) ?: mb_strtolower(trim((string)$login));
	return [ 'email' => $email, 'firstname' => $first !== '' ? $first : $name, 'lastname' => $last !== '' ? $last : $name, 'login' => $name ];
    }

    //the link and the user refreshed from the directory
    protected static function sync(LdapSettings $s, $user, $link, array $entry, array $profile, $role) {
	$om = \V::app()->om;
	if (!$link) {
	    $link = $om->create('LdapLink', [ 'aid' => $s->aid, 'user' => $user->_id ]);
	    \models\AuditLog::record('ldap.link', $user, 'linked to the directory: ' . $entry['dn'], [], null, $user);
	}
	$link->dn = $entry['dn'];
	$link->login = $profile['login'];
	$link->synced_at = new \MongoDB\BSON\UTCDateTime();
	$link->save();

	if ($profile['firstname'] !== (string)$user->firstname)
	    $user->firstname = $profile['firstname'];
	if ($profile['lastname'] !== (string)$user->lastname)
	    $user->lastname = $profile['lastname'];
	if ($profile['email'] !== '' && $profile['email'] !== (string)$user->email && !$om->findOne('User', [ 'aid' => $user->aid, 'email' => $profile['email'], '_id' => [ '$ne' => $user->_id ] ]))
	    $user->email = $profile['email'];
	if ((string)$user->role !== (string)$role->_id) {
	    $current = $om->findOne('Role', [ '_id' => $user->role, 'aid' => $user->aid ]);
	    if ($current && $current->iname === 'owner' && $role->iname !== 'owner' && self::lastOwner($user, $current)) {
		\models\AuditLog::record('ldap.role', $user, 'kept Owner (the last owner of the account; the directory groups give ' . $role->name . ')', [], null, $user);
	    } else {
		\models\AuditLog::record('ldap.role', $user, 'role from the directory groups: ' . ($current->name ?? '?') . ' -> ' . $role->name, [], null, $user);
		$user->role = $role->_id;
	    }
	}
	if ($user->modified()) {
	    if (!$user->validates() || !$user->save())
		\V::app()->log->error('LDAP sign-in: the user ' . $user->_id . ' was not refreshed: ' . json_encode($user->errors()));
	}
    }

    //$user is the only active user of the account with the owner role $owner
    protected static function lastOwner($user, $owner) {
	return \V::app()->om->count('User', [ 'aid' => $user->aid, 'role' => $owner->_id, 'state' => 'active', '_id' => [ '$ne' => $user->_id ] ]) === 0;
    }
}
