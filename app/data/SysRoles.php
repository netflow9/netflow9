<?php

namespace data;

class SysRoles {
    public static $data = [
	//Owner - owner
	[
	    'name' => 'Owner',
	    'iname' => 'owner',
	    'permissions' => [],
	    'override' => [
		'Account::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'User::create' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'User::read' =>			[ 'enabled' => true, 'scope' => 'global', ],
		'User::update' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'User::delete' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'User::change-password' =>	[ 'enabled' => true, 'scope' => 'global', ],
		'Project::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Project::read' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'Project::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Project::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Role::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Role::read' =>			[ 'enabled' => true, 'scope' => 'global', ],
		'Role::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Role::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		//CLD: 2026-10-22T00:28:13+03:00: Variable::* removed: the project variables are credentials (type Variable), permitted with Credential::*
		//CLD: 2026-10-08T14:36:10+03:00: credentials
		'Credential::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Credential::read' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'Credential::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Credential::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'File::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'File::read' =>			[ 'enabled' => true, 'scope' => 'global', ],
		'File::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'File::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Workflow::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Workflow::read' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'Workflow::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'Workflow::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		//CLD: 2026-10-11T19:47:51+03:00: publish / unpublish
		'Workflow::publish' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'DataTable::create' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'DataTable::read' =>		[ 'enabled' => true, 'scope' => 'global', ],
		'DataTable::update' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'DataTable::delete' =>		[ 'enabled' => true, 'scope' => 'global' ],
		'DataTable::readData' =>	[ 'enabled' => true, 'scope' => 'global', ],
		'DataTable::writeData' =>	[ 'enabled' => true, 'scope' => 'global', ],
	    ],
	],

	//Member - member
	[
	    'name' => 'Member',
	    'iname' => 'member',
	    'permissions' => [],
	    'override' => [
		'Account::update' =>		[ 'enabled' => false, 'scope' => 'global' ],
		'User::create' =>		[ 'enabled' => false, ],
		'User::read' =>			[ 'enabled' => true, 'scope' => 'own', ],
		'User::update' =>		[ 'enabled' => true, 'scope' => 'own', ],
		'User::delete' =>		[ 'enabled' => false, ],
		'User::change-password' =>	[ 'enabled' => true, 'scope' => 'own', ],
		'Project::create' =>		[ 'enabled' => false, ],
		'Project::read' =>		[ 'enabled' => true, 'scope' => 'project', ],
		'Project::update' =>		[ 'enabled' => false, ],
		'Project::delete' =>		[ 'enabled' => false, ],
		//CLD: 2026-10-22T00:28:13+03:00: Variable::* removed: the project variables are credentials (type Variable), permitted with Credential::*
		//CLD: 2026-10-08T14:36:10+03:00: credentials
		'Credential::create' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'Credential::read' =>		[ 'enabled' => true, 'scope' => 'project', ],
		'Credential::update' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'Credential::delete' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'File::create' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'File::read' =>			[ 'enabled' => true, 'scope' => 'project', ],
		'File::update' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'File::delete' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'Workflow::create' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'Workflow::read' =>		[ 'enabled' => true, 'scope' => 'project', ],
		'Workflow::update' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'Workflow::delete' =>		[ 'enabled' => true, 'scope' => 'project' ],
		//CLD: 2026-10-11T19:47:51+03:00: publish / unpublish
		'Workflow::publish' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'DataTable::create' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'DataTable::read' =>		[ 'enabled' => true, 'scope' => 'project', ],
		'DataTable::update' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'DataTable::delete' =>		[ 'enabled' => true, 'scope' => 'project' ],
		'DataTable::readData' =>	[ 'enabled' => true, 'scope' => 'project', ],
		'DataTable::writeData' =>	[ 'enabled' => true, 'scope' => 'project', ],
	    ],
	],

	//CLD: 2026-10-13T21:12:34+03:00: Participant - participant (user's choice): fills in the project's forms and completes the tasks assigned to them; both need only the
	//project's membership (plugins\Forms: FormController / TaskController), so every permission is off but their own user account and seeing their projects
	[
	    'name' => 'Participant',
	    'iname' => 'participant',
	    'permissions' => [],
	    'override' => [
		'Account::update' =>		[ 'enabled' => false, 'scope' => 'global' ],
		'User::create' =>		[ 'enabled' => false, ],
		'User::read' =>			[ 'enabled' => true, 'scope' => 'own', ],
		'User::update' =>		[ 'enabled' => true, 'scope' => 'own', ],
		'User::delete' =>		[ 'enabled' => false, ],
		'User::change-password' =>	[ 'enabled' => true, 'scope' => 'own', ],
		'Project::create' =>		[ 'enabled' => false, ],
		'Project::read' =>		[ 'enabled' => true, 'scope' => 'project', ],
		'Project::update' =>		[ 'enabled' => false, ],
		'Project::delete' =>		[ 'enabled' => false, ],
		'Role::create' =>		[ 'enabled' => false, ],
		'Role::read' =>			[ 'enabled' => false, ],
		'Role::update' =>		[ 'enabled' => false, ],
		'Role::delete' =>		[ 'enabled' => false, ],
		//CLD: 2026-10-22T00:28:13+03:00: Variable::* removed: the project variables are credentials (type Variable), permitted with Credential::*
		'Credential::create' =>		[ 'enabled' => false, ],
		'Credential::read' =>		[ 'enabled' => false, ],
		'Credential::update' =>		[ 'enabled' => false, ],
		'Credential::delete' =>		[ 'enabled' => false, ],
		'File::create' =>		[ 'enabled' => false, ],
		'File::read' =>			[ 'enabled' => false, ],
		'File::update' =>		[ 'enabled' => false, ],
		'File::delete' =>		[ 'enabled' => false, ],
		'Workflow::create' =>		[ 'enabled' => false, ],
		'Workflow::read' =>		[ 'enabled' => false, ],
		'Workflow::update' =>		[ 'enabled' => false, ],
		'Workflow::delete' =>		[ 'enabled' => false, ],
		'Workflow::publish' =>		[ 'enabled' => false, ],
		'DataTable::create' =>		[ 'enabled' => false, ],
		'DataTable::read' =>		[ 'enabled' => false, ],
		'DataTable::update' =>		[ 'enabled' => false, ],
		'DataTable::delete' =>		[ 'enabled' => false, ],
		'DataTable::readData' =>	[ 'enabled' => false, ],
		'DataTable::writeData' =>	[ 'enabled' => false, ],
	    ],
	],

    ];
}