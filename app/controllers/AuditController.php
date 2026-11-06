<?php

/*
    the audit log (models\AuditLog): list (newest first), its data for the grid, one entry's details (dialog).
    AuditLog::read: global scope = every entry of the account, project scope = the entries of the current project (member of it)
    also the execution log (models\ExecutionLog, ExecutionLog::read): ?log=execution on every action (default: the audit log)
*/

namespace controllers;

class AuditController extends \sys\BaseController {

    use DataTrait;

    //the log shown: AuditLog | ExecutionLog
    private $log = 'AuditLog';

    //?scope=system: the system log (every entry of the account; global scope only); else the current project's log (its entries only, also for global scope)
    private $system = false;

    //CLD: 2026-11-02T10:00:00+02:00: ?agent=1: only the tool calls of the agents (the audit log)
    private $agent = false;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	//CLD: 2026-11-06T23:25:44+02:00: the request's account (rt.account, set at sign-in), for the actions
	$this->account = \V::app()->config('rt.account');

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);
	if ((\V::app()->request->query['log'] ?? '') === 'execution')
	    $this->log = 'ExecutionLog';
	$this->system = (\V::app()->request->query['scope'] ?? '') === 'system';
	$this->agent = $this->log === 'AuditLog' && !empty(\V::app()->request->query['agent']);
    }

    //an unsaved entry of the current project: the object for the list check (project scope = membership)
    //system log: an entry without a project (only global scope may list it)
    private function projectEntry() {
	return \V::app()->om->create($this->log, [
	    'aid' => $this->account->_id,
	    'project' => $this->system ? null : ($this->project->_id ?? null),
	]);
    }

    //the system log: the whole account; the project log: only the current project's entries
    private function scopeQuery() {
	return ($this->system ? [] : [ 'project' => $this->project->_id ?? null ]) + ($this->agent ? [ 'action' => \models\AuditLog::AGENT_ACTION ] : []);
    }

    //the query string of this log's URLs (log, scope)
    private function logQuery() {
	$params = [];
	if ($this->log == 'ExecutionLog')
	    $params['log'] = 'execution';
	if ($this->system)
	    $params['scope'] = 'system';
	return $params ? '?' . http_build_query($params) : '';
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can($this->log . '::list', [ 'object' => $this->projectEntry() ]))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data']))
	    $resp->sendJSON($this->__data($this->log, $this->scopeQuery()));

	$system = $this->system;
	$log = $this->log;
	$agent = $this->agent;
	//CLD: 2026-11-02T10:00:00+02:00: the same log with / without the agents' filter (logQuery: without it)
	$q = $this->logQuery();
	$qData = $agent ? ($q === '' ? '?agent=1' : "{$q}&agent=1") : $q;
	$this->render('list', compact('system', 'log', 'q', 'qData', 'agent'));
    }

    //one entry (dialog): who / what acted, when, what, and the changed values
    public function view($req, $resp) {
	$oid = $this->getOidOrError();
	$entry = \V::app()->om->findOne($this->log, [ '_id' => $oid, 'aid' => $this->account->_id ]);
	if (empty($entry))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($this->log . '::read', [ 'object' => $entry ]))
	    \V::app()->error(403, 'Unauthorized');

	$this->render('view', compact('entry'));
    }
}
