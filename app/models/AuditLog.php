<?php

//the audit log. One entry per user action: create / update / delete of any object
// (BaseModel::save / delete fire
//the model:after-save / model:after-delete hooks, registered by \sys\Boot) and named actions (login, login.failed, logout, workflow.run,
//credential.test: AuditLog::record). Only actions of a logged-in user are recorded. Objects registered with 'auditLog' => false are not recorded
//(internal: jobs, executions, indexes, the logs). Updates keep each changed field's value before and after; secret fields (password type, encrypted)
//and keys outside the schema only show "changed"; long values are shortened. Entries are never changed. Fields with perm:read nobody (ids, browser,
//changes) are not in the grid; the entry dialog shows them. Read with AuditLog::read (global: the account, project: the entries of the current project)
//CLD: 2026-10-11T17:45:25+03:00: shares models\log\LogTrait with models\ExecutionLog (what triggers, workflow runs, the daemon and the console do: changes
//made while an execution runs go there, also when a user clicked Run); entries expire after the account's `audit_log_retention`

namespace models;

class AuditLog extends \sys\BaseModel {
    use \models\log\LogTrait;

    protected static $collection = 'audit_log';

    //CLD: 2026-10-12T12:18:11+03:00: removal after the retention (TTL); the account's / a project's entries, newest first (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'aid' => 1, 'created_at' => -1 ], [] ],
	[ [ 'aid' => 1, 'project' => 1, 'created_at' => -1 ], [] ],
	//CLD: 2026-10-22T00:00:00+03:00: the seal's numbers (verify)
	[ [ 'aid' => 1, 'seq' => 1 ], [] ],
    ];

    //CLD: 2026-10-22T00:00:00+03:00: the seal (tamper evidence, SOC2.md gap 6): each entry of an account gets the account's next number (`seq`) and an HMAC of
    //its stored document (`mac`, key derived from APP_SECRET). The numbers are kept in collection audit_seal: { _id: account id, seq: last number given,
    //pruned: highest number removed by the retention }. verify() finds changed or forged entries (MAC), removed ones (missing numbers, also the newest) and
    //entries without a MAC. Entries from before the seal have no number and are only counted
    const SEALED = true;
    const SEAL_COLLECTION = 'audit_seal';

    //the shortest retention (days; the account's `audit_log_retention` below it is raised to it; 0 = forever)
    const MIN_RETENTION_DAYS = 365;


    const OBJECT = 'AuditLog';
    const ACTIONS = [
	'login' => 'Login',
	'login.failed' => 'Failed login',
	//CLD: 2026-10-21T18:00:00+03:00: too many failed sign-ins or 2FA codes: locked for a while (models\user\LoginThrottle)
	'login.locked' => 'Sign-in locked',
	'logout' => 'Logout',
	'workflow.run' => 'Run workflow',
	'credential.test' => 'Test credential',
	//CLD: 2026-10-11T20:51:55+03:00: a data table created from a file (DatatableController::import)
	'datatable.import' => 'Import data table',
	//CLD: 2026-10-24T00:04:00+03:00: executions of the draft deleted from the Executions panel (WorkflowController::execution_delete, executions_delete; executions themselves are not recorded)
	'workflow.execution.delete' => 'Delete execution',
	'workflow.executions.delete' => 'Delete executions',
	//CLD: 2026-10-26T10:06:24+02:00: a running execution killed (WorkflowController::execution_kill, the Overview)
	'workflow.execution.kill' => 'Kill execution',
    ];

    public function getSchema() {
	return $this->logFields([
	    [
		'key' => 'user',
		'type' => 'object',
		'object' => 'User',
		'label' => 'User id',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    //the user's name when the action happened (kept after the user is deleted)
	    [
		'key' => 'user_name',
		'type' => 'text',
		'label' => 'User',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'ip',
		'type' => 'text',
		'label' => 'IP',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'user_agent',
		'type' => 'text',
		'label' => 'Browser',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    //CLD: 2026-10-22T00:00:00+03:00: the seal's number (the MAC, `mac`, is written after the entry: not a field)
	    [
		'key' => 'seq',
		'type' => 'number',
		'label' => 'Number',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	]);
    }

    //hook model:after-save: $before = [ key => value before ] of the changed keys (update) or null (create)
    public static function afterModelSave($o, $before) {
	if (!static::isRecorded($o))
	    return;

	$changes = static::savedChanges($o, $before);
	//only bookkeeping changed (e.g. updated_at): nothing to record
	if ($before !== null && empty($changes))
	    return;

	static::record($before === null ? 'create' : 'update', $o, null, $changes);
    }

    //hook model:after-delete: $data = the deleted document
    public static function afterModelDelete($o, $data) {
	if (static::isRecorded($o))
	    static::record('delete', $o, null, static::deletedChanges($o, $data), $data);
    }

    //records $action of the logged-in user (none: not recorded) on $o (may be null); $data: the object's values when $o no longer has them (deleted);
    //$user: the user acting when not the logged-in one (failed login)
    public static function record($action, $o = null, $details = null, $changes = [], $data = null, $user = null) {
	$web = \V::app()->user;
	if (\V::app()->config('app.mode') != 'web')
	    return;
	if ($user === null && $web->isGuest)
	    return;

	static::write($action, $o, $data, [
	    'aid' => $user ? $user->aid : ($web->aid ?? null),
	    'user' => $user ? $user->_id : $web->_id,
	    'user_name' => $user ? (string)$user : (string)$web->name,
	    'ip' => \V::app()->request->userIP,
	    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
	    'changes' => $changes,
	    'details' => $details,
	]);
    }

    //recorded: a logged-in user's change outside a workflow run (those go to the execution log), of an object the logs record
    public static function isRecorded($o) {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || \models\ExecutionLog::$execution !== null)
	    return false;
	return static::isLoggable($o);
    }

    // ------------------------------------------------------------------------------------
    //CLD: 2026-10-22T00:00:00+03:00: the seal (see SEALED), the retention, verify

    protected static function coll($name = null) {
	return \V::app()->mongo->selectCollection(static::_database(), $name ?? static::_collection());
    }

    //the account's next number
    protected static function nextSeq($aid) {
	$doc = static::coll(self::SEAL_COLLECTION)->findOneAndUpdate([ '_id' => $aid ], [ '$inc' => [ 'seq' => 1 ] ],
	    [ 'upsert' => true, 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ]);
	return (int)$doc['seq'];
    }

    //writes the MAC of entry $id as stored
    protected static function seal($id) {
	$doc = static::coll()->findOne([ '_id' => $id ], [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ]);
	if ($doc)
	    static::coll()->updateOne([ '_id' => $id ], [ '$set' => [ 'mac' => static::macOf($doc) ] ]);
    }

    //the MAC of stored document $doc (all of it but `mac`)
    public static function macOf(array $doc) {
	unset($doc['mac']);
	$text = json_encode(static::canonical($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
	return hash_hmac('sha256', $text, \V::app()->security->deriveKey('audit-log-seal'));
    }

    //BSON values in a fixed text form
    protected static function canonical($value) {
	if ($value instanceof \MongoDB\BSON\ObjectId)
	    return [ '$oid' => (string)$value ];
	if ($value instanceof \MongoDB\BSON\UTCDateTime)
	    return [ '$date' => (string)$value ];
	if (is_object($value) && method_exists($value, 'getArrayCopy'))
	    $value = $value->getArrayCopy();
	if (is_array($value))
	    return array_map([ static::class, 'canonical' ], $value);
	if (is_object($value))
	    return [ '$' . get_class($value) => (string)json_encode($value) ];
	return $value;
    }

    //the retention of account $acct, in days (0 = forever): at least MIN_RETENTION_DAYS
    public static function retentionDays($acct) {
	$days = (int)($acct->audit_log_retention ?? 0);
	return $days > 0 ? max($days, self::MIN_RETENTION_DAYS) : 0;
    }

    //removes the entries of account $acct older than its retention (the daemon, every 2 hours); the seal keeps the highest number removed, so verify
    //knows which ones are missing by the retention. Logs the seal's state (an outside record of the numbers: a removal of the newest entries shows there)
    public static function prune($acct) {
	$seal = static::coll(self::SEAL_COLLECTION);
	$days = static::retentionDays($acct);
	if ($days > 0) {
	    $limit = [ 'aid' => $acct->_id, 'created_at' => [ '$lte' => new \MongoDB\BSON\UTCDateTime((time() - $days * 60*60*24) * 1000) ] ];
	    $last = static::coll()->findOne($limit + [ 'seq' => [ '$exists' => true ] ], [ 'sort' => [ 'seq' => -1 ], 'projection' => [ 'seq' => 1 ] ]);
	    if ($last)
		$seal->updateOne([ '_id' => $acct->_id ], [ '$max' => [ 'pruned' => (int)$last['seq'] ] ]);
	    static::coll()->deleteMany($limit);
	}
	$state = $seal->findOne([ '_id' => $acct->_id ]);
	if ($state)
	    \V::app()->log->info('AuditLog seal: account ' . $acct->_id . ': last number ' . (int)($state['seq'] ?? 0) . ', removed by the retention up to ' . (int)($state['pruned'] ?? 0));
    }

    //checks the entries of account $aid: [ entries, unsealed (from before the seal), last (number given), pruned, problems: [ text ] ]
    public static function verify($aid) {
	$state = static::coll(self::SEAL_COLLECTION)->findOne([ '_id' => $aid ]);
	$last = (int)($state['seq'] ?? 0);
	$pruned = (int)($state['pruned'] ?? 0);
	$report = [ 'entries' => 0, 'unsealed' => 0, 'last' => $last, 'pruned' => $pruned, 'problems' => [] ];
	$missing = function($from, $to) use (&$report) {
	    $report['problems'][] = $from == $to ? "entry {$from} is missing" : "entries {$from} to {$to} are missing";
	};

	$report['unsealed'] = static::coll()->countDocuments([ 'aid' => $aid, 'seq' => [ '$exists' => false ] ]);
	$expected = $pruned + 1;
	$prev = null;
	$cursor = static::coll()->find([ 'aid' => $aid, 'seq' => [ '$exists' => true ] ],
	    [ 'sort' => [ 'seq' => 1, '_id' => 1 ], 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ]);
	foreach($cursor as $doc) {
	    $report['entries']++;
	    $seq = (int)$doc['seq'];
	    if (empty($doc['mac']))
		$report['problems'][] = "entry {$seq} ({$doc['_id']}) has no MAC";
	    elseif (!hash_equals(static::macOf($doc), (string)$doc['mac']))
		$report['problems'][] = "entry {$seq} ({$doc['_id']}) was changed or not written by the app (MAC)";
	    if ($seq === $prev)
		$report['problems'][] = "entry {$seq} ({$doc['_id']}) is there twice";
	    elseif ($seq > $last)
		$report['problems'][] = "entry {$seq} ({$doc['_id']}) has a number never given";
	    elseif ($seq > $expected)
		$missing($expected, $seq - 1);
	    $prev = $seq;
	    $expected = max($expected, $seq + 1);
	}
	if ($last >= $expected)
	    $missing($expected, $last);
	return $report;
    }
}
