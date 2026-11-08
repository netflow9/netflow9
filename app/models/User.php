<?php

namespace models;

class User extends \sys\BaseModel {
    protected static $collection = 'users';
    //login by username or email; users of an account (created by `v sys::indexes`)
    public static $indexes = [
	[ [ 'username' => 1 ], [] ],
	[ [ 'email' => 1 ], [] ],
	[ [ 'aid' => 1 ], [] ],
	//a "forgot password" link's token (only its hash; sparse: most users have none)
	[ [ 'reset_token_hash' => 1 ], [ 'unique' => true, 'sparse' => true ] ],
    ];

    //how long a "forgot password" link works
    const RESET_VALID = 86400;

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
		'showInForms' => false,
	    ],
	    //a super-admin of the installation (the MultiTenant plugin's administration: every account); set only from the console (./v MultiTenant::admins::grant), never by a form
	    [
		'key' => 'superadmin',
		'type' => 'checkbox',
		'label' => 'Super-admin',
		'default' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //CLD: 2026-10-24T08:20:00+03:00: the pending "forgot password" link: the SHA-256 hash of its token (the
	    //token is only in the email), when it stops working, the address that asked. Written by startPasswordReset / clearPasswordReset only (not save(): no audit entry)
	    [
		'key' => 'reset_token_hash',
		'type' => 'text',
		'label' => 'Password reset token hash',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'reset_expires_at',
		'type' => 'datetime',
		'label' => 'Password reset expires',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'reset_ip',
		'type' => 'text',
		'label' => 'Password reset asked from',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
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
		'encrypt' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //the time window (TOTP time slice) of the last 2FA code accepted: a code is used once (useTfaCode)
	    [
		'key' => 'tfa_last_slice',
		'type' => 'number',
		'label' => 'Last 2FA window',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //CLD: 2026-11-08T12:00:00+02:00: the user's preferences, one nested object (a field per preference: a new one is one more entry in `fields`): the project last
	    //opened (the next session starts on it, Boot), the sidebar's width (px, its resize handle) and collapsed state (its buttons: app.sidebar), the designer's step picker
	    //listing the basic steps only (its "Advanced steps" switch: app.workflow.js; the advanced ones still found by its search). Written by rememberPrefs only (not
	    //save(): no audit entry); read from rt.prefs (Boot: the defaults + what is stored)
	    [
		'key' => 'prefs',
		'type' => 'nested',
		'label' => 'Preferences',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
		'fields' => [
		    [
			'key' => 'last_project',
			'type' => 'object',
			'object' => 'Project',
			'label' => 'Last project'
		    ],
		    [
			'key' => 'sidebar_width',
			'type' => 'number',
			'label' => 'Sidebar width',
			'validate' => [ [ 'inRange', 'lower' => \controllers\SiteController::SIDEBAR_MIN_W, 'upper' => \controllers\SiteController::SIDEBAR_MAX_W, 'skipEmpty' => true,
			    'message' => 'From ' . \controllers\SiteController::SIDEBAR_MIN_W . ' to ' . \controllers\SiteController::SIDEBAR_MAX_W ] ]
		    ],
		    [
			'key' => 'sidebar_collapsed',
			'type' => 'checkbox',
			'label' => 'Sidebar collapsed',
			'default' => false
		    ],
		    [
			'key' => 'hide_advanced_steps',
			'type' => 'checkbox',
			'label' => 'Hide advanced steps',
			'default' => true
		    ],
		],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-11-08T12:00:00+02:00: new: the preferences in $set kept: only keys of `prefs`' fields, cast and validated by them. Written directly (not save(): no
    //audit entry), merged into the stored ones in the database (an update pipeline): the others, and another tab's changes, are kept; a missing or empty `prefs` is
    //started. Returns the errors ([ key => [ messages ] ]), [] when kept
    public function rememberPrefs(array $set) {
	$field = $this->schema['prefs'];
	$unknown = array_diff(array_keys($set), array_column($field->nestedFields(), 'key'));
	if ($unknown)
	    return [ (string)reset($unknown) => [ \V::t('Unknown preference') ] ];
	if (empty($set))
	    return [];

	$prefs = $field->nestedObject($set);
	if (!$prefs->validates())
	    return $prefs->errors();
	//the given keys only: the cast object also holds the other fields' defaults
	$values = array_intersect_key($prefs->data(), $set);
	\V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid ], [ [ '$set' => [ 'prefs' => [ '$mergeObjects' => [
	    [ '$cond' => [ [ '$eq' => [ [ '$type' => '$prefs' ], 'object' ] ], '$prefs', [ '$literal' => (object)[] ] ] ],
	    [ '$literal' => (object)$values ],
	] ] ] ] ]);
	return [];
    }

    public function __toString() {
	return $this->firstname . ' ' . $this->lastname;
    }

    //CLD: 2026-10-24T08:20:00+03:00: new: a new "forgot password" link for this user, valid RESET_VALID seconds, asked from $ip; replaces the user's earlier one.
    //Returns the token (for the email; only its hash is kept)
    //CLD: 2026-10-25T11:20:00+02:00: + $valid: seconds the link works (default RESET_VALID; a new user's first password: UserController::WELCOME_VALID)
    public function startPasswordReset($ip = null, $valid = null) {
	$token = bin2hex(random_bytes(24));
	\V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$set' => [
	    'reset_token_hash' => hash('sha256', $token),
	    'reset_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + (int)($valid ?? self::RESET_VALID)) * 1000),
	    'reset_ip' => $ip,
	] ]);
	return $token;
    }

    //the active user whose "forgot password" link has $token and still works, or null
    public static function findByResetToken($token) {
	if (!is_string($token) || $token === '')
	    return null;
	return \V::app()->om->findOne('User', [ 'reset_token_hash' => hash('sha256', $token), 'reset_expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ], 'state' => 'active' ]);
    }

    //the user's "forgot password" link used up (after the new password is set)
    public function clearPasswordReset() {
	\V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$unset' => [ 'reset_token_hash' => '', 'reset_expires_at' => '', 'reset_ip' => '' ] ]);
    }

    //2FA code $code of $secret (default: the user's own) accepted only once: refused when its time window is not newer than the last one accepted
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
	//CLD: 2026-11-07T12:00:00+02:00: $userId's account role, not the signed-in user's
	$role = \V::app()->auth->getUserRole($userId);
	if (empty($role))
	    return false;

	//build full permission name
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