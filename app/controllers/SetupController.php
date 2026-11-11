<?php

//the initial setup page, served at every address while app.env is missing (public_html/index.php, get_setup_config); once app.env exists it is not found.
//In steps: 1. /setup the system prerequisites (util\Setup::checks); 2. /setup/server the server's settings (models\setup\ServerForm; the database is
//checked here: it must have no account yet; the settings are kept in the setup session); 3. /setup/account the first account and its owner (models\setup\AccountForm),
//which installs: the indexes, the account (its system roles) and its owner (active; the Personal project is made at the first sign-in), and app.env (with a new
//APP_SECRET). When app.env cannot be written, the account is removed again. A step whose earlier one is not done goes back to it; the last step checks the
//prerequisites and the database again

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
	//the database's and SMTP's defaults from the environment when set (Docker: compose.yaml's NFN_SETUP_*: the mongo container, its app user)
	$form->assign($_SESSION['setup']['server'] ?? [
	    'base_url' => self::setupEnv('BASE_URL', 'https://' . $host),
	    'mongo_uri' => self::setupEnv('MONGO_URI', 'mongodb://127.0.0.1:27017/'),
	    'mongo_db' => self::setupEnv('MONGO_DB', 'rail22-db'),
	    'smtp_host' => self::setupEnv('SMTP_HOST', '127.0.0.1'),
	    'smtp_port' => self::setupEnv('SMTP_PORT', '25'),
	    'smtp_secure' => '',
	    //noreply@ the request's domain (without the port)
	    'mail_from' => 'noreply@' . preg_replace('/:\d+$/', '', $host),
	    //no mail_inbound_domain (the inbound email is not set up here)
	]);
	//the password is not shown again: an empty one keeps the one entered before
	$passwordKept = (string)($_SESSION['setup']['server']['smtp_password'] ?? '') !== '';
	if ($req->is('post')) {
	    $form->assign($req->data);
	    if ($passwordKept && (string)$form->smtp_password === '')
		$form->smtp_password = $_SESSION['setup']['server']['smtp_password'];
	    if ($form->validates()) {
		//util\Setup::database (shared with the command-line setup: commands\SysCommand)
		$problem = \util\Setup::database($form->values());
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

    //the environment's NFN_SETUP_<k> (the installer's answers), else $d
    private static function setupEnv($k, $d) {
	return (string)getenv('NFN_SETUP_' . $k) ?: $d;
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
		//util\Setup::install (shared with the command-line setup: commands\SysCommand)
		$user = \util\Setup::install($server, [ 'account_name' => $form->account_name, 'username' => $form->username, 'email' => $form->email,
		    'firstname' => $form->firstname, 'lastname' => $form->lastname, 'password' => $form->new_password ], $error);
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
}
