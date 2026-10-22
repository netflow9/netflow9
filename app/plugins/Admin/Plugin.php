<?php

/*
    CLD: 2026-10-22T23:00:00+03:00: new: the installation's administration, for super-admins (users with the `superadmin` flag, set only from the console: ./v Admin::admins::grant).
    Enabled in app/plugins.php.
      controllers/AdminController.php    /Admin/admin/...: the installation (its services: status, start / stop / restart), the accounts, an account (suspend / reactivate,
                                         "view as"), the plugins' sections
      util/Services.php                  the services (systemd units; the PID files of the scripts before them): status, start / stop / restart
      commands/AdminsCommand.php         ./v Admin::admins::<list | grant | revoke>
    Who may: a super-admin with 2FA (require()); every action is in the audit log of the account acted on and of the super-admin's own (audit()).
    "View as": the super-admin is signed in as the account's owner, read-only (any request but GET refused, except a few that only read), at most VIEW_MAX, with a bar to go back;
    the session's `impersonator` keeps who they are (sys\Boot lets a suspended account be viewed so).
    Hooks for the plugins:
      admin:services ($services: \ArrayObject)        id => [ label, description, unit (systemd), pidfile (before the unit: a script's), health: fn() => [ ok (bool|null), text ] ]
      admin:installation-panels ()                    the installation page's sections (echo)
      admin:account-panels ($account)                 the account page's sections (echo)
      admin:account-summary ($account, $items: \ArrayObject)   short texts for the accounts list (e.g. the credits)
*/

namespace plugins\Admin;

class Plugin {
    //how long a "view as" lasts at most (s)
    const VIEW_MAX = 3600;
    //what may be sent (not GET) while viewing as: go back, sign out, the expression help (reads only)
    const VIEW_ALLOWED = [ 'admin/admin/exit_view', 'site/logout', 'workflow/expression' ];

    public function __construct() {
	//the workflow daemon (the others: their plugins)
	\V::app()->hooks->addHook('admin:services', function(\ArrayObject $services) {
	    $services['daemon'] = [
		'label' => 'Workflow daemon',
		'description' => 'Runs the queued jobs: scheduled and background workflow runs, emails, timers',
		'unit' => 'nfn-daemon.service',
		'pidfile' => BASE . '/run/daemon.pid',
		'health' => function() {
		    $sock = BASE . '/run/job-notify.sock';
		    $s = @stream_socket_client('unix://' . $sock, $errno, $errstr, 1);
		    if (!$s)
			return [ false, 'does not answer on ' . basename($sock) . ($errstr ? ": {$errstr}" : '') ];
		    fclose($s);
		    return [ true, 'answers on ' . basename($sock) ];
		},
	    ];
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (self::isAdmin())
		echo '<a href="' . \V::app()->createUrl('/Admin/admin/index') . '" class="nav-link"><i class="bi bi-shield-lock"></i> Administration</a>';
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
	    . '<form method="post" action="' . \V::app()->createUrl('/Admin/admin/exit_view') . '" no-pjax class="m-0">'
	    . '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . $e(\V::app()->csrf->getToken(false)) . '">'
	    . '<button type="submit" class="btn btn-sm btn-warning py-0">Back to administration</button></form></div>';
    }

    // ------------------------------------------------------------------------------------
    //the services

    //the services of the installation (hook admin:services): [ id => definition ]
    public static function services() {
	$services = new \ArrayObject();
	\V::app()->hooks->applyHook('admin:services', [ $services ]);
	return $services->getArrayCopy();
    }
}
