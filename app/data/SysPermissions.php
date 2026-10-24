<?php

namespace data;

class SysPermissions {

    //CLD: 2026-10-20T23:40:00+03:00: the permissions plugins add: [ object name => [ permission, ... ] ] (as get()'s entries)
    private static $registered = [];

    //a plugin's permissions of object $objectName (the entries as in get(): name, scopes, desc, defaults, alias-of); call it from the plugin's constructor,
    //before any permission is checked (sys\AAA reads the list once)
    public static function register($objectName, array $permissions) {
	self::$registered[$objectName] = $permissions;
    }

    public static function get() {
	$data = [];

	//account
	$data['Account'] = [
	    [
		'name' => 'Account::update',
		'scopes' => [ 'global' ],
		'desc' => 'Update account settings'
	    ],
	    //CLD: 2026-10-24T09:45:36+03:00: the usage page (AccountController::usage): what the account uses of its tier's limits, in all and per project; owners (also the roles already stored)
	    [
		'name' => 'Account::usage',
		'scopes' => [ 'global' ],
		'desc' => 'View the account\'s usage and its plan\'s limits',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
	    ],
	];

	//user
	$data['User'] = [
	    [
		'name' => 'User::create',
		'scopes' => [ 'global' ],
		'desc' => 'Create new users'
	    ],
	    [
		'name' => 'User::read',
		'scopes' => [ 'global' ],
		'desc' => 'View user details (also grants: list)'
	    ],
	    [
		'name' => 'User::list',
		'alias-of' => 'User::read'
	    ],
	    [
		'name' => 'User::update',
		'scopes' => [ 'global', 'own' ],
		'desc' => 'Edit users',
	    ],
	    [
		'name' => 'User::delete',
		'scopes' => [ 'global' ],
		'desc' => 'Delete users'
	    ],
	    //specials
	    [
		'name' => 'User::change-password',
		'scopes' => [ 'global', 'own' ],
		'desc' => 'Change password'
	    ],
	];

	//projects
	$data['Project'] = [
	    [
		'name' => 'Project::create',
		'scopes' => [ 'global' ],
		'desc' => 'Create new projects'
	    ],
	    [
		'name' => 'Project::read',
		'scopes' => [ 'global' ],
		'desc' => 'View project details (also grants: list)'
	    ],
	    [
		'name' => 'Project::list',
		'alias-of' => 'Project::read'
	    ],
	    [
		'name' => 'Project::update',
		'scopes' => [ 'global' ],
		'desc' => 'Edit projects'
	    ],
	    [
		'name' => 'Project::delete',
		'scopes' => [ 'global' ],
		'desc' => 'Delete projects'
	    ],
	];

	//roles
	$data['Role'] = [
	    [
		'name' => 'Role::create',
		'scopes' => [ 'global' ],
		'desc' => 'Create new roles'
	    ],
	    [
		'name' => 'Role::read',
		'scopes' => [ 'global' ],
		'desc' => 'View role details (also grants: list)'
	    ],
	    [
		'name' => 'Role::list',
		'alias-of' => 'Role::read'
	    ],
	    [
		'name' => 'Role::update',
		'scopes' => [ 'global' ],
		'desc' => 'Edit roles'
	    ],
	    [
		'name' => 'Role::delete',
		'scopes' => [ 'global' ],
		'desc' => 'Delete roles'
	    ],
	];

	//CLD: 2026-10-22T00:28:13+03:00: Variable::* removed: the project variables are credentials (type Variable), permitted with Credential::*

	$data['Credential'] = [
	    [
		'name' => 'Credential::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create credentials'
	    ],
	    [
		'name' => 'Credential::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View credentials (also grants: list); secrets are never shown',
	    ],
	    [
		'name' => 'Credential::list',
		'alias-of' => 'Credential::read'
	    ],
	    [
		'name' => 'Credential::update',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Edit, test and connect credentials'
	    ],
	    [
		'name' => 'Credential::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete credentials'
	    ],
	];

	//files
	$data['File'] = [
	    [
		'name' => 'File::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create new file'
	    ],
	    [
		'name' => 'File::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View file (also grants: list)'
	    ],
	    [
		'name' => 'File::list',
		'alias-of' => 'File::read'
	    ],
	    [
		'name' => 'File::update',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Edit file'
	    ],
	    [
		'name' => 'File::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete file'
	    ],
	];

	//workflows
	$data['Workflow'] = [
	    [
		'name' => 'Workflow::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create new workflow'
	    ],
	    [
		'name' => 'Workflow::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View workflow details (also grants: list)'
	    ],
	    [
		'name' => 'Workflow::list',
		'alias-of' => 'Workflow::read'
	    ],
	    [
		'name' => 'Workflow::update',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Edit workflows'
	    ],
	    [
		'name' => 'Workflow::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete workflows'
	    ],
	    //CLD: 2026-10-11T19:47:51+03:00: publish / unpublish (was Workflow::update): who may make a version live; defaults as Workflow::update (also for the roles already stored)
	    [
		'name' => 'Workflow::publish',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Publish and unpublish workflows (their triggers start runs of the published version)',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	];

	//data tables
	$data['DataTable'] = [
	    [
		'name' => 'DataTable::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create new data tables'
	    ],
	    [
		'name' => 'DataTable::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View data table schema (also grants: list)'
	    ],
	    [
		'name' => 'DataTable::list',
		'alias-of' => 'DataTable::read'
	    ],
	    [
		'name' => 'DataTable::update',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Edit data table schema'
	    ],
	    [
		'name' => 'DataTable::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete data tables'
	    ],
	    [
		'name' => 'DataTable::readData',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View data table data'
	    ],
	    [
		'name' => 'DataTable::writeData',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Change data table data'
	    ],
	];

	$data['AuditLog'] = [
	    [
		'name' => 'AuditLog::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View the audit log (also grants: list); project: the entries of the projects the user is a member of',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
	    ],
	    [
		'name' => 'AuditLog::list',
		'alias-of' => 'AuditLog::read'
	    ],
	];

	//CLD: 2026-10-11T17:45:25+03:00: the execution log (models\ExecutionLog): as the audit log
	$data['ExecutionLog'] = [
	    [
		'name' => 'ExecutionLog::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View the execution log (also grants: list); project: the entries of the projects the user is a member of',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
	    ],
	    [
		'name' => 'ExecutionLog::list',
		'alias-of' => 'ExecutionLog::read'
	    ],
	];

	//CLD: 2026-10-20T23:40:00+03:00: McpToken's permissions are registered by the SysMCPTools plugin (register)

	//CLD: 2026-10-20T23:40:00+03:00: + the plugins' (register)
	$data += self::$registered;

	return $data;
    }
}