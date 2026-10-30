<?php

namespace sys;

class Boot {

    //CLD: 2026-10-21T16:30:00+03:00: the signed-in user as stored now (read again at each request by after_user_login)
    public $user = null;

    //CLD: 2026-10-21T16:30:00+03:00: what a user who must set up 2FA (the account's "Enforce MFA") can still open: the profile (its Activate button), the 2FA activation, sign out
    const MFA_SETUP_ROUTES = [ 'user/profile', 'user/tfa_activate', 'site/logout', 'site/tfa' ];

    public function __construct() {
	//the audit log records the users' changes of objects (models\AuditLog; web only: actions of people)
	//the execution log (models\ExecutionLog: changes made by workflow runs, daemon, console, webhook calls);
	//registered in every mode each log decides what it records

	\V::app()->hooks->addHook('model:after-save', [ \models\AuditLog::class, 'afterModelSave' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\AuditLog::class, 'afterModelDelete' ]);
	\V::app()->hooks->addHook('model:after-save', [ \models\ExecutionLog::class, 'afterModelSave' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\ExecutionLog::class, 'afterModelDelete' ]);

	//each minute of the daemon (commands/DaemonCommand.php, daemon:tick): start the scheduled steps whose schedule matches
	//and continue the sleeping executions whose time has come
	\V::app()->hooks->addHook('daemon:tick', [ \util\Scheduler::class, 'tick' ]);
	\V::app()->hooks->addHook('daemon:tick', [ \util\Scheduler::class, 'wake' ]);

	//the hooks of the workflow steps:
	//	- the token index follows the published version;
	//	- a deleted workflow takes its IMAP check state, tokens and tasks with it;
	//	- a completed Email inbound run deletes its raw message
	\V::app()->hooks->addHook('workflow:after-save', [ \models\TriggerToken::class, 'syncForWorkflow' ]);
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\TriggerToken::class, 'beforeWorkflowDelete' ]);
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\MailPoll::class, 'beforeWorkflowDelete' ]);

	//the FTP / SFTP step's state (files taken) goes with its workflow
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\RemoteFileState::class, 'beforeWorkflowDelete' ]);
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\WfTask::class, 'beforeWorkflowDelete' ]);
	//CLD: 2026-10-26T23:49:50+02:00: the Assistant's conversation goes with its workflow (was the Assistant plugin's)
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\AssistantChat::class, 'beforeWorkflowDelete' ]);

	if (\V::app()->config('app.mode') != 'web')
	    return;

    	\V::app()->hooks->addHook('on:after-user-login', [ $this, 'after_user_login' ]);
	//CLD: 2026-10-21T16:30:00+03:00: the account's "Enforce MFA": before the action (the sign-in's own request, still a guest then, completes and writes its audit entry)
	\V::app()->hooks->addHook('on:before-action', [ $this, 'enforce_mfa' ]);

	//the Webhook trigger's endpoint: `/hook/<32 hex token>` -> HookController::receive; callers are not users: no session
	\V::app()->session->noSessionFor('/hook/');
	\V::app()->router->add_rule('hook/<id:[a-f0-9]{32}>', 'hook/receive', true);
	\V::app()->router->add_rule('form/<id:[a-f0-9]{32}>', 'form/open', true);
	\V::app()->router->add_rule('task/<id:[a-f0-9]{24}>', 'task/open', true);
	//CLD: 2026-10-26T23:56:07+02:00: POST /mcp -> McpController::serve (was the SysMCPTools plugin's); its callers are tokens, not browser users: no session
	\V::app()->router->add_rule('mcp', 'mcp/serve', true);
	\V::app()->session->noSessionFor('/mcp');

	//CLD: 2026-10-26T23:49:50+02:00: the account's Assistant in the administration (the Admin plugin's account page; was the Assistant plugin's): its provider, its spending on its own key
	\V::app()->hooks->addHook('admin:account-panels', function($account) {
	    $om = \V::app()->om;
	    $data = $om->acrossAccounts(fn() => [
		'settings' => $om->findOne('AssistantSettings', [ 'aid' => $account->_id ]),
		'usage' => \models\AssistantUsage::current($account->_id),
	    ]);
	    echo \V::app()->view->renderFile('/views/assistant/_admin_account_panel', $data);
	});
    }

    public function after_user_login() {
	//CLD: 2026-10-21T16:30:00+03:00: the session keeps the user's data from the sign-in: check the stored user at each request, so that a user deleted, disabled or moved to
	//another account is signed out at once (was: kept the access until the session expired, up to 30 days with "remember me")
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user) || $user->state != 'active' || (string)$user->aid !== (string)\V::app()->user->aid) {
	    \V::app()->log->info('Boot: signed out user ' . \V::app()->user->_id . ': deleted, not active, or of another account');
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}
	//CLD: 2026-10-21T16:30:00+03:00: a changed account role applies at once (AAA reads the role id from the session; was: the role of the sign-in)
	if ((string)$user->role !== (string)\V::app()->user->role) {
	    \V::app()->user->role = $user->role;
	    \V::app()->auth->userRole = null;
	}
	$this->user = $user;
	//CLD: 2026-10-30T12:00:00+02:00: the stored user, for the layout (its UI state: sidebar_width)
	\V::app()->config('rt.user', $user);

	$account = \V::app()->om->findById('Account', \V::app()->user->aid);
	if (empty($account)) {
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}

	//CLD: 2026-10-22T23:00:00+03:00: a suspended account (state `disabled`) is signed out at once, unless a super-admin is viewing it (session `impersonator`: plugins\Admin)
	if ($account->state === 'disabled' && empty($_SESSION['impersonator'])) {
	    \V::app()->log->info('Boot: signed out user ' . \V::app()->user->_id . ': the account is suspended');
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}

	\V::app()->config('rt.account', $account);

	//CLD: 2026-10-21T23:00:00+03:00: the account's session limits (Account `session_idle`, minutes, and `session_max`, hours), for every session, "remember me" too
	//(that one only survives closing the browser): a session unused for longer, or signed in longer ago, is signed out (was: 24 hours idle, 30 days with "remember me")
	$now = time();
	$loginTs = (int)\V::app()->session->getState(Session::KEY_LOGIN_TS);
	$activeTs = (int)\V::app()->session->getState(Session::KEY_ACTIVE_TS);
	$idle = (int)($account->session_idle ?: 30) * 60;
	$max = (int)($account->session_max ?: 12) * 60*60;
	if ($loginTs === 0 || $now - $activeTs > $idle || $now - $loginTs > $max) {
	    \V::app()->log->info('Boot: signed out user ' . \V::app()->user->_id . ': session ' . ($loginTs === 0 ? 'from before the session limits' : ($now - $loginTs > $max ? 'too long' : 'idle too long')));
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}
	\V::app()->session->setState(Session::KEY_ACTIVE_TS, $now);

	//set a project in session
	//CLD: 2026-10-30T12:00:00+02:00: the project last opened (User last_project), if the user still owns it or is a member,
	//	else the personal project,
	//	else the first project the user is a member of (e.g. a Participant),
	//	else a personal project created now (as the seed command does);
	if (empty(\V::app()->user->crtProject)) {
	    $uid = \V::app()->user->_id;
	    $project = empty($this->user->last_project) ? null : \V::app()->om->findOne('Project', [ '_id' => \V::app()->om->to_oid($this->user->last_project), 'aid' => $account->_id,
		'$or' => [ [ 'owner' => $uid ], [ 'members.' . (string)$uid => [ '$exists' => true ] ] ] ]);
	    if (empty($project))
		$project = \V::app()->om->findOne('Project', [ 'aid' => $account->_id, 'owner' => $uid, 'personal' => true ]);
	    if (empty($project))
		$project = \V::app()->om->findOne('Project', [ 'aid' => $account->_id, 'members.' . (string)$uid => [ '$exists' => true ] ], [ 'sort' => [ '_id' => 1 ] ]);
	    if (empty($project)) {
		$project = \V::app()->om->create('Project', [ 'aid' => $account->_id, 'name' => 'Personal', 'owner' => $uid, 'personal' => true,
		    'members' => [ (string)$uid => [ 'role' => \V::app()->user->role ] ] ]);
		$project->save();
	    }
	    \V::app()->user->crtProject = $project->_id;
	}

    }

    //hook on:before-action: when the account enforces MFA (Account `enforce_mfa`, was saved but never checked), a signed-in user without 2FA can
    //only set it up (MFA_SETUP_ROUTES): pages go to the profile, other requests (ajax) are refused
    public function enforce_mfa() {
	if (\V::app()->user->isGuest || empty($this->user) || !empty($this->user->tfa_secret))
	    return;
	//not while a super-admin views the account (they passed their own 2FA; the account's user may have none)
	if (!empty($_SESSION['impersonator']))
	    return;
	if (empty(\V::app()->var('rt.account')->enforce_mfa ?? null))
	    return;

	$route = strtolower(\V::app()->var('rt.controller') . '/' . \V::app()->var('rt.action'));
	if (\V::app()->var('rt.module') === null && in_array($route, self::MFA_SETUP_ROUTES, true))
	    return;

	if (\V::app()->request->is('ajax') && !\V::app()->request->is('pjax'))
	    \V::app()->error(403, \V::t('Set up two-factor authentication first'));
	\V::app()->response->redirect(\V::app()->createUrl('/user/profile'));
    }

}
