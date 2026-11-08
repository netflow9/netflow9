<?php

/*
    the Share and Move dialogs of a resource of a project (data\Constants::PROJECT_KINDS), ?object=<kind>&id=<id>:

    the Share dialog (models\share\ShareableTrait): who it is shared with, at which level, what uses it there.
    manage shows it (POST: a new share); POST change: a share's level; POST remove: ends a share (&project=<id>). Each answers with the dialog again.
    Needs the resource's SHARE_PERM in its home project. A personal project neither shares nor receives

    the Move dialog (models\share\MovableTrait): which project it can move to, what stops working in its own.
    move shows it; POST (`project`): moves it. Needs the resource's MOVE_PERMS: `from` on it, `to` in the new project
*/

namespace controllers;

class ShareController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	$this->account = \V::app()->var('rt.account');
    }

    public function manage($req, $resp) {
	$resource = $this->resource('share');
	if ($req->is('post')) {
	    $project = $this->targetProject($resource, (string)($req->data['project'] ?? ''));
	    $access = (string)($req->data['access'] ?? '');
	    //CLD: 2026-11-03T16:00:00+02:00: what the resource itself refuses (a workflow not published)
	    if ($resource->shareProblem() !== null)
		$this->addToast('error', 'Not shared', $resource->shareProblem());
	    elseif (!$project || !isset($resource::SHARE_LEVELS[$access]))
		$this->addToast('error', 'Not shared', 'Choose a project and a level');
	    elseif ($resource->shareOf($project->_id))
		$this->addToast('warning', 'Not shared', 'It is already shared with that project');
	    else
		$this->saved($resource, $project, $access, $req->data);
	}
	$this->show($resource);
    }

    public function change($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$resource = $this->resource('share');
	$access = (string)($req->data['access'] ?? '');
	if (isset($resource::SHARE_LEVELS[$access]))
	    $this->saved($resource, $this->sharedProject($resource), $access, $req->data);
	$this->show($resource);
    }

    public function remove($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$resource = $this->resource('share');
	$resource->removeShare($this->sharedProject($resource));
	$this->addEvent('object::updated', [ 'object' => $resource->getObjectName(), 'oid' => (string)$resource->_id ]);
	$this->show($resource);
    }

    public function move($req, $resp) {
	$resource = $this->resource('move');
	$object = (string)$req->query['object'];
	$targets = $resource->moveTargets(\V::app()->user->_id);

	if ($req->is('post')) {
	    $project = $targets[(string)($req->data['project'] ?? '')] ?? null;
	    $problem = $resource->moveProblem() ?? ($project ? $resource->moveNameProblem($project) : 'Choose a project');
	    if ($problem !== null) {
		$this->addToast('error', 'Not moved', $problem);
	    } else {
		$resource->moveTo($project);
		$this->addToast('success', 'Moved', (string)$resource->name . ' is now in project ' . $project->name);
		//CLD: 2026-11-04T15:00:00+02:00: its designer is open behind the dialog and would go on saving: as a delete, away from it
		if ($resource instanceof \models\Workflow) {
		    $this->addEvent('sidebar::refresh');
		    $this->redirect('/site/index');
		}
		//as a delete: it left this list
		$this->addEvent('object::deleted', [ 'object' => $resource->getObjectName(), 'oid' => (string)$resource->_id ]);
		$this->sendEvents();
	    }
	}

	$this->render('move', compact('resource', 'object', 'targets'));
    }

    //CLD: 2026-11-03T18:00:00+02:00: + what the resource asks for (shareOptions: a credential's hosts)
    private function saved($resource, $project, $access, $data) {
	[ $options, $error ] = $resource->shareOptions(is_array($data) ? $data : []);
	if ($error !== null) {
	    $this->addToast('error', 'Not saved', $error);
	    return;
	}
	$resource->setShare($project, $access, $options);
	$this->addEvent('object::updated', [ 'object' => $resource->getObjectName(), 'oid' => (string)$resource->_id ]);
    }

    private function show($resource) {
	$names = [];
	$targets = [];
	foreach(\V::app()->om->find('Project', [ 'aid' => $this->account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p) {
	    $names[(string)$p->_id] = (string)$p->name;
	    if (!$p->personal && !$resource->isHome($p->_id) && !$resource->shareOf($p->_id))
		$targets[(string)$p->_id] = (string)$p->name;
	}
	$object = (string)\V::app()->request->query['object'];
	$this->render('manage', compact('resource', 'object', 'names', 'targets'));
    }

    //CLD: 2026-11-08T12:00:00+02:00: + $action: share (SHARE_PERM; not from a personal project: 400) or move (MOVE_PERMS `from`)
    //the resource of the request, if the user may share / move it (404 / 403 otherwise)
    private function resource($action) {
	$object = (string)(\V::app()->request->query['object'] ?? '');
	if (!in_array($object, \data\Constants::PROJECT_KINDS, true))
	    \V::app()->error(404, 'Not found');
	$resource = \V::app()->om->findOne($object, [ '_id' => $this->getOidOrError(), 'aid' => $this->account->_id ]);
	if (empty($resource))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($action === 'move' ? $resource::MOVE_PERMS['from'] : $resource::SHARE_PERM, [ 'object' => $resource ]))
	    \V::app()->error(403, 'Unauthorized');
	if ($action === 'move')
	    return $resource;
	$home = \V::app()->om->findOne('Project', [ '_id' => $resource->project, 'aid' => $resource->aid ]);
	if (empty($home) || $home->personal)
	    \V::app()->error(400, 'A personal project cannot share');
	return $resource;
    }

    //project $id if it can receive: of the account, not personal, not the resource's home; null otherwise
    private function targetProject($resource, $id) {
	if (!\util\Validator::rule('oid', $id))
	    return null;
	$project = \V::app()->om->findOne('Project', [ '_id' => $id, 'aid' => $resource->aid ]);
	return ($project && !$project->personal && !$resource->isHome($project->_id)) ? $project : null;
    }

    //the project of ?project=, one the resource is shared with (404 otherwise)
    private function sharedProject($resource) {
	$id = $this->getOidOrError('project');
	$project = $resource->shareOf($id) ? \V::app()->om->findOne('Project', [ '_id' => $id, 'aid' => $resource->aid ]) : null;
	if (empty($project))
	    \V::app()->error(404, 'Not found');
	return $project;
    }
}
