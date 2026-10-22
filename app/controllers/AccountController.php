<?php

namespace controllers;

class AccountController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$account = \V::app()->config('rt.account');

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:44:56+03:00: only the form's field; `hostname` is part of data collection names, `state` is not user-settable (was the whole POST)
	    //CLD: 2026-10-10T18:57:33+03:00: assign with permissions (the form fields the user may update; was onlyFields enforce_mfa)
	    $account->assign($req->data, true);

	    if ($account->validates()) {
		$account->save();
		$this->addToast('success', 'OK', 'Preferences saved!');
	    }
	}


	$this->render('settings', compact('account'));
    }

}