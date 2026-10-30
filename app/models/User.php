<?php

namespace models;

class User extends \sys\BaseModel {
    protected static $collection = 'users';
    //login by username or email; users of an account (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'username' => 1 ], [] ],
	[ [ 'email' => 1 ], [] ],
	[ [ 'aid' => 1 ], [] ],
	//CLD: 2026-10-24T08:20:00+03:00: a "forgot password" link's token (only its hash; sparse: most users have none)
	[ [ 'reset_token_hash' => 1 ], [ 'unique' => true, 'sparse' => true ] ],
    ];

    //CLD: 2026-10-24T08:20:00+03:00: how long a "forgot password" link works (was PasswordReset::VALID)
    const RESET_VALID = 86400;
    //CLD: 2026-10-25T11:20:00+02:00: how long the link of a new user's welcome email works (UserController::create / send_link), as a project invitation's
    const WELCOME_VALID = 7 * 86400;

    //CLD: 2026-10-25T11:20:00+02:00: a link to choose a password, emailed to this user (a new user's first one, or again on request): data\SysEmails `user_welcome`, by $by (who
    //created the user); false when the user has no password here (e.g. LDAP / SSO) or is not active
    public function sendPasswordLink($by = null) {
	if ($this->state !== 'active' || \sys\Identity::passwordSource($this) !== null)
	    return false;
	$token = $this->startPasswordReset(\V::app()->request->userIP ?? null, self::WELCOME_VALID);
	$account = \V::app()->om->findById('Account', (string)$this->aid);
	\util\EmailSend::system('user_welcome', (string)$this->email, [
	    'inviter' => trim((string)($by->firstname ?? '') . ' ' . (string)($by->lastname ?? '')) ?: 'An administrator',
	    'account' => (string)($account->name ?? '') ?: (string)($account->hostname ?? ''),
	    'email' => (string)$this->email,
	    'days' => (string)(self::WELCOME_VALID / 86400),
	    'link' => \V::app()->createAbsoluteUrl('/site/reset', [ 't' => $token ]),
	]);
	return true;
    }


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
		'showInForms' => false,
	    ],
	    //a super-admin of the installation (the Admin plugin's pages: every account); set only from the console (./v Admin::admins::grant), never by a form
	    [
		'key' => 'superadmin',
		'type' => 'checkbox',
		'label' => 'Super-admin',
		'default' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //CLD: 2026-10-24T08:20:00+03:00: the pending "forgot password" link (was the model models\user\PasswordReset; replaces conf_code, never used): the SHA-256 hash of its token (the
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
	    //CLD: 2026-10-30T12:00:00+02:00: the UI kept across sessions and browsers: the project last opened (the next session starts on it, Boot) and the sidebar's width (px, set by its
	    //resize handle: app.sidebar). Written by rememberUi only (not save(): no audit entry)
	    [
		'key' => 'last_project',
		'type' => 'object',
		'label' => 'Last project',
		'object' => 'Project',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'sidebar_width',
		'type' => 'number',
		'label' => 'Sidebar width',
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //CLD: 2026-10-30T13:00:00+02:00: the sidebar collapsed (its collapse / expand buttons: app.sidebar)
	    [
		'key' => 'sidebar_collapsed',
		'type' => 'checkbox',
		'label' => 'Sidebar collapsed',
		'default' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ]
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-10-30T12:00:00+02:00: new: the signed-in user's UI state kept ($set: last_project, sidebar_width, sidebar_collapsed)
    public static function rememberUi(array $set) {
	if (\V::app()->user->isGuest || empty($set))
	    return;
	\V::app()->om->updateOne('User', [ '_id' => \V::app()->om->to_oid(\V::app()->user->_id) ], [ '$set' => $set ]);
    }

    public function __toString() {
	return $this->firstname . ' ' . $this->lastname;
    }

    //CLD: 2026-10-24T08:20:00+03:00: new (was PasswordReset::start): a new "forgot password" link for this user, valid RESET_VALID seconds, asked from $ip; replaces the user's earlier one.
    //Returns the token (for the email; only its hash is kept)
    //CLD: 2026-10-25T11:20:00+02:00: + $valid: seconds the link works (default RESET_VALID; a new user's first password: WELCOME_VALID)
    public function startPasswordReset($ip = null, $valid = null) {
	$token = bin2hex(random_bytes(24));
	\V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$set' => [
	    'reset_token_hash' => hash('sha256', $token),
	    'reset_expires_at' => new \MongoDB\BSON\UTCDateTime((time() + (int)($valid ?? self::RESET_VALID)) * 1000),
	    'reset_ip' => $ip,
	] ]);
	return $token;
    }

    //CLD: 2026-10-24T08:20:00+03:00: new: the active user whose "forgot password" link has $token and still works, or null
    public static function findByResetToken($token) {
	if (!is_string($token) || $token === '')
	    return null;
	return \V::app()->om->findOne('User', [ 'reset_token_hash' => hash('sha256', $token), 'reset_expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ], 'state' => 'active' ]);
    }

    //CLD: 2026-10-24T08:20:00+03:00: new: the user's "forgot password" link used up (after the new password is set)
    public function clearPasswordReset() {
	\V::app()->om->updateOne('User', [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$unset' => [ 'reset_token_hash' => '', 'reset_expires_at' => '', 'reset_ip' => '' ] ]);
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