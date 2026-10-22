<?php

/*
    a sync execution slot

    sync executions (run inside a web request: designer Run, Webhook, Form, Human task, MCP run_workflow):
	at most app.executions.max_sync (app.env MAX_SYNC_EXECUTIONS) at the same time per account.
    Each running one holds a slot, a document `<aid>:<n>` (n < max) leased until `until`
    (renewed while it runs, so the slot of a process that died frees itself).
    Console processes (daemon workers, wf::run) are not limited (the daemon has its own --max-per-account);
    a run started inside a sync run (an AI step calling run_workflow) uses the slot already held.

    Taking a slot is an atomic upsert on the _id (a free slot is a missing or expired document), so the documents are written with the collection,
    not with save()
*/

namespace models;

class ExecutionSlot extends \sys\BaseModel {
    protected static $collection = 'execution_slots';

    //CLD: 2026-10-18T16:20:00+03:00: the slots of an account
    public static $indexes = [
	[ [ 'aid' => 1 ], [] ],
    ];

    //seconds a slot is held without being renewed; renewed every RENEW seconds while running
    const LEASE = 900;
    const RENEW = 60;

    //the slot this process holds: [ id, aid, refs, renewed ] | null
    private static $held = null;

    public function getSchema() {
	return [
	    //_id: `<aid>:<n>`
	    [
		'key' => 'pid',
		'type' => 'number',
		'label' => 'Process',
		'help' => false,
	    ],
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'Host',
		'help' => false,
	    ],
	    //taken at
	    [
		'key' => 'since',
		'type' => 'datetime',
		'label' => 'Since',
	    ],
	    //held until (free after it, when not renewed)
	    [
		'key' => 'until',
		'type' => 'datetime',
		'label' => 'Until',
	    ],
	];
    }

    //take a slot of account $aid (or use the one already held); false when all are taken. Release it with release()
    public static function acquire($aid) {
	$max = (int)\V::app()->config('app.executions.max_sync');
	if ($max <= 0 || PHP_SAPI === 'cli')
	    return true;
	if (self::$held !== null) {
	    self::$held['refs']++;
	    return true;
	}

	$now = time();
	for ($n = 0; $n < $max; $n++) {
	    $id = (string)$aid . ':' . $n;
	    try {
		//a free slot is a missing or expired document; a held one fails the upsert (duplicate _id)
		\V::app()->om->updateOne('ExecutionSlot', [ '_id' => $id, 'until' => [ '$lt' => new \MongoDB\BSON\UTCDateTime($now * 1000) ] ],
		    [ '$set' => [ 'aid' => \V::app()->om->to_oid($aid), 'pid' => getmypid(), 'host' => gethostname(), 'since' => new \MongoDB\BSON\UTCDateTime($now * 1000),
			'until' => new \MongoDB\BSON\UTCDateTime(($now + self::LEASE) * 1000) ] ],
		    [ 'upsert' => true ]);
	    } catch (\MongoDB\Driver\Exception\BulkWriteException | \MongoDB\Driver\Exception\CommandException $e) {
		if ($e->getCode() == 11000)
		    continue;
		throw $e;
	    }
	    self::$held = [ 'id' => $id, 'aid' => (string)$aid, 'refs' => 1, 'renewed' => $now ];
	    //freed also when the request ends without release() (exit, an error)
	    register_shutdown_function(function() use ($id) {
		if ((self::$held['id'] ?? null) !== $id)
		    return;
		self::$held['refs'] = 1;
		self::release();
	    });
	    return true;
	}
	return false;
    }

    public static function release() {
	if (self::$held === null || --self::$held['refs'] > 0)
	    return;
	$id = self::$held['id'];
	self::$held = null;
	\V::app()->om->deleteOne('ExecutionSlot', [ '_id' => $id, 'pid' => getmypid() ]);
    }

    //extend the lease of the slot held (called while running)
    public static function renew() {
	if (self::$held === null || time() - self::$held['renewed'] < self::RENEW)
	    return;
	self::$held['renewed'] = time();
	\V::app()->om->updateOne('ExecutionSlot', [ '_id' => self::$held['id'] ],
	    [ '$set' => [ 'until' => new \MongoDB\BSON\UTCDateTime((time() + self::LEASE) * 1000) ] ]);
    }
}
