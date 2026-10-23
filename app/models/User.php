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
	    //CLD: 2026-10-22T23:00:00+03:00: a super-admin of the installation (the Admin plugin's pages: every account); set only from the console (./v Admin::admins::grant), never by a form
	    [
		'key' => 'superadmin',
		'type' => 'checkbox',
		'label' => 'Super-admin',
		'default' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
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
	    ],
	    //CLD: 2026-10-24T00:20:00+03:00: the time window (TOTP time slice) of the last 2FA code accepted: a code is used once (useTfaCode)
	    [
		'key' => 'tfa_last_slice',
		'type' => 'number',
		'label' => 'Last 2FA window',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ]
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function __toString() {
	return $this->firstname . ' ' . $this->lastname;
    }

    //CLD: 2026-10-24T00:20:00+03:00: new: 2FA code $code of $secret (default: the user's own) accepted only once: refused when its time window is not newer than the last one accepted
    //(tfa_last_slice), so a code seen by someone else can't be used again within its ~90 s (SOC2.md gap 12). The window is claimed with one atomic update (two requests
    //with the same code: only one is accepted), written directly (not save(): no audit entry for it)
    public function useTfaCode($code, $secret = null) {
	$slice = 0;
	if (!(new \util\TOTP())->verifyCode($secret ?? $this->tfa_secret, (string)$code, 1, null, $slice))
	    return false;
	return \V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid, '$or' => [ [ 'tfa_last_slice' => [ '$lt' => $slice ] ], [ 'tfa_last_slice' => null ] ] ],
	    [ '$set' => [ 'tfa_last_slice' => $slice ] ]) > 0;
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