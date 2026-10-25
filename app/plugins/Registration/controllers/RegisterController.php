<?php

/*
    the sign-up pages of the Registration plugin; served at /Registration/register and /Registration/register/confirm
*/

namespace plugins\Registration\controllers;

class RegisterController extends \sys\BaseController {

    //CLD: 2026-10-16T12:14:57+03:00: sign-up (user's choices: the email first; a link, valid 24 hours, opens the page that creates the account; the same answer whether or not the
    //address already has an account - that address gets a "you already have an account" email instead; no rate limits for now). Nothing is created before the link is used
    public function index($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';

	$form = \models\user\EmailForm::create();
	$sent = false;
	if ($req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		$email = (string)$form->email;
		if (\V::app()->om->count('User', [ 'email' => $email ]) > 0) {
		    \util\EmailSend::system('signup_exists', $email, [ 'email' => $email, 'login' => \V::app()->createAbsoluteUrl('/site/login') ]);
		} else {
		    [ , $token ] = \plugins\Registration\models\Signup::start($email, \V::app()->request->userIP);
		    \util\EmailSend::system('signup_confirm', $email, [ 'link' => \V::app()->createAbsoluteUrl('/Registration/register/confirm', [ 't' => $token ]) ]);
		}
		$sent = true;
	    }
	}
	$this->render('index', compact('form', 'sent'));
    }

    //CLD: 2026-10-16T12:14:57+03:00: the link of a sign-up: the account's name, the user's name and password; then the account (its system roles), its owner (this user,
    //active) and the user logged in (the login makes the Personal project); the sign-up is used up. An expired / used link (or an address registered meanwhile) says so
    public function confirm($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';
	$token = (string)($req->query['t'] ?? '');
	$signup = \plugins\Registration\models\Signup::findByToken($token);
	if ($signup && \V::app()->om->count('User', [ 'email' => (string)$signup->email ]) > 0)
	    $signup = null;
	$form = \plugins\Registration\models\RegisterForm::create([], [ 'validateCurrentPassword' => false ]);
	if ($signup && $req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		$account = \models\Account::create([ 'state' => 'active', 'name' => trim((string)$form->account_name) ]);
		if (!$account->validates() || !$account->save())
		    throw new \ErrorException('sign-up: the account was not saved: ' . json_encode($account->errors()));
		\V::app()->var('rt.account', $account);
		try {
		    $roles = $account->setupRoles();
		    $user = \models\User::create([ 'aid' => $account->_id, 'email' => (string)$signup->email, 'firstname' => trim((string)$form->firstname),
			'lastname' => trim((string)$form->lastname), 'password' => \V::app()->security->generatePasswordHash((string)$form->new_password),
			'state' => 'active', 'role' => $roles['owner']->_id ]);
		    if (!$user->validates() || !$user->save())
			throw new \ErrorException('sign-up: the user was not saved: ' . json_encode($user->errors()));
		} catch (\Throwable $e) {
		    //no half-made account
		    foreach(\V::app()->om->find('Role', [ 'aid' => $account->_id ]) as $r)
			$r->delete();
		    $account->delete();
		    throw $e;
		}
		$signup->delete();
		\models\AuditLog::record('signup', $user, 'account ' . $account->name . ' created');
		//CLD: 2026-10-19T11:21:19+03:00: SiteController::signIn is public static (the plugin's pages sign in the same way)
		\controllers\SiteController::signIn($user);
	    }
	}
	$this->render('confirm', [ 'form' => $form, 'signup' => $signup, 'token' => $token ]);
    }
}
