<?php

namespace controllers;

class WorkflowController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function create($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$account = \V::app()->config('rt.account');
	$n = \V::app()->om->count('Workflow', [ 'aid' => $account->_id ]);

	$wf = \V::app()->om->create('Workflow', [
	    'aid' => $account->_id,
	    'name' => 'Workflow ' . ++$n,
	    'project' => \V::app()->user->crtProject,
	]);

	if (!\V::app()->user->can('Workflow::create', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	if (!$wf->validates())
	    \V::app()->error(400, 'Error creating workflow:' . print_r($wf->errors(), true));

	$wf->save();

	$this->addToast('success', 'OK', 'Workflow created!');
	$this->addEvent('sidebar::refresh');

	$this->redirect(\V::app()->createUrl('/workflow/index', [ 'id' => $wf->_id ]));
    }

    public function index($req, $resp) {
	$oid = $this->getOidOrError();
	$account = \V::app()->config('rt.account');
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => $account->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	//CLD: 2026-10-19T18:40:00+03:00: the designer only (GET); removed the form-post save of the canvas: it is saved by the autosave (save())
	if ($req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	//CLD: 2026-10-11T13:51:42+03:00: ?version=<id>: that published version, read-only
	$viewVersion = isset($req->query['version']) ? $this->findVersion($wf) : null;

	//CLD: 2026-10-11T13:51:42+03:00: + the version being viewed
	$this->render('index', compact('wf', 'viewVersion'));
    }

    //CLD: 2026-10-19T18:05:00+03:00: autosave from the designer (POST `nodes`, as the canvas form; each change, app.workflow.js onCommit): the draft is saved even with errors
    //in the steps' settings (validatesDraft), they stop Publish / Run. Answers { saved, nodes (as saved: configs normalized), stepIssues, issues (steps + connections),
    //draftChanged (differs from the published version) }, or { error } when the workflow's own rules refuse it. Workflow::update
    public function save($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');

	$wf->assign([ 'nodes' => $req->data['nodes'] ?? [] ]);
	if (!$wf->validatesDraft()) {
	    $messages = [];
	    foreach((array)$wf->errors() as $list)
		foreach((array)$list as $message)
		    $messages[] = (string)$message;
	    $resp->sendJSON([ 'error' => implode("\n", $messages ?: [ 'Error saving workflow' ]) ], true, 400);
	}
	if (!$wf->save())
	    $resp->sendJSON([ 'error' => 'The workflow could not be saved' ], true, 500);

	$active = $wf->publishedVersion();
	$resp->sendJSON([
	    'saved' => true,
	    'nodes' => array_values(is_array($wf->nodes) ? $wf->nodes : []),
	    'stepIssues' => $wf->stepIssues,
	    'issues' => array_merge($wf->stepIssues, $wf->validateFlow()),
	    'draftChanged' => $active !== null && !$wf->draftMatches($active),
	]);
    }

    //CLD: 2026-10-25T19:30:00+02:00: the errors in the steps' settings (Workflow::validateSteps) of the draft, or of ?version=<id>; asked by the designer once it is shown (were
    //computed in the page: ~0.15-0.5 s on each open of a workflow with Code steps). Answers { stepIssues }. Workflow::read
    public function step_issues($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$shown = isset($req->query['version']) ? $wf->forVersion($this->findVersion($wf)) : $wf;
	$resp->sendJSON([ 'stepIssues' => $shown->validateSteps() ]);
    }

    //CLD: 2026-10-25T20:00:00+02:00: the step definitions of the designer (WfNode::designerDefs), asked once per browser tab and kept there (were in each designer page,
    //~85 KB and more): { nodeDefs, version }
    public function node_defs($req, $resp) {
	$d = \models\WfNode::designerDefs(\V::app()->config('rt.account'));
	$resp->sendJSON([ 'nodeDefs' => (object)$d['defs'], 'version' => $d['version'] ]);
    }

    public function update_name($req, $resp) {
	$oid = $this->getOidOrError();
	$account = \V::app()->config('rt.account');
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => $account->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	if ($req->is('post') && !\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	$renderEmpty = false;
	if ($req->is('post')) {
	    $wf->icon = $req->data['icon'] ?? null;
	    $wf->name = $req->data['name'] ?? null;
	    if ($wf->validates()) {
		$wf->save();
		$this->addToast('success', 'OK', 'Name updated!');
		$this->addEvent('sidebar::refresh');
		$this->sendEvents();
		//$renderEmpty = true;
	    } else {
		$this->addToast('error', 'ERROR', 'Error saving workflow');
	    }
	}

	$this->render('update_name', compact('wf', 'renderEmpty'));
    }

    public function variables($req, $resp) {
	$oid = $this->getOidOrError();
	$account = \V::app()->config('rt.account');
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => $account->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	//CLD: 2026-10-06T23:10:30+03:00: pass the object, so its checkAccess applies (project scope: membership of its project)
	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post') && !\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	$renderEmpty = false;

	$this->render('variables', compact('wf'));
    }


    //CLD: 2026-10-08T08:10:24+03:00: starting config of a new step (WfNode::initConfig), asked by the designer when a step is added;
    //?id=<workflow>&fqname=<type::name>, the user must be allowed to update the workflow
    public function node_init($req, $resp) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	$def = \models\WfNode::getAll()[(string)($req->query['fqname'] ?? '')] ?? null;
	if (empty($def))
	    \V::app()->error(404, 'Unknown step type');

	$class = \V::app()->om->getObjectClass($def['objectName']);
	$resp->sendJSON([ 'config' => (object)$class::initConfig() ]);
    }

    //CLD: 2026-10-10T19:08:46+03:00: manual run from the designer: an execution started at the workflow's trigger, which must be a Manual trigger (trigger
    //`manual`; CLD: 2026-10-12T20:33:47+03:00: now any trigger with startNow(), see below; nothing started -> { execution: null, message }); POST, the user must be allowed to update the workflow; refused when the saved workflow has connection errors (as `wf::run`). Answers { execution } and
    //runs it after the answer is sent (as the webhook endpoint): the designer follows it through executions / execution. Named `start`:
    //BaseController::run() is the request dispatcher
    public function start($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');

	//a workflow has one trigger (Workflow::validateFlow)
	//CLD: 2026-10-12T20:33:47+03:00: any trigger whose class has its own startNow() (was the Manual trigger only): Manual / Schedule start one execution, Email (IMAP)
	//checks the mailbox now (one execution per message); the trigger's class makes the executions, they run after the answer
	$start = null;
	$class = null;
	$defs = \models\WfNode::getAll();
	foreach((is_array($wf->nodes) ? $wf->nodes : []) as $n) {
	    if (empty($n['isNode']) || empty($n['wfid']))
		continue;
	    $def = $defs[$n['fqname'] ?? ''] ?? null;
	    if (($def['type'] ?? '') === 'trigger' && !empty($def['canRun'])) {
		$start = $n;
		$class = \V::app()->om->getObjectClass($def['objectName']);
	    }
	}
	if ($start === null)
	    $resp->sendJSON([ 'error' => 'Run cannot start this workflow: its trigger is not started from here' ], true, 409);

	//CLD: 2026-10-19T17:10:00+03:00: issues() (was validateFlow): a draft can be saved with errors in the steps' settings, it is not run with them
	if (array_filter($wf->issues(), fn($i) => $i['level'] == 'error'))
	    $resp->sendJSON([ 'error' => 'The workflow has errors (steps\' settings or connections): fix them and save it first' ], true, 409);

	try {
	    $started = $class::startNow($wf, $start);
	} catch (\Exception $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 409);
	}
	$executions = $started['executions'] ?? [];
	foreach($executions as $exe) {
	    $exe->log(null, 'started by ' . (string)\V::app()->user->name);
	    $exe->save();
	    //CLD: 2026-10-11T17:35:22+03:00: audit log (executions themselves are not recorded)
	    \models\AuditLog::record('workflow.run', $wf, 'execution ' . (string)$exe->_id);
	}
	if (empty($executions))
	    //CLD: 2026-10-18T12:49:28+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'execution' => null, 'executions' => [], 'message' => (string)($started['message'] ?? 'Nothing was started') ]);

	//answer now, run after the connection is closed (php-fpm)
	//CLD: 2026-10-12T20:33:47+03:00: + executions (all ids, first = execution) and message
	//CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON without ending (was status + headers + body set here); the answer is sent below
	$resp->sendJSON([ 'execution' => (string)$executions[0]->_id, 'executions' => array_map(fn($e) => (string)$e->_id, $executions),
	    'message' => $started['message'] ?? null ], false);
	//CLD: 2026-10-18T18:34:20+03:00: output buffers are no longer used by the framework (was: ob_end_clean() of every level)
	$resp->output();
	flush();
	session_write_close();
	ignore_user_abort(true);
	if (function_exists('fastcgi_finish_request'))
	    fastcgi_finish_request();

	//CLD: 2026-10-17T19:29:50+03:00: sync executions (handed to the daemon when the account's are all running)
	foreach($executions as $exe)
	    $exe->runSync();
	exit(0);
    }

    //CLD: 2026-10-11T13:51:42+03:00: publish (was a JSON draft/published switch, 2026-10-11T13:29:36+03:00): GET shows the dialog, POST publishes. The saved draft
    //becomes a new version (models\WfVersion, v<n>, optional note), or ?version=<id> publishes an older version again as a new number; when the draft has
    //not changed since the version in `version`, that version is made active again (no new number). The workflow's triggers then run that version.
    //Refused while the steps to publish have connection errors. Workflow::update
    //CLD: 2026-10-11T19:47:51+03:00: Workflow::publish (was Workflow::update)
    public function publish($req, $resp) {
	$wf = $this->findWorkflow('Workflow::publish');
	$source = isset($req->query['version']) ? $this->findVersion($wf) : null;
	$active = $wf->publishedVersion();
	$reuse = $source === null && $active !== null && $wf->draftMatches($active);
	$check = $source ? $wf->forVersion($source) : $wf;
	//CLD: 2026-10-19T17:10:00+03:00: issues() (was validateFlow): a draft can be saved with errors in the steps' settings, it is not published with them
	$errors = array_values(array_filter($check->issues(), fn($i) => $i['level'] == 'error'));

	if ($req->is('post')) {
	    $params = [ 'id' => (string)$wf->_id ];
	    if ($errors) {
		$this->addToast('error', 'Not published', 'The workflow has errors (steps\' settings or connections): fix them and save it first');
		$this->redirect('/workflow/index', $params);
	    }

	    $version = $active;
	    if (!$reuse) {
		$version = \V::app()->om->create('WfVersion', [
		    'aid' => $wf->aid,
		    'project' => $wf->project,
		    'wf' => $wf->_id,
		    'number' => $wf->nextVersionNumber(),
		    'name' => (string)$wf->name,
		    'nodes' => $source ? $source->nodes : $wf->nodes,
		    'note' => mb_substr(trim((string)($req->data['note'] ?? '')), 0, 1000),
		    'from_number' => $source ? (int)$source->number : null,
		    'published_by' => \V::app()->user->_id,
		]);
		if (!$version->validates() || !$version->save()) {
		    $this->addToast('error', 'Not published', 'The version could not be saved');
		    $this->redirect('/workflow/index', $params);
		}
	    }

	    //the workflow's own fields only (its steps were checked above)
	    $wf->version = $version->_id;
	    $wf->status = \models\Workflow::PUBLISHED;
	    if (!$wf->save()) {
		$this->addToast('error', 'Not published', 'The workflow could not be saved');
		$this->redirect('/workflow/index', $params);
	    }
	    $this->addToast('success', 'Published', (string)$version . ' is published: its trigger starts runs of this version');
	    $this->addEvent('sidebar::refresh');
	    $this->redirect('/workflow/index', $params);
	}

	$next = (int)$wf->version_count + 1;
	$this->render('publish', compact('wf', 'source', 'active', 'reuse', 'errors', 'next'));
    }

    //CLD: 2026-10-11T13:51:42+03:00: stop the triggers (POST): the workflow goes back to draft; its versions and `version` are kept. Workflow::update
    public function unpublish($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::publish');
	$wf->status = \models\Workflow::DRAFT;
	if ($wf->save())
	    $this->addToast('success', 'Unpublished', 'The trigger of this workflow no longer starts runs');
	else
	    $this->addToast('error', 'Not changed', 'The workflow could not be saved');
	$this->addEvent('sidebar::refresh');
	$this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
    }

    //CLD: 2026-10-11T13:51:42+03:00: the versions dialog (newest first): view, publish again, copy into the draft. Workflow::read
    public function versions($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$versions = iterator_to_array(\V::app()->om->find('WfVersion', [ 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'sort' => [ 'number' => -1 ], 'projection' => [ 'nodes' => 0 ] ]), false);

	$names = [];
	$userIds = array_values(array_filter(array_map(fn($v) => $v->published_by, $versions)));
	if ($userIds) {
	    foreach(\V::app()->om->find('User', [ '_id' => [ '$in' => $userIds ] ]) as $u)
		$names[(string)$u->_id] = (string)$u->name;
	}
	$canUpdate = \V::app()->user->can('Workflow::update', [ 'object' => $wf ]);
	//CLD: 2026-10-11T19:47:51+03:00: publish again: Workflow::publish
	$canPublish = \V::app()->user->can('Workflow::publish', [ 'object' => $wf ]);
	$this->render('versions', compact('wf', 'versions', 'names', 'canUpdate', 'canPublish'));
    }

    //CLD: 2026-10-11T14:01:52+03:00: duplicate (POST): a new draft workflow in the same project with the saved draft's steps (or ?version=<id>: that version's), named
    //"Copy of ...", no versions, not published. Steps get new values from their initConfig() (values unique to a step, e.g. the webhook token: the copy
    //must not answer the original's URL). Workflow::read on the original, Workflow::create in its project
    public function duplicate($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::read');
	$source = isset($req->query['version']) ? $this->findVersion($wf) : null;

	$defs = \models\WfNode::getAll();
	$from = $source ? $source->nodes : $wf->nodes;
	$nodes = [];
	foreach((is_array($from) ? $from : []) as $key => $n) {
	    if (!empty($n['isNode']) && !empty($defs[$n['fqname'] ?? '']['hasInitConfig'])) {
		$class = \V::app()->om->getObjectClass($defs[$n['fqname']]['objectName']);
		$n['config'] = array_merge(is_array($n['config'] ?? null) ? $n['config'] : [], $class::initConfig());
	    }
	    $nodes[$key] = $n;
	}

	$copy = \V::app()->om->create('Workflow', [
	    'aid' => $wf->aid,
	    'project' => $wf->project,
	    'name' => 'Copy of ' . (string)$wf->name . ($source ? ' (' . (string)$source . ')' : ''),
	    'icon' => $wf->icon,
	    'nodes' => $nodes,
	]);
	if (!\V::app()->user->can('Workflow::create', [ 'object' => $copy ]))
	    \V::app()->error(403, 'Unauthorized');
	if (!$copy->validates() || !$copy->save()) {
	    $messages = [];
	    foreach((array)$copy->errors() as $list)
		foreach((array)$list as $message)
		    $messages[] = $message;
	    $this->addToast('error', 'Not duplicated', implode("\n", $messages ?: [ 'The copy could not be saved' ]));
	    $this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
	}

	$this->addToast('success', 'Duplicated', 'This is the copy: a draft, not published');
	$this->addEvent('sidebar::refresh');
	$this->redirect('/workflow/index', [ 'id' => (string)$copy->_id ]);
    }

    //CLD: 2026-10-11T14:05:19+03:00: delete (POST): the workflow, its executions and versions (Workflow::beforeDelete; plugins: webhook index, IMAP state). Workflow::delete
    public function delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::delete');
	$name = (string)$wf->name;
	if (!$wf->delete()) {
	    $this->addToast('error', 'Not deleted', 'The workflow could not be deleted');
	    $this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
	}
	$this->addToast('success', 'Deleted', 'Workflow `' . $name . '` deleted');
	$this->addEvent('sidebar::refresh');
	$this->redirect('/site/index');
    }

    //CLD: 2026-10-11T13:51:42+03:00: copy a version's steps into the draft (POST ?version=<id>); the published version is not changed. Workflow::update
    public function restore($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$version = $this->findVersion($wf);

	$wf->nodes = is_array($version->nodes) ? $version->nodes : [];
	if ($wf->validates() && $wf->save()) {
	    $this->addToast('success', 'Draft replaced', 'The draft now has the steps of ' . (string)$version);
	} else {
	    $messages = [];
	    foreach((array)$wf->errors() as $list)
		foreach((array)$list as $message)
		    $messages[] = $message;
	    $this->addToast('error', 'Draft not replaced', implode("\n", $messages ?: [ 'The workflow could not be saved' ]));
	}
	$this->addEvent('sidebar::refresh');
	$this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
    }

    //CLD: 2026-10-10T19:08:46+03:00: the workflow's latest executions (newest first, at most 30) for the designer's Executions panel
    public function executions($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');

	$list = [];
	$exes = \V::app()->om->find('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'sort' => [ '_id' => -1 ], 'limit' => 30, 'projection' => [ 'nodes' => 0, 'log' => 0, 'variables' => 0, 'tokens' => 0, 'input' => 0 ] ]);
	foreach($exes as $exe)
	    $list[] = $this->executionSummary($exe);
	$resp->sendJSON([ 'executions' => $list ]);
    }

    //CLD: 2026-10-10T19:08:46+03:00: one execution of the workflow: summary + variables + log (each entry with the title of its step)
    public function execution($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$eid = (string)($req->query['execution'] ?? '');
	if (!\util\Validator::rule('oid', $eid))
	    \V::app()->error(400, 'malformed request: not oid');
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	if (empty($exe))
	    \V::app()->error(404, 'Not found');

	$titles = [];
	foreach((is_array($exe->nodes) ? $exe->nodes : []) as $wfid => $n) {
	    if (!empty($n['isNode']))
		$titles[$n['wfid'] ?? $wfid] = (string)($n['title'] ?? '');
	}
	$log = [];
	foreach((is_array($exe->log) ? $exe->log : []) as $entry) {
	    $node = $entry['node'] ?? null;
	    $log[] = [
		'time' => $this->localTime($entry['ts'] ?? null, 'Y-m-d H:i:s'),
		'node' => $node,
		'title' => $node !== null ? ($titles[$node] ?? $node) : null,
		'message' => (string)($entry['message'] ?? ''),
	    ];
	}
	//CLD: 2026-10-18T12:49:28+03:00: $resp->sendJSON (was $this->json, removed)
	$resp->sendJSON([ 'execution' => $this->executionSummary($exe) + [ 'variables' => (object)(is_array($exe->variables) ? $exe->variables : []), 'log' => $log ] ]);
    }

    //CLD: 2026-10-23T23:00:00+03:00: delete an execution of the draft (POST ?execution=<id>; the Executions panel): a designer Run / console run, not one of a
    //published version, and not one still running or sleeping (canRun). Its entries in the execution log are kept. Answers { deleted }. Workflow::update (as Run)
    public function execution_delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$eid = (string)($req->query['execution'] ?? '');
	if (!\util\Validator::rule('oid', $eid))
	    \V::app()->error(400, 'malformed request: not oid');
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'version' => 1, 'state' => 1 ] ]);
	if (empty($exe))
	    \V::app()->error(404, 'Not found');
	if (is_numeric($exe->version))
	    $resp->sendJSON([ 'error' => 'Only executions of the draft can be deleted' ], true, 409);
	if ($exe->canRun())
	    $resp->sendJSON([ 'error' => 'The execution has not finished' ], true, 409);
	if (!$exe->delete())
	    $resp->sendJSON([ 'error' => 'The execution could not be deleted' ], true, 500);
	//CLD: 2026-10-24T00:04:00+03:00: audit log (executions are not recorded by themselves; SOC2.md gap 4)
	\models\AuditLog::record('workflow.execution.delete', $wf, 'execution ' . $eid);
	$resp->sendJSON([ 'deleted' => $eid ]);
    }

    //CLD: 2026-10-23T23:30:00+03:00: delete all executions of the draft (POST; the Executions panel's trash button): as execution_delete, those of a published
    //version and those still running or sleeping are kept. Answers { deleted: <count>, kept: <count> }. Workflow::update (as Run)
    public function executions_delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$deleted = 0;
	$failed = 0;
	$exes = \V::app()->om->find('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid, 'version' => null, 'state' => [ '$nin' => [ 'new', 'run', 'sleep' ] ] ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'version' => 1, 'state' => 1 ] ]);
	foreach($exes as $exe) {
	    if ($exe->delete())
		$deleted++;
	    else
		$failed++;
	}
	//CLD: 2026-10-24T00:04:00+03:00: audit log: how many were deleted (SOC2.md gap 4)
	if ($deleted)
	    //CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was the plural inline)
	    \models\AuditLog::record('workflow.executions.delete', $wf, \util\Format::plural($deleted, 'execution') . ' of the draft' . ($failed ? ", {$failed} not deleted" : ''));
	$kept = \V::app()->om->count('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	if ($failed && !$deleted)
	    $resp->sendJSON([ 'error' => 'The executions could not be deleted' ], true, 500);
	$resp->sendJSON([ 'deleted' => $deleted, 'kept' => $kept ]);
    }

    //CLD: 2026-10-11T22:38:36+03:00: live help of an expression field (POST, JSON): the problems of `text` (util\Expression::check) and, with `before` (the text up to the cursor),
    //the suggestions there (util\Expression::complete); the variables are those available at step `node` (Workflow::variablesAt) of `nodes` (the designer's
    //current steps, JSON; else the saved ones). Workflow::read
    public function expression($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::read');
	$data = is_array($req->data) ? $req->data : [];
	$text = (string)($data['text'] ?? '');
	if (strlen($text) > 20000)
	    //CLD: 2026-10-18T12:49:28+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'problems' => [], 'completion' => null ]);

	$nodes = isset($data['nodes']) ? json_decode((string)$data['nodes'], true) : null;
	if (!is_array($nodes))
	    $nodes = is_array($wf->nodes) ? $wf->nodes : [];
	$vars = \models\Workflow::variablesAt($nodes, (string)($data['node'] ?? ''));
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables ({{ vars.<name> }}: suggested, a name the project does not have flagged)
	//CLD: 2026-10-22T00:28:13+03:00: from the credentials of type Variable (was models\Variable)
	$vars[\models\credential\CredentialVariable::EXPRESSION_NAME] = \models\credential\CredentialVariable::expressionType($wf->aid, $wf->project);

	//CLD: 2026-10-11T23:02:07+03:00: mode `name`: a variable name field (suggestions of the variables; usage `read` warns about a name no step before sets)
	if (($data['mode'] ?? '') === 'name') {
	    $response = [ 'problems' => ($data['usage'] ?? '') === 'read' ? \util\Expression::checkName($text, $vars) : [], 'completion' => null ];
	    if (isset($data['before']))
		$response['completion'] = \util\Expression::completeName((string)$data['before'], $vars);
	    $resp->sendJSON($response);
	}

	//CLD: 2026-10-13T12:17:47+03:00: the field's syntax (`syntax`: text | reference, util\Expression::SYNTAX; default text)
	$syntax = array_key_exists((string)($data['syntax'] ?? ''), \util\Expression::SYNTAX) ? (string)$data['syntax'] : 'text';
	$response = [ 'problems' => \util\Expression::check($text, $vars, $syntax), 'completion' => null ];
	if (isset($data['before']))
	    $response['completion'] = \util\Expression::complete($text, (string)$data['before'], $vars, $syntax);
	$resp->sendJSON($response);
    }

    //CLD: 2026-10-19T20:10:00+03:00: assistant() moved into the Assistant plugin (plugins\Assistant\controllers\AssistantController::ask)

    //CLD: 2026-10-11T13:51:42+03:00: version ?version=<id> of workflow $wf, else 400 / 404
    private function findVersion($wf) {
	$vid = (string)(\V::app()->request->query['version'] ?? '');
	if (!\util\Validator::rule('oid', $vid))
	    \V::app()->error(400, 'malformed request: not oid');
	$version = \V::app()->om->findOne('WfVersion', [ '_id' => $vid, 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	if (empty($version))
	    \V::app()->error(404, 'Not found');
	return $version;
    }

    //the workflow `id` of the current account, if the user has $permName on it
    private function findWorkflow($permName) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	return $wf;
    }

    private function executionSummary($exe) {
	$start = $exe->start_at instanceof \MongoDB\BSON\UTCDateTime ? $exe->start_at : null;
	$end = $exe->end_at instanceof \MongoDB\BSON\UTCDateTime ? $exe->end_at : null;
	$created = $exe->created_at instanceof \MongoDB\BSON\UTCDateTime ? $exe->created_at : null;
	//milliseconds; a running execution counts until now
	$duration = $start ? (int)(string)($end ?? new \MongoDB\BSON\UTCDateTime()) - (int)(string)$start : null;
	return [
	    'id' => (string)$exe->_id,
	    'state' => (string)$exe->state,
	    'trigger' => (string)$exe->trigger,
	    //CLD: 2026-10-11T13:51:42+03:00: published version number, null = the draft
	    'version' => is_numeric($exe->version) ? (int)$exe->version : null,
	    'created' => $this->localTime($created),
	    'started' => $this->localTime($start),
	    'ended' => $this->localTime($end),
	    'duration' => $duration,
	    'wake_at' => $this->localTime($exe->wake_at),
	    'error' => $exe->error ? (string)$exe->error : null,
	];
    }

    //Y-m-d H:i:s in the app's timezone; null when not a date
    //CLD: 2026-10-24T16:30:00+03:00: util\Format::datetime: the account's datetime format, or $format (the log's times: with seconds, as before)
    private function localTime($value, $format = null) {
	if (!$value instanceof \MongoDB\BSON\UTCDateTime)
	    return null;
	return \util\Format::datetime($value, $format);
    }

    public function node_edit($req, $resp) {
	$node_data = $req->data ?? [];
	$this->render('node_edit', compact('node_data'));
    }

}