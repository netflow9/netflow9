<?php

namespace sys;

class Identity {

    public $reason = null;
    public $data = [];
    //CLD: 2026-10-21T20:10:00+03:00: the users (of several accounts) the name and password of authenticate() fit, when more than one
    public $choices = [];

    public function data() {
	return $this->data;
    }

    public function __construct() {
	$this->reason = \V::t('User or password is incorrect');
    }

    //common function for both authenticate and for authenticatebyID
    public function __auth($user) {
	if ($user->state != 'active')
	    return false;

	$this->data = $user->data();
	//CLD: 2026-10-10T09:14:41+03:00: never the encrypted fields (e.g. tfa_secret): the identity data goes into the session (stored in the database)
	foreach($user->encryptedFields() as $key)
	    unset($this->data[$key]);
	//CLD: 2026-10-21T23:00:00+03:00: nor the password hash and the confirmation code (were kept in every session record)
	unset($this->data['password'], $this->data['conf_code']);
	$this->data['name'] = (string)$user;
	$this->data['id'] = (string)$user->_id;
	$this->data['aid'] = $user->aid;
	
	return true;
    }

    // form
    public function authenticate($username = null, $password = null) {
	if (empty($password))
	    return false;

	//CLD: 2026-10-21T10:00:00+03:00: the plugins' sign-in first (hook identity:authenticate, e.g. the Ldap plugin): a plugin that handles the name sets `handled`, then `user` (the
	//user signed in) or `reason` (why not: shown on the login page); not handled: the password stored here, below
	$ctx = new \ArrayObject([ 'login' => (string)$username, 'password' => (string)$password, 'handled' => false, 'user' => null, 'reason' => null ]);
	\V::app()->hooks->applyHook('identity:authenticate', [ $ctx ]);
	if ($ctx['handled']) {
	    if (empty($ctx['user'])) {
		if (!empty($ctx['reason']))
		    $this->reason = \V::t((string)$ctx['reason']);
		return false;
	    }
	    $this->reason = null;
	    return $this->__auth($ctx['user']);
	}

	//CLD: 2026-10-21T20:10:00+03:00: usernames and emails are unique within an account only: the password is checked against every active user of that name (was: the first
	//user found, of any account, so the same email in a second account could not sign in there). One match signs in; several (the same name and password in several
	//accounts) are left in $choices for the user to pick (SiteController::choose_account), after the password was checked
	$matched = [];
	foreach(\V::app()->om->acrossAccounts(fn() => iterator_to_array(\V::app()->om->find('User', [
	    '$or' => [
		[ 'username' => $username ],
		[ 'email' => $username ],
	    ],
	    'state' => 'active',
	]), false)) as $user) {
	    //prevent login with no password
	    if (empty($user->password))
		continue;
	    if (\V::app()->security->validatePasswordHash($password, $user->password))
		$matched[] = $user;
	}

	if (count($matched) > 1) {
	    $this->choices = $matched;
	    $this->reason = null;
	    return false;
	}
	if (empty($matched))
	    return false;

	$this->reason = null;
	return $this->__auth($matched[0]);
    }


    //CLD: 2026-10-21T10:00:00+03:00: the system that keeps user $user's password when it is not this application (hook identity:password-source: e.g. 'LDAP', the Ldap plugin's
    //users), else null; such a user has no password reset or change here
    public static function passwordSource($user) {
	if (empty($user))
	    return null;
	$ctx = new \ArrayObject([ 'user' => $user, 'source' => null ]);
	\V::app()->hooks->applyHook('identity:password-source', [ $ctx ]);
	return $ctx['source'];
    }

    //from cli
    public function authenticateById($id, $source = null) {
	$user = \V::app()->om->findById('User', $id);
	if (!$user)
	    return false;

	return $this->__auth($user);
    }
}