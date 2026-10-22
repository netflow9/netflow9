<?php

namespace models;

class Project extends \sys\BaseModel {
    protected static $collection = 'projects';
    //CLD: 2026-10-12T12:18:11+03:00: projects of an account; a user's personal project (login) (created by `v setup::indexes`)
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
//		'unique' => true,
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
//		'required' => true,
//		'unique' => true,
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
		//CLD: 2026-10-10T19:00:56+03:00: perm:update (perm:edit is read by nothing)
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:57:33+03:00: not a form field (assign with permissions ignores it)
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
		//CLD: 2026-10-10T19:00:56+03:00: perm:update (perm:edit is read by nothing)
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:57:33+03:00: not a form field (assign with permissions ignores it)
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
		//CLD: 2026-10-10T19:00:56+03:00: perm:update (perm:edit is read by nothing)
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:57:33+03:00: not a form field (assign with permissions ignores it)
		'showInForms' => false,
	    ],

	];

	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-10-06T23:07:56+03:00: true if $userId is a member of the project $projectId of account $aid (used by the project-scoped
    //checkAccess of File, Workflow, Variable)
    public static function isMember($projectId, $aid, $userId) {
	if (empty($projectId) || empty($aid) || empty($userId))
	    return false;

	$project = \V::app()->om->findOne('Project', [ '_id' => $projectId, 'aid' => $aid ]);
	return !empty($project) && isset($project->members[(string)$userId]);
    }

    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	//build full permission name
	//CLD: 2026-10-06T23:05:30+03:00: only when short: AAA::checkAccess passes the full name (Project::update), which was
	//turned into Project::Project::update, so project-scoped users were always refused; field checks pass the short one
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//now get the primary role permission scope
	if ($role->getPermissionScope($permName) == 'global')
	    return true;

	//try membership role permission
	//CLD: 2026-10-05T20:44:56+03:00: members are stored as [ userId => [ 'role' => roleId ] ] (see ProjectController::create, _members view);
	//the whole entry was used as the role id, so the lookup never matched and ->getPermissionScope() was called on null
	$up_role_id = $this->members[(string)$userId]['role'] ?? null;
	if (empty($up_role_id))
	    return false;

	//CLD: 2026-10-05T20:44:56+03:00: the membership role must exist in the project's account
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
