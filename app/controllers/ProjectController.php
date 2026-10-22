<?php

namespace controllers;

class ProjectController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function create($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (!\V::app()->user->can('Project::create'))
	    \V::app()->error(403, 'Unauthorized');

	$account = \V::app()->config('rt.account');
	$n = \V::app()->om->count('Project', [ 'aid' => $account->_id ]);

	$role = \V::app()->om->findOne('Role', [ 'aid' => $account->_id, 'iname' => 'owner' ]);

	$project = \V::app()->om->create('Project', [
	    'aid' => $account->_id,
	    'name' => 'My project ' . ++$n,
	    'owner' => \V::app()->user->_id,
	    'members' => [
		(string)\V::app()->user->_id => [ 'role' => $role->_id ],
	    ]
	]);
	if (!$project->validates())
	    \V::app()->error(400, 'Error creating project:' . print_r($project->errors(), true));

	$project->save();

	$this->addToast('success', 'OK', 'Project created!');
	$this->addEvent('sidebar::refresh');

	$this->redirect(\V::app()->createUrl('/project/settings', [ 'id' => $project->_id ]));
    }

    public function settings($req, $resp) {
	//CLD: 2026-10-05T20:42:44+03:00: validate the id and restrict the lookup to the current account (was findById: any account's project id worked)
	$id = $this->getOidParam('id', (string)\V::app()->user->crtProject);
	$account = \V::app()->config('rt.account');
	$project = empty($id) ? null : \V::app()->om->findOne('Project', [ '_id' => $id, 'aid' => $account->_id ]);
	if (empty($project))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('Project::update', [ 'object' => $project ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:42:44+03:00: only the fields the settings form shows (was the whole POST: owner, members, personal, ...)
	    //CLD: 2026-10-05T20:44:56+03:00: use the shared BaseController::onlyFields()
	    //CLD: 2026-10-10T18:57:33+03:00: assign with permissions (the form fields the user may update; was onlyFields icon, name)
	    $project->assign($req->data, true);
	    if ($project->validates()) {
		$project->save();
		$this->addToast('success', 'OK', 'Project saved!');
		$this->addEvent('sidebar::refresh');
	    } else {
		$this->addToast('error', 'ERROR', 'Error saving project');
	    }
	}
	$this->render('settings', compact('project'));
    }



    //CLD: 2026-10-13T20:55:25+03:00: the members of a project (user's choice: managed by who has Project::update): add one (a user of the account, with a role), change a member's role,
    //remove one. POST only, back to the Members tab. A personal project has one member; a change cannot take away the last member with the owner role. Each change is
    //saved through the model (members is not a form field) and written to the audit log
    public function member_add($req, $resp) {
	$project = $this->memberProject();
	$account = \V::app()->config('rt.account');
	$uid = (string)($req->data['user'] ?? '');
	$user = \util\Validator::rule('oid', $uid) ? \V::app()->om->findOne('User', [ '_id' => $uid, 'aid' => $account->_id ]) : null;
	$role = $this->memberRole($req->data['role'] ?? '');
	$members = (array)$project->members;
	if (!$user || !$role)
	    $this->addToast('error', 'Not added', 'Choose a user and a role');
	elseif (isset($members[$uid]))
	    $this->addToast('warning', 'Not added', (string)$user . ' is a member already');
	else {
	    $members[$uid] = [ 'role' => $role->_id ];
	    $this->saveMembers($project, $members, 'project.member.add', (string)$user . ' added as ' . (string)$role);
	}
	$this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
    }

    public function member_role($req, $resp) {
	$project = $this->memberProject();
	$uid = (string)($req->query['user'] ?? '');
	$role = $this->memberRole($req->data['role'] ?? '');
	$members = (array)$project->members;
	if (!isset($members[$uid]) || !$role)
	    $this->addToast('error', 'Not changed', 'Unknown member or role');
	else {
	    $members[$uid] = [ 'role' => $role->_id ];
	    $user = \V::app()->om->findById('User', $uid);
	    $this->saveMembers($project, $members, 'project.member.role', ($user ? (string)$user : $uid) . ' is ' . (string)$role);
	}
	$this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
    }

    public function member_remove($req, $resp) {
	$project = $this->memberProject();
	$uid = (string)($req->query['user'] ?? '');
	$members = (array)$project->members;
	if (!isset($members[$uid]))
	    $this->addToast('error', 'Not removed', 'Unknown member');
	else {
	    unset($members[$uid]);
	    $user = \V::app()->om->findById('User', $uid);
	    $this->saveMembers($project, $members, 'project.member.remove', ($user ? (string)$user : $uid) . ' removed');
	}
	$this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
    }

    //CLD: 2026-10-17T22:00:00+03:00: invite by email (POST email, role): a user of the account with that address is added now (and told by email); another address gets an
    //invitation (models\user\ProjectInvite: a link, valid 7 days, that makes the user and joins the project) - that needs User::create too, since it adds a user to the account
    public function member_invite($req, $resp) {
	$project = $this->memberProject();
	$account = \V::app()->config('rt.account');
	$email = strtolower(trim((string)($req->data['email'] ?? '')));
	$role = $this->memberRole($req->data['role'] ?? '');
	$back = fn() => $this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
	if (!\util\Validator::rule('email', $email) || !$role) {
	    $this->addToast('error', 'Not invited', 'Enter an email address and choose a role');
	    $back();
	}
	$inviter = \V::app()->om->findById('User', \V::app()->user->_id);
	$vars = [ 'inviter' => (string)$inviter, 'project' => (string)$project->name, 'account' => (string)($account->name ?? '') ?: (string)$account->hostname ];
	//CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host

	$user = \V::app()->om->findOne('User', [ 'aid' => $account->_id, 'email' => $email ]);
	if ($user) {
	    $members = (array)$project->members;
	    if (isset($members[(string)$user->_id]))
		$this->addToast('warning', 'Not added', (string)$user . ' is a member already');
	    else {
		$members[(string)$user->_id] = [ 'role' => $role->_id ];
		$saved = $this->saveMembers($project, $members, 'project.member.add', (string)$user . ' added as ' . (string)$role);
		if ($saved && $user->state === 'active')
		    \util\EmailSend::system('project_added', (string)$user->email, $vars + [ 'link' => \V::app()->createAbsoluteUrl('/site/index') ]);
	    }
	    $back();
	}

	if (!\V::app()->user->can('User::create')) {
	    $this->addToast('error', 'Not invited', $email . ' is not a user of the account: only who may add users can invite new people');
	    $back();
	}
	[ , $token ] = \models\user\ProjectInvite::start($project, $email, $role->_id, $inviter);
	\util\EmailSend::system('project_invite', $email, $vars + [ 'link' => \V::app()->createAbsoluteUrl('/site/invite', [ 't' => $token ]), 'days' => intdiv(\models\user\ProjectInvite::VALID, 86400) ]);
	\models\AuditLog::record('project.member.invite', $project, $email . ' invited as ' . (string)$role);
	$this->addToast('success', 'OK', 'Invitation sent to ' . $email);
	$back();
    }

    //CLD: 2026-10-17T22:00:00+03:00: a pending invitation (?invite): sent again (a new link, the old one stops working) or cancelled
    public function member_invite_resend($req, $resp) {
	$project = $this->memberProject();
	$invite = $this->memberInvite($project);
	if ($invite) {
	    $account = \V::app()->config('rt.account');
	    $inviter = \V::app()->om->findById('User', \V::app()->user->_id);
	    [ , $token ] = \models\user\ProjectInvite::start($project, (string)$invite->email, $invite->role, $inviter);
	    //CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host
	    \util\EmailSend::system('project_invite', (string)$invite->email, [ 'inviter' => (string)$inviter, 'project' => (string)$project->name,
		'account' => (string)($account->name ?? '') ?: (string)$account->hostname, 'link' => \V::app()->createAbsoluteUrl('/site/invite', [ 't' => $token ]),
		'days' => intdiv(\models\user\ProjectInvite::VALID, 86400) ]);
	    $this->addToast('success', 'OK', 'Invitation sent again to ' . $invite->email);
	}
	$this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
    }

    public function member_invite_cancel($req, $resp) {
	$project = $this->memberProject();
	$invite = $this->memberInvite($project);
	if ($invite) {
	    $invite->delete();
	    \models\AuditLog::record('project.member.uninvite', $project, 'invitation of ' . $invite->email . ' cancelled');
	    $this->addToast('success', 'OK', 'Invitation of ' . $invite->email . ' cancelled');
	}
	$this->redirect('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'members' ]);
    }

    //the pending invitation ?invite of project $project, or null (told)
    private function memberInvite($project) {
	$id = (string)(\V::app()->request->query['invite'] ?? '');
	$invite = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('ProjectInvite', [ '_id' => $id, 'project' => $project->_id, 'aid' => $project->aid ]) : null;
	if (!$invite)
	    $this->addToast('error', 'Not found', 'The invitation was used or cancelled meanwhile');
	return $invite;
    }

    //the project of ?id (this account), POST, Project::update, not personal
    private function memberProject() {
	if (!\V::app()->request->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$id = $this->getOidOrError();
	$project = \V::app()->om->findOne('Project', [ '_id' => $id, 'aid' => \V::app()->config('rt.account')->_id ]);
	if (empty($project))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can('Project::update', [ 'object' => $project ]))
	    \V::app()->error(403, 'Unauthorized');
	if ($project->personal)
	    \V::app()->error(400, 'A personal project has no other members');
	return $project;
    }

    //a role of this account, or null
    private function memberRole($id) {
	$id = (string)$id;
	return \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('Role', [ '_id' => $id, 'aid' => \V::app()->config('rt.account')->_id ]) : null;
    }

    //saves the members (a change does not take away the last member with the owner role) and records the change
    //CLD: 2026-10-17T22:00:00+03:00: returns whether saved (member_invite emails only then)
    private function saveMembers($project, array $members, $action, $details) {
	$owner = \V::app()->om->findOne('Role', [ 'aid' => $project->aid, 'iname' => 'owner' ]);
	$owners = fn(array $list) => count(array_filter($list, fn($m) => $owner && (string)($m['role'] ?? '') === (string)$owner->_id));
	if ($owners((array)$project->members) > 0 && $owners($members) == 0) {
	    $this->addToast('error', 'Not saved', 'A project needs at least one member with the Owner role');
	    return false;
	}
	$project->members = $members;
	if (!$project->validates() || !$project->save()) {
	    $this->addToast('error', 'Not saved', 'The project could not be saved');
	    return false;
	}
	\models\AuditLog::record($action, $project, $details);
	$this->addToast('success', 'OK', $details);
	$this->addEvent('sidebar::refresh');
	return true;
    }
}