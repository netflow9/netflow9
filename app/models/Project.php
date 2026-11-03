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

    //true if $userId is a member of the project $projectId of account $aid
    //CLD: 2026-11-03T12:00:00+02:00: only for what needs just the membership (forms); permissions: can()
    public static function isMember($projectId, $aid, $userId) {
	if (empty($projectId) || empty($aid) || empty($userId))
	    return false;

	$project = \V::app()->om->findOne('Project', [ '_id' => $projectId, 'aid' => $aid ]);
	return !empty($project) && isset($project->members[(string)$userId]);
    }

    //CLD: 2026-11-03T12:00:00+02:00: new: the access rule of everything that belongs to a project: `global` scope in the user's account role passes everywhere; else the user's role in that project decides
    //(it must be of the project's account and have the permission on). The account role's `project` scope gives nothing by itself (was: it was enough, with any role in the project)
    public static function can($projectId, $aid, $userId, $permName) {
	if (\V::app()->auth->getUserRole()?->getPermissionScope($permName) == 'global')
	    return true;
	if (empty($projectId) || empty($aid) || empty($userId))
	    return false;

	$project = \V::app()->om->findOne('Project', [ '_id' => $projectId, 'aid' => $aid ]);
	$roleId = $project ? ($project->members[(string)$userId]['role'] ?? null) : null;
	if (empty($roleId))
	    return false;
	$role = \V::app()->om->findOne('Role', [ '_id' => $roleId, 'aid' => $aid ]);
	return !empty($role) && in_array($role->getPermissionScope($permName), [ 'project', 'global' ], true);
    }

    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//CLD: 2026-11-03T12:00:00+02:00: Project::can (was the same checks here)
	return self::can($this->_id, $this->aid, $userId, $permName);
    }

    public function __toString() {
	return $this->name ?? '';
    }
}
