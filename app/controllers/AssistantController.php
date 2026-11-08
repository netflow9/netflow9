<?php

/*
    the requests of the Assistant, served at /assistant/<action>:
	ask      the designer's Assistant
	chat_add, chat_clear  the conversation of the workflow, kept (models\assistant\AssistantChat)
	settings the Assistant's settings of the account
	connect  the models of a server (the settings page's Connect)
*/

namespace controllers;

use util\WorkflowAssistant;
use models\assistant\AssistantSettings;
use models\assistant\AssistantUsage;
use models\assistant\AssistantChat;

class AssistantController extends \sys\BaseController {

    //signed-in people only
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	$this->account = \V::app()->var('rt.account');
    }

    //workflow ?id of the current account that the user may $permName, else 404 / 403 (as WorkflowController::findWorkflow)
    private function findWorkflow($permName) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => $this->account->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($permName, [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	return $wf;
    }

    //the designer's Assistant (POST, JSON) on workflow ?id: `message` (the request), `history` (the earlier turns, JSON [ { role, text } ]), `nodes` (the designer's current steps,
    //JSON). Answers WorkflowAssistant::ask() (the new steps for the canvas: the designer applies them, its autosave saves them), or { error }. Workflow::update
    public function ask($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	$wf = $this->findWorkflow('Workflow::update');
	$data = is_array($req->data) ? $req->data : [];

	//the Assistant's settings (models\assistant\AssistantSettings)
	$settings = \V::app()->om->findOne('AssistantSettings', [ 'aid' => $wf->aid ]);

	if (!WorkflowAssistant::isReady($settings))
	    $resp->sendJSON([ 'error' => 'The Assistant is not set up: connect to a server and choose a model in Assistant settings (Settings menu)' ], true, 400);

	//the monthly budget (AssistantSettings::budgetOf): refused once it is spent, else what is left limits the request (WorkflowAssistant::$budget)
	$budget = AssistantSettings::budgetOf($settings);
	$left = $budget !== null ? $budget - AssistantUsage::current($wf->aid)->cost : null;
	if ($left !== null && $left <= 0)
	    $resp->sendJSON([ 'error' => sprintf('The Assistant\'s monthly budget (%.2f USD) is spent: it is available again next month, or raise the budget in Assistant settings (Settings menu)', $budget) ], true, 429);

	//System: paid in credits. The model's rates (AssistantWallet::ratesOf), then credits reserved (at most the installation's per-request limit, within
	//the account's monthly limit): they limit the request (WorkflowAssistant::$budget, in credits); settled after it (settle)
	$hold = null;
	$rates = null;
	//the provider of a plugin (util\AssistantSystem: its rates, reserve, settle)
	$system = WorkflowAssistant::providerOf($settings) === 'system' ? \util\AssistantSystem::provider() : null;
	if ($system !== null) {
	    //System AI's credits (plugins\MultiTenant: SystemAiWallet, SystemAi::maxCreditsPerRequest)
	    $rates = $system->rates(WorkflowAssistant::resolve($settings));
	    if ($rates === null) {
		\V::app()->log->error('Assistant: no credit rates for the System AI model (its plugin\'s credit rates)');
		$resp->sendJSON([ 'error' => 'The Assistant of this installation is not priced yet: ask its administrator' ], true, 503);
	    }
	    try {
		$hold = $system->reserve($wf->aid, (int)$settings->credit_cap ?: null);
	    } catch (\ErrorException $e) {
		$resp->sendJSON([ 'error' => $e->getMessage() ], true, 503);
	    }
	    if ($hold === null)
		$resp->sendJSON([ 'error' => 'The Assistant\'s credits are used up' . ((int)$settings->credit_cap ? ' (or the account\'s monthly limit is reached)' : '')
		    . ': the monthly allowance comes back on the 1st; ask who manages the account for a top-up' ], true, 429);
	    $left = $hold[1];
	}
	$project = \V::app()->om->findOne('Project', [ '_id' => $wf->project, 'aid' => $wf->aid ]);
	if (empty($project))
	    $resp->sendJSON([ 'error' => 'The project of this workflow is not available' ], true, 400);

	$nodes = isset($data['nodes']) ? json_decode((string)$data['nodes'], true) : null;
	if (!is_array($nodes))
	    $nodes = is_array($wf->nodes) ? $wf->nodes : [];

	$history = isset($data['history']) ? json_decode((string)$data['history'], true) : [];

	//the model can take minutes: the session is not held meanwhile (the designer's other requests, e.g. the Executions panel, go on)
	session_write_close();
	set_time_limit(0);
	$assistant = null;
	try {
	    $assistant = new WorkflowAssistant($wf, $project, $settings);
	    $assistant->budget = $left;
	    $assistant->rates = $rates;
	    $result = $assistant->ask(is_array($history) ? $history : [], (string)($data['message'] ?? ''), $nodes);
	} catch (\ErrorException $e) {
	    //the tokens of a failed request were spent too
	    $this->addUsage($wf->aid, $assistant);
	    $this->settle($system, $wf, $hold, $assistant);
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 502);
	}
	$this->addUsage($wf->aid, $assistant);
	//System: the credits it used, and those left
	if ($hold !== null)
	    [ $result['credits'], $result['credits_left'] ] = $this->settle($system, $wf, $hold, $assistant);
	$resp->sendJSON($result);
    }

    //a message of the panel added to the conversation of workflow ?id (POST: `message`, JSON: AssistantChat::clean) => { message }
    //(as kept: + uid, by, at).
    public function chat_add($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$data = is_array($req->data) ? $req->data : [];
	$m = isset($data['message']) ? json_decode((string)$data['message'], true) : null;
	if (!is_array($m))
	    $resp->sendJSON([ 'error' => 'No message' ], true, 400);
	$resp->sendJSON([ 'message' => AssistantChat::add($wf, $m) ]);
    }

    //a new conversation of workflow ?id (POST) => { cleared: true }. Workflow::update
    public function chat_clear($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	AssistantChat::clear($wf);
	$resp->sendJSON([ 'cleared' => true ]);
    }

    //System: reservation $hold of workflow $wf's account settled with what $assistant used (nothing when no model answered: the reservation is
    //freed) => [ credits charged, credits left ]
    // the System provider's settle (util\AssistantSystem)
    private function settle($system, $wf, $hold, $assistant) {
	if ($hold === null || $system === null)
	    return [ 0, null ];
	return $system->settle($wf->aid, $hold, $assistant, (string)$wf->_id);
    }

    //what $assistant spent (its last ask()) added to account $aid's month (AssistantUsage); nothing when no model answered
    private function addUsage($aid, $assistant) {
	if ($assistant === null || !array_sum($assistant->usage))
	    return;
	try {
	    AssistantUsage::add($aid, $assistant->cost, $assistant->usage);
	} catch (\Exception $e) {
	    \V::app()->log->error('Assistant: the usage was not recorded: ' . $e->getMessage());
	}
    }

    //the Assistant's settings of the account (models\assistant\AssistantSettings;), for who may change the account
    //linked from the Settings menu (layouts/main_aside.php)
    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$settings = $this->findSettings();

	if ($req->is('post')) {
	    //the saved key belongs to the saved provider and Server URL: another one without a new key starts without it (never sent to another server)
	    $before = [ (string)$settings->provider, trim((string)$settings->url) ];
	    //the form's fields the user may update (an empty API key keeps the stored one: BaseModel::assign)
	    $settings->assign($req->data, true);
	    if ($before !== [ (string)$settings->provider, trim((string)$settings->url) ] && (string)($req->data['api_key'] ?? '') === '')
		$settings->api_key = '';
	    if ($settings->validates() && $settings->save()) {
		$this->addToast('success', 'OK', 'Assistant settings saved!');
		//CLD: 2026-11-02T23:00:00+02:00: a budget this model's spending is not counted in
		if (AssistantSettings::budgetOf($settings) !== null && AssistantSettings::pricesOf($settings) === null)
		    $this->addToast('warning', 'Budget', 'Rail22 does not know the price of this model: what it spends is not counted in the monthly budget');
	    }
	    else
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	}

	//this month's spending, the installation's server (System; null: none)
	$usage = AssistantUsage::current($settings->aid);
	$system = AssistantSettings::system();
	//the System part of the page is the provider's (util\AssistantSystem::settingsHtml: the model, the credits, the monthly limit)
	$systemHtml = $system !== null ? \util\AssistantSystem::provider()->settingsHtml($settings) : null;
	$this->render('settings', compact('settings', 'usage', 'system', 'systemHtml'));
    }

    //the Assistant's settings of the current account; new (not saved) when it has none yet
    private function findSettings() {
	$aid = $this->account->_id;
	return \V::app()->om->findOne('AssistantSettings', [ 'aid' => $aid ]) ?: \V::app()->om->create('AssistantSettings', [ 'aid' => $aid ]);
    }

    //Connect on the settings page (POST, JSON): `provider`, `url`, `api_key` as in the form (not saved yet) => { models: [ { id, label } ] }
    //(WorkflowAssistant::models), or { error }. An empty API key is the saved one, but only for the saved provider and Server URL: never sent to another server
    public function connect($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$data = is_array($req->data) ? $req->data : [];
	$saved = $this->findSettings();
	$provider = (string)($data['provider'] ?? '');
	//AssistantSettings::providers
	if (!array_key_exists($provider, \models\assistant\AssistantSettings::providers()))
	    $resp->sendJSON([ 'error' => 'Choose a provider' ], true, 400);
	//System: the installation's model, nothing to connect to (its server and key are never used from here)
	if ($provider === 'system')
	    $resp->sendJSON([ 'error' => 'System uses the model of this installation: nothing to connect' ], true, 400);
	$url = trim((string)($data['url'] ?? ''));
	if ($url !== '' && !preg_match('~^https?://[^\s?#]+$~i', $url))
	    $resp->sendJSON([ 'error' => 'Server URL: an http:// or https:// URL, without ? or #' ], true, 400);
	$key = (string)($data['api_key'] ?? '');
	if ($key === '' && $provider === WorkflowAssistant::providerOf($saved) && $url === trim((string)$saved->url))
	    $key = (string)$saved->api_key;

	try {
	    $models = WorkflowAssistant::models((object)[ 'provider' => $provider, 'url' => $url, 'api_key' => $key ]);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 502);
	}
	if (!$models)
	    $resp->sendJSON([ 'error' => 'Connected, but the server lists no model' ], true, 502);
	$resp->sendJSON([ 'models' => $models ]);
    }

}
