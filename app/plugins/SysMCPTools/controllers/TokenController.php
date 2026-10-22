<?php

/*
    the access tokens of the project's MCP server (models\McpToken; the server: McpController, POST /mcp).
    list (the page; with ?data=1 the grid's data, as FileController::list), create (a name; the token is shown once, then only its first characters), delete (= revoke).
    A token acts as the user who made it
*/

//CLD: 2026-10-20T23:40:00+03:00: moved to the SysMCPTools plugin (was controllers\TokenController): /SysMCPTools/token/<action>
namespace plugins\SysMCPTools\controllers;

class TokenController extends \sys\BaseController {

    use \controllers\DataTrait;

    public $project = null;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);
	if (empty($this->project))
	    \V::app()->error(404, 'No current project');
    }

    //an unsaved token of the current project: the object for list/create checks (project scope = membership)
    private function projectToken() {
	return \V::app()->om->create('McpToken', [
	    'aid' => \V::app()->config('rt.account')->_id,
	    'project' => $this->project->_id,
	]);
    }

    //CLD: 2026-10-21T00:45:00+03:00: with ?data=1 the grid's data (was the action data, which answered an empty list without the permission: now 403, as the page)
    public function list($req, $resp) {
	if (!\V::app()->user->can('McpToken::list', [ 'object' => $this->projectToken() ]))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data']))
	    $resp->sendJSON($this->__data('McpToken', [ 'project' => $this->project->_id ]));

	$this->render('list', [ 'project' => $this->project ]);
    }

    public function create($req, $resp) {
	$token = $this->projectToken();
	if (!\V::app()->user->can('McpToken::create', [ 'object' => $token ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post')) {
	    $token->assign($req->data, true);
	    $secret = $token->generate();
	    $token->user = \V::app()->user->_id;
	    if ($token->validates()) {
		$token->save();
		$this->addEvent('object::created', [ 'object' => 'McpToken', 'oid' => (string)$token->_id ]);
		//shown once: the token itself is not kept
		$this->render('created', compact('token', 'secret'));
		return;
	    }
	}

	$this->render('form', compact('token'));
    }

    public function delete($req, $resp) {
	$oid = $this->getOidOrError();
	$token = \V::app()->om->findOne('McpToken', [ '_id' => $oid, 'aid' => \V::app()->config('rt.account')->_id, 'project' => $this->project->_id ]);
	if (empty($token))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('McpToken::delete', [ 'object' => $token ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post')) {
	    $token->delete();
	    $this->addToast('success', 'OK', 'Token revoked');
	    $this->addEvent('object::deleted', [ 'object' => 'McpToken', 'oid' => null ]);
	    $this->sendEvents();
	}
    }
}
