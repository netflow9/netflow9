<?php

namespace sys;

class Boot {

    //the signed-in user as stored (read again at each request by after_user_login)
    public $user = null;

    //what a user who must set up 2FA (the account's "Enforce MFA") can still open: the profile (its Activate button), the 2FA activation, sign out
    const MFA_SETUP_ROUTES = [ 'user/profile', 'user/tfa_activate', 'site/logout', 'site/tfa' ];

    public function __construct() {
	//the audit log records the users' changes of objects (models\AuditLog; web only: actions of people)
	//the execution log (models\ExecutionLog: changes made by workflow runs, daemon, console, webhook calls);
	//registered in every mode each log decides what it records

	\V::app()->hooks->addHook('model:after-save', [ \models\AuditLog::class, 'afterModelSave' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\AuditLog::class, 'afterModelDelete' ]);
	\V::app()->hooks->addHook('model:after-save', [ \models\ExecutionLog::class, 'afterModelSave' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\ExecutionLog::class, 'afterModelDelete' ]);
	//the choices of the step forms read in this request may have changed
	\V::app()->hooks->addHook('model:after-save', [ \models\wf\WfNode::class, 'forgetChoices' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\wf\WfNode::class, 'forgetChoices' ]);

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

	//a polling trigger's state (e.g. Veeam event) goes with its workflow
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\TriggerState::class, 'beforeWorkflowDelete' ]);
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\wf\WfTask::class, 'beforeWorkflowDelete' ]);

	//the Assistant's conversation goes with its workflow
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\assistant\AssistantChat::class, 'beforeWorkflowDelete' ]);
	//the conversations with an agent go with its workflow; a run that stops ends its turn
	\V::app()->hooks->addHook('workflow:before-delete', [ \models\agent\AgentConversation::class, 'beforeWorkflowDelete' ]);
	\V::app()->hooks->addHook('execution:stopped', [ \models\agent\AgentConversation::class, 'afterRun' ]);
	//an execution's AI step traces go with it; and the conversation it answers is freed
	\V::app()->hooks->addHook('execution:before-delete', [ \models\agent\AgentTrace::class, 'dropFor' ]);
	\V::app()->hooks->addHook('execution:before-delete', [ \models\agent\AgentConversation::class, 'beforeExecutionDelete' ]);

	//a completed Email (inbound) run deletes its raw message, in every mode: the daemon runs them
	\V::app()->hooks->addHook('execution:end', [ \models\node\NodeTriggerEmail::class, 'afterExecutionEnd' ]);

	//the LDAP and SAML sign-ins: a deleted user's link; the sign-in (sys\Identity::authenticate) and who keeps a password here (password reset / change);
	//in every mode: the check suite signs in from the console
	\V::app()->hooks->addHook('model:after-delete', [ \models\LdapLink::class, 'afterModelDelete' ]);
	\V::app()->hooks->addHook('identity:authenticate', [ \util\LdapAuth::class, 'onAuthenticate' ]);
	\V::app()->hooks->addHook('identity:password-source', [ \util\LdapAuth::class, 'onPasswordSource' ]);
	\V::app()->hooks->addHook('model:after-delete', [ \models\saml\SamlLink::class, 'afterModelDelete' ]);
	\V::app()->hooks->addHook('identity:authenticate', [ \util\SamlAuth::class, 'onAuthenticate' ]);
	\V::app()->hooks->addHook('identity:password-source', [ \util\SamlAuth::class, 'onPasswordSource' ]);

	//the workflow daemon, a service of the Services page (util\Services); the plugins add theirs
	\V::app()->hooks->addHook('app:services', function(\ArrayObject $services) {
	    $services['daemon'] = [
		'label' => 'Workflow daemon',
		'description' => 'Runs the queued jobs: scheduled and background workflow runs, emails, timers',
		//the installation's own unit (util\Services::unit: nfn-daemon-<user>)
		'unit' => \util\Services::unit('daemon'),
		'pidfile' => BASE . '/run/daemon.pid',
		//on its port, 127.0.0.1:<DAEMON_PORT>
		'health' => function() {
		    $address = \models\Job::daemonAddress();
		    $port = parse_url($address, PHP_URL_PORT);
		    $s = @stream_socket_client($address, $errno, $errstr, 1);
		    if (!$s)
			return [ false, "does not answer on port {$port}" . ($errstr ? ": {$errstr}" : '') ];
		    fclose($s);
		    return [ true, "answers on port {$port}" ];
		},
	    ];
	}, 5);

	//the tool servers' services (util\ToolServers: an mcp.json entry's `service`, e.g. the web browser's nfn-playwright-<user>)
	\V::app()->hooks->addHook('app:services', function(\ArrayObject $services) {
	    foreach(\util\ToolServers::all() as $id => $s) {
		if (empty($s['service']) || isset($services[$s['service']]))
		    continue;
		$services[$s['service']] = [
		    'label' => $s['label'] . ' (tool server)',
		    'description' => 'The MCP server `' . $id . '` the AI steps use (Tool servers)',
		    'unit' => \util\Services::unit($s['service']),
		    'pidfile' => null,
		    'health' => function() use ($s) {
			[ $ok, $text ] = \util\ToolServers::ping($s, 5);
			return $ok ? [ true, 'MCP answers: ' . $text ] : [ false, 'MCP does not answer' ];
		    },
		];
	    }
	}, 6);

	//Haraka, inbound mail (util\InboundMail::service: when found and its unit is this installation's)
	\V::app()->hooks->addHook('app:services', function(\ArrayObject $services) {
	    if (($s = \util\InboundMail::service()) !== null)
		$services['haraka'] = $s;
	}, 7);

	if (\V::app()->config('app.mode') != 'web')
	    return;

    	\V::app()->hooks->addHook('on:after-user-login', [ $this, 'after_user_login' ]);
	//the account's "Enforce MFA": before the action (the sign-in's own request, still a guest then, completes and writes its audit entry)
	\V::app()->hooks->addHook('on:before-action', [ $this, 'enforce_mfa' ]);

	//the Webhook trigger's endpoint: `/hook/<32 hex token>` -> HookController::receive; callers are not users: no session
	\V::app()->session->noSessionFor('/hook/');
	\V::app()->router->add_rule('hook/<id:[a-f0-9]{32}>', 'hook/receive', true);

	//the GravityZone event trigger's endpoint: `/gravityzone/<32 hex token>` -> GravityzoneController::receive (GravityZone's push service; no session)
	\V::app()->session->noSessionFor('/gravityzone/');
	\V::app()->router->add_rule('gravityzone/<id:[a-f0-9]{32}>', 'gravityzone/receive', true);

	//the FortiGate event trigger's endpoint: `/fortigate/<32 hex token>` -> FortigateController::receive (an automation stitch's webhook; no session)
	\V::app()->session->noSessionFor('/fortigate/');
	\V::app()->router->add_rule('fortigate/<id:[a-f0-9]{32}>', 'fortigate/receive', true);
	//CLD: 2026-11-12T10:00:00+02:00: the Shopify event trigger's endpoint: `/shopify/<32 hex token>` -> ShopifyController::receive (the store's webhooks; no session)
	\V::app()->session->noSessionFor('/shopify/');
	\V::app()->router->add_rule('shopify/<id:[a-f0-9]{32}>', 'shopify/receive', true);
	\V::app()->router->add_rule('form/<id:[a-f0-9]{32}>', 'form/open', true);
	\V::app()->router->add_rule('task/<id:[a-f0-9]{24}>', 'task/open', true);
	//the chat page of a Chat trigger: `/agent/<32 hex token>`; its embeddable page: `/chat/<32 hex token>`, its token is the credential: no session
	\V::app()->router->add_rule('agent/<id:[a-f0-9]{32}>', 'agent/open', true);
	\V::app()->session->noSessionFor('/chat/');
	\V::app()->router->add_rule('chat/<id:[a-f0-9]{32}>', 'chat/open', true);

	//POST /mcp -> McpController::serve; its callers are tokens, not browser users: no session
	\V::app()->router->add_rule('mcp', 'mcp/serve', true);
	\V::app()->session->noSessionFor('/mcp');

	//the account's Assistant in the administration (the MultiTenant plugin's account page): its provider, its spending on its own key
	\V::app()->hooks->addHook('admin:account-panels', function($account) {
	    $om = \V::app()->om;
	    $data = $om->acrossAccounts(fn() => [
		'settings' => $om->findOne('AssistantSettings', [ 'aid' => $account->_id ]),
		'usage' => \models\assistant\AssistantUsage::current($account->_id),
	    ]);
	    echo \V::app()->view->renderFile('/views/assistant/_admin_account_panel', $data);
	});
    }

    public function after_user_login() {
	//the session keeps the user's data from the sign-in: check the stored user at each request, so that a user deleted, disabled or moved to
	//another account is signed out at once
	$user = \V::app()->om->findById('User', \V::app()->user->_id);
	if (empty($user) || $user->state != 'active' || (string)$user->aid !== (string)\V::app()->user->aid) {
	    \V::app()->log->info('Boot: signed out user ' . \V::app()->user->_id . ': deleted, not active, or of another account');
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}
	//a changed account role applies at once (AAA reads the role id from the session)
	if ((string)$user->role !== (string)\V::app()->user->role) {
	    \V::app()->user->role = $user->role;
	    \V::app()->auth->userRole = null;
	}
	$this->user = $user;

	//the stored user
	\V::app()->config('rt.user', $user);
	//its preferences (User prefs): the defaults + what is stored, cast (a key no longer in `prefs`' fields dropped); for the layout and views
	$prefs = $user->schema['prefs']->nestedObject(is_array($user->prefs) ? $user->prefs : [])->data();
	\V::app()->config('rt.prefs', $prefs);

	$account = \V::app()->om->findById('Account', \V::app()->user->aid);
	if (empty($account)) {
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}

	//a suspended account (state `disabled`) is signed out at once, unless a super-admin is viewing it (session `impersonator`: plugins\MultiTenant)
	if ($account->state === 'disabled' && empty($_SESSION['impersonator'])) {
	    \V::app()->log->info('Boot: signed out user ' . \V::app()->user->_id . ': the account is suspended');
	    \V::app()->user->logout();
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	}

	\V::app()->config('rt.account', $account);

	//the account's session limits (Account `session_idle`, minutes, and `session_max`, hours), for every session, "remember me" too
	//(that one only survives closing the browser): a session unused for longer, or signed in longer ago, is signed out
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
	//the project last opened (User prefs last_project), if the user still owns it or is a member,
	//	else the personal project,
	//	else the first project the user is a member of (e.g. a Participant),
	//	else a personal project created now (as the seed command does);
	//one kept in the session that is not in the account (deleted) is chosen again
	if (!empty(\V::app()->user->crtProject) && \V::app()->om->count('Project', [ '_id' => \V::app()->user->crtProject, 'aid' => $account->_id ]) == 0)
	    \V::app()->user->crtProject = null;
	if (empty(\V::app()->user->crtProject)) {
	    $uid = \V::app()->user->_id;
	    $project = empty($prefs['last_project']) ? null : \V::app()->om->findOne('Project', [ '_id' => $prefs['last_project'], 'aid' => $account->_id,
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
	//a signed-in user always has a current project: what asks of it (sys\AAA, the controllers) relies on it
	if (empty(\V::app()->user->crtProject))
	    throw new \ErrorException('Boot: no current project for user ' . \V::app()->user->_id);

    }

    //hook on:before-action: when the account enforces MFA (Account `enforce_mfa`), a signed-in user without 2FA can
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
