<?php

/*
    the requests of the Assistant plugin, served at /Assistant/assistant/<action>:
	ask      the designer's Assistant (was WorkflowController::assistant)
	settings the Assistant's settings of the account
	connect  the models of a server (the settings page's Connect)
*/

namespace plugins\Assistant\controllers;

use plugins\Assistant\util\WorkflowAssistant;

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
	try {
	    $assistant = new WorkflowAssistant($wf, $project, $settings);
	    $result = $assistant->ask(is_array($history) ? $history : [], (string)($data['message'] ?? ''), $nodes);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 502);
	}
	$resp->sendJSON($result);
    }

    //the Assistant's settings of the account (models\AssistantSettings;), for who may change the account
    //linked from the Settings menu (Plugin::settingsMenu)
    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');

	$settings = $this->findSettings();

	if ($req->is('post')) {
	    //the form's fields the user may update (an empty API key keeps the stored one: BaseModel::assign)
	    $settings->assign($req->data, true);
	    if ($settings->validates() && $settings->save())
		$this->addToast('success', 'OK', 'Assistant settings saved!');
	    else
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	}

	$this->render('settings', compact('settings'));
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
	if (!array_key_exists($provider, \plugins\Assistant\models\AssistantSettings::PROVIDERS))
	    $resp->sendJSON([ 'error' => 'Choose a provider' ], true, 400);
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
