<?php

/*
    CLD: 2026-10-31T01:00:00+02:00: new: the knowledge bases of the current project (models\KnowledgeBase) and the account's embedding model (models\EmbeddingSettings).
	list            the project's knowledge bases (app.GridView; ?data=1: JSON)                                   KnowledgeBase::list
	create, update  the form (name, description, chunk size, overlap) in a dialog                                 KnowledgeBase::create / update
	delete          POST: with its documents and chunks                                                            KnowledgeBase::delete
	manage          one knowledge base: its documents (added, removed), a test search, re-index                    KnowledgeBase::read (changes: update)
	documents       JSON: the documents (and the knowledge base's state; the page polls while some are pending)    KnowledgeBase::read
	add             POST JSON: project files (`files`), a web page (`url`), a text (`title`, `text`, `key`): queued    KnowledgeBase::update
	upload          POST: an uploaded file, saved as a file of the project, queued                                  KnowledgeBase::update + File::create
	remove          POST: a document (`doc`: its id)                                                                KnowledgeBase::update
	search          POST JSON: `query`, `results` -> { results } (the test search)                                  KnowledgeBase::read
	reindex         POST: every chunk embedded again with the account's model (in the daemon)                      KnowledgeBase::update
	settings        the account's embedding model (Settings menu)                                                   Account::update
	connect         POST JSON: the models of the server of the form's provider / URL / key                          Account::update
    Documents added here are indexed by the daemon (KnowledgeBase::queue -> indexJob).
*/

namespace controllers;

use models\KnowledgeBase;
use models\EmbeddingSettings;

class KnowledgeController extends \sys\BaseController {

    //files listed in the "Project files" choice at most (the newest)
    const MAX_FILE_CHOICES = 500;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->project = empty(\V::app()->user->crtProject) ? null : \V::app()->om->findById('Project', \V::app()->user->crtProject);
    }

    //an unsaved knowledge base of the current project (the permission checks of list / create)
    private function proto() {
	if (empty($this->project))
	    \V::app()->error(404, 'No project');
	return \V::app()->om->create('KnowledgeBase', [ 'aid' => \V::app()->var('rt.account')->_id, 'project' => $this->project->_id ]);
    }

    //the knowledge base `id` of the current account and project, if the user has $permName on it (404 / 403 otherwise)
    private function findKb($permName) {
	$oid = $this->getOidOrError();
	$kb = empty($this->project) ? null : \V::app()->om->findOne('KnowledgeBase', [ '_id' => $oid, 'aid' => \V::app()->var('rt.account')->_id, 'project' => $this->project->_id ]);
	if (empty($kb))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $kb ]))
	    \V::app()->error(403, 'Unauthorized');
	return $kb;
    }

    //a POST, else 405
    private function requirePost($req) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('KnowledgeBase::list', [ 'object' => $this->proto() ]))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    $query = [ 'aid' => \V::app()->var('rt.account')->_id, 'project' => $this->project->_id ];
	    $response = [ 'nRecords' => \V::app()->om->count('KnowledgeBase', $query), 'data' => [] ];
	    foreach(\V::app()->om->find('KnowledgeBase', $query, [ 'sort' => [ 'name' => 1 ] ]) as $kb) {
		$state = (string)$kb->state === 'reindexing' ? 'Re-indexing' : ($kb->needsReindex() ? 'Re-index needed' : 'Ready');
		$response['data'][] = [
		    '_id' => (string)$kb->_id,
		    'name' => (string)$kb->name,
		    'documents' => (int)$kb->documents,
		    'chunks' => (int)$kb->chunks,
		    'model' => (string)$kb->model,
		    'state' => $state,
		    'created_at' => $kb->schema['created_at']->display(),
		];
	    }
	    $resp->sendJSON($response);
	}

	$settings = EmbeddingSettings::of(\V::app()->var('rt.account')->_id);
	$this->render('list', compact('settings'));
    }

    public function create($req, $resp) {
	$kb = $this->proto();
	if (!\V::app()->user->can('KnowledgeBase::create', [ 'object' => $kb ]))
	    \V::app()->error(403, 'Unauthorized');
	$settings = EmbeddingSettings::of($kb->aid);

	if ($req->is('post')) {
	    $kb->assign($req->data, true);
	    $kb->model = trim((string)$settings->model);
	    if (!$settings->ready()) {
		$this->addToast('danger', 'Not created', 'Set the account\'s embedding model first (Settings: Knowledge bases)');
		$this->sendEvents();
	    }
	    if ($kb->validates() && $kb->save()) {
		$this->addToast('success', 'OK', 'Knowledge base created');
		$this->addEvent('object::created', [ 'object' => 'KnowledgeBase', 'oid' => (string)$kb->_id ]);
		$this->addEvent('pjax::redirect', [ 'url' => \V::app()->createUrl('/knowledge/manage', [ 'id' => (string)$kb->_id ]) ]);
		$this->sendEvents();
	    }
	}
	$this->render('form', compact('kb', 'settings'));
    }

    public function update($req, $resp) {
	$kb = $this->findKb('KnowledgeBase::update');
	$settings = EmbeddingSettings::of($kb->aid);
	if ($req->is('post')) {
	    $kb->assign($req->data, true);
	    if ($kb->validates() && $kb->save()) {
		$this->addToast('success', 'OK', 'Knowledge base saved');
		$this->addEvent('object::updated', [ 'object' => 'KnowledgeBase', 'oid' => (string)$kb->_id ]);
		$this->sendEvents();
	    }
	}
	$this->render('form', compact('kb', 'settings'));
    }

    public function delete($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::delete');
	$kb->delete();
	$this->addToast('success', 'OK', 'Knowledge base deleted');
	$this->addEvent('object::deleted', [ 'object' => 'KnowledgeBase', 'oid' => null ]);
	if (!empty($req->query['ret']))
	    $this->addEvent('pjax::redirect', [ 'url' => \V::app()->createUrl('/knowledge/list') ]);
	$this->sendEvents();
    }

    public function manage($req, $resp) {
	$kb = $this->findKb('KnowledgeBase::read');
	$canUpdate = \V::app()->user->can('KnowledgeBase::update', [ 'object' => $kb ]);
	$canDelete = \V::app()->user->can('KnowledgeBase::delete', [ 'object' => $kb ]);
	$canUpload = $canUpdate && \V::app()->user->can('File::create', [ 'project' => $this->project ]);
	$settings = EmbeddingSettings::of($kb->aid);
	//the project's files with text (PDF, Office, text), newest first: the "Project files" choice
	$files = [];
	if ($canUpdate) {
	    $text = array_merge([ 'pdf' ], array_keys(\util\OfficeText::TYPES), \models\node\NodeAppAi::TEXT_TYPES);
	    foreach(\V::app()->om->find('File', [ 'metadata.aid' => $kb->aid, 'metadata.project' => $kb->project ], [ 'sort' => [ 'uploadDate' => -1 ], 'limit' => self::MAX_FILE_CHOICES * 2 ]) as $f) {
		if (in_array($f->extension(), $text, true) && count($files) < self::MAX_FILE_CHOICES)
		    $files[(string)$f->_id] = (string)$f->filename;
	    }
	}
	$this->render('manage', compact('kb', 'canUpdate', 'canDelete', 'canUpload', 'settings', 'files'));
    }

    public function documents($req, $resp) {
	$kb = $this->findKb('KnowledgeBase::read');
	$resp->sendJSON([ 'documents' => $kb->documentList(1000), 'kb' => [ 'documents' => (int)$kb->documents, 'chunks' => (int)$kb->chunks, 'state' => (string)$kb->state,
	    'state_message' => (string)$kb->state_message, 'model' => (string)$kb->model, 'dims' => (int)$kb->dims, 'needsReindex' => $kb->needsReindex() ] ]);
    }

    public function add($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::update');
	if (!EmbeddingSettings::of($kb->aid)->ready())
	    $resp->sendJSON([ 'error' => 'Set the account\'s embedding model first (Settings: Knowledge bases)' ], true, 409);
	$data = is_array($req->data) ? $req->data : [];
	$sources = [];
	foreach((array)($data['files'] ?? []) as $id)
	    $sources[] = [ 'type' => 'file', 'file' => (string)$id ];
	if (trim((string)($data['url'] ?? '')) !== '')
	    $sources[] = [ 'type' => 'url', 'url' => trim((string)$data['url']) ];
	if (trim((string)($data['text'] ?? '')) !== '')
	    $sources[] = [ 'type' => 'text', 'title' => (string)($data['title'] ?? ''), 'text' => (string)$data['text'], 'key' => (string)($data['key'] ?? '') ];
	if (!$sources)
	    $resp->sendJSON([ 'error' => 'Nothing to add' ], true, 400);
	$queued = [];
	try {
	    foreach($sources as $s)
		$queued[] = $kb->queue($s);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage(), 'queued' => $queued ], true, 400);
	}
	$resp->sendJSON([ 'queued' => $queued ]);
    }

    //an uploaded file: a file of the project (as the Files page's upload), then queued
    public function upload($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::update');
	if (!\V::app()->user->can('File::create', [ 'project' => $this->project ]))
	    \V::app()->error(403, 'Unauthorized');
	if (!EmbeddingSettings::of($kb->aid)->ready())
	    $resp->sendJSON([ 'error' => 'Set the account\'s embedding model first (Settings: Knowledge bases)' ], true, 409);
	$file = $req->files['file'] ?? null;
	if (empty($file) || ($file['error'] ?? 1) > 0 || !is_uploaded_file($file['tmp_name']))
	    $resp->sendJSON([ 'error' => 'The file was not uploaded' . (empty($file['error']) ? '' : " (error {$file['error']})") ], true, 400);
	if (!\models\File::isAllowedName($file['name']))
	    $resp->sendJSON([ 'error' => 'File type not allowed' ], true, 415);
	$fp = fopen($file['tmp_name'], 'rb');
	try {
	    $id = \models\File::createFromStream($file['name'], $fp, [ 'metadata' => [ 'project' => $this->project->_id, 'owner' => \V::app()->user->_id,
		'aid' => \V::app()->config('rt.account')->_id, 'created_at' => new \MongoDB\BSON\UTCDateTime(), 'source' => 'knowledge' ] ]);
	} catch (\util\LimitException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 413);
	}
	try {
	    $key = $kb->queue([ 'type' => 'file', 'file' => (string)$id ]);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 400);
	}
	$resp->sendJSON([ 'queued' => [ $key ], 'file' => (string)$id ]);
    }

    public function remove($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::update');
	$doc = (string)($req->query['doc'] ?? '');
	$d = \util\Validator::rule('oid', $doc) ? KnowledgeBase::documentCollection()->findOne([ '_id' => new \MongoDB\BSON\ObjectId($doc), 'kb' => $kb->_id ]) : null;
	if (!$d)
	    \V::app()->error(404, 'Not found');
	$kb->removeDocument((string)$d['key']);
	$this->addToast('success', 'OK', 'Document removed');
	$this->addEvent('object::deleted', [ 'object' => 'KbDocument', 'oid' => $doc ]);
	$this->sendEvents();
    }

    public function search($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::read');
	$data = is_array($req->data) ? $req->data : [];
	$start = microtime(true);
	try {
	    $results = $kb->search((string)($data['query'] ?? ''), (int)($data['results'] ?? 5), [], null, 60);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 400);
	}
	$resp->sendJSON([ 'results' => $results, 'ms' => (int)round((microtime(true) - $start) * 1000) ]);
    }

    public function reindex($req, $resp) {
	$this->requirePost($req);
	$kb = $this->findKb('KnowledgeBase::update');
	try {
	    $kb->reindex();
	    $this->addToast('success', 'OK', 'Re-indexing: the chunks are embedded again in the background');
	} catch (\ErrorException $e) {
	    $this->addToast('danger', 'Not re-indexed', $e->getMessage());
	}
	$this->addEvent('object::updated', [ 'object' => 'KnowledgeBase', 'oid' => (string)$kb->_id ]);
	$this->sendEvents();
    }

    // ------------------------------------------------------------------------------------
    //the account's embedding model

    public function settings($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
	$aid = \V::app()->var('rt.account')->_id;
	$settings = EmbeddingSettings::of($aid);

	if ($req->is('post')) {
	    //the saved key belongs to the saved provider and Server URL: another one without a new key starts without it (never sent to another server)
	    $before = [ (string)$settings->provider, trim((string)$settings->url) ];
	    $settings->assign($req->data, true);
	    if ($before !== [ (string)$settings->provider, trim((string)$settings->url) ] && (string)($req->data['api_key'] ?? '') === '')
		$settings->api_key = '';
	    if ($settings->validates() && $settings->save())
		$this->addToast('success', 'OK', 'Knowledge base settings saved');
	    else
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	}

	//the knowledge bases of the account indexed with another model (re-index them)
	$stale = [];
	if ($settings->ready()) {
	    foreach(\V::app()->om->find('KnowledgeBase', [ 'aid' => $aid, 'chunks' => [ '$gt' => 0 ], 'model' => [ '$ne' => trim((string)$settings->model) ] ]) as $kb)
		$stale[] = $kb;
	}
	$this->render('settings', compact('settings', 'stale'));
    }

    //Connect on the settings page (POST, JSON): `provider`, `url`, `api_key` as in the form (not saved yet) => { models: [ { id, label } ] }, or { error }. An empty API key is the
    //saved one, but only for the saved provider and Server URL
    public function connect($req, $resp) {
	$this->requirePost($req);
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
	$data = is_array($req->data) ? $req->data : [];
	$saved = EmbeddingSettings::of(\V::app()->var('rt.account')->_id);
	$provider = (string)($data['provider'] ?? '');
	if (!array_key_exists($provider, EmbeddingSettings::PROVIDERS))
	    $resp->sendJSON([ 'error' => 'Choose a provider' ], true, 400);
	$url = trim((string)($data['url'] ?? ''));
	if ($url !== '' && !preg_match('~^https?://[^\s?#]+$~i', $url))
	    $resp->sendJSON([ 'error' => 'Server URL: an http:// or https:// URL, without ? or #' ], true, 400);
	$key = (string)($data['api_key'] ?? '');
	if ($key === '' && $provider === (string)$saved->provider && $url === trim((string)$saved->url))
	    $key = (string)$saved->api_key;
	$probe = \V::app()->om->create('EmbeddingSettings', [ 'aid' => $saved->aid, 'provider' => $provider, 'url' => $url, 'api_key' => $key ]);
	try {
	    $models = \util\Embeddings::models($probe);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 502);
	}
	if (!$models)
	    $resp->sendJSON([ 'error' => 'Connected, but the server lists no model' ], true, 502);
	$resp->sendJSON([ 'models' => $models ]);
    }
}
