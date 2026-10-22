<?php

//CLD: 2026-10-19T12:52:41+03:00: new controller: the initial setup page, served at every address while app.env is missing (public_html/index.php, get_setup_config);
//once app.env exists it is not found. It shows the system prerequisites (util\Setup::checks); when the required ones pass, it asks the server's settings and the first
//user, then: connects to the database (it must have no account yet), creates the indexes, the account (its system roles) and its owner (active; the Personal project
//is made at the first sign-in), and writes app.env (with a new APP_SECRET). When app.env cannot be written, the account is removed again
//CLD: 2026-10-19T14:09:20+03:00: in steps (was one page): 1. /setup the prerequisites; 2. /setup/server the server's settings (models\setup\ServerForm; the database is
//checked here, the settings are kept in the setup session); 3. /setup/account the first account (models\setup\AccountForm), which installs. A step whose earlier one is
//not done goes back to it; the last step checks the prerequisites and the database again

namespace controllers;

class SetupController extends \sys\BaseController {

    //action => title (views/setup/_steps.php)
    const STEPS = [ 'index' => 'Prerequisites', 'server' => 'Server', 'account' => 'First account' ];

    //step 1
    public function index($req, $resp) {
	$this->begin();
	$checks = \util\Setup::checks();
	$ready = \util\Setup::ready($checks);
	$this->render('index', compact('checks', 'ready'));
    }

    //step 2
    public function server($req, $resp) {
	$this->begin();
	if (!\util\Setup::ready(\util\Setup::checks()))
	    $resp->redirect('/setup');

	$form = \models\setup\ServerForm::create();
	$host = (string)(\V::app()->request->httpHost ?? 'localhost');
	$form->assign($_SESSION['setup']['server'] ?? [
	    'base_url' => 'https://' . $host,
	    'mongo_uri' => 'mongodb://127.0.0.1:27017/',
	    'mongo_db' => 'netflow9-db',
	    'smtp_host' => '127.0.0.1',
	    'smtp_port' => '25',
	    'smtp_secure' => '',
	    //CLD: 2026-10-19T14:20:35+03:00: noreply@ the request's domain (without the port)
	    'mail_from' => 'noreply@' . preg_replace('/:\d+$/', '', $host),
	    //CLD: 2026-10-19T14:18:52+03:00: no mail_inbound_domain (the inbound email is not set up here)
	]);
	//the password is not shown again: an empty one keeps the one entered before
	$passwordKept = (string)($_SESSION['setup']['server']['smtp_password'] ?? '') !== '';
	if ($req->is('post')) {
	    $form->assign($req->data);
	    if ($passwordKept && (string)$form->smtp_password === '')
		$form->smtp_password = $_SESSION['setup']['server']['smtp_password'];
	    if ($form->validates()) {
		$problem = $this->database($form->values());
		if ($problem) {
		    $form->errors($problem[0], $problem[1]);
		} else {
		    $_SESSION['setup']['server'] = $form->values();
		    $resp->redirect('/setup/account');
		}
	    }
	}
	$this->render('server', compact('form', 'passwordKept'));
    }

    //step 3
    public function account($req, $resp) {
	$this->begin();
	if (!\util\Setup::ready(\util\Setup::checks()))
	    $resp->redirect('/setup');
	$server = $_SESSION['setup']['server'] ?? null;
	if (empty($server))
	    $resp->redirect('/setup/server');

	$form = \models\setup\AccountForm::create([], [ 'validateCurrentPassword' => false ]);
	$error = null;
	if ($req->is('post')) {
	    $form->assign($req->data);
	    if ($form->validates()) {
		$user = $this->install($server, $form, $error);
		if ($user) {
		    unset($_SESSION['setup']);
		    $this->render('done', compact('user'));
		    return;
		}
	    }
	}
	$this->render('account', compact('form', 'error', 'server'));
    }

    //only while app.env is missing; the steps' page
    private function begin() {
	if (!\V::app()->config('app.setup'))
	    \V::app()->error(404, 'Not found');
	$this->layout = 'bare';
    }

    //connects to the server's database ($server: ServerForm::values()) and makes it the app's; it must have no account yet. Returns null, or [ field, message ]
    private function database(array $server) {
	try {
	    $mongo = new \sys\Mongo([ 'uri' => (string)$server['mongo_uri'], 'database' => (string)$server['mongo_db'], 'uriOptions' => [ 'serverSelectionTimeoutMS' => 5000 ] ]);
	    $mongo->selectDatabase((string)$server['mongo_db'])->command([ 'ping' => 1 ]);
	} catch (\Throwable $e) {
	    return [ 'mongo_uri', 'Cannot connect: ' . $e->getMessage() ];
	}
	\V::app()->mongo = $mongo;
	if (\V::app()->om->count('Account', []) > 0)
	    return [ 'mongo_db', 'This database already has accounts: choose another one, or empty it' ];
	return null;
    }

    //creates the first account and writes app.env; returns the owner (a User), or null with the reason in $error (the account form's own errors are on $form)
    private function install(array $server, $form, &$error) {
	//one setup at a time
	$lock = fopen(BASE . '/tmp/setup.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	    $error = 'Another setup is running';
	    return null;
	}
	try {
	    if (is_file(BASE . '/app.env')) {
		$error = 'The app is already set up (app.env exists): reload the page';
		return null;
	    }

	    //the database of step 2, checked again
	    $problem = $this->database($server);
	    if ($problem) {
		$error = $problem[1];
		return null;
	    }
	    $om = \V::app()->om;

	    $failed = array_filter($om->createIndexes(), fn($r) => $r['error'] !== null);
	    if ($failed) {
		$error = 'Indexes not created: ' . implode('; ', array_map(fn($r) => $r['label'] . ': ' . $r['error'], $failed));
		return null;
	    }

	    $account = \models\Account::create([ 'state' => 'active', 'name' => trim((string)$form->account_name) ]);
	    if (!$account->validates() || !$account->save())
		throw new \ErrorException('setup: the account was not saved: ' . json_encode($account->errors()));
	    \V::app()->var('rt.account', $account);
	    try {
		$roles = $account->setupRoles();
		$user = \models\User::create([ 'aid' => $account->_id, 'username' => trim((string)$form->username), 'email' => (string)$form->email,
		    'firstname' => trim((string)$form->firstname), 'lastname' => trim((string)$form->lastname),
		    'password' => \V::app()->security->generatePasswordHash((string)$form->new_password), 'state' => 'active', 'role' => $roles['owner']->_id ]);
		if (!$user->validates() || !$user->save())
		    throw new \ErrorException('setup: the user was not saved: ' . json_encode($user->errors()));

		$url = rtrim(trim((string)$server['base_url']), '/');
		$content = \util\Setup::envContent([
		    'BASE_URL' => $url,
		    'MONGO_URI' => trim((string)$server['mongo_uri']),
		    'MONGO_DB' => (string)$server['mongo_db'],
		    'SMTP_HOST' => trim((string)$server['smtp_host']),
		    'SMTP_PORT' => (int)$server['smtp_port'],
		    'SMTP_USERNAME' => trim((string)$server['smtp_username']),
		    'SMTP_PASSWORD' => (string)$server['smtp_password'],
		    'SMTP_SECURE' => in_array((string)$server['smtp_secure'], [ 'tls', 'ssl' ], true) ? (string)$server['smtp_secure'] : '',
		    'SMTP_DOMAIN' => (string)parse_url($url, PHP_URL_HOST),
		    'MAIL_FROM' => trim((string)$server['mail_from']),
		    'ERRORS_EMAIL' => (string)$form->email,
		]);
		if (!\util\Setup::writeEnv($content))
		    throw new \ErrorException('app.env could not be written in ' . BASE);
	    } catch (\Throwable $e) {
		//no half-made account
		foreach($om->find('User', [ 'aid' => $account->_id ]) as $u)
		    $u->delete();
		foreach($om->find('Role', [ 'aid' => $account->_id ]) as $r)
		    $r->delete();
		$account->delete();
		$error = 'Setup failed: ' . $e->getMessage();
		return null;
	    }
	    \V::app()->log->info('setup: account ' . $account->name . ' created, owner ' . $user->username . ', app.env written');
	    return $user;
	} finally {
	    flock($lock, LOCK_UN);
	    fclose($lock);
	}
    }
}
