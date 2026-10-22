<?php

namespace models;

class User extends \sys\BaseModel {
    protected static $collection = 'users';
    //CLD: 2026-10-12T12:18:11+03:00: login by username or email; users of an account (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'username' => 1 ], [] ],
	[ [ 'email' => 1 ], [] ],
	[ [ 'aid' => 1 ], [] ],
    ];


    // we use these attributes just readonly, usually for display only
    // define just the fields that needs formatting: avatar, datetime
    public function getSchema() {
	$schema = $this->getSystemSchema();

	$fields = [
	    //email
	    [
		'key' => 'email',
		'type' => 'email',
		'transform' => 'tolower',
		'label' => 'Email',
		'help' => false,

		'required' => true,
		'unique' => true,

		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	    //password
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'permission',
		//CLD: 2026-10-10T18:54:42+03:00: not a form field (set by the password change / system)
		'showInForms' => false,
	    ],
	    //firstname
	    [
		'key' => 'firstname',
		'type' => 'text',
		'label' => 'Firstname',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
    	    ],
	    //lastname
	    [
		'key' => 'lastname',
		'type' => 'text',
		'label' => 'Lastname',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	    //state
	    [
		'key' => 'state',
		'type' => 'select',
		'default' => 'active',
		'choices' => [
		    'active' => 'Active',
		    'unconfirmed' => 'Unconfirmed',
		    'disabled' => 'Disabled',
		],
		'label' => 'State',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
		//CLD: 2026-10-10T18:54:42+03:00: not a form field (set by the password change / system)
		'showInForms' => false,
	    ],
	    //conf_code
	    [
		'key' => 'conf_code',
		'type' => 'text',
		'label' => 'Confirmation code',
		'required' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:54:42+03:00: not a form field (set by the password change / system)
		'showInForms' => false,
	    ],
	    //role
	    [
		'key' => 'role',
		'type' => 'object',
		'label' => 'Role',
		'object' => 'Role',
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	    //tfa_secret
	    [
		'key' => 'tfa_secret',
		'type' => 'text',
		'label' => '2FA secret',
		//CLD: 2026-10-10T09:14:41+03:00: stored encrypted
		'encrypt' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:54:42+03:00: not a form field (set by the password change / system)
		'showInForms' => false,
	    ]
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function __toString() {
	return $this->firstname . ' ' . $this->lastname;
    }


    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	//build full permission name
	//CLD: 2026-10-06T23:06:18+03:00: only when short: AAA::checkAccess passes the full name (e.g. User::update), which was doubled
	//(User::User::update) and never matched a role permission; field checks pass the short one
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//check if the user has permission
	if (!isset($role->permissions[$permName]))
	    return false;

	//now get the permission scope
	$scope = $role->permissions[$permName]['scope'] ?? 'none';

	if ($scope == 'own' && $userId == $this->_id)
	    return true;

	if ($scope == 'global')
	    return true;

	//default: false
	return false;
    }

}