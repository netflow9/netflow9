<?php

namespace controllers;

class SiteController extends \sys\BaseController {

    //CLD: 2026-10-21T20:10:00+03:00: session key of the accounts to choose from after a sign-in whose name and password fit users of several accounts (login, choose_account),
    //and how long the choice waits (seconds)
    const CHOICES = 'login_choices';
    const CHOICES_TTL = 300;
    //CLD: 2026-10-30T12:00:00+02:00: the sidebar's width limits (px; as app.sidebar's minWidth / maxWidth)
    const SIDEBAR_MIN_W = 250;
    const SIDEBAR_MAX_W = 400;

    public function login($req, $resp) {
	$this->layout = 'bare';

	$form = \models\user\LoginForm::create();

	if ($req->is('post')) {
	    $form->assign($req->data);
	    //CLD: 2026-10-21T18:00:00+03:00: the sign-in throttle (models\user\LoginThrottle): a locked name or address is refused before the password is checked
	    $nameKey = \models\user\LoginThrottle::nameKey($form->username);
	    $ipKey = \models\user\LoginThrottle::ipKey();
	    $lockedFor = max(\models\user\LoginThrottle::lockedFor($nameKey), \models\user\LoginThrottle::lockedFor($ipKey));
	    if ($lockedFor > 0) {
		$form->errors('password', \models\user\LoginThrottle::message($lockedFor));
		$resp->status = 429;
	    } elseif ($form->validates()) {
		//search for a user
		$identity = new \sys\Identity();
		if ($identity->authenticate($form->username, $form->password)) {
		    \models\user\LoginThrottle::clear($nameKey);
		    //CLD: 2026-10-16T13:15:57+03:00: signIn() (the 2FA step when the user has one, the login, the audit entry; shared with the sign-up and the password reset; was here)
		    $this->signIn(\V::app()->om->findById('User', $identity->data()['_id']), !empty($form->rememberme));
		} elseif (!empty($identity->choices)) {
		    //CLD: 2026-10-21T20:10:00+03:00: the name and password fit users of several accounts: the user picks one (choose_account); the password was right
		    \models\user\LoginThrottle::clear($nameKey);
		    $_SESSION[self::CHOICES] = [
			'users' => array_map(fn($u) => (string)$u->_id, $identity->choices),
			'remember' => !empty($form->rememberme),
			'expires' => time() + self::CHOICES_TTL,
		    ];
		    $resp->redirect('/site/choose_account');
		} else {
		    $form->errors('password', $identity->reason);
		    //CLD: 2026-10-11T17:35:22+03:00: audit log: a failed login of an existing user (the entry belongs to the user's account; unknown names are not recorded)
		    $failed = \V::app()->om->findOne('User', [ '$or' => [ [ 'username' => (string)$form->username ], [ 'email' => (string)$form->username ] ] ]);
		    if (!empty($failed))
			\models\AuditLog::record('login.failed', $failed, 'wrong password, or the user is not active', [], null, $failed);
		    //CLD: 2026-10-21T18:00:00+03:00: counted for the name and the address; a new lock is recorded
		    $nameLocked = \models\user\LoginThrottle::fail($nameKey);
		    $ipLocked = \models\user\LoginThrottle::fail($ipKey);
		    if ($nameLocked || $ipLocked) {
			$what = $nameLocked ? 'sign-in name' : 'address ' . ($_SERVER['REMOTE_ADDR'] ?? '');
			\V::app()->log->warn("SiteController: sign-in locked for {$what} (login `" . (string)$form->username . '`)');
			if (!empty($failed))
			    \models\AuditLog::record('login.locked', $failed, "too many failed sign-ins: {$what} locked", [], null, $failed);
		    }
		}
	    }
	}
	$this->render('login', compact('form'));
    }

    //CLD: 2026-10-17T22:00:00+03:00: the link of a project invitation (models\user\ProjectInvite): the user's name and password; then the user (of the invitation's account, account role
    //Member, active), a member of the invited projects, signed in on the project. When the address became a user of the account meanwhile, it joins the projects and signs in as usual.
    //An expired / used link says so
    public function invite($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';
	$om = \V::app()->om;
	$token = (string)($req->query['t'] ?? '');
	$invite = \models\user\ProjectInvite::findByToken($token);
	$account = $invite ? $om->findById('Account', (string)$invite->aid) : null;
	$project = $account ? $om->findOne('Project', [ '_id' => $invite->project, 'aid' => $account->_id ]) : null;
	if (!$project)
	    $invite = null;
	$existing = $invite ? $om->findOne('User', [ 'aid' => $account->_id, 'email' => (string)$invite->email ]) : null;
	if ($existing) {
	    \V::app()->var('rt.account', $account);
	    $invite->accept($existing);
	}
	$form = \models\user\InviteForm::create([], [ 'validateCurrentPassword' => false ]);
	if ($invite && !$existing && $req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		\V::app()->var('rt.account', $account);
		$role = $om->findOne('Role', [ 'aid' => $account->_id, 'iname' => 'member' ]);
		$user = \models\User::create([ 'aid' => $account->_id, 'email' => (string)$invite->email, 'firstname' => trim((string)$form->firstname),
		    'lastname' => trim((string)$form->lastname), 'password' => \V::app()->security->generatePasswordHash((string)$form->new_password),
		    'state' => 'active', 'role' => $role->_id ?? null ]);
		if (!$user->validates() || !$user->save())
		    throw new \ErrorException('invite: the user was not saved: ' . json_encode($user->errors()));
		\models\AuditLog::record('signup', $user, 'joined ' . $account->name . ' (invitation to ' . $project->name . ')', [], null, $user);
		$joined = $invite->accept($user);
		//signed in on the invited project (else the first one joined)
		$ids = array_map(fn($p) => (string)$p->_id, $joined);
		\V::app()->user->crtProject = in_array((string)$project->_id, $ids, true) ? $project->_id : ($joined[0]->_id ?? null);
		$this->signIn($user);
	    }
	}
	$this->render('invite', [ 'form' => $form, 'invite' => $invite, 'project' => $project, 'account' => $account, 'existing' => $existing, 'token' => $token ]);
    }

    //CLD: 2026-10-16T13:15:57+03:00: signs user $user in and goes to the app: first the 2FA step when the user has one (unless $tfaDone), else the login (persistent: $remember) and its
    //audit entry ($details); was written in login(), tfa() and register_confirm()
    //CLD: 2026-10-19T11:21:19+03:00: public static: also used by the Registration plugin's sign-up (uses no controller state; $this->signIn() calls still work)
    public static function signIn($user, $remember = false, $details = null, $tfaDone = false) {
	if (!$tfaDone && !empty($user->tfa_secret)) {
	    $_SESSION['tfa_user_id'] = (string)$user->_id;
	    //CLD: 2026-10-05T20:02:30+03:00: carry the "remember me" choice over the 2FA step
	    $_SESSION['tfa_remember'] = $remember;
	    \V::app()->response->redirect('/site/tfa');
	}
	$identity = new \sys\Identity();
	if (!$identity->authenticateById((string)$user->_id))
	    \V::app()->response->redirect('/site/login');
	//CLD: 2026-10-05T20:02:30+03:00: persistent session only with "remember me" (was: always a 30 days identity cookie)
	\V::app()->user->login($identity, $remember);
	\models\AuditLog::record('login', $user, $details);
	\V::app()->response->redirect('/site/index');
    }

    //CLD: 2026-10-16T13:15:57+03:00: "forgot username or password" (user's choices: the email asked; for each active user of that address - an address may sign in to several accounts -
    //an email with the username and a link to set a new password, valid once for 24 hours; the same answer whether or not the address has an account)
    public function forgot($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';
	$form = \models\user\EmailForm::create();
	$sent = false;
	if ($req->is('post')) {
	    $form->assign($req->data);
	    //CLD: 2026-10-24T00:20:00+03:00: rate limits (models\user\LoginThrottle; SOC2.md gap 12): per address, refused with 429 once over; per email, nothing is sent once over, with the same answer
	    //(the page tells nothing about the accounts)
	    $ipKey = \models\user\LoginThrottle::resetIpKey();
	    $ipLocked = \models\user\LoginThrottle::lockedFor($ipKey);
	    if ($ipLocked > 0) {
		$form->errors('email', \models\user\LoginThrottle::message($ipLocked));
		$resp->status = 429;
	    } elseif ($form->validates()) {
		if (\models\user\LoginThrottle::fail($ipKey))
		    \V::app()->log->warn('SiteController: password reset requests locked for ' . $ipKey);
		$emailKey = \models\user\LoginThrottle::resetEmailKey((string)$form->email);
		$emailLocked = \models\user\LoginThrottle::lockedFor($emailKey) > 0;
		if (!$emailLocked && \models\user\LoginThrottle::fail($emailKey))
		    \V::app()->log->warn('SiteController: password reset emails locked for ' . $emailKey);
		//CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host
		foreach($emailLocked ? [] : \V::app()->om->find('User', [ 'email' => (string)$form->email, 'state' => 'active' ]) as $user) {
		    //CLD: 2026-10-21T10:00:00+03:00: not for a user whose password is kept elsewhere (e.g. an LDAP user: the directory's password)
		    if (\sys\Identity::passwordSource($user) !== null)
			continue;
		    //CLD: 2026-10-24T08:20:00+03:00: User::startPasswordReset (was PasswordReset::start)
		    $token = $user->startPasswordReset(\V::app()->request->userIP);
		    $account = \V::app()->om->findById('Account', (string)$user->aid);
		    \util\EmailSend::system('password_reset', (string)$user->email, [ 'username' => (string)($user->username ?: $user->email), 'email' => (string)$user->email,
			'account' => (string)($account->name ?? '') ?: (string)$account->hostname, 'link' => \V::app()->createAbsoluteUrl('/site/reset', [ 't' => $token ]) ]);
		}
		$sent = true;
	    }
	}
	$this->render('forgot', compact('form', 'sent'));
    }

    //CLD: 2026-10-16T13:15:57+03:00: the link of a "forgot" email: a new password (twice; ChangePasswordForm's rules); then the user's other sessions are ended, the user's links are used up, a
    //"password changed" email is sent, and the user is signed in here (2FA still asked when set)
    public function reset($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';
	$token = (string)($req->query['t'] ?? '');

	//CLD: 2026-10-24T08:20:00+03:00: User::findByResetToken (was a PasswordReset, then its user)
	$user = \models\User::findByResetToken($token);
	//CLD: 2026-10-21T10:00:00+03:00: a link of a user whose password is now kept elsewhere (e.g. became an LDAP user) no longer works
	if ($user && \sys\Identity::passwordSource($user) !== null)
	    $user = null;
	$form = \models\user\ChangePasswordForm::create([], [ 'validateCurrentPassword' => false ]);
	if ($user && $req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		\V::app()->var('rt.account', \V::app()->om->findById('Account', (string)$user->aid));
		$user->password = \V::app()->security->generatePasswordHash((string)$form->new_password);
		if (!$user->save())
		    throw new \ErrorException('reset: the password was not saved: ' . json_encode($user->errors()));
		//CLD: 2026-10-24T08:20:00+03:00: User::clearPasswordReset (were the user's PasswordReset deleted)
		$user->clearPasswordReset();
		\V::app()->session->endFor($user->_id);
		\models\AuditLog::record('password.reset', $user, 'with a "forgot" link; the other sessions ended', [], null, $user);
		//CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host
		\util\EmailSend::system('password_changed', (string)$user->email, [ 'email' => (string)$user->email, 'login' => \V::app()->createAbsoluteUrl('/site/login') ]);
		$this->signIn($user, false, 'after a password reset');
	    }
	}
	$this->render('reset', [ 'form' => $form, 'user' => $user, 'token' => $token ]);
    }

    //CLD: 2026-10-21T20:10:00+03:00: the accounts a sign-in's name and password fit (login: CHOICES): the user picks one and is signed in there (2FA still asked when set)
    public function choose_account($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$this->layout = 'bare';

	$pending = $_SESSION[self::CHOICES] ?? null;
	if (empty($pending['users']) || ($pending['expires'] ?? 0) < time()) {
	    unset($_SESSION[self::CHOICES]);
	    $resp->redirect('/site/login');
	}

	$om = \V::app()->om;
	$choices = [];
	foreach($pending['users'] as $id) {
	    $user = $om->findOne('User', [ '_id' => $id, 'state' => 'active' ]);
	    $account = $user ? $om->findById('Account', (string)$user->aid) : null;
	    if ($account)
		$choices[$id] = [ 'user' => $user, 'account' => (string)($account->name ?? '') ?: (string)$account->hostname ];
	}

	if ($req->is('post')) {
	    $id = (string)($req->data['user'] ?? '');
	    if (!isset($choices[$id]))
		$resp->redirect('/site/choose_account');
	    unset($_SESSION[self::CHOICES]);
	    $this->signIn($choices[$id]['user'], !empty($pending['remember']), 'account chosen at sign-in');
	}

	$this->render('choose_account', [ 'choices' => $choices ]);
    }

    public function tfa($req, $resp) {
	$this->layout = 'bare';

	$user_id = $_SESSION['tfa_user_id'] ?? null;
	if (empty($user_id))
	    $resp->redirect('/site/login');

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:02:30+03:00: "remember me" choice saved by login()
	    $remember = !empty($_SESSION['tfa_remember']);
	    unset($_SESSION['tfa_user_id'], $_SESSION['tfa_remember']);

	    $code = $req->data['code'] ?? null;
	    if (empty($code))
		$resp->redirect('/site/login');

	    $user = \V::app()->om->findById('User', $user_id);
	    //CLD: 2026-10-21T18:00:00+03:00: the 2FA throttle (models\user\LoginThrottle, per user): a locked user's code is not checked
	    $tfaKey = \models\user\LoginThrottle::tfaKey($user_id);
	    $lockedFor = \models\user\LoginThrottle::lockedFor($tfaKey);
	    if ($lockedFor > 0) {
		$error = \models\user\LoginThrottle::message($lockedFor);
		$resp->status = 429;
	    //CLD: 2026-10-24T00:20:00+03:00: a code is accepted once (User::useTfaCode; was TOTP::verifyCode: a code could be used again within its window); a reused one counts as wrong
	    } elseif (!empty($user) && $user->useTfaCode($code)) {
		\models\user\LoginThrottle::clear($tfaKey);
		//CLD: 2026-10-16T13:15:57+03:00: signIn() (was the login written here)
		$this->signIn($user, $remember, 'with a 2FA code', true);
	    } elseif (!empty($user)) {
		//CLD: 2026-10-11T17:35:22+03:00: audit log
		\models\AuditLog::record('login.failed', $user, 'wrong 2FA code', [], null, $user);
		//CLD: 2026-10-21T18:00:00+03:00: counted; a new lock is recorded
		$error = \V::t('The code is not correct. Sign in again.');
		if (\models\user\LoginThrottle::fail($tfaKey)) {
		    \V::app()->log->warn("SiteController: 2FA locked for user {$user_id}");
		    \models\AuditLog::record('login.locked', $user, 'too many wrong 2FA codes: 2FA locked', [], null, $user);
		    $error = \models\user\LoginThrottle::message(\models\user\LoginThrottle::lockedFor($tfaKey));
		}
	    }
	}

	//CLD: 2026-10-21T18:00:00+03:00: $error: a wrong code or a lock (was: the page shown again without a word)
	$this->render('tfa', [ 'error' => $error ?? null ]);
    }

    public function logout($req, $resp) {
	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	//CLD: 2026-10-11T21:35:50+03:00: POST only (the Settings menu's Logout is a form; a GET link let any page log the user out)
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	//CLD: 2026-10-11T17:35:22+03:00: audit log (before: the user is still known)
	\models\AuditLog::record('logout', \V::app()->om->findById('User', \V::app()->user->_id));
	\V::app()->user->logout();
	$resp->redirect(\V::app()->user->getLoginUrl());
    }

    public function index($req, $resp) {
	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->render('index');
    }

    //CLD: 2026-10-14T21:43:04+03:00: new action: the figures of the Overview dashboard (public_html/js/app.overview.js), JSON, for the current project and ?period=24h|7d|30d|90d:
    //tiles (executions, success rate, run time p50 / p95, running now, open tasks; with the previous period), executions per hour / day (completed, error), by trigger,
    //per workflow (runs, success, p50 / p95, last run, runs per bucket), failing steps, stuck work (long runs, late wake-ups, overdue tasks), recent executions.
    //Only the workflows the user may read (Workflow::read); without any, only the user's open tasks. Buckets and durations in ms; moments (at, since) as ISO 8601
    //(app.formatter.timeAgo takes them)
    //CLD: 2026-10-19T12:05:32+03:00: app.formatter.timeAgo (was app.timeAgo.inWords)
    const OVERVIEW_PERIODS = [ '24h' => [ 86400, 3600 ], '7d' => [ 7 * 86400, 86400 ], '30d' => [ 30 * 86400, 86400 ], '90d' => [ 90 * 86400, 86400 ] ];
    const OVERVIEW_MAX = 50000;
    //a run (not sleeping) this long is listed as stuck
    const OVERVIEW_LONG_RUN = 900;

    public function overview($req, $resp) {
	if (\V::app()->user->isGuest)
	    \V::app()->error(401, 'Log in first.');
	$om = \V::app()->om;
	$user = \V::app()->user;
	$account = \V::app()->config('rt.account');
	$project = $om->findOne('Project', [ '_id' => $user->crtProject, 'aid' => $account->_id ?? null ]);
	if (!$project || !$user->can('Project::read', [ 'object' => $project ]))
	    \V::app()->error(403, 'Unauthorized');

	$period = isset(self::OVERVIEW_PERIODS[$req->query['period'] ?? '']) ? (string)$req->query['period'] : '7d';
	[ $length, $step ] = self::OVERVIEW_PERIODS[$period];
	$tz = new \DateTimeZone(date_default_timezone_get());
	$now = time();
	//buckets end with the current hour / day (local time)
	$last = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
	$last = $step == 3600 ? $last->setTime((int)$last->format('H'), 0) : $last->setTime(0, 0);
	$count = intdiv($length, $step);
	$buckets = [];
	for($i = $count - 1; $i >= 0; $i--)
	    $buckets[] = $last->modify('-' . ($i * $step) . ' seconds')->getTimestamp();
	$from = $buckets[0];
	$prevFrom = $from - $length;
	$bucketOf = function($t) use ($buckets) {
	    for($i = count($buckets) - 1; $i >= 0; $i--)
		if ($t >= $buckets[$i])
		    return $i;
	    return null;
	};
	$idAt = fn($t) => new \MongoDB\BSON\ObjectId(sprintf('%08x', $t) . '0000000000000000');
	$ms = fn($d) => $d instanceof \MongoDB\BSON\UTCDateTime ? (int)(string)$d : null;
	$iso = fn($d) => $d instanceof \MongoDB\BSON\UTCDateTime ? $d->toDateTime()->setTimezone($tz)->format('c') : ($d instanceof \MongoDB\BSON\ObjectId ? date('c', $d->getTimestamp()) : null);
	$percentile = function(array $values, $p) {
	    if (!$values)
		return null;
	    sort($values);
	    return $values[(int)min(count($values) - 1, floor($p * (count($values) - 1) + 0.5))];
	};

	//the workflows the user may read
	$workflows = [];
	foreach($om->find('Workflow', [ 'aid' => $project->aid, 'project' => $project->_id ], [ 'projection' => [ 'nodes' => 0 ] ]) as $wf) {
	    if ($user->can('Workflow::read', [ 'object' => $wf ]))
		$workflows[(string)$wf->_id] = $wf;
	}
	$out = [ 'period' => $period, 'step' => $step, 'buckets' => array_map(fn($t) => $t * 1000, $buckets), 'workflowsVisible' => (bool)$workflows ];

	//open tasks: of these workflows, or (no workflow rights) the user's own
	$tasks = [];
	if ($workflows) {
	    foreach($om->find('WfTask', [ 'aid' => $project->aid, 'project' => $project->_id, 'state' => 'open', 'wf' => [ '$in' => array_map(fn($w) => $w->_id, array_values($workflows)) ] ]) as $t)
		$tasks[] = $t;
	} else {
	    //CLD: 2026-10-17T13:24:18+03:00: WfTask is a core model (was plugins\BaseNodes\models)
	    $tasks = \models\WfTask::openFor($user, $project->aid, $project->_id);
	}
	$overdueTasks = array_values(array_filter($tasks, fn($t) => ($ms($t->due_at) ?? PHP_INT_MAX) < $now * 1000));
	$out['tiles']['tasks'] = [ 'open' => count($tasks), 'overdue' => count($overdueTasks) ];
	if (!$workflows) {
	    //CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was addHeader + write + end)
	    $resp->sendJSON($out);
	}

	$wfIds = array_map(fn($w) => $w->_id, array_values($workflows));
	$base = [ 'aid' => $project->aid, 'project' => $project->_id, 'wf' => [ '$in' => $wfIds ] ];
	$fields = [ 'state' => 1, 'trigger' => 1, 'wf' => 1, 'start_at' => 1, 'end_at' => 1, 'error' => 1, 'wake_at' => 1 ];

	//this period
	$series = [ 'completed' => array_fill(0, $count, 0), 'error' => array_fill(0, $count, 0), 'cancelled' => array_fill(0, $count, 0) ];
	$triggers = [];
	$per = [];
	$durations = [];
	$states = [ 'end' => 0, 'error' => 0, 'cancel' => 0 ];
	$total = 0;
	$errorIds = [];
	foreach($om->find('WfExecution', $base + [ '_id' => [ '$gte' => $idAt($from) ] ], [ 'projection' => $fields, 'limit' => self::OVERVIEW_MAX ]) as $e) {
	    $total++;
	    $wf = (string)$e->wf;
	    $at = $e->_id->getTimestamp();
	    $b = $bucketOf($at);
	    $per[$wf] = $per[$wf] ?? [ 'runs' => 0, 'end' => 0, 'error' => 0, 'durations' => [], 'spark' => array_fill(0, $count, 0) ];
	    $per[$wf]['runs']++;
	    if ($b !== null)
		$per[$wf]['spark'][$b]++;
	    $triggers[(string)$e->trigger] = ($triggers[(string)$e->trigger] ?? 0) + 1;
	    if (isset($states[$e->state])) {
		$states[$e->state]++;
		$key = [ 'end' => 'completed', 'error' => 'error', 'cancel' => 'cancelled' ][$e->state];
		if ($b !== null)
		    $series[$key][$b]++;
	    }
	    if ($e->state === 'end' || $e->state === 'error')
		$per[$wf][$e->state]++;
	    if ($e->state === 'error')
		$errorIds[] = $e->_id;
	    $start = $ms($e->start_at);
	    $end = $ms($e->end_at);
	    if ($e->state === 'end' && $start !== null && $end !== null) {
		$durations[] = $end - $start;
		$per[$wf]['durations'][] = $end - $start;
	    }
	}
	$finished = $states['end'] + $states['error'];

	//the previous period, for the comparison
	$prev = [ 'total' => 0, 'end' => 0, 'error' => 0 ];
	foreach($om->find('WfExecution', $base + [ '_id' => [ '$gte' => $idAt($prevFrom), '$lt' => $idAt($from) ] ], [ 'projection' => [ 'state' => 1 ], 'limit' => self::OVERVIEW_MAX ]) as $e) {
	    $prev['total']++;
	    if (isset($prev[$e->state]))
		$prev[$e->state]++;
	}

	//now: running / sleeping (any start), and those waiting for a person (an open task)
	$running = $om->count('WfExecution', $base + [ 'state' => [ '$in' => [ 'run', 'sleep' ] ] ]);
	$waiting = count(array_unique(array_map(fn($t) => (string)$t->execution, $tasks)));

	$out['tiles'] += [
	    'executions' => [ 'value' => $total, 'previous' => $prev['total'] ],
	    'success' => [ 'rate' => $finished ? $states['end'] / $finished : null, 'previous' => ($prev['end'] + $prev['error']) ? $prev['end'] / ($prev['end'] + $prev['error']) : null,
		'errors' => $states['error'], 'cancelled' => $states['cancel'] ],
	    'duration' => [ 'p50' => $percentile($durations, 0.5), 'p95' => $percentile($durations, 0.95) ],
	    'running' => [ 'value' => $running, 'waiting' => $waiting ],
	];
	$out['series'] = $series;
	arsort($triggers);
	$out['triggers'] = array_map(fn($k, $v) => [ 'trigger' => $k, 'count' => $v ], array_keys($triggers), $triggers);

	//per workflow (every readable one, also without runs in the period), with its last run
	$out['workflows'] = [];
	foreach($workflows as $id => $wf) {
	    $p = $per[$id] ?? [ 'runs' => 0, 'end' => 0, 'error' => 0, 'durations' => [], 'spark' => array_fill(0, $count, 0) ];
	    $lastRun = $om->findOne('WfExecution', [ 'aid' => $project->aid, 'wf' => $wf->_id ], [ 'sort' => [ '_id' => -1 ], 'projection' => [ 'state' => 1 ] ]);
	    $version = $wf->publishedVersion();
	    $out['workflows'][] = [
		'id' => $id,
		'name' => (string)$wf->name,
		'status' => $wf->status === \models\Workflow::PUBLISHED && $version ? 'v' . $version->number : 'draft',
		'runs' => $p['runs'],
		'success' => ($p['end'] + $p['error']) ? $p['end'] / ($p['end'] + $p['error']) : null,
		'p50' => $percentile($p['durations'], 0.5),
		'p95' => $percentile($p['durations'], 0.95),
		'last' => $lastRun ? [ 'at' => $iso($lastRun->_id), 'state' => (string)$lastRun->state ] : null,
		'spark' => $p['spark'],
	    ];
	}

	//failing steps: the failed steps of the failed runs
	$failing = [];
	foreach($errorIds ? $om->find('WfExecution', [ '_id' => [ '$in' => $errorIds ] ], [ 'projection' => [ 'wf' => 1, 'nodes' => 1, 'error' => 1 ], 'sort' => [ '_id' => -1 ] ]) : [] as $e) {
	    foreach((array)$e->nodes as $n) {
		if (($n['state'] ?? null) !== 'fail')
		    continue;
		$key = (string)$e->wf . '/' . ($n['wfid'] ?? '');
		$failing[$key] = $failing[$key] ?? [ 'wf' => (string)$e->wf, 'workflow' => (string)($workflows[(string)$e->wf]->name ?? ''), 'step' => (string)($n['title'] ?? $n['wfid'] ?? ''),
		    'count' => 0, 'error' => mb_substr((string)$e->error, 0, 300), 'at' => $iso($e->_id) ];
		$failing[$key]['count']++;
	    }
	}
	usort($failing, fn($a, $b) => $b['count'] <=> $a['count']);
	$out['failing'] = array_slice(array_values($failing), 0, 10);

	//stuck: running for long, sleeping past their wake-up, overdue tasks
	$stuck = [];
	foreach($om->find('WfExecution', $base + [ '$or' => [
	    [ 'state' => 'run', 'start_at' => [ '$lt' => new \MongoDB\BSON\UTCDateTime(($now - self::OVERVIEW_LONG_RUN) * 1000) ] ],
	    [ 'state' => 'sleep', 'wake_at' => [ '$lt' => new \MongoDB\BSON\UTCDateTime($now * 1000) ] ],
	] ], [ 'projection' => $fields, 'sort' => [ '_id' => 1 ], 'limit' => 10 ]) as $e)
	    //CLD: 2026-10-26T10:06:24+02:00: + id and canKill (a long run: the Kill button, WorkflowController::execution_kill; Workflow::update)
	    $stuck[] = [ 'kind' => $e->state === 'run' ? 'running' : 'late', 'id' => (string)$e->_id, 'wf' => (string)$e->wf, 'workflow' => (string)($workflows[(string)$e->wf]->name ?? ''),
		'since' => $e->state === 'run' ? $iso($e->start_at) : $iso($e->wake_at),
		'canKill' => $e->state === 'run' && isset($workflows[(string)$e->wf]) && $user->can('Workflow::update', [ 'object' => $workflows[(string)$e->wf] ]) ];
	foreach(array_slice($overdueTasks, 0, 10) as $t)
	    $stuck[] = [ 'kind' => 'task', 'wf' => (string)$t->wf, 'workflow' => (string)($workflows[(string)$t->wf]->name ?? ''), 'title' => (string)$t->title,
		'since' => $iso($t->due_at), 'task' => (string)$t->_id ];
	$out['stuck'] = $stuck;

	//recent executions
	$out['recent'] = [];
	foreach($om->find('WfExecution', $base, [ 'projection' => $fields, 'sort' => [ '_id' => -1 ], 'limit' => 10 ]) as $e) {
	    $start = $ms($e->start_at);
	    $end = $ms($e->end_at);
	    $out['recent'][] = [ 'id' => (string)$e->_id, 'wf' => (string)$e->wf, 'workflow' => (string)($workflows[(string)$e->wf]->name ?? ''), 'trigger' => (string)$e->trigger,
		'state' => (string)$e->state, 'duration' => $start !== null && $end !== null ? $end - $start : null, 'at' => $iso($e->_id) ];
	}

	//CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was addHeader + write + end)
	$resp->sendJSON($out);
    }

    public function switch_project($req, $resp) {
	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$oid = $this->getOidOrError();
	$account = \V::app()->config('rt.account');
	$project = \V::app()->om->findOne('Project', [ '_id' => $oid, 'aid' => $account->_id ]);
	if (empty($project))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('Project::read', [ 'object' => $project ]))
	    \V::app()->error(403, 'Unauthorized');

	\V::app()->user->crtProject = $project->_id;
	//CLD: 2026-10-30T12:00:00+02:00: the next session starts on this project (Boot)
	\models\User::rememberUi([ 'last_project' => $project->_id ]);

	$resp->redirect('/site/index');
    }

    //CLD: 2026-10-30T12:00:00+02:00: new: the sidebar's width (px) set by its resize handle (app.sidebar), kept for the user (User sidebar_width; was this browser only: localStorage)
    public function sidebar_width($req, $resp) {
	if (\V::app()->user->isGuest)
	    \V::app()->error(401, 'Unauthorized');
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	$w = filter_var($req->data['width'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => self::SIDEBAR_MIN_W, 'max_range' => self::SIDEBAR_MAX_W ] ]);
	if ($w === false)
	    \V::app()->error(400, 'Invalid width');
	\models\User::rememberUi([ 'sidebar_width' => $w ]);

	$resp->sendJSON([ 'ok' => true ]);
    }

    public function render_aside($req, $resp) {
	if (\V::app()->user->isGuest)
	    return;

	$this->layout = false;
	$this->render('/layouts/main_aside');
    }

}