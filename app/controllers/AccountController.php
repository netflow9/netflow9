<?php

namespace controllers;

class AccountController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    //CLD: 2026-10-31T11:32:07+02:00: two tabs (?__tab=): general (the settings, Account::update) and usage (was the action usage, Account::usage); the first one the user may see by default
    //CLD: 2026-10-31T19:30:00+02:00: + services (the installation's services, util\Services): without the Admin plugin, for the account's owners (with it: the super-admins' page /services/index)
    public function settings($req, $resp) {
	$tabs = array_keys(array_filter([ 'general' => \V::app()->user->can('Account::update'), 'usage' => \V::app()->user->can('Account::usage'),
	    'services' => !\util\Services::adminPlugin() && \util\Services::allowed() ]));
	if (!$tabs)
	    \V::app()->error(403, 'Unauthorized');
	$tab = (string)($req->query['__tab'] ?? '');
	if (!in_array($tab, $tabs, true))
	    $tab = $tabs[0];

	$account = \V::app()->config('rt.account');

	if ($tab === 'services') {
	    $services = \util\Services::withStatus();
	    $this->render('settings', compact('account', 'tab', 'tabs', 'services'));
	    return;
	}

	if ($tab === 'usage') {
	    $usage = \util\Tiers::usage($account);
	    $limits = \util\Tiers::limits($account);
	    $projects = [];
	    foreach(\V::app()->om->find('Project', [ 'aid' => $account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p)
		$projects[(string)$p->_id] = $p;
	    $this->render('settings', compact('account', 'tab', 'tabs', 'usage', 'limits', 'projects'));
	    return;
	}

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:44:56+03:00: only the form's field; `hostname` is part of data collection names, `state` is not user-settable (was the whole POST)
	    //CLD: 2026-10-10T18:57:33+03:00: assign with permissions (the form fields the user may update; was onlyFields enforce_mfa)
	    $account->assign($req->data, true);

	    if ($account->validates()) {
		$account->save();
		$this->addToast('success', 'OK', 'Preferences saved!');
	    }
	}


	$this->render('settings', compact('account', 'tab', 'tabs'));
    }

    //CLD: 2026-10-31T11:32:07+02:00: usage() removed: the Usage tab of the settings (what the account uses of its tier's limits, in all and per project)

}