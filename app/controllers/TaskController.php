<?php

/*
    "My tasks" - the tasks of the Human task steps (plugins\Forms\models\WfTask):
    /task/list      the open tasks of the current project the user may complete (assigned to them, to their role, or to any member)
    /task/<id>      GET: the task (instructions + form); POST (JSON { answers }): checked on the server (util\SmartForm::answers), the task claimed (only one person
                  completes it) and done, the waiting run resumed after the answer -> { ok, message }; 422 { errors } / 409 when it is no longer open

*/
namespace controllers;

class TaskController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	if (\V::app()->user->isGuest) {
	    if (\V::app()->request->is('post'))
		//CLD: 2026-10-18T18:37:53+03:00: \V::app()->response->sendJSON (was $this->json, removed)
		\V::app()->response->sendJSON([ 'ok' => false, 'error' => 'Log in first.' ], true, 401);
	    $this->redirect(\V::app()->user->getLoginUrl());
	}
    }

    public function list($req, $resp) {
	$account = \V::app()->config('rt.account');
	$tasks = \models\WfTask::openFor(\V::app()->user, $account->_id ?? null, null);
	$names = [];
	$projects = [];
	foreach($tasks as $t) {
	    if (!isset($names[(string)$t->wf]))
		$names[(string)$t->wf] = (string)(\V::app()->om->findOne('Workflow', [ '_id' => $t->wf, 'aid' => $t->aid ])->name ?? '');
	    if (!isset($projects[(string)$t->project]))
		$projects[(string)$t->project] = (string)(\V::app()->om->findOne('Project', [ '_id' => $t->project, 'aid' => $t->aid ])->name ?? '');
	}
	$this->render('list', compact('tasks', 'names', 'projects'));
    }

    public function open($req, $resp) {
	$id = (string)($req->query['id'] ?? '');
	$post = $req->is('post');
	$account = \V::app()->config('rt.account');
	$task = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('WfTask', [ '_id' => $id, 'aid' => $account->_id ?? null ]) : null;
	if (!$task || !$task->isFor(\V::app()->user)) {
	    if ($post)
		//CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
		$resp->sendJSON([ 'ok' => false, 'error' => 'This task does not exist, or it is not yours.' ], true, 404);
	    \V::app()->error(404, 'This task does not exist, or it is not yours.');
	}
	$expired = $task->due_at instanceof \MongoDB\BSON\UTCDateTime && (int)(string)$task->due_at <= (int)(microtime(true) * 1000);
	if (!$post) {
	    $this->render('open', compact('task', 'expired'));
	    return;
	}
	if ($task->state !== 'open' || $expired)
	    //CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'ok' => false, 'error' => $task->state === 'done' ? 'This task was already completed.' : 'This task is no longer open.' ], true, 409);

	$body = json_decode((string)file_get_contents('php://input'), true);
	$form = \util\SmartForm::normalize($task->form ?? []);
	[ $answers, $errors ] = \util\SmartForm::answers($form, is_array($body['answers'] ?? null) ? $body['answers'] : []);
	if ($errors)
	    //CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'Some fields need attention.', 'errors' => $errors ], true, 422);

	//only one person completes it
	if (\V::app()->om->updateMany('WfTask', [ '_id' => $task->_id, 'state' => 'open' ], [ '$set' => [ 'state' => 'done' ] ]) < 1)
	    //CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'This task was already completed.' ], true, 409);
	$task = \V::app()->om->findById('WfTask', (string)$task->_id);
	$task->answers = $answers;
	$task->completed_by = \V::app()->user->_id;
	$task->completed_at = new \MongoDB\BSON\UTCDateTime();
	$task->save();

	//CLD: 2026-10-30T15:00:00+02:00: the run resumed by the daemon (queued; was resumed here after the answer: runSync)
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => $task->execution, 'aid' => $task->aid ]);
	if ($exe && $exe->canRun())
	    $exe->queue('task completed by ' . (string)\V::app()->user->name . ': queued for the daemon');

	$resp->sendJSON([ 'ok' => true, 'message' => 'Done, thank you.' ]);
    }
}
