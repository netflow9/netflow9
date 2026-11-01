<?php

namespace commands;

/*
    CLD: 2026-11-01T15:00:00+02:00: new: the installation's commands in one (were SetupCommand, AuditCommand, WfCommand: setup::*, audit::verify, wf::*); run as the app's user,
    in app/commands (or app/v from the app's folder). SysCommand.sh: the audit and runs from the shell.

    setup, only while app.env is missing (app/v.php: get_setup_console_config; the other actions need the app's settings)
	../v sys::checks      the prerequisites (util\Setup::checks); exits with 1 when a required one fails
	../v sys::validate    checks the answers (JSON on stdin, as sys::install) and the database, installs nothing; exits with 1 when one is not valid
	../v sys::install     reads the answers as JSON on stdin (no secret in the arguments):
            {
        	"server": { base_url, mongo_uri, mongo_db, smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password, mail_from },
                "owner": { account_name, username, email, firstname, lastname, password }
            }
        checks them as the browser's forms (models\setup\ServerForm, AccountForm), then the prerequisites and the database (it must have no account),
        and installs (util\Setup::install: the indexes, the account and its owner, app.env); exits with 1 when something fails

    once set up (app.env exists)
	../v sys::indexes     creates the indexes every model declares (static $indexes of its class, plugins' models included); creating an index that exists does nothing,
			      so it can be run again (e.g. after an update); the indexes are made by ObjectManager::createIndexes (shared with util\Setup::install); exits
			      with 1 when one fails
	../v sys::audit [--account=<id>]     checks the audit log's seal (models\AuditLog::verify): changed or forged entries, missing ones, entries without a MAC; every account,
			      or one; exits with 1 when a problem is found
	../v sys::run --id=<workflow id>     starts an execution of the workflow (its draft) and runs it; not when its connections or steps' settings have errors
	../v sys::resume --id=<execution id> runs a sleeping execution again (e.g. after a Wait)
*/
class SysCommand extends \sys\BaseController {

    // ------------------------------------------------------------------------------------
    //setup

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

    //CLD: 2026-10-31T12:00:00+02:00: moved here from SeedCommand::indexes (was `../v seed::indexes`); only once the app is set up (sys::install creates them itself)
    public function indexes($req = null, $resp = null) {
	if (\V::app()->config('app.setup'))
	    $this->fail('the app is not set up yet (app.env is missing): sys::install creates the indexes');
	$failed = 0;
	$collections = [];
	foreach(\V::app()->om->createIndexes() as $r) {
	    $collections[strtok($r['label'], ' ')] = true;
	    if ($r['error'] === null) {
		echo "ok    {$r['label']} ({$r['name']})\n";
	    } else {
		$failed++;
		echo "FAIL  {$r['label']}: {$r['error']}\n";
	    }
	}
	echo count($collections) . " collections" . ($failed ? ", {$failed} failed" : '') . "\n";
	if ($failed)
	    exit(1);
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
	fwrite(STDERR, "sys: {$message}\n");
	exit(1);
    }

    // ------------------------------------------------------------------------------------
    //the audit log

    public function audit($req = null, $resp = null) {
	$id = $req->params['account'] ?? null;
	$accounts = \V::app()->om->find('Account');
	if ($id) {
	    $acct = preg_match('/^[a-f0-9]{24}$/', (string)$id) ? \V::app()->om->findById('Account', $id) : null;
	    if (!$acct) {
		echo "account not found: {$id}\n";
		exit(1);
	    }
	    $accounts = [ $acct ];
	}

	$problems = 0;
	foreach($accounts as $acct) {
	    $r = \models\AuditLog::verify($acct->_id);
	    echo "account {$acct->_id}" . ((string)$acct->name !== '' ? " ({$acct->name})" : '') . ": {$r['entries']} sealed entries, numbers "
		. ($r['pruned'] + 1) . " to {$r['last']}" . ($r['pruned'] ? " (up to {$r['pruned']} removed by the retention)" : '')
		. ($r['unsealed'] ? ", {$r['unsealed']} from before the seal" : '') . ': ' . (empty($r['problems']) ? 'OK' : count($r['problems']) . ' problem(s)') . "\n";
	    foreach($r['problems'] as $p)
		echo "  - {$p}\n";
	    $problems += count($r['problems']);
	}
	exit($problems ? 1 : 0);
    }

    // ------------------------------------------------------------------------------------
    //workflows

    public function run($req = null, $resp = null) {
	$wf = $this->findOrExit('Workflow', $req->params['id'] ?? null);
	$this->setAccount($wf->aid);

	//CLD: 2026-10-06T23:25:57+03:00: do not run a workflow whose connections have errors (warnings are printed)
	//CLD: 2026-10-19T17:10:00+03:00: issues() (was validateFlow): + errors in the steps' settings (a draft may be saved with them)
	$issues = $wf->issues();
	foreach($issues as $i)
	    echo $i['level'] . ': ' . $i['message'] . ($i['node'] ? " [{$i['node']}]" : '') . "\n";
	if (array_filter($issues, fn($i) => $i['level'] == 'error')) {
	    echo "not started: fix the errors first\n";
	    exit(1);
	}

	//CLD: 2026-10-11T17:45:25+03:00: started from the console (was the default `manual`: the execution log and Executions panel showed a designer Run)
	$exe = \models\WfExecution::startFor($wf, 'console');
	$exe->save();
	echo "execution " . $exe->_id . " of workflow `" . $wf->name . "`\n";

	$exe->run();
	$this->report($exe);
    }

    public function resume($req = null, $resp = null) {
	$exe = $this->findOrExit('WfExecution', $req->params['id'] ?? null);
	$this->setAccount($exe->aid);

	if (!$exe->canRun()) {
	    echo "execution " . $exe->_id . " is `" . $exe->state . "`: nothing to run\n";
	    exit(1);
	}

	$exe->run();
	$this->report($exe);
    }

    private function findOrExit($objectName, $id) {
	if (empty($id) || !\util\Validator::rule('oid', (string)$id)) {
	    echo "usage: --id=<{$objectName} id>\n";
	    exit(1);
	}
	$o = \V::app()->om->findById($objectName, (string)$id);
	if (empty($o)) {
	    echo "{$objectName} {$id} not found\n";
	    exit(1);
	}
	return $o;
    }

    //nodes read objects of the account (e.g. Field_object choices)
    private function setAccount($aid) {
	\V::app()->var('rt.account', \V::app()->om->findById('Account', $aid));
    }

    private function report($exe) {
	echo "state: " . $exe->state . ($exe->error ? " (" . $exe->error . ")" : "") . "\n";
	if ($exe->state == 'sleep' && $exe->wake_at)
	    echo "wake at: " . $exe->wake_at->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') . "\n";

	echo "variables: " . json_encode($exe->variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	echo "log:\n";
	foreach($exe->log as $entry) {
	    $node = $entry['node'] ?? null;
	    $title = ($node && isset($exe->__nodes[$node])) ? (string)$exe->__nodes[$node] : '-';
	    echo "  [{$title}] " . $entry['message'] . "\n";
	}
    }
}
