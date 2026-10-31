<?php

/*
    the account's System AI credits (Settings menu: AI credits), for who may change the account:
	/SystemAI/credits/index    the System AI (provider, model, its credits per 1M tokens), the credits (this month's allowance, the top-ups), the last changes
    (were on the Assistant's settings page)
*/

namespace plugins\SystemAI\controllers;

use plugins\SystemAI\util\SystemAi;
use plugins\SystemAI\models\SystemAiWallet;
use plugins\SystemAI\models\SystemAiLedger;

class CreditsController extends \sys\BaseController {

    //signed-in people only
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function index($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
	//CLD: 2026-10-31T12:04:10+02:00: no page while the System AI is not configured (as the Settings menu)
	if (!SystemAi::available())
	    \V::app()->error(404, 'Not found');

	$aid = \V::app()->config('rt.account')->_id;
	$system = SystemAi::config();
	$ledger = iterator_to_array(SystemAiLedger::recent($aid, 25), false);
	//the names of the workflows that spent credits
	$ids = array_values(array_unique(array_filter(array_map(fn($e) => (string)$e->wfid, $ledger), fn($id) => preg_match('/^[a-f0-9]{24}$/', $id))));
	$workflows = [];
	if ($ids)
	    foreach(\V::app()->om->find('Workflow', [ '_id' => [ '$in' => array_map([ \V::app()->om, 'to_oid' ], $ids) ], 'aid' => $aid ]) as $wf)
		$workflows[(string)$wf->_id] = (string)$wf->name;
	$this->render('index', [
	    'system' => $system,
	    'rates' => $system ? SystemAiWallet::ratesOf($system) : null,
	    'wallet' => SystemAiWallet::current($aid),
	    'ledger' => $ledger,
	    'workflows' => $workflows,
	]);
    }
}
