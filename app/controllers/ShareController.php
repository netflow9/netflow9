<?php

/*
    CLD: 2026-11-03T14:00:00+02:00: new: the Share dialog of a resource (models\share\ShareableTrait): who it is shared with, at which level, what uses it there.
    ?object=<kind>&id=<id>: manage shows it (POST: a new share); POST change: a share's level; POST remove: ends a share (&project=<id>). Each answers with the dialog again.
    Needs the resource's SHARE_PERM in its home project. A personal project neither shares nor receives
*/

namespace controllers;

class ShareController extends \sys\BaseController {

    //what can be shared: the `object` parameter
    const KINDS = [ 'DataTableDef', 'Workflow', 'Credential' ];

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function manage($req, $resp) {
	$resource = $this->resource();
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
	$resource = $this->resource();
	$access = (string)($req->data['access'] ?? '');
	if (isset($resource::SHARE_LEVELS[$access]))
	    $this->saved($resource, $this->sharedProject($resource), $access, $req->data);
	$this->show($resource);
    }

    public function remove($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$resource = $this->resource();
	$resource->removeShare($this->sharedProject($resource));
	$this->addEvent('object::updated', [ 'object' => $resource->getObjectName(), 'oid' => (string)$resource->_id ]);
	$this->show($resource);
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
	$account = \V::app()->config('rt.account');
	$names = [];
	$targets = [];
	foreach(\V::app()->om->find('Project', [ 'aid' => $account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p) {
	    $names[(string)$p->_id] = (string)$p->name;
	    if (!$p->personal && !$resource->isHome($p->_id) && !$resource->shareOf($p->_id))
		$targets[(string)$p->_id] = (string)$p->name;
	}
	$object = (string)\V::app()->request->query['object'];
	$this->render('manage', compact('resource', 'object', 'names', 'targets'));
    }

    //the resource of the request, if the user may share it (404 / 403 otherwise)
    private function resource() {
	$object = (string)(\V::app()->request->query['object'] ?? '');
	if (!in_array($object, self::KINDS, true))
	    \V::app()->error(404, 'Not found');
	$resource = \V::app()->om->findOne($object, [ '_id' => $this->getOidOrError(), 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($resource))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($resource::SHARE_PERM, [ 'object' => $resource ]))
	    \V::app()->error(403, 'Unauthorized');
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
