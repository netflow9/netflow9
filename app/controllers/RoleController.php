<?php

namespace controllers;

class RoleController extends \sys\BaseController {

    use DataTrait;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('Role::list'))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    $response = $this->__data('Role');
	    $resp->sendJSON($response);
	}

	$this->render('list');
    }


    //CLD: 2026-10-13T21:12:34+03:00: a new custom role (POST; Role::create): "New role N", every permission off; its page follows (name and permissions)
    public function create($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (!\V::app()->user->can('Role::create'))
	    \V::app()->error(403, 'Unauthorized');
	$account = \V::app()->var('rt.account');
	$permissions = [];
	foreach(\data\SysPermissions::get() as $objectName => $perms) {
	    foreach($perms as $p) {
		if (empty($p['alias-of']))
		    $permissions[$p['name']] = [ 'enabled' => false ];
	    }
	}
	$role = \V::app()->om->create('Role', [ 'aid' => $account->_id, 'name' => 'New role ' . (\V::app()->om->count('Role', [ 'aid' => $account->_id ]) + 1),
	    'permissions' => $permissions, 'override' => [] ]);
	if (!$role->validates() || !$role->save())
	    \V::app()->error(400, 'The role could not be created');
	$this->addToast('success', 'OK', 'Role created: set its name and permissions');
	$this->redirect('/role/update/' . (string)$role->_id);
    }

    public function update($req, $resp) {
	$oid = $this->getOidOrError();
	$account = \V::app()->var('rt.account');
	$role = \V::app()->om->findOne('Role', [ '_id' => $oid, 'aid' => $account->_id ]);
	if (empty($role))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('Role::read', [ 'object' => $role ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post') && !\V::app()->user->can('Role::update', [ 'object' => $role ]))
	    \V::app()->error(403, 'Unauthorized');

	//apply overrides
	foreach($role->override as $permName => $ovrData)
	    $role->permissions[$permName] = $ovrData;

	if ($req->is('post')) {
	    //process POST data
	    $permissions = $req->getDataParam('permissions', []);
	    if (empty($permissions) || !is_array($permissions))
		\V::app()->error(400, 'Malformed request');

	    //get all permissions and set to false in `permissions`
	    foreach(\data\SysPermissions::get() as $objectName => $perms) {
		foreach(array_column($perms, 'name') as $permName)
		    $role->permissions[$permName] = [ 'enabled' => false ];
	    }

	    //assign
	    foreach($permissions as $permName => $permData) {
		if (!array_key_exists($permName, $role->permissions))
		    continue;
		$role->permissions[$permName] = [ 'enabled' => (bool)$permData['enabled'], 'scope' => $permData['scope'] ?? 'own' ];
	    }

	    //apply override
	    foreach($role->override as $permName => $permData)
		$role->permissions[$permName] = $permData;

	    //CLD: 2026-10-13T21:12:34+03:00: a custom role (no internal name) can be renamed; the system roles (owner, member, participant) keep theirs
	    if (empty($role->iname) && trim((string)$req->getDataParam('name', '')) !== '')
		$role->name = mb_substr(trim((string)$req->getDataParam('name', '')), 0, 100);

	    if ($role->validates()) {
		$role->save();
		$this->addToast('success', 'OK', 'Role saved!');
	    } else {
		$this->addToast('error', 'ERROR', 'Error on save!');
	    }
	}

	$this->render('update', compact('role'));
    }
}