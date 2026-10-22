<?php

namespace commands;

class SeedCommand extends \sys\BaseController {

    public $a_class;
    public function test() {
	$this->a_class = '\sys\BaseObject';

	$o = (new $this->a_class())->_stop();
    }


    /*
	setup of the database
	../v seed::indexes
	    creates the indexes every model declares (static $indexes of its class, plugins' models included);
	    creating an index that exists does nothing, so it can be run again (e.g. after an update)
    */
    //CLD: 2026-10-19T12:52:41+03:00: the indexes are made by ObjectManager::createIndexes (shared with the initial setup); this prints its result
    public function indexes($req = null, $resp = null) {
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


    // -------------------------------------------------------------------------------------------------------
    public function run() {
	\V::app()->log->setFacility('console');
	\V::app()->log->setLevel('DEBUG');

	//delete everything
	$db = \V::app()->mongo->selectDatabase(\V::app()->mongo->database);
	foreach($db->listCollectionNames() as $collName) {
	    if (str_starts_with($collName, 'system.'))
		continue;
	    echo "Drop $collName\n";
	    $db->dropCollection($collName);
	}

	//create an account
	echo "Create account\n";
	$account = \models\Account::create([ 'state' => 'active' ]);
	if (!$account->validates()) {
	    echo "account does not validate:" . print_r($account->errors(), true);
	    exit;
	}
	$account->save();
	\V::app()->var('rt.account', $account);

	//now default roles: Owner/Member
	echo "Create default roles\n";
	//CLD: 2026-10-16T12:14:57+03:00: Account::setupRoles (shared with the sign-up; was written here)
	$account->setupRoles();


	//CLD: 2026-10-13T20:09:05+03:00: a user with the owner role and one with the member role (the member was commented out); each gets its Personal project
	$users = [
	    [ 'username' => 'ab', 'firstname' => 'A', 'lastname' => 'B', 'email' => 'ab@gmail.com', 'role' => 'owner' ],
	    [ 'username' => 'cd', 'firstname' => 'C', 'lastname' => 'D', 'email' => 'cd@gmail.com', 'role' => 'member' ],
	];

	echo "Create some users\n";
	foreach($users as $uDef) {
	    //get role
	    $role = \V::app()->om->findOne('Role', [ 'aid' => $account->_id, 'iname' => $uDef['role'] ]);

	    //now create a user
	    $user = \models\User::create([
		'aid' => $account->_id,
		'username' => $uDef['username'],
		'email' => $uDef['email'],
		'firstname' => $uDef['firstname'],
		'lastname' => $uDef['lastname'],
		'password' => \V::app()->security->generatePasswordHash('123'),
		//while seeding
		'state' => 'active',
		'role' => $role->_id,
	    ]);

	    if (!$user->validates()) {
		echo "user does not validate:" . print_r($user->errors(), true);
		exit;
	    }
	    $user->save();

	    //now create a Project
	    $project = \models\Project::create([
		'aid' => $account->_id,
		'name' => 'Personal',
		'owner' => $user->_id,
		'personal' => true,
		'members' => [
		    (string)$user->_id => [ 'role' => $role->_id ],
		],
	    ]);

	    if (!$project->validates()) {
		echo "project does not validate:" . print_r($project->errors(), true);
		exit;
	    }
	    $project->save();

	    //now create a Workflow
	    $wf = \models\Workflow::create([
		'aid' => $account->_id,
		'name' => 'Workflow #1',
		'project' => $project->_id,
	    ]);

	    if (!$wf->validates()) {
		echo "workflow does not validate:" . print_r($wf->errors(), true);
		exit;
	    }
	    $wf->save();

	    //create collection indexes
	    $this->indexes();
	}
    }

}
