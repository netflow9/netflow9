<?php

/*
    the pages of the InboundMail plugin, served at /InboundMail/inbound/<action>:
    settings   the account's inbound mail settings
*/

namespace plugins\InboundMail\controllers;

use plugins\InboundMail\Plugin;

class InboundController extends \sys\BaseController {

    //signed-in people only (as the core controllers); nothing while the plugin is inactive (no Haraka)
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	if (!Plugin::harakaFound())
	    \V::app()->error(404, 'Not found');
    }

    //the email prefix of the account's inbound addresses (InboundMailSettings), for who may change the account (as AccountController::settings)
    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	//CLD: 2026-10-21T11:30:00+03:00: new settings (not saved) for an account without them (findOne gave null: an error on assign / render)
	$aid = \V::app()->config('rt.account')->_id;
	$settings = \V::app()->om->findOne('InboundMailSettings', [ 'aid' => $aid ])
	    ?: \V::app()->om->create('InboundMailSettings', [ 'aid' => $aid, 'prefix' => \plugins\InboundMail\models\InboundMailSettings::defaultPrefixFor($aid) ]);
	if ($req->is('post')) {
	    $settings->assign($req->data, true);
	    if ($settings->validates() && $settings->save())
		$this->addToast('success', 'OK', 'Inbound mail settings saved!');
	    else
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	}

	$domain = (string)\V::app()->config('mail.inbound_domain');
	$this->render('settings', compact('settings', 'domain'));
    }
}
