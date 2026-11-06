<?php

/*
    CLD: 2026-11-04T12:00:00+02:00: new: the Move dialog of a resource (models\share\MovableTrait): which project it can move to, what stops working in its own.
    ?object=<kind>&id=<id>: manage shows it; POST (`project`): moves it. Needs the resource's MOVE_PERMS: `from` on it, `to` in the new project
*/

namespace controllers;

class MoveController extends \sys\BaseController {

    //what can be moved: the `object` parameter
    const KINDS = [ 'DataTableDef', 'Credential', 'Workflow' ];

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	//CLD: 2026-11-06T23:25:44+02:00: the request's account (rt.account, set at sign-in), for the actions
	$this->account = \V::app()->config('rt.account');
    }

    public function manage($req, $resp) {
	$object = (string)($req->query['object'] ?? '');
	if (!in_array($object, self::KINDS, true))
	    \V::app()->error(404, 'Not found');
	$resource = \V::app()->om->findOne($object, [ '_id' => $this->getOidOrError(), 'aid' => $this->account->_id ]);
	if (empty($resource))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($resource::MOVE_PERMS['from'], [ 'object' => $resource ]))
	    \V::app()->error(403, 'Unauthorized');

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

	$this->render('manage', compact('resource', 'object', 'targets'));
    }
}
