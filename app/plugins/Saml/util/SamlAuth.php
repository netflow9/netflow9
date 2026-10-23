<?php

//CLD: 2026-10-23T12:00:00+03:00: new: the sign-in with SAML (SsoController::acs), by the accounts' settings (models\SamlSettings), and the hooks of sys\Identity:
//  signIn($s, $claims, $opt)            the user of the claims of a checked response (util\SamlSp::response): [ 'user' => User|null, 'reason' => text|null ]. The user: the SSO
//                                       user of the subject (SamlLink: the NameID; the email with a transient NameID), else a user of the account with the person's email when
//                                       Link existing users is on (linked now: its password here removed), else a new user (provision), else refused. Refreshed at each
//                                       sign-in: first / last name, email (when no other user of the account has it), the role from the groups (groupMap, else defaultRole:
//                                       none = refused); the last Owner of an account keeps that role. $opt: dry (the settings page's test: nothing saved), trace (the steps)
//  claim($s, $claims, $key)             the values of the person's attribute $key (attrEmail, attrFirstname, attrLastname, attrGroups)
//  useOnce($aid, $claims)               false when the assertion was already used (a replay), else remembered until it expires
//  onAuthenticate / onPasswordSource    hooks identity:authenticate (the password of a name whose users are all SSO users is refused: "sign in with SSO") and
//                                       identity:password-source ('SSO' for an SSO user: no password reset / change here)
//  accountFor($email)                   the enabled settings for "Sign in with SSO" of $email: its SSO user's account, else the account of its email domain; null

namespace plugins\Saml\util;

use plugins\Saml\models\SamlSettings;
use plugins\Saml\models\SamlLink;

class SamlAuth {
    const GENERIC = 'The single sign-on did not succeed';

    //the enabled settings by account id (one process)
    protected static $settings = [];

    // ------------------------------------------------------------------------------------
    //hooks

    public static function onAuthenticate(\ArrayObject $ctx) {
	$login = trim((string)$ctx['login']);
	if ($login === '')
	    return;
	$sso = \V::app()->om->acrossAccounts(function() use ($login) {
	    $users = iterator_to_array(\V::app()->om->find('User', [ '$or' => [ [ 'username' => $login ], [ 'email' => mb_strtolower($login) ] ], 'state' => 'active' ]), false);
	    if (!$users)
		return false;
	    foreach($users as $u) {
		if (self::passwordSource($u) === null)
		    return false;
	    }
	    return true;
	});
	//every user of that name is an SSO user: no password here (removed when linked), the IdP signs them in
	if ($sso) {
	    $ctx['handled'] = true;
	    $ctx['user'] = null;
	    $ctx['reason'] = 'This user signs in with single sign-on: use "Sign in with SSO"';
	}
    }

    public static function onPasswordSource(\ArrayObject $ctx) {
	if (!empty($ctx['user']) && self::passwordSource($ctx['user']) !== null)
	    $ctx['source'] = 'SSO';
    }

    public static function passwordSource($user) {
	if (empty($user->_id) || !self::enabledFor($user->aid))
	    return null;
	return SamlLink::forUser($user) ? 'SSO' : null;
    }

    //the settings of account $aid when its SAML sign-in is on, else null
    public static function enabledFor($aid) {
	$key = (string)$aid;
	if ($key === '')
	    return null;
	if (!array_key_exists($key, self::$settings)) {
	    $s = \V::app()->om->acrossAccounts(fn() => \V::app()->om->findOne('SamlSettings', [ 'aid' => \V::app()->om->to_oid($aid), 'enabled' => true ]));
	    self::$settings[$key] = $s ?: null;
	}
	return self::$settings[$key];
    }

    //forget the settings read (they were changed; the check suite)
    public static function reset() {
	self::$settings = [];
    }

    public static function accountFor($email) {
	$email = mb_strtolower(trim((string)$email));
	$at = strrpos($email, '@');
	if ($at === false)
	    return null;
	return \V::app()->om->acrossAccounts(function() use ($email, $at) {
	    $om = \V::app()->om;
	    $found = [];
	    foreach($om->find('User', [ 'email' => $email, 'state' => 'active' ]) as $u) {
		if (($s = self::enabledFor($u->aid)) && SamlLink::forUser($u))
		    $found[(string)$s->aid] = $s;
	    }
	    if (count($found) === 1)
		return reset($found);
	    $domain = substr($email, $at + 1);
	    foreach($om->find('SamlSettings', [ 'enabled' => true ]) as $s) {
		if (in_array($domain, $s->domainList(), true))
		    return $s;
	    }
	    return null;
	});
    }

    // ------------------------------------------------------------------------------------
    //the sign-in

    public static function claim(SamlSettings $s, array $claims, $key) {
	foreach($s->attributeNames($key) as $name) {
	    foreach($claims['attributes'] ?? [] as $attr => $values) {
		if (strcasecmp($attr, $name) === 0 && $values)
		    return $values;
	    }
	}
	return [];
    }

    //email (lower case), first name, last name of the claims
    public static function profile(SamlSettings $s, array $claims) {
	$email = mb_strtolower(trim((string)(self::claim($s, $claims, 'attrEmail')[0] ?? '')));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL))
	    $email = filter_var(mb_strtolower((string)$claims['nameId']), FILTER_VALIDATE_EMAIL) ? mb_strtolower((string)$claims['nameId']) : '';
	$first = trim((string)(self::claim($s, $claims, 'attrFirstname')[0] ?? ''));
	$last = trim((string)(self::claim($s, $claims, 'attrLastname')[0] ?? ''));
	if ($first === '' || $last === '') {
	    $full = preg_split('/\s+/', trim((string)(self::claim($s, $claims, 'displayName')[0] ?? '')), 2);
	    $first = $first !== '' ? $first : ($full[0] ?? '');
	    $last = $last !== '' ? $last : ($full[1] ?? '');
	}
	$name = $email !== '' ? strstr($email, '@', true) : (string)$claims['nameId'];
	return [ 'email' => $email, 'firstname' => $first !== '' ? $first : $name, 'lastname' => $last !== '' ? $last : $name ];
    }

    public static function useOnce($aid, array $claims) {
	$om = \V::app()->om;
	$id = hash('sha256', (string)$claims['assertionId']);
	if ($om->findOne('SamlReplay', [ 'aid' => $om->to_oid($aid), 'assertion' => $id ]))
	    return false;
	$r = $om->create('SamlReplay', [ 'aid' => $om->to_oid($aid), 'assertion' => $id,
	    'expires_at' => new \MongoDB\BSON\UTCDateTime((max((int)$claims['expires'], time()) + SamlSp::CLOCK_SKEW) * 1000) ]);
	try {
	    return (bool)$r->save();
	} catch (\Throwable $e) {
	    //the unique index: used meanwhile
	    return false;
	}
    }

    protected static function refuse($reason = self::GENERIC) {
	return [ 'user' => null, 'reason' => $reason ];
    }

    public static function signIn(SamlSettings $s, array $claims, array $opt = []) {
	$dry = !empty($opt['dry']);
	$trace = [];
	if (array_key_exists('trace', $opt))
	    $trace = &$opt['trace'];
	$om = \V::app()->om;
	$profile = self::profile($s, $claims);
	$subject = $s->isTransient() || $claims['format'] === SamlSettings::TRANSIENT ? $profile['email'] : (string)$claims['nameId'];
	$trace[] = 'NameID: ' . $claims['nameId'] . ($claims['format'] !== '' ? ' (' . $claims['format'] . ')' : '');
	foreach($claims['attributes'] as $name => $values)
	    $trace[] = 'Attribute ' . $name . ': ' . implode('; ', $values);
	$trace[] = 'Person: ' . ($profile['email'] !== '' ? $profile['email'] : 'no email') . ', ' . $profile['firstname'] . ' ' . $profile['lastname'];
	if ($subject === '') {
	    $trace[] = 'Subject: none (a transient NameID and no email)';
	    return self::refuse('The IdP sent no email address for you: ask the account owner');
	}

	//the role
	$groups = self::claim($s, $claims, 'attrGroups');
	$trace[] = 'Groups: ' . ($groups ? implode('; ', $groups) : 'none');
	[ $roleId, $row ] = $s->roleFor($groups);
	$role = $roleId ? $om->findOne('Role', [ '_id' => $roleId, 'aid' => $s->aid ]) : null;
	if (!$role) {
	    $trace[] = $roleId ? 'Role: the role chosen no longer exists' : 'Role: none (in none of the groups, and Others is "Refuse the sign-in")';
	    return self::refuse('Your groups at the identity provider do not give you access to ' . \V::app()->config('app.name'));
	}
	$trace[] = 'Role: ' . $role->name . ($row !== null ? " (group {$row})" : ' (Others)');

	//the user: linked, else by email, else new
	$account = $om->findById('Account', (string)$s->aid);
	\V::app()->var('rt.account', $account);
	$link = $om->findOne('SamlLink', [ 'aid' => $s->aid, 'subject' => $subject ]);
	$user = $link ? $om->findOne('User', [ '_id' => $link->user, 'aid' => $s->aid ]) : null;
	if ($user) {
	    $trace[] = 'User: ' . $user . ' (an SSO user)';
	    if ($user->state !== 'active') {
		$trace[] = 'User: not active (' . $user->state . ')';
		return self::refuse('Your user is not active: ask the account owner');
	    }
	} else {
	    $link = null;
	    $existing = $profile['email'] !== '' ? $om->findOne('User', [ 'aid' => $s->aid, 'email' => $profile['email'] ]) : null;
	    if ($existing && SamlLink::forUser($existing)) {
		//the user of another subject (the IdP's ID of the person changed?): not taken over
		$trace[] = 'User: ' . $existing . ' has this email and is the SSO user of another subject';
		return self::refuse('Your email is the one of another single sign-on user: ask the account owner');
	    } elseif ($existing) {
		if (!$s->linkExisting) {
		    $trace[] = 'User: ' . $existing . ' has this email; Link existing users is off';
		    return self::refuse('You have a user with a password here: sign in with it, or ask the account owner to link it to single sign-on');
		}
		if ($existing->state !== 'active') {
		    $trace[] = 'User: ' . $existing . ' is not active (' . $existing->state . ')';
		    return self::refuse('Your user is not active: ask the account owner');
		}
		$user = $existing;
		$trace[] = 'User: ' . $existing . ' (linked to single sign-on now; its password here removed)';
	    } elseif ($s->provision) {
		if ($profile['email'] === '') {
		    $trace[] = 'User: cannot be created: no email (attributes ' . implode(', ', $s->attributeNames('attrEmail')) . ', or an email NameID)';
		    return self::refuse('The IdP sent no email address for you: ask the account owner');
		}
		$user = \models\User::create([ 'aid' => $s->aid, 'email' => $profile['email'], 'firstname' => $profile['firstname'], 'lastname' => $profile['lastname'],
		    'state' => 'active', 'role' => $role->_id ]);
		$trace[] = 'User: created: ' . $user . ' <' . $profile['email'] . '>';
		if (!$dry) {
		    if (!$user->validates() || !$user->save()) {
			\V::app()->log->error('SAML sign-in: the user was not saved: ' . json_encode($user->errors()));
			return self::refuse('Your user could not be created: ask the account owner');
		    }
		    \models\AuditLog::record('saml.provision', $user, 'created at the first SAML sign-in: ' . $subject, [], null, $user);
		}
	    } else {
		$trace[] = 'User: none in the account, and Create users is off';
		return self::refuse('You have no user in this account: ask the account owner');
	    }
	}
	if (!$dry)
	    self::sync($s, $user, $link, $subject, (string)$claims['format'], $profile, $role);
	return [ 'user' => $user, 'reason' => null ];
    }

    //the link and the user refreshed from the claims
    protected static function sync(SamlSettings $s, $user, $link, $subject, $format, array $profile, $role) {
	$om = \V::app()->om;
	$linked = !$link;
	if ($linked) {
	    $link = $om->create('SamlLink', [ 'aid' => $s->aid, 'user' => $user->_id ]);
	    \models\AuditLog::record('saml.link', $user, 'linked to single sign-on: ' . $subject, [], null, $user);
	    //signs in at the IdP only (Identity::authenticate checks the passwords of every account's users of a name); unset: an empty value keeps the password (Field_password)
	    unset($user->password);
	}
	$link->subject = $subject;
	$link->format = $format;
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
		\models\AuditLog::record('saml.role', $user, 'kept Owner (the last owner of the account; the IdP groups give ' . $role->name . ')', [], null, $user);
	    } else {
		\models\AuditLog::record('saml.role', $user, 'role from the IdP groups: ' . ($current->name ?? '?') . ' -> ' . $role->name, [], null, $user);
		$user->role = $role->_id;
	    }
	}
	if ($user->modified() || $linked) {
	    if (!$user->validates() || !$user->save())
		\V::app()->log->error('SAML sign-in: the user ' . $user->_id . ' was not refreshed: ' . json_encode($user->errors()));
	}
    }

    //$user is the only active user of the account with the owner role $owner
    protected static function lastOwner($user, $owner) {
	return \V::app()->om->count('User', [ 'aid' => $user->aid, 'role' => $owner->_id, 'state' => 'active', '_id' => [ '$ne' => $user->_id ] ]) === 0;
    }
}
