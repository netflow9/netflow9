<?php

namespace controllers;

class UserController extends \sys\BaseController {
    use DataTrait;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('User::list'))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    $response = $this->__data('User');
	    $resp->sendJSON($response);
	}

	$this->render('list');
    }

    public function profile($req, $resp) {
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user))
	    \V::app()->error(404, 'Not found');

	if ($req->is('post') && !\V::app()->user->can('User::update', [ 'object' => $user ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:41:34+03:00: only the fields the profile form shows; assigning the whole POST let a user change own role (Member -> Owner),
	    //clear tfa_secret (disabling 2FA without a code), state, or store a plain-text password
	    //CLD: 2026-10-10T18:54:42+03:00: the form fields the user may update (assign with permissions; password, state, tfa_secret, reset_* are not form fields); the role
	    //is a form field (create) the user may update on itself (User::update scope own): left out here, explicitly
	    $data = is_array($req->data) ? $req->data : [];
	    unset($data['role']);
	    $user->assign($data, true);

	    if ($user->validates()) {
		$user->save();
		$this->addToast('success', 'OK', 'User saved!');
	    } else {
		$this->addToast('error', 'ERROR', 'Error on save!');
	    }
	}

	$this->render('profile', compact('user'));
    }

    //CLD: 2026-10-25T11:20:00+02:00: edit a user of the account (was empty: the list's links did nothing): name, email, role, state, for who may update users (User::update on it);
    //one's own user is the profile (no role / state change on oneself); the account keeps an active Owner (checked on the role and the state); a disabled user's sessions end.
    //A modal (as create), back to the list
    public function update($req, $resp) {
	$user = $this->accountUser();
	if ((string)$user->_id === (string)\V::app()->user->_id)
	    return $this->redirect('/user/profile');

	if ($req->is('post')) {
	    $before = [ 'role' => (string)$user->role, 'state' => (string)$user->state ];
	    $data = is_array($req->data) ? array_intersect_key($req->data, array_flip([ 'firstname', 'lastname', 'email', 'role', 'state' ])) : [];
	    $user->assign($data, true);
	    //the state is not a form field (the profile and create forms have none): set here, with its update permission, one of its choices
	    if (isset($data['state']) && $user->schema['state']->checkAccess(\V::app()->user->_id, 'update') && isset($user->schema['state']->choices[(string)$data['state']]))
		$user->state = (string)$data['state'];

	    $account = \V::app()->config('rt.account');
	    if (!empty($user->role) && empty(\V::app()->om->findOne('Role', [ '_id' => $user->role, 'aid' => $account->_id ])))
		\V::app()->error(400, 'malformed request: unknown role');

	    $valid = $user->validates();
	    //the last active Owner keeps the role and stays active
	    if ($valid && $this->isActiveOwner($before['role'], $before['state']) && !$this->isActiveOwner((string)$user->role, (string)$user->state)
		&& $this->activeOwners($user->_id) === 0) {
		$user->errors('role', 'The account needs an active Owner: give another user the Owner role first');
		$valid = false;
	    }

	    if ($valid) {
		$user->save();
		if ($before['state'] === 'active' && $user->state !== 'active')
		    \V::app()->session->endFor($user->_id);
		$this->addToast('success', 'OK', 'User saved!');
		return $this->redirect('/user/list');
	    }
	}

	$this->render('update', [ 'user' => $user, 'canSendLink' => $user->state === 'active' && \sys\Identity::passwordSource($user) === null ]);
    }

    //CLD: 2026-10-25T11:20:00+02:00: email a user of the account a link to choose a password (the welcome email again: e.g. its link expired); POST, User::update on it
    public function send_link($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$user = $this->accountUser();
	if ($user->sendPasswordLink(\V::app()->user))
	    $this->addToast('success', 'Sent', 'An email with a link to choose a password was sent to ' . $user->email);
	else
	    $this->addToast('error', 'Not sent', 'This user does not sign in with a password here, or is not active');
	return $this->redirect('/user/list');
    }

    //CLD: 2026-10-25T11:20:00+02:00: the user ?id of the current account, if the current user may update it (404 / 403 otherwise)
    protected function accountUser() {
	$id = $this->getOidOrError('id');
	$user = \V::app()->om->findOne('User', [ '_id' => new \MongoDB\BSON\ObjectId($id), 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($user))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('User::update', [ 'object' => $user ]))
	    \V::app()->error(403, 'Unauthorized');
	return $user;
    }

    //CLD: 2026-10-25T11:20:00+02:00: whether role id $roleId is the account's Owner role and $state is active
    protected function isActiveOwner($roleId, $state) {
	if ($state !== 'active' || $roleId === '')
	    return false;
	$role = \V::app()->om->findById('Role', $roleId);
	return ($role->iname ?? null) === 'owner';
    }

    //CLD: 2026-10-25T11:20:00+02:00: the account's active users with the Owner role, other than $exceptId
    protected function activeOwners($exceptId) {
	$owner = \V::app()->om->findOne('Role', [ 'aid' => \V::app()->config('rt.account')->_id, 'iname' => 'owner' ]);
	if (empty($owner))
	    return 0;
	return (int)\V::app()->om->count('User', [ 'aid' => \V::app()->config('rt.account')->_id, 'role' => $owner->_id, 'state' => 'active', '_id' => [ '$ne' => $exceptId ] ]);
    }

    public function create($req, $resp) {
	if (!\V::app()->user->can('User::create'))
	    \V::app()->error(403, 'Unauthorized');

	$account = \V::app()->config('rt.account');
	$user = \V::app()->om->create('User', [ 'aid' => $account->_id ]);

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:41:34+03:00: only the fields the create form shows (was the whole POST: password, state, tfa_secret, ...)
	    //CLD: 2026-10-10T18:54:42+03:00: the form fields the user may update (assign with permissions; was onlyFields firstname, lastname, email, role)
	    $user->assign($req->data, true);

	    //CLD: 2026-10-05T20:41:34+03:00: the role must belong to this account; roles are looked up by id only when checking permissions
	    if (!empty($user->role) && empty(\V::app()->om->findOne('Role', [ '_id' => $user->role, 'aid' => $account->_id ])))
		\V::app()->error(400, 'malformed request: unknown role');

	    if ($user->validates()) {
		$user->save();
		//CLD: 2026-10-25T11:20:00+02:00: the new user gets a link to choose a password (was: no password, no email: they could not sign in)
		if ($user->sendPasswordLink(\V::app()->user))
		    $this->addToast('success', 'OK', 'User created. An email with a link to choose a password was sent to ' . $user->email);
		else
		    $this->addToast('success', 'OK', 'User saved!');
		$this->redirect('/user/list');
	    }
	}

	$this->render('create', compact('user'));
    }

    public function self_change_password($req, $resp) {
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user))
	    \V::app()->error(404, 'Not found');

	if ($req->is('post') && !\V::app()->user->can('User::change-password', [ 'object' => $user ]))
	    \V::app()->error(403, 'Unauthorized');

	//CLD: 2026-10-21T10:00:00+03:00: a password kept elsewhere (e.g. an LDAP user's: the directory's) is changed there
	if (($source = \sys\Identity::passwordSource($user)) !== null) {
	    $this->addToast('error', 'Not here', "Your password is your {$source} password: change it there");
	    $this->redirect('/user/profile');
	}

	$form = \models\user\ChangePasswordForm::create();

	if ($req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		$user->password = \V::app()->security->generatePasswordHash($form->new_password);
		$user->save();
		//CLD: 2026-10-21T16:30:00+03:00: the user's other sessions end (as after a password reset; a session opened with the old password no longer counts)
		\V::app()->session->endFor($user->_id);
		$this->addToast('success', 'OK', 'Password changed!');
		$this->redirect('/user/profile');
	    }
	}

	//clear values, keep errors
	$form->crt_password = '';
	$form->new_password = '';
	$form->confirm_password = '';
	$this->render('self_change_password', compact('form'));
    }

    public function tfa_activate($req, $resp) {
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user))
	    \V::app()->error(404, 'Not found');

	$totp = new \util\TOTP();
	if ($req->is('get')) {
	    $secret = $totp->createSecret();
	    $_SESSION['totp_secret'] = $secret;
	    $this->render('tfa_activate', compact('totp', 'secret', 'user'));
	    return;
	}
	
	//if here, we have a post and we should validate secret
	$secret = $_SESSION['totp_secret'];
	unset($_SESSION['totp_secret']);
	$code = $req->data['code'] ?? null;
	if (empty($code))
	    \V::app()->error(400, 'malformed request: missing code');

	//CLD: 2026-10-24T00:20:00+03:00: User::useTfaCode (was TOTP::verifyCode): the activation code can't then be used to sign in
	if (!empty($secret) && $user->useTfaCode($code, $secret)) {
	    $user->tfa_secret = $secret;
	    $user->save();
	    $this->addToast('success', 'OK', '2FA Activated!');
	} else {
	    $this->addToast('error', 'ERROR', 'There was an error activating 2FA');
	}
	$this->redirect('/user/profile');
    }

    public function tfa_deactivate($req, $resp) {
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user))
	    \V::app()->error(404, 'Not found');

	//CLD: 2026-10-21T16:30:00+03:00: not while the account enforces MFA (the user would be sent back to set it up)
	if (!empty(\V::app()->config('rt.account')->enforce_mfa))
	    \V::app()->error(403, 'Two-factor authentication is required by your account');

	if ($req->is('get')) {
	    $this->render('tfa_deactivate', compact('user'));
	    return;
	}

	$code = $req->data['code'] ?? null;
	if (empty($code))
	    \V::app()->error(400, 'malformed request: missing code');

	//CLD: 2026-10-24T00:20:00+03:00: a code is accepted once (User::useTfaCode; was TOTP::verifyCode)
	if ($user->useTfaCode($code)) {
	    $user->tfa_secret = null;
	    $user->save();
	    $this->addToast('success', 'OK', '2FA Deactivated!');
	} else {
	    $this->addToast('error', 'ERROR', 'There was an error deactivating 2FA');
	}
	$this->redirect('/user/profile');



    }

}