<?php

namespace models;

class Project extends \sys\BaseModel {
    protected static $collection = 'projects';
    public static $indexes = [
	[ [ 'aid' => 1 ], [] ],
	[ [ 'owner' => 1, 'personal' => 1 ], [] ],
    ];


    public function getSchema() {
	$schema = $this->getSystemSchema();

	$fields = [
	    //name
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //icon
	    [
		'key' => 'icon',
		'type' => 'icon',
		'label' => 'Icon',
		'help' => false,
		'default' => 'layers::black',
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //owner
	    [
		'key' => 'owner',
		'type' => 'object',
		'object' => 'User',
		'label' => 'Owner',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	    //personal
	    [
		'key' => 'personal',
		'type' => 'checkbox',
		'label' => 'Personal',
		'help' => false,
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	    //members, array of:
	    // (string)$user->_id => [ 'role' => $role->_id ],
	    [
		'key' => 'members',
		'type' => 'array',
		'label' => 'Members',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	];

	return array_merge($fields, $this->getSystemSchema());
    }

    //true if $userId is a member of the project $projectId of account $aid (used by the project-scoped
    //checkAccess of File, Workflow, Variable)
    public static function isMember($projectId, $aid, $userId) {
	if (empty($projectId) || empty($aid) || empty($userId))
	    return false;

	$project = \V::app()->om->findOne('Project', [ '_id' => $projectId, 'aid' => $aid ]);
	return !empty($project) && isset($project->members[(string)$userId]);
    }

    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//now get the primary role permission scope
	if ($role->getPermissionScope($permName) == 'global')
	    return true;

	//try membership role permission
	$up_role_id = $this->members[(string)$userId]['role'] ?? null;
	if (empty($up_role_id))
	    return false;

	//the membership role must exist in the project's account
	$up_role = \V::app()->om->findOne('Role', [ '_id' => $up_role_id, 'aid' => $this->aid ]);
	if (empty($up_role))
	    return false;

	if (in_array($up_role->getPermissionScope($permName), [ 'project', 'global' ]))
	    return true;

	//default: false
	return false;
    }

    public function __toString() {
	return $this->name ?? '';
    }
}
