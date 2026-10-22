<?php

//CLD: 2026-10-17T22:00:00+03:00: new model: an invitation to a project (Members tab: ProjectController::member_invite) for an email address that is not a user of the account yet;
//the link (valid 7 days, used once) opens SiteController::invite: the person's name and password make the user (account role Member) and a member of the project with the
//invitation's role (and of the account's other projects the address was invited to). Only a hash of the link's token is kept (the token is in the email); MongoDB removes it
//when it expires (expires_at, a TTL index). Global: the link is opened before signing in (aid kept)
//  [ $invite, $token ] = ProjectInvite::start($project, $email, $roleId, $by);   a new one (the address's earlier one for the project replaced)
//  $invite = ProjectInvite::findByToken($token);                                the valid one of a link, or null
//  ProjectInvite::pending($project)                                             the project's valid ones

namespace models\user;

class ProjectInvite extends \sys\BaseModel {
    protected static $collection = 'project_invites';
    public static $indexes = [
	[ [ 'token_hash' => 1 ], [ 'unique' => true ] ],
	[ [ 'project' => 1, 'email' => 1 ], [] ],
	[ [ 'expires_at' => 1 ], [ 'expireAfterSeconds' => 0 ] ],
    ];

    const VALID = 7 * 86400;

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project',
		'required' => true
	    ],
	    [
		'key' => 'email',
		'type' => 'email',
		'label' => 'Email',
		'required' => true,
		'transform' => 'tolower'
	    ],
	    [
		'key' => 'role',
		'type' => 'object',
		'object' => 'Role',
		'label' => 'Role',
		'required' => true
	    ],
	    [
		'key' => 'invited_by',
		'type' => 'object',
		'object' => 'User',
		'label' => 'Invited by'
	    ],
	    [
		'key' => 'token_hash',
		'type' => 'text',
		'label' => 'Token hash',
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'expires_at',
		'type' => 'datetime',
		'label' => 'Expires',
		'required' => true
	    ],
	];
    }

    public static function start($project, $email, $roleId, $by = null) {
	$email = strtolower(trim((string)$email));
	foreach(\V::app()->om->find('ProjectInvite', [ 'project' => $project->_id, 'email' => $email ]) as $old)
	    $old->delete();
	$token = bin2hex(random_bytes(24));
	$invite = \V::app()->om->create('ProjectInvite', [
	    'aid' => $project->aid,
	    'project' => $project->_id,
	    'email' => $email,
	    'role' => $roleId,
	    'invited_by' => $by ? $by->_id : null,
	    'token_hash' => hash('sha256', $token),
	    'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VALID) * 1000)
	]);
	if (!$invite->validates() || !$invite->save())
	    throw new \ErrorException('ProjectInvite: not saved: ' . json_encode($invite->errors()));
	return [ $invite, $token ];
    }

    public static function findByToken($token) {
	if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token))
	    return null;
	return \V::app()->om->findOne('ProjectInvite', [ 'token_hash' => hash('sha256', $token), 'expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ] ]);
    }

    public static function pending($project) {
	return \V::app()->om->find('ProjectInvite', [ 'project' => $project->_id, 'expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'sort' => [ 'email' => 1 ] ]);
    }

    //user $user (of the invitation's account, just made) joins the projects the address was invited to in that account (each with its invitation's role; projects gone or
    //personal skipped); the invitations are used up. The projects joined
    public function accept($user) {
	$om = \V::app()->om;
	$joined = [];
	foreach($om->find('ProjectInvite', [ 'aid' => $this->aid, 'email' => (string)$user->email, 'expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ] ]) as $invite) {
	    $project = $om->findOne('Project', [ '_id' => $invite->project, 'aid' => $this->aid ]);
	    if ($project && !$project->personal) {
		$members = (array)$project->members;
		if (!isset($members[(string)$user->_id])) {
		    $members[(string)$user->_id] = [ 'role' => $invite->role ];
		    $project->members = $members;
		    if ($project->save()) {
			$joined[] = $project;
			\models\AuditLog::record('project.member.add', $project, (string)$user . ' joined (invitation)', [], null, $user);
		    }
		}
	    }
	    $invite->delete();
	}
	return $joined;
    }
}
