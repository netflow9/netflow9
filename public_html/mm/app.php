<?php

class App {
    // log out from system
    function do_logout() {
	R::app()->user->logout();
	R::app()->response->redirect(R::app()->url('app.login'));
    }

    // login page
    public function do_login() {
	$password = R::app()->request->getDataParam('password');
	$username = R::app()->request->getDataParam('username');
	$db = R::app()->request->getDataParam('db');

	$error = R::app()->request->getQueryParam('error');

	if (R::app()->request->isPost()) {
	    //first validate local app auth
	    if (!empty(R::app()->config['auth'])) {
		$__password = R::app()->config['users'][$username] ?? null;
		if (empty($__password) || $__password != $password) {
		    R::app()->render('app_login', [ 'error' => 'Wrong credentials' ]);
		    return;
		}
	    }

	    if (!R::app()->server->connect($username, $password, $db)) {
		R::app()->render('app_login', [ 'error' => R::app()->server->error ]);
		return;
	    }

	    //login & remember user
	    R::app()->user->login($username, $password, $db);
	    R::app()->response->redirect(R::app()->url('app.home'));
	}

	R::app()->render('app_login', compact('error'));
    }

    // show all databases stats
    public function do_db_list() {
	R::app()->render('db_list');
    }

    //show all collections in a database
    public function do_collection_list() {
	//current db
	$db = R::app()->request->getQueryParam('db');
	R::app()->render('collection_list', compact('db'));
    }

    //retrieve flash messages
    public function do_messages() {
	$data = R::app()->user->getMessages();
	echo json_encode($data);
	R::app()->end();
    }

    public function do_nav_collections() {
	//current db
	$db = R::app()->request->getQueryParam('db');

	$data = [];
/*
	$data[] = [
	    'title' => '<input type="text" class="search" style="line-height:1">',
	    'icon' => 'bi bi-search',
	    //'url' => R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $cName ]),
	    'key' => "$db-search",
	    'type' => 'search',
	];
*/
	$collections = R::app()->server->listCollections($db);
	foreach($collections as $cName => $cCount) {
	    $icon = 'bi bi-table';
	    if (strpos($cName, '.files') || strpos($cName, '.chunks'))
		$icon = 'bi bi-files';

	    $data[] = [
		'title' => $cName . ' (' . $cCount . ')',
		'icon' => $icon,
		'url' => R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $cName ]),
		'key' => "$db.$cName",
		'type' => 'collection',
	    ];
	}
	echo json_encode($data);
	R::app()->end();
    }

    public function do_nav_collection() {
	//current db
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$data = [];
	$collections = R::app()->server->listCollections($db);
	foreach($collections as $cName => $cCount) {
	    if ($cName == $collection) {
		$icon = 'bi bi-table';
		if (strpos($cName, '.files') || strpos($cName, '.chunks'))
		    $icon = 'bi bi-files';
		$data[$cName] = [
		    'title' => $cName . ' (' . $cCount . ')',
		    'icon' => $icon,
		    'url' => R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $cName ]),
		    'key' => "$db.$cName",
		    'type' => 'collection',
		];
		break;
	    }
	}
	echo json_encode($data);
	R::app()->end();
    }


    //the homepage
    public function do_home() {
	//command line
	$commandLine = '';

	try {
	    $command = new \MongoDB\Driver\Command([ 'getCmdLineOpts' => 1 ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $ret = current($cursor->toArray());

	    if (isset($ret['argv']))
		$commandLine = implode(' ', $ret['argv']);
	} catch (Exception $e) {
	}

	//web server
	$webServers = array();
	if (isset($_SERVER["SERVER_SOFTWARE"])) {
	    list($webServer) = explode(" ", $_SERVER["SERVER_SOFTWARE"]);
	    $webServers["Web server"] = $webServer;
	}
	$webServers["<a href=\"http://www.php.net\" target=\"_blank\">PHP version</a>"] = "PHP " . PHP_VERSION;
	$webServers["MongoDB DRIVER version"] = phpversion('mongodb');

	//build info
	$buildInfos = [];

	try {
	    $command = new \MongoDB\Driver\Command([ 'buildInfo' => 1 ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $ret = current($cursor->toArray());

	    if ($ret["ok"]) {
		$buildInfos['version'] = $ret['version'];
	    }
	} catch (Exception $e) {
	}

	//connection
	$connections = array(
	    "URI" => R::app()->server->getUri(),
	    "Username" => "******",
	    "Password" => "******"
	);

	R::app()->render('app_home', compact('connections', 'buildInfos', 'webServers', 'commandLine'));
    }

    /** rename collection **/
    public function do_collection_rename() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');
	$newname = $collection;

	if (R::app()->request->isPost()) {
	    $newname = R::app()->request->getDataParam('newname');
	    $remove_target = R::app()->request->getDataParam('remove_target');
	    if (empty($newname)) {
		$error = "Please enter a new name.";
		R::app()->render('collection_rename', compact('error', 'db', 'collection', 'newname'));
		return;
	    }

	    $collections = R::app()->server->listCollections($db);
	    if (isset($collections[$newname]) && !$remove_target) {
		$error = "There is already a collection with this name.";
		R::app()->render('collection_rename', compact('error', 'db', 'collection', 'newname'));
		return;
	    }

	    $command = new \MongoDB\Driver\Command([
		'renameCollection' => "$db.$collection",
		'to' => "$db.$newname",
		'dropTarget' => !empty($remove_target),
	    ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'Collection renamed',
	    ]);
	    
	    echo "<script>$(document).trigger({type:'navigate',key:'$db.$newname'});</script>";
	    return;
	}
	R::app()->render('collection_rename', compact('db', 'collection', 'newname'));
    }

    /** duplicate collection **/
    public function do_collection_duplicate() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$target = $collection . '_copy';
	$remove_target = 1;
	$copy_indexes = 1;

	if (R::app()->request->isPost()) {
	    $target = R::app()->request->getDataParam('target');
	    $remove_target = R::app()->request->getDataParam('remove_target');
	    $copy_indexes = R::app()->request->getDataParam('copy_indexes');
	    if (empty($target)) {
		$error = 'Please enter a valid target name';
		R::app()->render('collection_duplicate', compact('error'));
		return;
	    }

	    if ($remove_target) {
		$command = new \MongoDB\Driver\Command([ 'drop' => $target ]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
	    }

	    $command = new \MongoDB\Driver\Command([
		'aggregate' => $collection,
		'pipeline' => [ [ '$out' => $target ] ],
		'cursor' => new stdClass(),
	    ]);
	    $cursor = R::app()->server->manager->executeCommand($db, $command);
	    $ret = current($cursor->toArray());

	    if ($copy_indexes) {
		$command = new \MongoDB\Driver\Command([
		    'listIndexes' => $collection,
		]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
		foreach ($cursor as $i) {
		    $_command = new \MongoDB\Driver\Command([
        		'createIndexes' => $collection,
        		'indexes' => [ (object)$i ],
		    ]);
		    $_cursor = R::app()->server->manager->executeCommand($db, $_command);
		    $ret = current($_cursor->toArray());
		}
	    }

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'Collection duplicated',
	    ]);

	    echo "<script>$(document).trigger({type:'navigate',key:'$db.$target'});</script>";
	    return;
 	}
	R::app()->render('collection_duplicate', compact('db', 'collection', 'target', 'remove_target', 'copy_indexes'));
    }

    /** create new collection **/
    public function do_collection_create() {
	//if db is provided in url, use it
	$db = R::app()->request->getQueryParam('db');

	$name = '';
	$size = 0;
	$is_capped = '';
	$max = 0;

	if (R::app()->request->isPost()) {
	    if (empty($db))
		$db = R::app()->request->getDataParam('db');

	    if (empty($db)) {
		$error = 'Database is required';
		R::app()->render('collection_create', compact('error'));
		return;
	    }

	    $is_capped = R::app()->request->getDataParam('is_capped');
	    $name = R::app()->request->getDataParam('name');
	    $size = (int)R::app()->request->getDataParam('size');
	    $max = (int)R::app()->request->getDataParam('max');

	    if (empty($name)) {
		$error = 'Collection name is required';
		R::app()->render('collection_create', compact('error', 'db', 'name', 'is_capped', 'size', 'max'));
		return;
	    }

	    $cmd = [ 'create' => $name ];
	    if (!empty($is_capped))
		$cmd['capped'] = true;
	    if ($size > 0)
		$cmd['size'] = $size;
	    if ($max > 0)
		$cmd['max'] = $max;

	    $command = new \MongoDB\Driver\Command($cmd);
	    $cursor = R::app()->server->manager->executeCommand($db, $command);
	    $ret = current($cursor->toArray());

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'A new collection was created',
	    ]);

	    echo "<script>$(document).trigger({type:'navigate',key:'$db.$name'});</script>";
	    return;
	}

	R::app()->render('collection_create', compact('db', 'name', 'size', 'max', 'is_capped'));
    }

    /** drop collection**/
    public function do_collection_drop() {
	if (!R::app()->request->isPost())
	    return;
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	if (empty($collection) || empty($db))
	    return;
	
	$command = new \MongoDB\Driver\Command([ 'drop' => $collection ]);
	$cursor = R::app()->server->manager->executeCommand($db, $command);
	R::app()->user->addMessage([
	    'type' => 'success',
	    'body' => 'Collection dropped',
	]);
	echo "<script>$(document).trigger({type:'navigate',key:'$db'});</script>";
    }

    /** drop database**/
    public function do_db_drop() {
	if (!R::app()->request->isPost())
	    return;
	$db = R::app()->request->getQueryParam('db');

	if (empty($db))
	    return;

	$command = new \MongoDB\Driver\Command([ 'dropDatabase' => 1 ]);
	$cursor = R::app()->server->manager->executeCommand($db, $command);
	R::app()->user->addMessage([
	    'type' => 'success',
	    'body' => 'Database dropped',
	]);
	echo "<script>$(document).trigger({type:'navigate',key:'dbs'});</script>";
    }

    /** execute command on a database**/
    public function do_db_command() {
	$db = R::app()->request->getQueryParam('db', 'admin');
	$command = var_export([ 'listCommands' => 1 ], true);

	$view_params = [
	    'db' => $db,
	    'command' => $command,
	];
	if (R::app()->request->isPost()) {
	    $db = R::app()->request->getDataParam('db', $db);

	    $view_params['db'] = $db;

	    $command = R::app()->request->getDataParam('command');
	    $view_params['command'] = $command;

	    $_cmd = Utils::varEvalPHP($command);
	    if (!is_array($_cmd)) {
		$error = 'You should send a valid command';
		R::app()->render('db_command', compact('command', 'db', 'error'));
		return;
	    }

	    try {
		$command = new \MongoDB\Driver\Command($_cmd);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
		$view_params['ret'] = current($cursor->toArray());
	    } catch(Exception $e) {
		$view_params['error'] = $e->getMessage();
	    }
	}

	R::app()->render('db_command', $view_params);
    }

    /** server processlist **/
    public function do_server_processlist() {
	$progs = [];

	try {
	    $command = new \MongoDB\Driver\Command([ 'currentOp' => true, '$all' => true, 'active' => true ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);

	    $ret = current($cursor->toArray());
	    if ($ret['ok'])
		$progs = $ret['inprog'];

	    foreach ($progs as $index => $prog) {
		foreach ($prog as $key => $value) {
		    if (empty($value))
			continue;
		    if (is_array($value)) {
			$progs[$index][$key] = Utils::highlight($value);
		    }
		}
	    }
	} catch (Exception $e) {
	}
	R::app()->render('server_processlist', compact('progs'));
    }

    /** kill one operation in processlist **/
    public function do_server_killop() {
	$opid = R::app()->request->getQueryParam('opid');

	try {
	    $command = new \MongoDB\Driver\Command([ 'killOp' => true, 'op' => (int)$opid, ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $ret = current($cursor->toArray());
	    if ($ret['ok']) {
		R::app()->user->addMessage([
		    'type' => 'success',
		    'body' => 'A process was killed',
		]);
	    }
	} catch(Exception $e) {
	    R::app()->user->addMessage([
		'type' => 'error',
		'body' => $e->getMessage(),
	    ]);
	}

	R::app()->response->redirect(R::app()->url('app.server_processlist'));
    }

    /** Server Status **/
    public function do_server_status() {
	$view_params = [];

	try {
	    //status
	    $command = new \MongoDB\Driver\Command([ 'serverStatus' => 1 ]);
	    $cursor = R::app()->server->manager->executeCommand('admin', $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $ret = current($cursor->toArray());
	    $view_params['status'] = Utils::highlight($ret);
	} catch (Exception $e) {
	}

	R::app()->render('server_status', $view_params);
    }

    //----------------------------------------------------------------------------
    /** authentication **/
    public function do_user_list() {
	$users = [];
	try {
	    $db = R::app()->user->db();
	    $collection = 'system.users';
	    $query = new \MongoDB\Driver\Query([]);
	    $cursor = R::app()->server->manager->executeQuery("$db.$collection", $query);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $users = $cursor->toArray();
	} catch(Exception $e) {
	}

	R::app()->render('user_list', compact('users'));
    }

    /** add user **/
    public function do_user_add() {
	$view_params = [];

	$roles = [
	    'read' => 'read',
	    'readWrite' => 'readWrite',
	    'dbAdmin' => 'dbAdmin',
	    'dbOwner' => 'dbOwner',
	    'userAdmin' => 'userAdmin',
	    'backup' => 'backup',
	    'restore' => 'restore',
	    'readAnyDatabase' => 'readAnyDatabase',
	    'readWriteAnyDatabase' => 'readWriteAnyDatabase',
	    'userAdminAnyDatabase' => 'userAdminAnyDatabase',
	    'dbAdminAnyDatabase' => 'dbAdminAnyDatabase',
	    'root' => 'root',
	];
	$view_params['roles'] = $roles;


	if (R::app()->request->isPost()) {
	    $db = R::app()->request->getDataParam('db');
	    $username = trim(R::app()->request->getDataParam('username'));
	    $password = trim(R::app()->request->getDataParam('password'));
	    $password2 = trim(R::app()->request->getDataParam('password2'));
	    $role = trim(R::app()->request->getDataParam('role'));

	    $view_params['username'] = $username;
	    $view_params['role'] = $role;
	    $view_params['db'] = $db;

	    if (empty($username)) {
		R::app()->render('user_add', $view_params + [ 'error' => "You must supply a username for user." ]);
		return;
	    }
	    if (empty($password)) {
		R::app()->render('user_add', $view_params + [ 'error' => "You must supply a password for user." ]);
		return;
	    }
	    if ($password != $password2) {
		R::app()->render('user_add', $view_params + [ 'error' => "Passwords do not match." ]);
		return;
	    }

	    if (empty($role)) {
		R::app()->render('user_add', $view_params + [ 'error' => "You must supply a role for user." ]);
		return;
	    }

	    if (empty($db)) {
		R::app()->render('user_add', $view_params + [ 'error' => "You must supply a database for user." ]);
		return;
	    }
	    try {
		$command = new \MongoDB\Driver\Command([
		    'createUser' => $username,
		    'roles' => [
			[ 'role' => $role, 'db' => $db ],
		    ],
		    'pwd' => $password,
		]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
		$ret = current($cursor->toArray());

		R::app()->user->addMessage([
		    'type' => 'success',
		    'body' => 'User created',
		]);
	    } catch (Exception $e) {
		R::app()->render('user_add', $view_params + [ 'error' => $e->getMessage() ]);
		return;
	    }


	    R::app()->response->redirect(R::app()->url('app.user_list'));
	}
	R::app()->render('user_add', $view_params);
    }

    /** change password for user **/
    public function do_user_password() {
	$db = R::app()->request->getQueryParam('db');
	if (empty($db)) {
	    R::app()->render('user_password', $view_params + [ 'error' => 'Database is missing' ]);
	    return;
	}

	$user = R::app()->request->getQueryParam('user');
	if (empty($user)) {
	    R::app()->render('user_password', $view_params + [ 'error' => 'User is missing' ]);
	    return;
	}

	$view_params = [
	    'user' => $user,
	    'db' => $db,
	];
	if (R::app()->request->isPost()) {
	    try {
		$password = trim(R::app()->request->getDataParam('password'));
		$password2 = trim(R::app()->request->getDataParam('password2'));
		if (empty($password)) {
		    R::app()->render('user_password', $view_params + [ 'error' => "You must supply a password for user." ]);
		    return;
		}
		if ($password != $password2) {
		    R::app()->render('user_password', $view_params + [ 'error' => "Passwords do not match." ]);
		    return;
		}


		$command = new \MongoDB\Driver\Command([
		    'updateUser' => $user,
		    'pwd' => $password,
		]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
		$ret = current($cursor->toArray());

		R::app()->user->addMessage([
		    'type' => 'success',
		    'body' => 'User password changed',
		]);

	    } catch (Exception $e) {
		R::app()->user->addMessage([
		    'type' => 'error',
		    'body' => 'User password error:' . $e->getMessage(),
		]);
	    }
	    R::app()->response->redirect(R::app()->url('app.user_list'));
	}
	R::app()->render('user_password', $view_params);
    }

    /** delete user **/
    public function do_user_drop() {
	if (!R::app()->request->isPost())
	    return;

	try {
	    $db = R::app()->request->getQueryParam('db');
	    if (empty($db)) {
		R::app()->user->addMessage([
		    'type' => 'error',
		    'body' => 'User drop error: missing db',
		]);
		R::app()->response->redirect(R::app()->url('app.user_list'));
		return;
	    }
	    $user = R::app()->request->getQueryParam('user');
	    if (empty($user)) {
		R::app()->user->addMessage([
		    'type' => 'error',
		    'body' => 'User drop error: missing user',
		]);
		R::app()->response->redirect(R::app()->url('app.user_list'));
		return;
	    }

	    $command = new \MongoDB\Driver\Command([
		'dropUser' => $user,
	    ]);
	    $cursor = R::app()->server->manager->executeCommand($db, $command);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $ret = current($cursor->toArray());

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'User dropped',
	    ]);
	} catch (Exception $e) {
	    R::app()->user->addMessage([
		'type' => 'error',
		'body' => 'User drop error:' . $e->getMessage(),
	    ]);
	    R::app()->response->redirect(R::app()->url('app.user_list'));
	    return;
	}

	R::app()->response->redirect(R::app()->url('app.user_list'));
    }

    /** browse collection **/
    public function do_collection_browse() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');
	$command = R::app()->request->getQueryParam('command', 'findAll');
	$update = R::app()->request->getQueryParam('update', null);
	$sort = R::app()->request->getQueryParam('sort', null);
	$projection = R::app()->request->getQueryParam('projection', null);
	$criteria = R::app()->request->getQueryParam('criteria', null);

	if (R::app()->request->isPost()) {
	    $sort = R::app()->request->getDataParam('sort', null);
	    $projection = R::app()->request->getDataParam('projection', null);
	    $criteria = R::app()->request->getDataParam('criteria', null);
	    $update = R::app()->request->getDataParam('update', null);
	    $command = R::app()->request->getDataParam('command', 'findAll');
	}

	if (empty($sort))
	    $sort = var_export([ '_id' => -1 ], true);
	if (empty($projection))
	    $projection = "array(\n\t\n)";
	if (empty($criteria))
	    $criteria = "array(\n\t\n)";
	if (empty($update))
	    $update = "array(\n\t'\$set' => array(\n\t\t//fields\n\t)\n)";

	$view_params = [
	    'db' => $db,
	    'collection' => $collection,
	    //'coll' => $coll,
	    'criteria' => $criteria,
	    'sort' => $sort,
	    'projection' => $projection,
	    'update' => $update,
	    'command' => $command,
	    //'pager' => new Pager([ 'size' => 20, 'total' => 0, 'page' => 1 ]),
	];

	//parse criteria
	try {
	    $_criteria = Utils::varEvalPHP($criteria);
	} catch(Exception $e) {
	    R::app()->render('collection_browse', $view_params + [ 'error' => $e->getMessage() ]);
	    return;
	}

	//count the number of affected records
	$_command = new \MongoDB\Driver\Command([
	    'count' => $collection,
	    'query' => (object)$_criteria,
	]);
	$cursor = R::app()->server->manager->executeCommand($db, $_command);
	$res = current($cursor->toArray());
	$view_params['count'] = $res->n;


	//perform the command
	switch (true) {
	    case $command == 'remove' && R::app()->request->isPost():
		$bulk = new \MongoDB\Driver\BulkWrite();
		$bulk->delete($_criteria);
		$result = R::app()->server->manager->executeBulkWrite("$db.$collection", $bulk);
		$view_params['result'] = $result;
		break;
	    case $command == 'update' && R::app()->request->isPost():
		//parse update
		try {
		    $_update = Utils::varEvalPHP($update);
		} catch(Exception $e) {
		    R::app()->render('collection_browse', $view_params + [ 'error' => $e->getMessage() ]);
		    return;
		}

		if (empty($_update)) {
		    R::app()->render('collection_browse', $view_params + [ 'error' => 'nothing to update' ]);
		    return;
		}
		//check update operators
		$ops = [
		    '$set', '$unset', '$rename',
		    '$addToSet', '$pop', '$pull', '$push',
		    '$inc', '$currentDate', '$setOnInsert',
		    '$and', '$or', '$xor', '$not',
		];
		$op_count = 0;
		foreach($ops as $op) {
		    if (isset($_update[$op]) && !empty($_update[$op]))
			$op_count++;
		}

		if ($op_count == 0) {
		    R::app()->render('collection_browse', $view_params + [ 'error' => 'nothing to update' ]);
		    return;
		}

		$bulk = new \MongoDB\Driver\BulkWrite();
		$bulk->update($_criteria, $_update, [ 'upsert' => false, 'multi' => true ]);
		$result = R::app()->server->manager->executeBulkWrite("$db.$collection", $bulk);
		$view_params['result'] = $result;
		break;

	    case $command == 'findAll':
		$pager = new Pager([
		    'size' => 20,
		    'total' => $view_params['count'],
		    'page' => R::app()->request->getQueryParam('page', 1),
		]);
		$view_params['pager'] = $pager;

		//parse sort
		try {
		    $_sort = Utils::varEvalPHP($sort);
		} catch(Exception $e) {
		    R::app()->render('collection_browse', $view_params + [ 'error' => $e->getMessage() ]);
		    return;
		}

		//parse projection
		try {
		    $_projection = Utils::varEvalPHP($projection);
		} catch(Exception $e) {
		    R::app()->render('collection_browse', $view_params + [ 'error' => $e->getMessage() ]);
		    return;
		}

		$_options = [
		    'limit' => $pager->size(),
		    'skip' => $pager->offset(),
		];
		if (!empty($_projection))
		    $_options['projection'] = $_projection;
		if (!empty($_sort))
		    $_options['sort'] = $_sort;
/*
		$log = fopen(dirname(__FILE__) . '/log.txt', 'a+');
		fwrite($log, var_export($_criteria, true) . "\n");
		fclose($log);
*/

		$query = new \MongoDB\Driver\Query($_criteria, $_options);
		$cursor = R::app()->server->manager->executeQuery("$db.$collection", $query);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);

		$rows = [];
		foreach ($cursor as $r) {
		    $rows[] = [
			'disp' => Utils::highlight($r),
			'text' => Utils::exportPHP($r),
			'r' => $r,
		    ];
		}
		$view_params['rows'] = $rows;
		break;
	}

	R::app()->render('collection_browse', $view_params);
    }

    //---------------------------------------------------------------------
    /** aggregate collection **/
    public function do_collection_aggregate() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$stage_ops = [
	    '$addFields' => [ 'cast' => 'object' ],
	    '$bucket' => [],
	    '$bucketAuto' => [],
	    '$count' => [],
	    '$densify' => [],
	    '$documents' => [],
	    '$facet' => [],
	    '$fill' => [],
	    '$geoNear' => [],
	    '$graphLookup' => [],
	    '$group' => [],
	    '$limit' => [],
	    '$lookup' => [],
	    '$match' => [],
	    '$merge' => [],
	    '$out' => [ 'cast' => 'object' ],
	    '$project' => [],
	    '$redact' => [],
	    '$replaceRoot' => [],
	    '$replaceWith' => [],
	    '$sample' => [],
	    '$set' => [],
	    '$setWindowFields' => [],
	    '$skip' => [],
	    '$sort' => [],
	    '$sortByCount' => [],
	    '$unionWith' => [],
	    '$unset' => [],
	    '$unwind' => [],
	];

	$view_params = [
	    'db' => $db,
	    'collection' => $collection,
	    //'coll' => $coll,
	    'pipeline' => [ '' => "array(\n\t\n)" ],
	    'stage_ops' => array_keys($stage_ops),
	    'output' => null,
	];

	if (R::app()->request->isPost()) {
	    $stage_op = R::app()->request->getDataParam('stage_op', null);
	    $stage_data = R::app()->request->getDataParam('stage_data', null);

	    $pipeline = [];
	    foreach ($stage_op as $index => $op) {
		$op = trim($op);
		if (empty($op))
		    continue;

		$data = $stage_data[$index];
		$pipeline[$op] = $data;
	    }

	    $view_params['pipeline'] = $pipeline;

	    if (empty($pipeline)) {
		$view_params['error'] = 'Pipeline should not be empty.';
		R::app()->render('collection_aggregate', $view_params);
		return;
	    }

	    //now validate the pipeline
	    $_pipeline = [];
	    foreach($pipeline as $op => $data) {
		//parse data
		try {
		    $_data = Utils::varEvalPHP($data);
		    switch($stage_ops[$op]['cast'] ?? null) {
			case 'object':
			    $_data = (object)$_data;
			    break;
		    }
		    $_pipeline[] = [ $op => $_data ];
		} catch(Exception $e) {
		    R::app()->render('collection_aggregate', $view_params + [ 'error' => "error at $op:" . $e->getMessage() ]);
		    return;
		}
	    }

	    try {
		$command = new \MongoDB\Driver\Command([
		    'aggregate' => $collection,
		    'pipeline' => $_pipeline,
		    'cursor' => new stdClass(),
		]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
		$view_params['output'] = $cursor->toArray();
	    } catch (Exception $e) {
		R::app()->render('collection_aggregate', $view_params + [ 'error' => $e->getMessage() ]);
		return;
	    }
	}
	R::app()->render('collection_aggregate', $view_params);
    }

    //---------------------------------------------------------------
    /** indexes on collection **/
    public function do_index_list() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$command = new \MongoDB\Driver\Command([
	    'aggregate' => $collection,
	    'pipeline' => [ [ '$indexStats' => new stdClass() ] ],
	    'cursor' => new stdClass(),
	]);
	$cursor = R::app()->server->manager->executeCommand($db, $command);
	$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);

	$indexes = [];
	foreach ($cursor as $i) {
	    //$name = $i->getName();
	    $indexes[$i['name']] = [
		'key_text' => Utils::highlight($i['key']),
		'key' => $i['key'] ?? [],
		'name' => $i['name'] ?? '',
		'v' => $i['spec']['v'] ?? 'X',
		'2dsphere' => array_search('2dsphere', $i['key'], true) !== false,
		'geo' => array_search('geoHaystack', $i['key'], true) !== false,
		'sparse' => !empty($i['spec']['sparse']),
		'text' => array_search('text', $i['key'], true) !== false,
		'ttl' => array_key_exists('expireAfterSeconds', $i['spec']),
		'unique' => !empty($i['spec']['unique']),
		'accesses' => $i['accesses'],
	    ];
	}

	R::app()->render('index_list', compact('db', 'collection', 'indexes'));
    }

    /** drop a collection index **/
    // to be tested
    public function do_index_drop() {
	if (!R::app()->request->isPost())
	    return;
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$index = R::app()->request->getQueryParam('index');
	if (empty($index)) {
	    R::app()->user->addMessage([
		'type' => 'error',
		'body' => 'No index name provided',
	    ]);
	    R::app()->response->redirect(R::app()->url('app.index_list', [ 'db' => $db, 'collection' => $collection ]));
	}

	$command = new \MongoDB\Driver\Command([
	    'dropIndexes' => $collection,
	    'index' => $index,
	]);
	$cursor = R::app()->server->manager->executeCommand($db, $command);
	$ret = current($cursor->toArray());

	R::app()->user->addMessage([
	    'type' => 'success',
	    'body' => 'Index dropped',
	]);
	    
	R::app()->response->redirect(R::app()->url('app.index_list', [ 'db' => $db, 'collection' => $collection ]));
    }

    /** create a collection index **/
    // incomplete
    public function do_index_create() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$view_params = [
	    'db' => $db,
	    'collection' => $collection,
	    'attrs' => [ '' => 1],
	];

	if (R::app()->request->isPost()) {
	    $fields = R::app()->request->getDataParam('field');
	    $orders = R::app()->request->getDataParam('order');
	    $partial = R::app()->request->getDataParam('partial');
	    $unique = R::app()->request->getDataParam('unique');
	    $sparse = R::app()->request->getDataParam('sparse');
	    $name = trim(R::app()->request->getDataParam('name'));
	    $partial_filter = R::app()->request->getDataParam('partial_filter');

	    $view_params['partial'] = $partial;
	    $view_params['unique'] = $unique;
	    $view_params['name'] = $name;
	    $view_params['sparse'] = $sparse;
	    $view_params['partial_filter'] = $partial_filter;

	    //build the index key array definition
	    $attrs = [];
	    foreach ($fields as $index => $field) {
		$field = trim($field);
		if (empty($field))
		    continue;
		$order = $orders[$index];
		if (is_numeric($order))
		    $order = (int)$order;
		    
		$attrs[$field] = $order;
	    }

	    $view_params['attrs'] = $attrs;

	    if (empty($attrs)) {
		$view_params['error'] = 'Index should contain one field at least.';
		R::app()->render('index_create', $view_params);
		return;
	    }

	    $indexDef = [ 'key' => $attrs ];
	    if (!empty($partial))
		$indexDef['partial'] = 1;
	
	    if ($partial) {
		try {
		    $_partial_filter = Utils::varEvalPHP($partial_filter);
		} catch(Exception $e) {
		    $view_params['error'] = $e->getMessage();
		    R::app()->render('index_create', $view_params);
		    return;
		}

		if (empty($_partial_filter) || !is_array($_partial_filter)) {
		    $view_params['error'] = "Data must be a valid PHP Array.";
		    R::app()->render('index_create', $view_params);
		    return;
		}
		$indexDef['partialFilterExpression'] = $_partial_filter;
	    }
	
	    if (!empty($unique))
		$indexDef['unique'] = 1;

	    if (!empty($sparse))
		$indexDef['sparse'] = 1;

	    //name
	    if (!empty($name))
		$indexDef['name'] = $name;
	    if (empty($indexDef['name']))
		$indexDef['name'] = implode('_', array_keys($attrs));

	    try {
		$command = new \MongoDB\Driver\Command([
        	    'createIndexes' => $collection,
        	    'indexes' => [ (object)$indexDef ],
		]);
		$cursor = R::app()->server->manager->executeCommand($db, $command);
		$ret = current($cursor->toArray());
	    } catch (ErrorException $e) {
		$view_params['error'] = $e->getMessage();
		R::app()->render('index_create', $view_params);
		return;
	    }

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'Index created',
	    ]);

	    R::app()->response->redirect(R::app()->url('app.index_list', [ 'db' => $db, 'collection' => $collection ]));
	}
	R::app()->render('index_create', $view_params);
    }

    //---------------------------------------------------------------
    /** create/edit document **/
    public function do_document_edit() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$id = R::app()->request->getQueryParam('id');
	$id = ObjectId($id);

	$error = null;

	$record = [];
	if (!empty($id)) {
	    //$record = $coll->findOne([ '_id' => $id ]);

	    $query = new \MongoDB\Driver\Query([ '_id' => $id ], [ 'limit' => 1 ]);
	    $cursor = R::app()->server->manager->executeQuery("$db.$collection", $query);
	    $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $record = current($cursor->toArray());

	    unset($record['_id']);
	}

	$document = Utils::exportPHP($record);

	if (R::app()->request->isPost()) {
	    $document = R::app()->request->getDataParam('document');

	    try {
		$record = Utils::varEvalPHP($document);
	    } catch(Exception $e) {
		$error = $e->getMessage();
	    }

	    if (!empty($error)) {
		R::app()->render('document_edit', compact('db', 'collection', 'document', 'error'));
		return;
	    }

	    if (empty($record) || !is_array($record)) {
		$error = "Data must be a valid PHP Array.";
		R::app()->render('document_edit', compact('db', 'collection', 'document', 'error'));
		return;
	    }

	    $bulk = new \MongoDB\Driver\BulkWrite();

	    if (empty($id)) {
		$bulk->insert($record);
	    } else {
		$bulk->update([ '_id' => $id ], $record, [ 'multi' => false, 'upsert' => false ]);
	    }

	    $result = R::app()->server->manager->executeBulkWrite("$db.$collection", $bulk);

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => empty($id) ? 'Record inserted' : 'Record updated',
	    ]);

	    $url_params = [
		'db' => $db,
		'collection' => $collection,
		'criteria' => R::app()->request->getQueryParam('criteria', ''),
	    ];
	    R::app()->response->redirect(R::app()->url('app.collection_browse', $url_params));
	}

	R::app()->render('document_edit', compact('db', 'collection', 'document', 'error', 'id'));
    }

    /** delete document **/
    public function do_document_delete() {
	if (!R::app()->request->isPost())
	    return;
	
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$id = R::app()->request->getQueryParam('id');
	$id = ObjectId($id);

	if (empty($id))
	    return;

	try {
	    $bulk = new \MongoDB\Driver\BulkWrite();
	    $bulk->delete([ '_id' => $id ], [ 'limit' => true ]);
	    $result = R::app()->server->manager->executeBulkWrite("$db.$collection", $bulk);

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'Record deleted',
	    ]);
	} catch(Exception $e) {
	    R::app()->user->addMessage([
		'type' => 'error',
		'body' => $e->getMessage(),
	    ]);
	}

	$url_params = [
	    'db' => $db,
	    'collection' => $collection,
	    'criteria' => R::app()->request->getQueryParam('criteria', ''),
	];

	R::app()->response->redirect(R::app()->url('app.collection_browse', $url_params));
    }

    // * load single document
    public function do_document_get() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');

	$id = R::app()->request->getQueryParam('id');
	$id = ObjectId($id);

	if (empty($id)) {
	    echo "invalid id";
	    return;
	}

	$query = new \MongoDB\Driver\Query([ '_id' => $id ], [ 'limit' => 1 ]);
	$cursor = R::app()->server->manager->executeQuery("$db.$collection", $query);
	$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	$row = current($cursor->toArray());

	if (empty($row)) {
	    echo "record was removed";
	    return;
	}
	
	echo Utils::highlight($row);
    }

    // Upload file
    public function do_file_upload() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');
	$chunkSize = 131072; //128K

	if (R::app()->request->isPost()) {
	    $file = R::app()->request->getFile('file');
	    if (empty($file)) {
		$error = 'No file uploaded';
		R::app()->render('file_upload', compact('db', 'collection', 'error'));
		return;
	    }
	    if ($file['error'] > 0) {
		$errors = [
		    0 => 'There is no error, the file uploaded with success',
		    1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
		    2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
		    3 => 'The uploaded file was only partially uploaded',
		    4 => 'No file was uploaded',
		    6 => 'Missing a temporary folder',
		    7 => 'Failed to write file to disk.',
		    8 => 'A PHP extension stopped the file upload.',
		];

		$error = 'File upload error: ' . $errors[$file['error']];
		R::app()->render('file_upload', compact('db', 'collection', 'error'));
		return;
	    }
	    if (!is_uploaded_file($file['tmp_name'])) {
        	$error = 'Not an uploaded file';
		R::app()->render('file_upload', compact('db', 'collection', 'error'));
		return;
    	    }

	    $fileId = new \MongoDB\BSON\ObjectId();
	    $hashCtx = hash_init('md5');
	    $n = 0;
	    $chunks_collection = str_replace('.files', '.chunks', $collection);

	    //upload
	    $fp = fopen($file['tmp_name'], 'rb');
	    while (!feof($fp)) {
		$buffer = fread($fp, $chunkSize);

		$bulk = new \MongoDB\Driver\BulkWrite();
		$bulk->insert([
		    'files_id' => $fileId,
		    'n' => $n,
		    'data' => new \MongoDB\BSON\Binary($buffer, \MongoDB\BSON\Binary::TYPE_GENERIC),
		]);
		$result = R::app()->server->manager->executeBulkWrite("$db.$chunks_collection", $bulk);
		$n++;
		hash_update($hashCtx, $buffer);
	    }

	    //create an entry in $collection
	    $bulk = new \MongoDB\Driver\BulkWrite();
	    $bulk->insert([
		'_id' => $fileId,
		'filename' => $file['name'],
		'chunkSize' => $chunkSize,
		'length' => $file['size'],
		'uploadDate' => new \MongoDB\BSON\UTCDateTime(),
		'md5' => hash_final($hashCtx),
	    ]);
	    $result = R::app()->server->manager->executeBulkWrite("$db.$collection", $bulk);
	    fclose($fp);

	    R::app()->user->addMessage([
		'type' => 'success',
		'body' => 'File uploaded',
	    ]);
	    R::app()->response->redirect(R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection ]));
	}

	R::app()->render('file_upload', compact('db', 'collection'));
    }

    /** download file in GridFS **/
    public function do_file_download() {
	$db = R::app()->request->getQueryParam('db');
	$collection = R::app()->request->getQueryParam('collection');
	$id = R::app()->request->getQueryParam('id');
	if (empty($id))
	    return;
	$id = ObjectId($id);
	if (empty($id))
	    return;

	$query = new \MongoDB\Driver\Query([ '_id' => $id ], [ 'limit' => 1 ]);
	$cursor = R::app()->server->manager->executeQuery("$db.$collection", $query);
	$file = current($cursor->toArray());

	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename=" . $file->filename);

	//find all chunks from bucket, then echo
	$chunks_collection = str_replace('.files', '.chunks', $collection);
	$query = new \MongoDB\Driver\Query([ 'files_id' => $id ], [ 'sort' => [ 'n' => 1 ] ]);
	$cursor = R::app()->server->manager->executeQuery("$db.$chunks_collection", $query);

	ob_end_clean();

	foreach($cursor as $chunk)
	    echo $chunk->data->getData();

	R::app()->end();
    }

}