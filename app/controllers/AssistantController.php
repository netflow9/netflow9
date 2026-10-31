<?php

/*
    the requests of the Assistant plugin, served at /assistant/<action>:
	ask      the designer's Assistant (was WorkflowController::assistant)
	chat_add, chat_clear  CLD: 2026-10-23T23:45:00+03:00: the conversation of the workflow, kept (models\AssistantChat)
	settings the Assistant's settings of the account
	connect  the models of a server (the settings page's Connect)
*/

//CLD: 2026-10-26T23:49:50+02:00: moved to the core (was the Assistant plugin's); its System provider only while the SystemAI plugin is enabled; served at /assistant/<action> (was /assistant/<action>)
namespace controllers;

use util\WorkflowAssistant;
use models\AssistantSettings;
use models\AssistantUsage;
use models\AssistantChat;
use plugins\MultiTenant\util\SystemAi;
use plugins\MultiTenant\models\SystemAiWallet;

class AssistantController extends \sys\BaseController {

    //signed-in people only
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    //workflow ?id of the current account that the user may $permName, else 404 / 403 (as WorkflowController::findWorkflow)
    private function findWorkflow($permName) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($permName, [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	return $wf;
    }

    //the designer's Assistant (POST, JSON) on workflow ?id: `message` (the request), `history` (the earlier turns, JSON [ { role, text } ]), `nodes` (the designer's current steps,
    //JSON). Answers WorkflowAssistant::ask() (the new steps for the canvas: the designer applies them, its autosave saves them), or { error }. Workflow::update
    //CLD: 2026-10-20T10:00:00+03:00: the server, key and model of Assistant settings only (no `credential`: was one of the workflow's project, or the account's server)
    public function ask($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	$wf = $this->findWorkflow('Workflow::update');
	$data = is_array($req->data) ? $req->data : [];

	//CLD: 2026-10-19T20:40:00+03:00: the Assistant's settings (models\AssistantSettings; were the account's fields)
	$settings = \V::app()->om->findOne('AssistantSettings', [ 'aid' => $wf->aid ]);

	if (!WorkflowAssistant::isReady($settings))
	    $resp->sendJSON([ 'error' => 'The Assistant is not set up: connect to a server and choose a model in Assistant settings (Settings menu)' ], true, 400);
	//CLD: 2026-10-22T14:00:00+03:00: the monthly budget (AssistantSettings::budgetOf): refused once it is spent, else what is left limits the request (WorkflowAssistant::$budget)
	$budget = AssistantSettings::budgetOf($settings);
	$left = $budget !== null ? $budget - AssistantUsage::current($wf->aid)->cost : null;
	if ($left !== null && $left <= 0)
	    $resp->sendJSON([ 'error' => sprintf('The Assistant\'s monthly budget (%.2f USD) is spent: it is available again next month, or raise the budget in Assistant settings (Settings menu)', $budget) ], true, 429);
	//CLD: 2026-10-22T15:00:00+03:00: System: paid in credits. The model's rates (AssistantWallet::ratesOf), then credits reserved (at most the installation's per-request limit, within
	//the account's monthly limit): they limit the request (WorkflowAssistant::$budget, in credits); settled after it (settle)
	$hold = null;
	$rates = null;
	if (WorkflowAssistant::providerOf($settings) === 'system') {
	    //CLD: 2026-10-23T10:00:00+03:00: the System AI's credits (plugins\MultiTenant: SystemAiWallet, SystemAi::maxCreditsPerRequest; were AssistantWallet, config assistant.system)
	    $rates = SystemAiWallet::ratesOf(WorkflowAssistant::resolve($settings));
	    if ($rates === null) {
		\V::app()->log->error('Assistant: no credit rates for the System AI model: set them in plugins/MultiTenant/credit_rates.php');
		$resp->sendJSON([ 'error' => 'The Assistant of this installation is not priced yet: ask its administrator' ], true, 503);
	    }
	    try {
		$hold = SystemAiWallet::reserve($wf->aid, SystemAi::maxCreditsPerRequest(), (int)$settings->credit_cap ?: null);
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
	    //CLD: 2026-10-22T14:00:00+03:00: the tokens of a failed request were spent too
	    $this->addUsage($wf->aid, $assistant);
	    $this->settle($wf, $hold, $assistant);
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 502);
	}
	$this->addUsage($wf->aid, $assistant);
	//CLD: 2026-10-22T15:00:00+03:00: System: the credits it used, and those left
	if ($hold !== null)
	    [ $result['credits'], $result['credits_left'] ] = $this->settle($wf, $hold, $assistant);
	$resp->sendJSON($result);
    }

    //CLD: 2026-10-23T23:45:00+03:00: new: a message of the panel added to the conversation of workflow ?id (POST: `message`, JSON: AssistantChat::clean) => { message }
    //(as kept: + uid, by, at). Workflow::update (as ask)
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

    //CLD: 2026-10-23T23:45:00+03:00: new: a new conversation of workflow ?id (POST) => { cleared: true }. Workflow::update
    public function chat_clear($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	AssistantChat::clear($wf);
	$resp->sendJSON([ 'cleared' => true ]);
    }

    //CLD: 2026-10-22T15:00:00+03:00: new: System: reservation $hold of workflow $wf's account settled with what $assistant used (nothing when no model answered: the reservation is
    //freed) => [ credits charged, credits left ]
    private function settle($wf, $hold, $assistant) {
	if ($hold === null)
	    return [ 0, null ];
	$used = $assistant !== null && array_sum($assistant->usage) ? SystemAiWallet::charge($assistant->credits) : 0;
	try {
	    //CLD: 2026-10-23T10:00:00+03:00: + source, model (the System AI's ledger: SystemAiLedger)
	    $left = SystemAiWallet::settle($wf->aid, $hold[0], $used, $used ? [
		'source' => 'assistant', 'model' => (string)(SystemAi::config()->model ?? ''), 'wfid' => (string)$wf->_id, 'uid' => (string)\V::app()->user->_id, 'cost_usd' => round($assistant->cost, 6),
		'input_tokens' => (int)$assistant->usage['input_tokens'] + (int)$assistant->usage['cache_read_input_tokens'] + (int)$assistant->usage['cache_creation_input_tokens'],
		'output_tokens' => (int)$assistant->usage['output_tokens'] ] : []);
	} catch (\Exception $e) {
	    //the reservation frees itself (SystemAiWallet::HOLD_LEASE); the credits are not taken
	    \V::app()->log->error("Assistant: {$used} credits of account {$wf->aid} not taken: " . $e->getMessage());
	    $left = null;
	}
	return [ $used, $left ];
    }

    //CLD: 2026-10-22T14:00:00+03:00: new: what $assistant spent (its last ask()) added to account $aid's month (AssistantUsage); nothing when no model answered
    private function addUsage($aid, $assistant) {
	if ($assistant === null || !array_sum($assistant->usage))
	    return;
	try {
	    AssistantUsage::add($aid, $assistant->cost, $assistant->usage);
	} catch (\Exception $e) {
	    \V::app()->log->error('Assistant: the usage was not recorded: ' . $e->getMessage());
	}
    }

    //the Assistant's settings of the account (models\AssistantSettings;), for who may change the account
    //linked from the Settings menu (layouts/main_aside.php)
    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$settings = $this->findSettings();

	if ($req->is('post')) {
	    //CLD: 2026-10-22T14:00:00+03:00: the saved key belongs to the saved provider and Server URL: another one without a new key starts without it (never sent to another server)
	    $before = [ (string)$settings->provider, trim((string)$settings->url) ];
	    //the form's fields the user may update (an empty API key keeps the stored one: BaseModel::assign)
	    $settings->assign($req->data, true);
	    if ($before !== [ (string)$settings->provider, trim((string)$settings->url) ] && (string)($req->data['api_key'] ?? '') === '')
		$settings->api_key = '';
	    if ($settings->validates() && $settings->save())
		$this->addToast('success', 'OK', 'Assistant settings saved!');
	    else
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	}

	//CLD: 2026-10-22T14:00:00+03:00: + this month's spending, the installation's server (System; null: none)
	$usage = AssistantUsage::current($settings->aid);
	$system = AssistantSettings::system();
	//CLD: 2026-10-22T15:00:00+03:00: System's credits: the wallet, its last entries, the model's rates
	//CLD: 2026-10-23T10:00:00+03:00: the System AI's (SystemAiWallet); the last entries: on its own page (/MultiTenant/credits/index)
	//CLD: 2026-10-26T23:49:50+02:00: only when there is a System AI (SystemAiWallet is the SystemAI plugin's)
	$wallet = $system ? SystemAiWallet::current($settings->aid) : null;
	$rates = $system ? SystemAiWallet::ratesOf($system) : null;
	$this->render('settings', compact('settings', 'usage', 'system', 'wallet', 'rates'));
    }

    //CLD: 2026-10-20T10:00:00+03:00: the Assistant's settings of the current account; new (not saved) when it has none yet
    private function findSettings() {
	$aid = \V::app()->config('rt.account')->_id;
	return \V::app()->om->findOne('AssistantSettings', [ 'aid' => $aid ]) ?: \V::app()->om->create('AssistantSettings', [ 'aid' => $aid ]);
    }

    //CLD: 2026-10-20T10:00:00+03:00: new: Connect on the settings page (POST, JSON): `provider`, `url`, `api_key` as in the form (not saved yet) => { models: [ { id, label } ] }
    //(WorkflowAssistant::models), or { error }. An empty API key is the saved one, but only for the saved provider and Server URL: never sent to another server
    public function connect($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$data = is_array($req->data) ? $req->data : [];
	$saved = $this->findSettings();
	$provider = (string)($data['provider'] ?? '');
	//CLD: 2026-10-20T10:30:00+03:00: AssistantSettings::PROVIDERS (was WorkflowAssistant::)
	if (!array_key_exists($provider, \models\AssistantSettings::PROVIDERS))
	    $resp->sendJSON([ 'error' => 'Choose a provider' ], true, 400);
	//CLD: 2026-10-22T14:00:00+03:00: System: the installation's model, nothing to connect to (its server and key are never used from here)
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
