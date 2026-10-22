<?php

namespace models;

class Role extends \sys\BaseModel {
    protected static $collection = 'roles';
    //CLD: 2026-10-12T12:18:11+03:00: roles of an account (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'aid' => 1, 'iname' => 1 ], [] ],
    ];


    public function _init() {
	parent::_init();

	//override
	if (!empty($this->override)) {
	    foreach($this->override as $permName => $permData)
		$this->permissions[$permName] = $permData;
	}

	//CLD: 2026-10-08T08:55:11+03:00: defaults of plugin permissions for the system roles (owner, member) that do not have them stored
	if (!empty($this->iname)) {
	    foreach(\data\SysPermissions::get() as $permissions) {
		foreach($permissions as $p) {
		    if (!empty($p['defaults'][$this->iname]) && !isset($this->permissions[$p['name']]))
			$this->permissions[$p['name']] = $p['defaults'][$this->iname];
		}
	    }
	}

	//restrict Account::manage to `owner` role
	$this->permissions['Account::manage'] = [ 'enabled' => $this->iname == 'owner', 'scope' => 'global', ];
    }

    public function isPermissionEnabled($permName) {
	return $this->permissions[$permName]['enabled'] ?? false;
    }

    public function getPermissionScope($permName) {
	if (!$this->isPermissionEnabled($permName))
	    return null;

	if (!$this->permissions[$permName]['enabled'])
	    return false;

	return $this->permissions[$permName]['scope'];
    }

    public function getSchema() {
	$fields = [
	    //name of role
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'required' => true,
		'unique' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //internal name of role, just for system roles
	    [
		'key' => 'iname',
		'type' => 'text',
		'label' => 'Internal name',
		'required' => false,
		'unique' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
	    ],

	    //permissions
	    [
		'key' => 'permissions',
		'type' => 'array',
		'label' => 'Permissions',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
	    ],

	    //override permissions
	    [
		'key' => 'override',
		'type' => 'array',
		'label' => 'Override',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
	    ],
	];

	return array_merge($fields, $this->getSystemSchema());
    }

    public function __toString() {
	return $this->name ?? '';
    }

}
