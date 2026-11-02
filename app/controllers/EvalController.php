<?php

/*
    CLD: 2026-11-02T15:46:06+02:00: new: the designer's Tests panel (app.WfTests; util\AgentEval): a workflow's test cases, its test runs, Tests must pass to publish. All on ?id=<workflow>, answers in JSON.
    Workflow::read to see them (as the executions they come from), Workflow::update to change the cases and run them (as Run), Workflow::publish for the gate (who may publish decides what publishing asks for)
*/

namespace controllers;

use models\WfTestCase;
use models\WfTestRun;
use util\AgentEval;

class EvalController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	if (\V::app()->user->isGuest)
	    \V::app()->response->sendJSON([ 'error' => 'Log in first' ], true, 401);
    }

    private function findWorkflow($permName) {
	$oid = $this->getOidOrError();
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $oid, 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($wf))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $wf ]))
	    \V::app()->error(403, 'Unauthorized');
	return $wf;
    }

    private function findCase($wf, $id) {
	$case = \util\Validator::rule('oid', (string)$id) ? \V::app()->om->findOne('WfTestCase', [ '_id' => (string)$id, 'wf' => $wf->_id, 'aid' => $wf->aid ]) : null;
	if (empty($case))
	    \V::app()->error(404, 'Not found');
	return $case;
    }

    private static function caseData($case) {
	return [ 'id' => (string)$case->_id, 'name' => (string)$case->name, 'source' => (string)$case->source, 'vars' => (object)($case->input['vars'] ?? []), 'tools' => (string)$case->tools, 'mocks' => array_values((array)$case->mocks),
	    'steps' => array_values(array_map(fn($s) => (string)($s['title'] ?? ''), (array)$case->steps)), 'checks' => array_values((array)$case->checks), 'notes' => array_values((array)$case->notes) ];
    }

    //the panel: the cases, the runs (newest first, without their cases), the gate, the AI steps a check can name, the choices. Workflow::read
    public function data($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$cases = [];
	foreach(\V::app()->om->find('WfTestCase', [ 'wf' => $wf->_id, 'aid' => $wf->aid ], [ 'sort' => [ '_id' => 1 ] ]) as $c)
	    $cases[] = self::caseData($c);
	$classes = \models\WfNode::nodeClasses();
	$steps = [];
	foreach((array)$wf->nodes as $n) {
	    $name = $classes[$n['fqname'] ?? ''] ?? null;
	    if (!empty($n['isNode']) && $name && is_subclass_of(\V::app()->om->getObjectClass($name), \models\node\NodeAppAi::class))
		$steps[] = [ 'id' => (string)$n['wfid'], 'title' => (string)($n['title'] ?? '') ];
	}
	$gate = AgentEval::gate($wf);
	$resp->sendJSON([ 'cases' => $cases, 'runs' => array_map([ AgentEval::class, 'summary' ], WfTestRun::ofWorkflow($wf)), 'required' => $gate['required'], 'gate' => [ 'ok' => $gate['ok'], 'message' => $gate['message'] ], 'steps' => $steps,
	    'canEdit' => \V::app()->user->can('Workflow::update', [ 'object' => $wf ]), 'canPublish' => \V::app()->user->can('Workflow::publish', [ 'object' => $wf ]), 'maxCases' => WfTestCase::MAX_CASES,
	    'modes' => WfTestCase::MODES, 'checkTypes' => WfTestCase::CHECKS, 'operators' => WfTestCase::OPERATORS ]);
    }

    //one run with its cases and the difference from the run before it (?run=). Workflow::read
    public function run_detail($req, $resp) {
	$wf = $this->findWorkflow('Workflow::read');
	$run = WfTestRun::read((string)($req->query['run'] ?? ''), $wf);
	if (!$run)
	    \V::app()->error(404, 'Not found');
	$resp->sendJSON([ 'run' => AgentEval::summary($run) + [ 'cases' => array_values((array)($run['cases'] ?? [])) ], 'diff' => AgentEval::diff($run, WfTestRun::previous($run)) ]);
    }

    //a case created (no `case_id`) or changed: POST `case`, JSON { name, vars, tools, mocks, checks } => { case }; its problems in { error }. Workflow::update
    public function case_save($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$data = json_decode((string)($req->data['case'] ?? ''), true);
	if (!is_array($data))
	    $resp->sendJSON([ 'error' => 'No test case' ], true, 400);
	$id = (string)($req->data['case_id'] ?? '');
	if ($id === '' && \V::app()->om->count('WfTestCase', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]) >= WfTestCase::MAX_CASES)
	    $resp->sendJSON([ 'error' => 'A workflow has at most ' . WfTestCase::MAX_CASES . ' test cases' ], true, 409);
	$case = $id !== '' ? $this->findCase($wf, $id) : \V::app()->om->create('WfTestCase', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'trigger' => '', 'source' => '', 'steps' => [], 'notes' => [] ]);
	[ $checks, $errors ] = WfTestCase::normalize($data['checks'] ?? []);
	[ $input, $more ] = WfTestCase::normalizeInput($data['vars'] ?? []);
	[ $mocks, $third ] = WfTestCase::normalizeMocks($data['mocks'] ?? []);
	$errors = array_merge($errors, $more, $third);
	$name = mb_substr(trim((string)($data['name'] ?? '')), 0, 200);
	if ($name === '')
	    $errors[] = 'Name: give the test case a name';
	$mode = (string)($data['tools'] ?? 'mock');
	if (!array_key_exists($mode, WfTestCase::MODES))
	    $errors[] = 'Tool calls: unknown choice';
	if ($errors)
	    $resp->sendJSON([ 'error' => implode("\n", $errors) ], true, 400);
	$case->name = $name;
	$case->input = $input;
	$case->tools = $mode;
	$case->mocks = $mocks;
	$case->checks = $checks;
	if (!$case->validates() || !$case->save()) {
	    $messages = [];
	    foreach((array)$case->errors() as $list)
		foreach((array)$list as $m)
		    $messages[] = (string)$m;
	    $resp->sendJSON([ 'error' => implode("\n", $messages ?: [ 'The test case could not be saved' ]) ], true, 400);
	}
	\models\AuditLog::record('workflow.test.save', $wf, 'test case ' . (string)$case->name);
	$resp->sendJSON([ 'case' => self::caseData($case) ]);
    }

    //Save as test case: from the execution ?execution= => { case }. Workflow::update
    public function case_from_execution($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$eid = (string)($req->query['execution'] ?? '');
	$exe = \util\Validator::rule('oid', $eid) ? \V::app()->om->findOne('WfExecution', [ '_id' => $eid, 'wf' => $wf->_id, 'aid' => $wf->aid ]) : null;
	if (empty($exe))
	    \V::app()->error(404, 'Not found');
	if (\V::app()->om->count('WfTestCase', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]) >= WfTestCase::MAX_CASES)
	    $resp->sendJSON([ 'error' => 'A workflow has at most ' . WfTestCase::MAX_CASES . ' test cases' ], true, 409);
	try {
	    $case = AgentEval::fromExecution($wf, $exe);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => ucfirst($e->getMessage()) ], true, 409);
	}
	if (!$case->validates() || !$case->save())
	    $resp->sendJSON([ 'error' => 'The test case could not be saved: its inputs and recorded results are too large' ], true, 400);
	\models\AuditLog::record('workflow.test.save', $wf, 'test case ' . (string)$case->name . ' from execution ' . $eid);
	$resp->sendJSON([ 'case' => self::caseData($case) ]);
    }

    //POST ?case=. Workflow::update
    public function case_delete($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$case = $this->findCase($wf, $req->query['case'] ?? '');
	$name = (string)$case->name;
	if (!$case->delete())
	    $resp->sendJSON([ 'error' => 'The test case could not be deleted' ], true, 500);
	\models\AuditLog::record('workflow.test.delete', $wf, 'test case ' . $name);
	$resp->sendJSON([ 'deleted' => true ]);
    }

    //run the tests of the saved draft, in the daemon (POST) => { run }; one already running: that one. Workflow::update
    //named start: BaseController::run() is the request dispatcher
    public function start($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	try {
	    [ $run, $new ] = AgentEval::start($wf, (string)\V::app()->user->name);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'error' => ucfirst($e->getMessage()) ], true, 409);
	}
	if ($new)
	    \models\AuditLog::record('workflow.test.run', $wf, 'test run ' . (string)$run['_id']);
	$resp->sendJSON([ 'run' => AgentEval::summary($run), 'started' => $new ]);
    }

    //POST ?run=. Workflow::update
    public function run_stop($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::update');
	$run = WfTestRun::read((string)($req->query['run'] ?? ''), $wf);
	if (!$run)
	    \V::app()->error(404, 'Not found');
	if (!AgentEval::stop($run, (string)\V::app()->user->name))
	    $resp->sendJSON([ 'error' => 'The test run is no longer running' ], true, 409);
	$resp->sendJSON([ 'stopped' => true ]);
    }

    //Tests must pass to publish (POST `required`: 1 | 0). Workflow::publish; the change is in the audit log (a saved model)
    public function required($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$wf = $this->findWorkflow('Workflow::publish');
	$wf->tests_required = !empty($req->data['required']) && (string)$req->data['required'] !== '0';
	if (!$wf->save())
	    $resp->sendJSON([ 'error' => 'The workflow could not be saved' ], true, 500);
	$resp->sendJSON([ 'required' => (bool)$wf->tests_required ]);
    }
}
