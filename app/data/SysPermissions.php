<?php

namespace data;

class SysPermissions {

    //the permissions plugins add: [ object name => [ permission, ... ] ] (as get()'s entries)
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
	    //the usage page (AccountController::usage): what the account uses of its tier's limits, in all and per project; owners (also the roles already stored)
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
	    //export / import of the project's workflows, credentials (with their secrets, in a file sealed by a passphrase) and data tables
	    //(ProjectController::export / import, util\Transfer); owners (also the roles already stored)
	    [
		'name' => 'Project::transfer',
		'scopes' => [ 'global' ],
		'desc' => 'Export and import workflows, credentials (with their secrets) and data tables',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
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
	    //sharing a credential with other projects of the account (ShareController), and allowing it any host; owners (also the roles already stored)
	    [
		'name' => 'Credential::share',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Share credentials with other projects',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
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
	    //publish / unpublish: who may make a version live; defaults as Workflow::update (also for the roles already stored)
	    [
		'name' => 'Workflow::publish',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Publish and unpublish workflows (their triggers start runs of the published version)',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	    //sharing a workflow with other projects of the account (ShareController); owners (also the roles already stored)
	    [
		'name' => 'Workflow::share',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Share workflows with other projects',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
	    ],
	    //what a shared workflow offers (WorkflowController::shared): its description, inputs, outputs and step names; not its settings or runs
	    [
		'name' => 'Workflow::viewShared',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'See what workflows shared with the project offer: description, inputs, outputs, step names',
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
	    //sharing a table with other projects of the account (ShareController); owners (also the roles already stored)
	    [
		'name' => 'DataTable::share',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Share data tables with other projects',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => false ]
		]
	    ],
	];

	//knowledge bases (models\kb\KnowledgeBase; controllers\KnowledgeController); defaults for the roles already stored, as the data tables'
	$kbDefaults = [ 'owner' => [ 'enabled' => true, 'scope' => 'global' ], 'member' => [ 'enabled' => true, 'scope' => 'project' ] ];
	$data['KnowledgeBase'] = [
	    [
		'name' => 'KnowledgeBase::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create knowledge bases',
		'defaults' => $kbDefaults,
	    ],
	    [
		'name' => 'KnowledgeBase::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View knowledge bases, their documents, and search them (also grants: list)',
		'defaults' => $kbDefaults,
	    ],
	    [
		'name' => 'KnowledgeBase::list',
		'alias-of' => 'KnowledgeBase::read'
	    ],
	    [
		'name' => 'KnowledgeBase::update',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Change knowledge bases: their settings, add and remove documents, re-index',
		'defaults' => $kbDefaults,
	    ],
	    [
		'name' => 'KnowledgeBase::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete knowledge bases',
		'defaults' => $kbDefaults,
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

	//the execution log (models\ExecutionLog): as the audit log
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

	//access tokens of the project's MCP server (a token acts as the user who made it, within that user's permissions);
	//defaults: also for the roles already stored
	$data['McpToken'] = [
	    [
		'name' => 'McpToken::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create MCP server tokens',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	    [
		'name' => 'McpToken::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View MCP server tokens (also grants: list); the tokens themselves are shown only once',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	    [
		'name' => 'McpToken::list',
		'alias-of' => 'McpToken::read'
	    ],
	    [
		'name' => 'McpToken::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete (revoke) MCP server tokens',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	];

	//and the plugins' (register)
	$data += self::$registered;

	return $data;
    }
}