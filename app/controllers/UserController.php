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

    public function update() {
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