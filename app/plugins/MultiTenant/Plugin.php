<?php

/*
    CLD: 2026-10-31T21:00:00+02:00: MultiTenant: the Admin, Registration and SystemAI plugins in one (an installation that hosts many accounts). Enabled in app/plugins.php.
    Its options: config.php, or config.local.php next to it when present (the installation's own, not in git; options()):
      registration   the sign-up of new accounts (was the Registration plugin)
      system_ai      the System AI, paid in credits (was the SystemAI plugin; it also needs SYSTEM_AI_* in app.env)
    The administration (was the Admin plugin), always:
      the installation's administration, for super-admins (users with the `superadmin` flag, set only from the console: ./v MultiTenant::admins::grant).
      controllers/AdminController.php    /MultiTenant/admin/...: the installation, the accounts, an account (suspend / reactivate, "view as"), the plugins' sections;
                                         credits_plan / credits_grant: an account's System AI credits (was the SystemAI plugin's AdminController)
      CLD: 2026-10-31T11:05:39+02:00: the services are the core's (util\Services, controllers\ServicesController: Settings, Services); with this plugin, only super-admins see them
      commands/AdminsCommand.php         ./v MultiTenant::admins::<list | grant | revoke>
      CLD: 2026-10-31T11:53:38+02:00: tiers.php (tiers.local.php)    the accounts' tiers (util\Tiers, hook tiers:table; was app/tiers.php); commands/TiersCommand.php: ./v MultiTenant::tiers::<show | set>
      Who may: a super-admin with 2FA (require()); every action is in the audit log of the account acted on and of the super-admin's own (audit()).
      "View as": the super-admin is signed in as the account's owner, read-only (any request but GET refused, except a few that only read), at most VIEW_MAX, with a bar to go back;
      the session's `impersonator` keeps who they are (sys\Boot lets a suspended account be viewed so).
      Hooks for the plugins:
        admin:installation-panels ()                    the installation page's sections (echo)
        admin:account-panels ($account)                 the account page's sections (echo)
        admin:account-features ($account)               the account page's sections of what the account may use, after its features (echo; CLD: 2026-10-24T10:06:05+03:00: e.g. the tool servers)
        admin:account-summary ($account, $items: \ArrayObject)   short texts for the accounts list (e.g. the credits)
    The registration (option `registration`): the sign-up page (controllers/RegisterController.php: /MultiTenant/register, /MultiTenant/register/confirm; models/Signup.php,
    RegisterForm.php) and its link on the sign-in page; without it, no sign-up.
    The System AI (option `system_ai`): the model server this installation offers to every account (app.env SYSTEM_AI_*: config ai.system), paid in credits (as a prepaid SIM: a
    monthly allowance without rollover, top-ups that last 12 months), used by the Assistant (core: models\AssistantSettings, provider System) and the AI steps of the same provider
    (Credential System AI; models\node\NodeAppAi, hook ai:system-provider); without it the Assistant offers no System provider.
      util/SystemAi.php                  the System AI (config), the known prices, a step's credential
      util/StepSession.php               an AI step's run: credits reserved, counted, settled
      models/SystemAiWallet.php          an account's credits; models/SystemAiLedger.php: their changes
      credit_rates.php                   the credits per model and the margin (credit_rates.local.php: the installation's own)
      controllers/CreditsController      /MultiTenant/credits/index: the account's credits (Settings menu: AI credits)
      commands/CreditsCommand.php        ./v MultiTenant::credits::<show | add | plan | rates>
*/

namespace plugins\MultiTenant;

class Plugin {
    //how long a "view as" lasts at most (s)
    const VIEW_MAX = 3600;
    //what may be sent (not GET) while viewing as: go back, sign out, the expression help (reads only)
    const VIEW_ALLOWED = [ 'admin/admin/exit_view', 'site/logout', 'workflow/expression' ];

    //CLD: 2026-10-31T21:00:00+02:00: the options (config.php, or config.local.php when present); set by the check suite to try both ways
    public static $options = null;

    public static function options() {
	if (self::$options === null) {
	    $f = __DIR__ . '/config.local.php';
	    $o = require(is_file($f) ? $f : __DIR__ . '/config.php');
	    self::$options = (is_array($o) ? $o : []) + [ 'registration' => false, 'system_ai' => false ];
	}
	return self::$options;
    }

    //CLD: 2026-10-31T21:00:00+02:00: this plugin is enabled (app/plugins.php)
    public static function enabled() {
	return in_array('MultiTenant', (array)\V::app()->config('modules'), true);
    }

    //CLD: 2026-10-31T21:00:00+02:00: the sign-up of new accounts is on (enabled, option `registration`)
    public static function registration() {
	return self::enabled() && !empty(self::options()['registration']);
    }

    //CLD: 2026-10-31T21:00:00+02:00: the System AI is on (enabled, option `system_ai`); whether it is configured: util\SystemAi::available
    public static function systemAi() {
	return self::enabled() && !empty(self::options()['system_ai']);
    }

    public function __construct() {
	//CLD: 2026-10-31T21:00:00+02:00: the objects of the sign-up and of the System AI's credits, whatever the options (their collections are kept)
	\V::app()->om->registerObjects([
	    //a sign-up waiting for its email to be confirmed (no account yet: not in the audit log)
	    'Signup' => [
		'class' => __NAMESPACE__ . '\\models\\Signup',
		'auditLog' => false,
	    ],
	    'SystemAiWallet' => [
		'class' => __NAMESPACE__ . '\\models\\SystemAiWallet',
	    ],
	    'SystemAiLedger' => [
		'class' => __NAMESPACE__ . '\\models\\SystemAiLedger',
	    ],
	]);

	//CLD: 2026-10-31T21:00:00+02:00: the sign-up (was the Registration plugin), with the option
	if (self::options()['registration'])
	    $this->initRegistration();
	//CLD: 2026-10-31T21:00:00+02:00: the System AI (was the SystemAI plugin), with the option
	if (self::options()['system_ai'])
	    $this->initSystemAi();

	//CLD: 2026-10-31T11:05:39+02:00: the workflow daemon's service is the core's (sys\Boot, hook app:services)

	//CLD: 2026-10-31T11:53:38+02:00: the accounts' tiers (util\Tiers): this plugin's tiers.php, or tiers.local.php next to it when present (an installation's own, not in git); in every mode
	\V::app()->hooks->addHook('tiers:table', function(\ArrayObject $table) {
	    $f = __DIR__ . '/tiers.local.php';
	    $t = require(is_file($f) ? $f : __DIR__ . '/tiers.php');
	    foreach((is_array($t) ? $t : []) as $k => $v)
		$table[$k] = $v;
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (self::isAdmin())
		echo '<a href="' . \V::app()->createUrl('/MultiTenant/admin/index') . '" class="nav-link"><i class="bi bi-shield-lock"></i> Administration</a>';
	});
	\V::app()->hooks->addHook('on:before-action', [ $this, 'beforeAction' ]);
	\V::app()->hooks->addHook('layout:page-top', [ $this, 'viewBar' ]);
    }

    // ------------------------------------------------------------------------------------
    //who

    //the signed-in user is a super-admin (read from the database each request: sys\Boot::$user), not viewing as someone
    public static function isAdmin() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || self::viewing())
	    return false;
	return !empty(\V::app()->boot->user->superadmin ?? null);
    }

    //the admin pages: a super-admin with 2FA, else refused (not found: the pages are not shown to others)
    public static function require() {
	if (\V::app()->user->isGuest)
	    \V::app()->user->loginRequired();
	if (self::viewing())
	    \V::app()->error(403, 'You are viewing an account: go back first (the bar at the bottom)');
	if (!self::isAdmin())
	    \V::app()->error(404, 'Not found');
	if (empty(\V::app()->boot->user->tfa_secret))
	    \V::app()->error(403, 'Super-admins need two-factor authentication: set it up in your profile first');
    }

    //"view as" in progress: [ uid, aid, name, account, account_name, user_name, since, return ], or null
    public static function viewing() {
	return !empty($_SESSION['impersonator']) && is_array($_SESSION['impersonator']) ? $_SESSION['impersonator'] : null;
    }

    // ------------------------------------------------------------------------------------
    //the audit log

    //$action of the super-admin signed in (or $admin: [ uid, aid, name ]) on account $account: in the account's audit log and in the super-admin's own
    public static function audit($action, $account, $details = null, $admin = null) {
	$admin = $admin ?? [ 'uid' => \V::app()->user->_id, 'aid' => \V::app()->user->aid, 'name' => (string)\V::app()->user->name ];
	$details = 'super-admin: ' . $details;
	foreach(array_unique([ (string)$account->_id, (string)$admin['aid'] ]) as $aid) {
	    $actor = new class($aid, $admin) {
		public $aid;
		public $_id;
		private $name;
		public function __construct($aid, $admin) {
		    $this->aid = \V::app()->om->to_oid($aid);
		    $this->_id = \V::app()->om->to_oid((string)$admin['uid']);
		    $this->name = $admin['name'] . ' (super-admin)';
		}
		public function __toString() {
		    return $this->name;
		}
	    };
	    \models\AuditLog::record($action, $account, $details, [], null, $actor);
	}
    }

    // ------------------------------------------------------------------------------------
    //"view as"

    //hook on:before-action: while viewing as, the time limit, and only reading
    public function beforeAction() {
	$v = self::viewing();
	if ($v === null || \V::app()->user->isGuest)
	    return;
	if (time() - (int)$v['since'] > self::VIEW_MAX) {
	    self::endView('time limit');
	    \V::app()->response->redirect($v['return'] ?? '/site/index');
	}
	$route = strtolower(trim(\V::app()->config('rt.module') . '/' . \V::app()->config('rt.controller') . '/' . \V::app()->config('rt.action'), '/'));
	$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	if (!in_array($method, [ 'GET', 'HEAD' ], true) && !in_array($route, self::VIEW_ALLOWED, true))
	    \V::app()->error(403, 'Read-only: you are viewing this account as ' . $v['user_name'] . ' (go back to change anything)');
    }

    //signs the super-admin in as $user (an owner of $account), read-only; $return: where going back leads
    public static function startView($account, $user, $return) {
	$admin = [ 'uid' => (string)\V::app()->user->_id, 'aid' => (string)\V::app()->user->aid, 'name' => (string)\V::app()->user->name ];
	$identity = new \sys\Identity();
	$identity->allowSuspended = true;
	if (!\V::app()->om->acrossAccounts(fn() => $identity->authenticateById((string)$user->_id)))
	    throw new \ErrorException('cannot sign in as ' . $user . ': ' . $identity->reason);
	self::audit('view_as', $account, 'viewing as ' . $user . ' (' . $user->email . ')', $admin);
	\V::app()->session->setState('user', null);
	//the account signed in to, before the sign-in's checks (sys\Boot::after_user_login reads the user in it), as SiteController's sign-ins
	\V::app()->var('rt.account', $account);
	$_SESSION['impersonator'] = $admin + [ 'account' => (string)$account->_id, 'account_name' => (string)$account->name, 'user_name' => (string)$user, 'since' => time(),
	    'return' => $return ];
	\V::app()->user->login($identity, false);
    }

    //back to the super-admin ($why: in the audit log)
    public static function endView($why = 'back') {
	$v = self::viewing();
	if ($v === null)
	    return;
	$om = \V::app()->om;
	[ $account, $own ] = $om->acrossAccounts(fn() => [ $om->findById('Account', $v['account']), $om->findById('Account', (string)$v['aid']) ]);
	$identity = new \sys\Identity();
	\V::app()->session->setState('user', null);
	unset($_SESSION['impersonator']);
	if (!$own || !$om->acrossAccounts(fn() => $identity->authenticateById((string)$v['uid']))) {
	    \V::app()->user->logout();
	    return;
	}
	\V::app()->var('rt.account', $own);
	\V::app()->user->login($identity, false);
	if ($account)
	    self::audit('view_as_end', $account, 'stopped viewing as ' . $v['user_name'] . " ({$why}, " . (time() - (int)$v['since']) . ' s)', $v);
    }

    //hook layout:page-top: the bar while viewing as
    public function viewBar() {
	$v = self::viewing();
	if ($v === null || \V::app()->user->isGuest)
	    return;
	$e = fn($s) => \V::app()->html->escape((string)$s);
	$left = max(0, self::VIEW_MAX - (time() - (int)$v['since']));
	echo '<div class="position-fixed bottom-0 start-50 translate-middle-x mb-3 px-3 py-2 rounded-pill shadow bg-warning-subtle text-warning-emphasis border border-warning-subtle d-flex align-items-center gap-2 small" style="z-index: 1090; max-width: calc(100% - 32px)">'
	    . '<i class="bi bi-eye"></i><span class="text-truncate">Viewing <strong>' . $e($v['account_name'] ?: $v['account']) . '</strong> as ' . $e($v['user_name']) . ' &middot; read-only &middot; ' . (int)ceil($left / 60) . ' min left</span>'
	    . '<form method="post" action="' . \V::app()->createUrl('/MultiTenant/admin/exit_view') . '" no-pjax class="m-0">'
	    . '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . $e(\V::app()->csrf->getToken(false)) . '">'
	    . '<button type="submit" class="btn btn-sm btn-warning py-0">Back to administration</button></form></div>';
    }

    //CLD: 2026-10-31T11:05:39+02:00: services() moved to util\Services::services (the core's Services page)

    // ------------------------------------------------------------------------------------
    //CLD: 2026-10-31T21:00:00+02:00: the sign-up (was plugins\Registration\Plugin's constructor)

    private function initRegistration() {
	//the system emails of the sign-up (util\EmailSend::system)
	//  signup_confirm   the link that confirms a sign-up (vars: link)
	//  signup_exists    a sign-up asked for an address that already has an account (vars: email, login)
	\data\SysEmails::$data += [
	    'signup_confirm' => [
		'subject' => 'Confirm your email for {{ app }}',
		'email' => [
		    'preheader' => 'One click and your {{ app }} account is ready',
		    'blocks' => [
			[
			    'type' => 'html',
			    'html' => '<h1>Welcome to {{ app }}</h1>'
			    . '<p>Confirm your email address to create your account. The link works once, for 24 hours.</p>'
			    . '<p><a href="{{ link }}"><strong>Confirm my email address</strong></a></p>'
			    . '<p style="color: #555555">If the link does not open, copy this address into your browser:<br>{{ link }}</p>'
			    . '<p style="color: #555555">You did not ask for an account? Ignore this email: nothing is created.</p>'
			],
		    ],
		],
	    ],
	    'signup_exists' => [
		'subject' => 'You already have a {{ app }} account',
		'email' => [
		    'preheader' => 'Log in with this address',
		    'blocks' => [
			[
			    'type' => 'html',
			    'html' => '<h1>You already have an account</h1>'
			    . '<p>Someone, maybe you, asked to create a {{ app }} account with {{ email }}. This address already has one.</p>'
			    . '<p><a href="{{ login }}"><strong>Log in</strong></a>. Forgot the password? Use "Forgot username or password?" on the login page.</p>'
			    . '<p style="color: #555555">It was not you? Ignore this email: nothing changed.</p>'
			],
		    ],
		],
	    ],
	];

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('site:login-links', function() {
	    echo \V::app()->html->link(\V::t('New here? Create an account'), '/MultiTenant/register', [ 'class' => 'pt-1' ]);
	});
    }

    // ------------------------------------------------------------------------------------
    //CLD: 2026-10-31T21:00:00+02:00: the System AI (was plugins\SystemAI\Plugin's constructor)

    private function initSystemAi() {
	//the System AI for the AI steps of its provider (models\node\NodeAppAi::systemProvider): also in the console (the daemon runs the executions)
	\V::app()->hooks->addHook('ai:system-provider', function(\ArrayObject $box, $aid) {
	    $system = util\SystemAi::config();
	    if ($system === null)
		return;
	    $box['provider'] = [
		'provider' => $system->provider,
		'model' => $system->model,
		'label' => 'System AI (this installation: ' . $system->model . ', paid in credits)',
		'credential' => fn() => util\SystemAi::stepCredential($system),
		'start' => fn($model, array $info) => util\StepSession::start($aid, $system, $model, $info),
	    ];
	});

	//the administration (always on: this plugin's): an account's credits (plan, top-ups, ledger: controllers/AdminController), the System AI and the credit rates; read across the
	//accounts (the super-admin's own account is not the one shown) (were the Assistant's)
	\V::app()->hooks->addHook('admin:account-summary', function($account, \ArrayObject $items) {
	    $w = \V::app()->om->acrossAccounts(fn() => models\SystemAiWallet::current($account->_id));
	    if ($w['v'] > 0)
		//CLD: 2026-10-24T16:30:00+03:00: util\Format::number (was number_format)
		$items[] = \util\Format::number(models\SystemAiWallet::balance($w)) . ' credits';
	});
	\V::app()->hooks->addHook('admin:account-panels', function($account) {
	    $data = \V::app()->om->acrossAccounts(fn() => [
		'account' => $account,
		'wallet' => models\SystemAiWallet::current($account->_id),
		'ledger' => iterator_to_array(models\SystemAiLedger::recent($account->_id, 10), false),
		'system' => util\SystemAi::config(),
	    ]);
	    echo \V::app()->view->renderFile('/plugins/MultiTenant/views/admin/_systemai_account_panel', $data);
	});
	//CLD: 2026-10-24T09:45:36+03:00: the tiers (util\Tiers): the credits used this month on the usage page; a new tier gives this month's allowance at once (when the account has no plan of its own)
	\V::app()->hooks->addHook('tiers:usage', function($aid, \ArrayObject $extra) {
	    if (!util\SystemAi::config())
		return;
	    $w = \V::app()->om->acrossAccounts(fn() => models\SystemAiWallet::current($aid));
	    $extra['ai_monthly_credits'] = [ (int)$w['month_used'], (int)$w['allowance'] ];
	});
	\V::app()->hooks->addHook('tiers:changed', function($account) {
	    \V::app()->om->acrossAccounts(function() use ($account) {
		if (models\SystemAiWallet::current($account->_id)['plan'] === null)
		    models\SystemAiWallet::setPlan($account->_id, null);
	    });
	});
	\V::app()->hooks->addHook('admin:installation-panels', function() {
	    echo \V::app()->view->renderFile('/plugins/MultiTenant/views/admin/_systemai_installation_panel', [ 'system' => util\SystemAi::config(),
		'table' => models\SystemAiWallet::rateTable() ]);
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	//CLD: 2026-10-31T12:04:10+02:00: only when the System AI is configured (util\SystemAi::available: SYSTEM_AI_* in app.env)
	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update') && util\SystemAi::available())
		echo '<a href="' . \V::app()->createUrl('/MultiTenant/credits/index') . '" class="nav-link"><i class="bi bi-coin"></i> AI credits</a>';
	});
    }
}
