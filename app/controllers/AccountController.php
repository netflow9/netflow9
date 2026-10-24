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

    //CLD: 2026-10-24T09:45:36+03:00: what the account uses of its tier's limits (util\Tiers), in all and per project (only a breakdown: the limits are the account's)
    public function usage($req, $resp) {
	if (!\V::app()->user->can('Account::usage'))
	    \V::app()->error(403, 'Unauthorized');

	$account = \V::app()->config('rt.account');
	$usage = \util\Tiers::usage($account);
	$limits = \util\Tiers::limits($account);
	$projects = [];
	foreach(\V::app()->om->find('Project', [ 'aid' => $account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p)
	    $projects[(string)$p->_id] = $p;
	$this->render('usage', compact('account', 'usage', 'limits', 'projects'));
    }

}