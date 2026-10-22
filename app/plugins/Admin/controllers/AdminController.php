<?php

/*
    CLD: 2026-10-22T23:00:00+03:00: new: the administration pages, at /Admin/admin/<action> (super-admins with 2FA: Plugin::require; across the accounts on purpose):
	index         the installation: its services (status, start / stop / restart), the app, the plugins' sections
	service       POST: start / stop / restart a service
	accounts      the accounts (search by name, hostname or a user's email)
	account       one account: its users and projects, suspend / reactivate, view as, the plugins' sections
	account_state POST: suspend / reactivate
	view_as       POST: sign in as the account's owner, read-only (Plugin::startView)
	exit_view     POST: back (allowed while viewing as)
*/

namespace plugins\Admin\controllers;

use plugins\Admin\Plugin;
use plugins\Admin\util\Services;

class AdminController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	//going back from "view as" is the one action of someone viewing as (Plugin::require refuses the others)
	if (\V::app()->config('rt.action') !== 'exit_view')
	    Plugin::require();
    }

    // ------------------------------------------------------------------------------------
    //the installation

    public function index($req, $resp) {
	$services = [];
	foreach(Plugin::services() as $id => $s)
	    $services[$id] = $s + [ 'status' => Services::status($s) ];
	$app = $this->appInfo();
	$this->render('index', compact('services', 'app'));
    }

    public function service($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$id = (string)($req->data['id'] ?? '');
	$verb = (string)($req->data['verb'] ?? '');
	$s = Plugin::services()[$id] ?? null;
	if (!$s)
	    \V::app()->error(404, 'Not found');
	[ $ok, $message ] = Services::control($s, $verb);
	\V::app()->log->info("Admin: {$verb} {$id} by " . \V::app()->user->name . ': ' . ($ok ? 'ok' : $message));
	\models\AuditLog::record('service_' . $verb, null, "super-admin: {$verb} {$s['label']} (" . ($ok ? 'done' : 'failed: ' . mb_substr($message, 0, 200)) . ')');
	$this->flash($ok ? 'success' : 'danger', $message);
	$resp->redirect('/Admin/admin/index');
    }

    //the app's version and its server
    private function appInfo() {
	$git = @file_get_contents(BASE . '/.git/HEAD');
	$commit = null;
	if ($git && preg_match('~^ref: (\S+)~', $git, $m))
	    $commit = trim((string)@file_get_contents(BASE . '/.git/' . $m[1])) ?: null;
	elseif ($git)
	    $commit = trim($git);
	$mongo = null;
	try {
	    $info = \V::app()->mongo->selectDatabase('admin')->command([ 'buildInfo' => 1 ])->toArray()[0] ?? null;
	    $mongo = $info['version'] ?? null;
	} catch (\Throwable $e) {
	}
	$om = \V::app()->om;
	$count = fn($name, $q = []) => $om->acrossAccounts(fn() => $om->count($name, $q));
	return [
	    'commit' => $commit ? substr($commit, 0, 10) : null,
	    'php' => PHP_VERSION,
	    'mongo' => $mongo,
	    'host' => gethostname(),
	    'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
	    'disk_free' => @disk_free_space(BASE),
	    'disk_total' => @disk_total_space(BASE),
	    'accounts' => $count('Account'),
	    'suspended' => $count('Account', [ 'state' => 'disabled' ]),
	    'users' => $count('User'),
	    'workflows' => $count('Workflow'),
	    'jobs_waiting' => $count('Job', [ 'state' => 'new' ]),
	    'runs_24h' => $count('WfExecution', [ '_id' => [ '$gte' => self::oidAt(time() - 86400) ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //the accounts

    public function accounts($req, $resp) {
	$om = \V::app()->om;
	$q = trim((string)($req->query['q'] ?? ''));
	$query = [];
	if ($q !== '') {
	    $rx = new \MongoDB\BSON\Regex(preg_quote($q), 'i');
	    $byUser = $om->acrossAccounts(fn() => array_map(fn($u) => $u->aid, iterator_to_array($om->find('User', [ '$or' => [ [ 'email' => $rx ], [ 'username' => $rx ] ] ], [ 'projection' => [ 'aid' => 1 ], 'limit' => 200 ]), false)));
	    $query = [ '$or' => [ [ 'name' => $rx ], [ 'hostname' => $rx ], [ '_id' => [ '$in' => array_values(array_filter($byUser)) ] ] ] ];
	}
	$accounts = $om->acrossAccounts(fn() => iterator_to_array($om->find('Account', $query, [ 'sort' => [ '_id' => -1 ], 'limit' => 500 ]), false));
	$counts = [ 'users' => $this->countByAccount('User'), 'projects' => $this->countByAccount('Project'), 'workflows' => $this->countByAccount('Workflow'),
	    'runs' => $this->countByAccount('WfExecution', [ '_id' => [ '$gte' => self::oidAt(time() - 30 * 86400) ] ]) ];
	$this->render('accounts', compact('accounts', 'counts', 'q'));
    }

    public function account($req, $resp) {
	$account = $this->findAccount($req->query['id'] ?? null);
	$om = \V::app()->om;
	$aid = $account->_id;
	[ $users, $projects, $roles ] = $om->acrossAccounts(fn() => [
	    iterator_to_array($om->find('User', [ 'aid' => $aid ], [ 'sort' => [ '_id' => 1 ] ]), false),
	    iterator_to_array($om->find('Project', [ 'aid' => $aid ], [ 'sort' => [ '_id' => 1 ] ]), false),
	    iterator_to_array($om->find('Role', [ 'aid' => $aid ]), false),
	]);
	$roleNames = [];
	foreach($roles as $r)
	    $roleNames[(string)$r->_id] = (string)$r->name;
	$workflows = $this->countByAccount('Workflow', [ 'aid' => $aid ], 'project');
	$owner = $this->ownerOf($account);
	$this->render('account', compact('account', 'users', 'projects', 'roleNames', 'workflows', 'owner'));
    }

    public function account_state($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$account = $this->findAccount($req->data['id'] ?? null);
	$state = (string)($req->data['state'] ?? '');
	if (!in_array($state, [ 'active', 'disabled' ], true))
	    \V::app()->error(400, 'Unknown state');
	$reason = trim(mb_substr((string)($req->data['reason'] ?? ''), 0, 300));
	if ($state === 'disabled' && $reason === '') {
	    $this->flash('danger', 'Say why the account is suspended (the reason is kept in the audit log)');
	    $resp->redirect('/Admin/admin/account?id=' . $account->_id);
	}
	if ((string)$account->state !== $state) {
	    //written with the collection: the account's own checks (its form's permissions) are not the super-admin's
	    \V::app()->om->acrossAccounts(fn() => \V::app()->om->updateOne('Account', [ '_id' => $account->_id ], [ '$set' => [ 'state' => $state, 'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]));
	    Plugin::audit($state === 'disabled' ? 'account_suspend' : 'account_reactivate', $account, ($state === 'disabled' ? 'suspended' : 'reactivated') . ($reason !== '' ? ": {$reason}" : ''));
	}
	$this->flash('success', $state === 'disabled' ? 'Suspended: its users are signed out, nothing new runs, its queued jobs wait' : 'Reactivated');
	$resp->redirect('/Admin/admin/account?id=' . $account->_id);
    }

    // ------------------------------------------------------------------------------------
    //view as

    public function view_as($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$account = $this->findAccount($req->data['id'] ?? null);
	if ((string)$account->_id === (string)\V::app()->user->aid) {
	    $this->flash('danger', 'This is your own account');
	    $resp->redirect('/Admin/admin/account?id=' . $account->_id);
	}
	$owner = $this->ownerOf($account);
	if (!$owner) {
	    $this->flash('danger', 'The account has no active owner to view it as');
	    $resp->redirect('/Admin/admin/account?id=' . $account->_id);
	}
	try {
	    Plugin::startView($account, $owner, '/Admin/admin/account?id=' . $account->_id);
	} catch (\ErrorException $e) {
	    $this->flash('danger', $e->getMessage());
	    $resp->redirect('/Admin/admin/account?id=' . $account->_id);
	}
	$resp->redirect('/site/index');
    }

    public function exit_view($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$v = Plugin::viewing();
	if ($v === null)
	    $resp->redirect('/Admin/admin/index');
	Plugin::endView();
	$resp->redirect($v['return'] ?? '/Admin/admin/index');
    }

    // ------------------------------------------------------------------------------------

    //account $id (any account), else 404
    private function findAccount($id) {
	$account = preg_match('/^[a-f0-9]{24}$/', (string)$id) ? \V::app()->om->acrossAccounts(fn() => \V::app()->om->findById('Account', (string)$id)) : null;
	if (!$account)
	    \V::app()->error(404, 'Not found');
	return $account;
    }

    //the account's first active user with the owner role
    private function ownerOf($account) {
	$om = \V::app()->om;
	return $om->acrossAccounts(function() use ($om, $account) {
	    $role = $om->findOne('Role', [ 'aid' => $account->_id, 'iname' => 'owner' ]);
	    return $role ? $om->findOne('User', [ 'aid' => $account->_id, 'role' => $role->_id, 'state' => 'active' ], [ 'sort' => [ '_id' => 1 ] ]) : null;
	});
    }

    //objects of $objectName per account (or per $by), matching $match: [ id => n ]
    private function countByAccount($objectName, array $match = [], $by = 'aid') {
	$class = \V::app()->om->getObjectClass($objectName);
	$coll = \V::app()->mongo->selectCollection($class::_database(), $class::_collection());
	$n = [];
	foreach($coll->aggregate([ [ '$match' => (object)$match ], [ '$group' => [ '_id' => '$' . $by, 'n' => [ '$sum' => 1 ] ] ] ]) as $row)
	    $n[(string)$row['_id']] = (int)$row['n'];
	return $n;
    }

    //the smallest ObjectId of unix time $ts (objects created since then: _id >= it)
    private static function oidAt($ts) {
	return new \MongoDB\BSON\ObjectId(sprintf('%08x', (int)$ts) . str_repeat('0', 16));
    }

    //a message for the next page
    private function flash($type, $message) {
	$_SESSION['admin_flash'] = [ 'type' => $type, 'message' => (string)$message ];
    }
}
