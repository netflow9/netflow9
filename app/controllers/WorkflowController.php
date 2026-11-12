<?php

namespace controllers;

class WorkflowController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	//the request's account (rt.account, set at sign-in), for the actions
	$this->account = \V::app()->config('rt.account');
    }

    public function create($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$n = \V::app()->om->count('Workflow');

	$wf = \V::app()->om->create('Workflow', [
	    'aid' => $this->account->_id,
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
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	//the designer only (GET): the canvas is saved by the autosave (save())
	if ($req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	//?version=<id>: that published version, read-only
	$viewVersion = isset($req->query['version']) ? $this->findVersion($wf) : null;

	//with the version being viewed
	$this->render('index', compact('wf', 'viewVersion'));
    }

    //autosave from the designer (POST `nodes`, as the canvas form; each change, app.workflow.js onCommit): the draft is saved even with errors
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

    //the errors in the steps' settings (Workflow::validateSteps) of the draft, or of ?version=<id>; asked by the designer once it is shown (computing them in the page
    //takes ~0.15-0.5 s for a workflow with Code steps). Answers { stepIssues }. Workflow::read
    public function step_issues($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$shown = isset($req->query['version']) ? $wf->forVersion($this->findVersion($wf)) : $wf;
	$resp->sendJSON([ 'stepIssues' => $shown->validateSteps() ]);
    }

    //the step definitions of the designer (WfNode::designerDefs), asked once per browser tab and kept there
    //(~85 KB and more): { nodeDefs, version }
    public function node_defs($req, $resp) {
	$d = \models\wf\WfNode::designerDefs($this->account);
	//with what the canvas needs from the forms (the forms themselves: node_schema)
	$resp->sendJSON([ 'nodeDefs' => (object)\models\wf\WfNode::withCanvasHints($d['defs']), 'version' => $d['version'] ]);
    }

    //the form of step type ?fqname, with the choices of the current project (credentials, tables, workflows, ...), asked each time a step's dialog
    //opens: what was added since is offered (they were in node_defs, kept by the tab). Answers { configSchema }. Workflow::read
    public function node_schema($req, $resp) {
	$this->findWorkflow('Workflow::read');
	$def = \models\wf\WfNode::getAll()[(string)($req->query['fqname'] ?? '')] ?? null;
	if (empty($def))
	    \V::app()->error(404, 'Unknown step type');
	$resp->sendJSON([ 'configSchema' => \models\wf\WfNode::schemaOf(\V::app()->om->getObjectClass($def['objectName'])) ]);
    }

    public function update_name($req, $resp) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid ]);
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
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	//pass the object, so its checkAccess applies (project scope: membership of its project)
	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post') && !\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	$renderEmpty = false;

	$this->render('variables', compact('wf'));
    }


    //starting config of a new step (WfNode::initConfig), asked by the designer when a step is added;
    //?id=<workflow>&fqname=<type::name>, the user must be allowed to update the workflow
    public function node_init($req, $resp) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('Workflow::update', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');

	$def = \models\wf\WfNode::getAll()[(string)($req->query['fqname'] ?? '')] ?? null;
	if (empty($def))
	    \V::app()->error(404, 'Unknown step type');

	$class = \V::app()->om->getObjectClass($def['objectName']);
	$resp->sendJSON([ 'config' => (object)$class::initConfig() ]);
    }

    //run from the designer: an execution started at the workflow's trigger (any trigger with startNow(), see below; nothing started -> { execution: null, message }); POST,
    //the user must be allowed to update the workflow; refused when the saved workflow has connection errors (as `sys::run`). Answers { execution, executions, message };
    //the daemon runs it (queued), the designer follows it through executions / execution. Named `start`: BaseController::run() is the request dispatcher
    public function start($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');

	//a workflow has one trigger (Workflow::validateFlow)
	//any trigger whose class has its own startNow(): Manual / Schedule start one execution, Email (IMAP)
	//checks the mailbox now (one execution per message); the trigger's class makes the executions, they run after the answer
	$start = null;
	$class = null;
	$defs = \models\wf\WfNode::getAll();
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

	//issues(): a draft can be saved with errors in the steps' settings, it is not run with them
	if (array_filter($wf->issues(), fn($i) => $i['level'] == 'error'))
	    $resp->sendJSON([ 'error' => 'The workflow has errors (steps\' settings or connections): fix them and save it first' ], true, 409);

	try {
	    $started = $class::startNow($wf, $start);
	} catch (\Exception $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 409);
	}
	$executions = $started['executions'] ?? [];
	//each one queued for the daemon (saved)
	foreach($executions as $exe) {
	    $exe->log(null, 'started by ' . (string)\V::app()->user->name);
	    $exe->queue();
	    //audit log (executions themselves are not recorded)
	    \models\AuditLog::record('workflow.run', $wf, 'execution ' . (string)$exe->_id);
	}
	if (empty($executions))
	    $resp->sendJSON([ 'execution' => null, 'executions' => [], 'message' => (string)($started['message'] ?? 'Nothing was started') ]);

	//executions: all ids, the first = execution
	$resp->sendJSON([ 'execution' => (string)$executions[0]->_id, 'executions' => array_map(fn($e) => (string)$e->_id, $executions),
	    'message' => $started['message'] ?? null ]);
    }

    //publish: GET shows the dialog, POST publishes. The saved draft
    //becomes a new version (models\wf\WfVersion, v<n>, optional note), or ?version=<id> publishes an older version again as a new number; when the draft has
    //not changed since the version in `version`, that version is made active again (no new number). The workflow's triggers then run that version.
    //Refused while the steps to publish have connection errors. Workflow::publish
    public function publish($req, $resp) {
	$wf = $this->findWorkflow('Workflow::publish');
	$source = isset($req->query['version']) ? $this->findVersion($wf) : null;
	$active = $wf->publishedVersion();
	$reuse = $source === null && $active !== null && $wf->draftMatches($active);
	$check = $source ? $wf->forVersion($source) : $wf;
	//issues(): a draft can be saved with errors in the steps' settings, it is not published with them
	$errors = array_values(array_filter($check->issues(), fn($i) => $i['level'] == 'error'));
	//the tests of the draft (util\AgentEval::gate); when Tests must pass to publish is on, a new version of the draft needs them passed. Not an older version or an unchanged draft published again: a way back stays open
	$gate = \util\AgentEval::gate($wf);
	$blocked = \util\AgentEval::blocks($gate, $source, $reuse);
	//shared, and this changes its inputs or outputs: the workflows of other projects that call it (the dialog says)
	$callers = [];
	if (!empty((array)$wf->shares) && $active && self::callContractOf($check->nodes) != self::callContractOf($active->nodes))
	    $callers = array_column($wf->consumers(), 'name');

	if ($req->is('post')) {
	    $params = [ 'id' => (string)$wf->_id ];
	    if ($errors) {
		$this->addToast('error', 'Not published', 'The workflow has errors (steps\' settings or connections): fix them and save it first');
		$this->redirect('/workflow/index', $params);
	    }
	    if ($blocked) {
		$this->addToast('error', 'Not published', 'Tests must pass to publish: ' . $gate['message']);
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
	    //the steps' own publishing work (e.g. GravityZone event: its push service), their messages as toasts; the triggers of the version replaced that the new
	    //one does not have: their unpublishing work
	    foreach(self::publishHooks($wf, $version, $active) as $message)
		$this->addToast('info', 'Published', $message);
	    $this->addEvent('sidebar::refresh');
	    $this->redirect('/workflow/index', $params);
	}

	$next = (int)$wf->version_count + 1;
	//with the tests
	$this->render('publish', compact('wf', 'source', 'active', 'reuse', 'errors', 'next', 'gate', 'blocked', 'callers'));
    }

    //what callers of a workflow with steps $nodes rely on (its Call trigger's inputs and outputs), without the step id and the description
    private static function callContractOf($nodes) {
	return array_diff_key((array)\models\node\NodeTriggerCall::contractOf($nodes), [ 'wfid' => 1, 'description' => 1 ]);
    }

    //stop the triggers (POST): the workflow goes back to draft; its versions and `version` are kept. Workflow::update
    public function unpublish($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::publish');
	$wf->status = \models\Workflow::DRAFT;
	if ($wf->save()) {
	    $this->addToast('success', 'Unpublished', 'The trigger of this workflow no longer starts runs');
	    //shared: calls from the other projects fail from now on
	    if (!empty((array)$wf->shares))
		$this->addToast('warning', 'Shared workflow', 'Other projects can no longer call it until it is published again');
	    //the steps' own unpublishing work (e.g. GravityZone event: its push service off)
	    foreach(self::publishHooks($wf, null, $wf->publishedVersion()) as $message)
		$this->addToast('info', 'Unpublished', $message);
	}
	else
	    $this->addToast('error', 'Not changed', 'The workflow could not be saved');
	$this->addEvent('sidebar::refresh');
	$this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
    }

    //the steps of $new (a version published; null: unpublished) with an onPublish($wf, $node), and those of $old (the version replaced) not in $new with an
    //onUnpublish($wf, $node), called; their messages (an error stops nothing: the workflow is published / unpublished already)
    private static function publishHooks($wf, $new, $old) {
	$messages = [];
	$kept = [];
	foreach(($new && is_array($new->nodes)) ? $new->nodes : [] as $n) {
	    $class = self::nodeClassOf($n);
	    if ($class && method_exists($class, 'onPublish')) {
		$kept[(string)($n['config']['token'] ?? $n['wfid'] ?? '')] = true;
		$messages[] = $class::onPublish($wf, $n);
	    }
	}
	foreach(($old && is_array($old->nodes)) ? $old->nodes : [] as $n) {
	    $class = self::nodeClassOf($n);
	    if ($class && method_exists($class, 'onUnpublish') && !isset($kept[(string)($n['config']['token'] ?? $n['wfid'] ?? '')]))
		$messages[] = $class::onUnpublish($wf, $n);
	}
	return array_values(array_filter($messages, fn($m) => is_string($m) && $m !== ''));
    }

    //the class of step $n of a workflow's nodes (null: a connection, or a step type that is gone)
    private static function nodeClassOf($n) {
	$def = empty($n['isNode']) ? null : (\models\wf\WfNode::getAll()[$n['fqname'] ?? ''] ?? null);
	return $def ? \V::app()->om->getObjectClass($def['objectName']) : null;
    }

    //the versions dialog (newest first): view, publish again, copy into the draft. Workflow::read
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
	//publish again: Workflow::publish
	$canPublish = \V::app()->user->can('Workflow::publish', [ 'object' => $wf ]);
	$this->render('versions', compact('wf', 'versions', 'names', 'canUpdate', 'canPublish'));
    }

    //duplicate (POST): a new draft workflow in the same project with the saved draft's steps (or ?version=<id>: that version's), named
    //"Copy of ...", no versions, not published. Steps get new values from their initConfig() (values unique to a step, e.g. the webhook token: the copy
    //must not answer the original's URL). Workflow::read on the original, Workflow::create in its project
    public function duplicate($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::read');
	$source = isset($req->query['version']) ? $this->findVersion($wf) : null;

	$defs = \models\wf\WfNode::getAll();
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

    //what a shared workflow offers, from its published version: description, inputs, outputs, step names (Workflow::viewShared; a dialog). Not its settings: they can hold typed secrets
    public function shared($req, $resp) {
	$wf = $this->findWorkflow('Workflow::viewShared');
	$live = $wf->liveCopy();
	$contract = \models\node\NodeTriggerCall::contractOf($live?->nodes);
	$defs = \models\wf\WfNode::getAll();
	$steps = [];
	foreach((is_array($live?->nodes) ? $live->nodes : []) as $n) {
	    if (is_array($n) && !empty($n['isNode']))
		$steps[] = [ 'title' => (string)($n['title'] ?? ''), 'type' => (string)($defs[$n['fqname'] ?? '']['label'] ?? '') ];
	}
	$this->render('shared', compact('wf', 'live', 'contract', 'steps'));
    }

    //delete (POST): the workflow, its executions and versions (Workflow::beforeDelete; plugins: webhook index, IMAP state). Workflow::delete
    public function delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::delete');
	$name = (string)$wf->name;
	//not while it is shared: ending each share (the Share dialog) shows which workflows of the other project call it
	if (!empty((array)$wf->shares)) {
	    $this->addToast('error', 'Not deleted', 'This workflow is shared with other projects: stop sharing it first');
	    $this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
	}
	if (!$wf->delete()) {
	    $this->addToast('error', 'Not deleted', 'The workflow could not be deleted');
	    $this->redirect('/workflow/index', [ 'id' => (string)$wf->_id ]);
	}
	$this->addToast('success', 'Deleted', 'Workflow `' . $name . '` deleted');
	$this->addEvent('sidebar::refresh');
	$this->redirect('/site/index');
    }

    //copy a version's steps into the draft (POST ?version=<id>); the published version is not changed. Workflow::update
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

    //the workflow's latest executions (newest first, at most 30) for the designer's Executions panel
    public function executions($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');

	$list = [];
	//not those of test runs (the Tests panel links to them)
	$exes = \V::app()->om->find('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid, 'test_run' => null ], [ 'sort' => [ '_id' => -1 ], 'limit' => 30, 'projection' => [ 'nodes' => 0, 'log' => 0, 'variables' => 0, 'tokens' => 0, 'input' => 0 ] ]);
	foreach($exes as $exe)
	    $list[] = $this->executionSummary($exe);
	$resp->sendJSON([ 'executions' => $list ]);
    }

    //one execution of the workflow: summary + variables + log (each entry with the title of its step)
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
		'time' => \util\Format::datetime($entry['ts'] ?? null, 'Y-m-d H:i:s'),
		'node' => $node,
		'title' => $node !== null ? ($titles[$node] ?? $node) : null,
		'message' => (string)($entry['message'] ?? ''),
	    ];
	}
	//run: where each step and connection is (the designer shows it on the canvas: runStatus); traces: its AI steps' visits without their rounds (execution_trace), traces_total
	[ $traces, $tracesTotal ] = \models\agent\AgentTrace::ofExecution($exe);
	$resp->sendJSON([ 'execution' => $this->executionSummary($exe) + [ 'variables' => (object)(is_array($exe->variables) ? $exe->variables : []), 'log' => $log, 'run' => $this->runStatus($exe),
	    'traces' => array_map(fn($t) => $this->traceSummary($t, $exe), $traces), 'traces_total' => $tracesTotal ] ]);
    }

    //one trace of an execution, its rounds and calls, for the timeline of the Executions panel (?execution=<id>&trace=<id>). Workflow::read, as the execution
    public function execution_trace($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$eid = (string)($req->query['execution'] ?? '');
	if (!\util\Validator::rule('oid', $eid))
	    \V::app()->error(400, 'malformed request: not oid');
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'state' => 1 ] ]);
	$trace = $exe ? \models\agent\AgentTrace::read((string)($req->query['trace'] ?? ''), $wf->aid, $exe) : null;
	if (!$trace)
	    \V::app()->error(404, 'Not found');
	$rounds = array_map(function($round) {
	    $round['calls'] = array_map(function($call) {
		if (isset($call['approval']['at']))
		    $call['approval']['at'] = \util\Format::datetime($call['approval']['at'], 'Y-m-d H:i:s');
		return $call;
	    }, array_values((array)($round['calls'] ?? [])));
	    return $round;
	}, array_values((array)($trace['rounds'] ?? [])));
	$resp->sendJSON([ 'trace' => $this->traceSummary($trace, $exe) + [ 'rounds' => $rounds ] ]);
    }

    //a link to a trace (?id=<trace_id>, e.g. from the variable of the AI step in a later step's message): the designer, its Executions panel on it. Workflow::read
    public function trace($req, $resp) {
	$trace = \models\agent\AgentTrace::read((string)($req->query['id'] ?? ''), $this->account->_id);
	if (!$trace)
	    \V::app()->error(404, 'This agent trace is no longer kept');
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $trace['wf'], 'aid' => $trace['aid'] ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('Workflow::read', [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	$resp->redirect(\V::app()->createUrl('/workflow/index', [ 'id' => $wf->_id, 'execution' => $trace['execution'], 'trace' => $trace['_id'] ]));
    }

    //a trace as the panel shows it: an open one of an execution that no longer runs (its process died) is stopped
    private function traceSummary(array $t, $exe) {
	$state = (string)($t['state'] ?? '');
	if (in_array($state, \models\agent\AgentTrace::OPEN, true) && !$exe->canRun())
	    $state = 'stopped';
	$start = $t['started_at'] ?? null;
	$end = $t['ended_at'] ?? null;
	$totals = (array)($t['totals'] ?? []);
	return [
	    'id' => (string)$t['_id'],
	    'node' => (string)($t['node'] ?? ''),
	    'step' => (string)($t['step'] ?? ''),
	    'visit' => (int)($t['visit'] ?? 1),
	    'state' => $state,
	    'error' => isset($t['error']) ? (string)$t['error'] : null,
	    'provider' => (string)($t['provider'] ?? ''),
	    'model' => (string)($t['model'] ?? ''),
	    'system' => !empty($t['system']),
	    'prices' => $t['prices'] ?? null,
	    'totals' => $totals,
	    //what the step was given of the conversation
	    'conversation' => $t['conversation'] ?? null,
	    'started' => \util\Format::datetime($start),
	    'duration' => $start ? (int)(string)($end ?? new \MongoDB\BSON\UTCDateTime()) - (int)(string)$start : null,
	    'updated' => (string)($t['updated_at'] ?? ''),
	];
    }

    //run a finished execution again with the same inputs (POST ?execution=<id>; the Executions panel), as a new execution of the draft, queued: Run's checks and audit entry. Answers
    //{ execution }. Workflow::update (as Run)
    public function execution_again($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$eid = (string)($req->query['execution'] ?? '');
	if (!\util\Validator::rule('oid', $eid))
	    \V::app()->error(400, 'malformed request: not oid');
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	if (empty($exe))
	    \V::app()->error(404, 'Not found');
	if (array_filter($wf->issues(), fn($i) => $i['level'] == 'error'))
	    $resp->sendJSON([ 'error' => 'The workflow has errors (steps\' settings or connections): fix them and save it first' ], true, 409);
	try {
	    $again = $exe->again($wf);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => ucfirst($e->getMessage()) ], true, 409);
	}
	$again->log(null, 'started by ' . (string)\V::app()->user->name . ' with the inputs of execution ' . $eid);
	$again->queue();
	\models\AuditLog::record('workflow.run', $wf, 'execution ' . (string)$again->_id . ', with the inputs of ' . $eid);
	$resp->sendJSON([ 'execution' => (string)$again->_id ]);
    }

    //the steps and connections of an execution as the designer shows them: { nodes: { wfid: { status, runs } }, edges: { wfid: status } }, only those
    //reached. A step's status: current (a token is at it, or a loop doing its passes, while the execution runs), sleep (a Wait), wait (a Merge waiting for its other inputs),
    //done, fail, stopped (a token was at it when the execution was killed). A connection: passed (a token went through it; executions before it was recorded: both ends reached),
    //active (a token on it now, to a current step)
    private function runStatus($exe) {
	$state = (string)$exe->state;
	$running = in_array($state, [ 'new', 'run' ], true);
	$data = is_array($exe->nodes) ? $exe->nodes : [];
	$steps = [];
	$edges = [];
	foreach($data as $wfid => $n) {
	    $id = (string)($n['wfid'] ?? $wfid);
	    if (!empty($n['isNode']))
		$steps[$id] = $n;
	    elseif (!empty($n['isEdge']))
		$edges[$id] = $n;
	}

	$tokens = is_array($exe->tokens) ? $exe->tokens : [];
	$atNode = [];
	$onEdge = [];
	foreach($tokens as $t) {
	    $atNode[(string)($t['node'] ?? '')] = true;
	    if (!empty($t['edge']))
		$onEdge[(string)$t['edge']] = true;
	}
	//a failed run stops at the step that failed; one stopped by the web request running it (no step marked failed) at the steps it was running
	$failed = false;
	foreach($steps as $n)
	    $failed = $failed || ($n['state'] ?? '') === 'fail';

	$nodes = [];
	foreach($steps as $id => $n) {
	    $nodeState = (string)($n['state'] ?? 'ready');
	    $runs = (int)($n['runs'] ?? 0);
	    $here = isset($atNode[$id]) || !empty($n['active']);
	    $status = null;
	    if ($here && $running)
		$status = $nodeState === 'sleep' ? 'sleep' : 'current';
	    elseif ($here && $state === 'cancel')
		$status = 'stopped';
	    elseif ($here && $state === 'error' && !$failed && $nodeState !== 'done')
		$status = 'fail';
	    elseif (in_array($nodeState, [ 'done', 'fail', 'wait', 'sleep' ], true))
		$status = $nodeState;
	    elseif ($nodeState === 'run')
		$status = $running ? 'current' : ($state === 'cancel' ? 'stopped' : 'fail');
	    elseif ($runs > 0)
		$status = 'done';
	    if ($status !== null)
		$nodes[$id] = [ 'status' => $status, 'runs' => $runs ];
	}

	$recorded = false;
	foreach($edges as $e)
	    $recorded = $recorded || ($e['state'] ?? '') === 'passed';
	$list = [];
	foreach($edges as $id => $e) {
	    $from = $nodes[(string)($e['fromNode'] ?? '')]['status'] ?? null;
	    $to = $nodes[(string)($e['toNode'] ?? '')]['status'] ?? null;
	    if (isset($onEdge[$id]) && $running && $to === 'current')
		$list[$id] = 'active';
	    elseif ($recorded ? ($e['state'] ?? '') === 'passed' : ($from === 'done' && $to !== null))
		$list[$id] = 'passed';
	}
	return [ 'nodes' => (object)$nodes, 'edges' => (object)$list ];
    }

    //delete an execution of the draft (POST ?execution=<id>; the Executions panel): a designer Run / console run, not one of a
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
	//audit log (executions are not recorded by themselves; SOC2.md gap 4)
	\models\AuditLog::record('workflow.execution.delete', $wf, 'execution ' . $eid);
	$resp->sendJSON([ 'deleted' => $eid ]);
    }

    //kill a running (or waiting) execution (POST ?execution=<id>; the Overview's Needs attention, the designer's Executions panel): WfExecution::kill. Answers { killed };
    //409 when it no longer runs. Workflow::update (as Run)
    public function execution_kill($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$eid = (string)($req->query['execution'] ?? '');
	if (!\util\Validator::rule('oid', $eid))
	    \V::app()->error(400, 'malformed request: not oid');
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'state' => 1 ] ]);
	if (empty($exe))
	    \V::app()->error(404, 'Not found');
	if (!$exe->kill((string)\V::app()->user->name))
	    $resp->sendJSON([ 'error' => 'The execution is no longer running' ], true, 409);
	\models\AuditLog::record('workflow.execution.kill', $wf, 'execution ' . $eid);
	$resp->sendJSON([ 'killed' => $eid ]);
    }

    //delete all executions of the draft (POST; the Executions panel's trash button): as execution_delete, those of a published
    //version and those still running or sleeping are kept. Answers { deleted: <count>, kept: <count> }. Workflow::update (as Run)
    public function executions_delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$deleted = 0;
	$failed = 0;
	//not those of test runs: they go with their run
	$exes = \V::app()->om->find('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid, 'version' => null, 'test_run' => null, 'state' => [ '$nin' => [ 'new', 'run', 'sleep' ] ] ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'version' => 1, 'state' => 1 ] ]);
	foreach($exes as $exe) {
	    if ($exe->delete())
		$deleted++;
	    else
		$failed++;
	}
	//audit log: how many were deleted (SOC2.md gap 4)
	if ($deleted)
	    //util\Format::plural
	    \models\AuditLog::record('workflow.executions.delete', $wf, \util\Format::plural($deleted, 'execution') . ' of the draft' . ($failed ? ", {$failed} not deleted" : ''));
	$kept = \V::app()->om->count('WfExecution', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	if ($failed && !$deleted)
	    $resp->sendJSON([ 'error' => 'The executions could not be deleted' ], true, 500);
	$resp->sendJSON([ 'deleted' => $deleted, 'kept' => $kept ]);
    }

    //live help of an expression field (POST, JSON): the problems of `text` (util\Expression::check) and, with `before` (the text up to the cursor),
    //the suggestions there (util\Expression::complete); the variables are those available at step `node` (Workflow::variablesAt) of `nodes` (the designer's
    //current steps, JSON; else the saved ones). Workflow::read
    public function expression($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::read');
	$data = is_array($req->data) ? $req->data : [];
	$text = (string)($data['text'] ?? '');
	if (strlen($text) > 20000)
	    $resp->sendJSON([ 'problems' => [], 'completion' => null ]);

	$nodes = isset($data['nodes']) ? json_decode((string)$data['nodes'], true) : null;
	if (!is_array($nodes))
	    $nodes = is_array($wf->nodes) ? $wf->nodes : [];
	$vars = \models\Workflow::variablesAt($nodes, (string)($data['node'] ?? ''));
	//the project variables ({{ vars.<name> }}: suggested, a name the project does not have flagged), from the credentials of type Variable
	$vars[\models\credential\CredentialVariable::EXPRESSION_NAME] = \models\credential\CredentialVariable::expressionType($wf->aid, $wf->project);

	//mode `name`: a variable name field (suggestions of the variables; usage `read` warns about a name no step before sets)
	if (($data['mode'] ?? '') === 'name') {
	    $response = [ 'problems' => ($data['usage'] ?? '') === 'read' ? \util\Expression::checkName($text, $vars) : [], 'completion' => null ];
	    if (isset($data['before']))
		$response['completion'] = \util\Expression::completeName((string)$data['before'], $vars);
	    $resp->sendJSON($response);
	}

	//the field's syntax (`syntax`: text | reference, util\Expression::SYNTAX; default text)
	$syntax = array_key_exists((string)($data['syntax'] ?? ''), \util\Expression::SYNTAX) ? (string)$data['syntax'] : 'text';
	$response = [ 'problems' => \util\Expression::check($text, $vars, $syntax), 'completion' => null ];
	if (isset($data['before']))
	    $response['completion'] = \util\Expression::complete($text, (string)$data['before'], $vars, $syntax);
	$resp->sendJSON($response);
    }

    //version ?version=<id> of workflow $wf, else 400 / 404
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
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	return $wf;
    }

    private function executionSummary($exe) {
	$start = $exe->start_at instanceof \MongoDB\BSON\UTCDateTime ? $exe->start_at : null;
	$end = $exe->end_at instanceof \MongoDB\BSON\UTCDateTime ? $exe->end_at : null;
	//milliseconds; a running execution counts until now
	$duration = $start ? (int)(string)($end ?? new \MongoDB\BSON\UTCDateTime()) - (int)(string)$start : null;
	return [
	    'id' => (string)$exe->_id,
	    'state' => (string)$exe->state,
	    'trigger' => (string)$exe->trigger,
	    //published version number, null = the draft
	    'version' => is_numeric($exe->version) ? (int)$exe->version : null,
	    'created' => \util\Format::datetime($exe->created_at),
	    'started' => \util\Format::datetime($start),
	    'ended' => \util\Format::datetime($end),
	    'duration' => $duration,
	    'wake_at' => \util\Format::datetime($exe->wake_at),
	    'error' => $exe->error ? (string)$exe->error : null,
	    //the execution whose inputs it ran with
	    'rerun_of' => $exe->rerun_of ? (string)$exe->rerun_of : null,
	    //of a test run: not run again, not saved as a test case
	    'test_run' => $exe->test_run ? (string)$exe->test_run : null,
	];
    }

}