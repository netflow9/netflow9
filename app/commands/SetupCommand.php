<?php

namespace commands;

//CLD: 2026-10-29T02:00:00+02:00: new: the initial setup from the command line (scripts/install.sh runs it as the app's user), the same as the browser's (controllers\SetupController):
//only while app.env is missing (app/v.php: get_setup_console_config)
//  ../v setup::checks      the prerequisites (util\Setup::checks); exits with 1 when a required one fails
//  ../v setup::validate    checks the answers (JSON on stdin, as setup::install) and the database, installs nothing; exits with 1 when one is not valid
//  ../v setup::install     reads the answers as JSON on stdin (no secret in the arguments):
//                            { "server": { base_url, mongo_uri, mongo_db, smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password, mail_from },
//                              "owner": { account_name, username, email, firstname, lastname, password } }
//                          checks them as the browser's forms (models\setup\ServerForm, AccountForm), then the prerequisites and the database (it must have no account),
//                          and installs (util\Setup::install: the indexes, the account and its owner, app.env); exits with 1 when something fails
class SetupCommand extends \sys\BaseController {

    public function checks($req = null, $resp = null) {
	$this->setupOnly();
	exit($this->printChecks() ? 0 : 1);
    }

    public function validate($req = null, $resp = null) {
	$this->setupOnly();
	$answers = $this->answers();
	$problem = \util\Setup::database($answers[0]);
	if ($problem)
	    $this->fail("{$problem[0]}: {$problem[1]}");
	echo "answers: OK\n";
	exit(0);
    }

    public function install($req = null, $resp = null) {
	$this->setupOnly();
	[ $server, $owner ] = $this->answers();

	if (!$this->printChecks())
	    $this->fail('a required prerequisite is missing (above)');

	$problem = \util\Setup::database($server);
	if ($problem)
	    $this->fail("{$problem[0]}: {$problem[1]}");

	$user = \util\Setup::install($server, $owner, $error);
	if (!$user)
	    $this->fail((string)$error);
	echo "set up: account {$owner['account_name']}, owner {$user->username} ({$user->email}); app.env written\n";
	exit(0);
    }

    //the answers on stdin, checked as the browser's forms: [ the server's values, the owner ] (util\Setup::install's arguments); stops when they are not valid
    private function answers() {
	$input = json_decode((string)file_get_contents('php://stdin'), true);
	if (!is_array($input) || !is_array($input['server'] ?? null) || !is_array($input['owner'] ?? null))
	    $this->fail('the answers are missing: JSON { "server": {...}, "owner": {...} } on stdin');
	$owner = $input['owner'];

	$server = \models\setup\ServerForm::create();
	$server->assign($input['server']);
	$account = \models\setup\AccountForm::create([], [ 'validateCurrentPassword' => false ]);
	$account->assign([ 'account_name' => $owner['account_name'] ?? '', 'username' => $owner['username'] ?? '', 'email' => $owner['email'] ?? '',
	    'firstname' => $owner['firstname'] ?? '', 'lastname' => $owner['lastname'] ?? '',
	    'new_password' => $owner['password'] ?? '', 'cfm_password' => $owner['password'] ?? '' ]);
	$problems = [];
	foreach ([ $server, $account ] as $form) {
	    if (!$form->validates()) {
		foreach ($form->errors() as $field => $messages)
		    $problems[] = "{$field}: " . implode('; ', (array)$messages);
	    }
	}
	if ($problems)
	    $this->fail("the answers are not valid:\n  " . implode("\n  ", $problems));

	return [ $server->values(), [ 'account_name' => $account->account_name, 'username' => $account->username, 'email' => $account->email,
	    'firstname' => $account->firstname, 'lastname' => $account->lastname, 'password' => $account->new_password ] ];
    }

    //only while app.env is missing
    private function setupOnly() {
	if (!\V::app()->config('app.setup'))
	    $this->fail('the app is already set up (app.env exists)');
    }

    //prints the checks that fail (required: !, recommended: -); true when every required one passes
    private function printChecks() {
	//not the request's HTTPS: there is no request here
	$checks = array_values(array_filter(\util\Setup::checks(), fn($c) => $c['name'] !== 'HTTPS'));
	foreach ($checks as $c) {
	    if (!$c['ok'])
		echo ($c['required'] ? '! ' : '- ') . "{$c['name']}: {$c['value']} (needed for: {$c['need']})\n";
	}
	$ready = \util\Setup::ready($checks);
	echo $ready ? "prerequisites: OK\n" : "prerequisites: a required one is missing (!)\n";
	return $ready;
    }

    private function fail($message) {
	fwrite(STDERR, "setup: {$message}\n");
	exit(1);
    }
}
