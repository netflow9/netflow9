<?php

/*
    CLD: 2026-10-22T22:00:00+03:00: new: a session of a tool server held by a running AI step: at most `max_sessions` at once on the installation (documents `<server>:<n>`) and
    `max_sessions_per_account` per account (`<server>:<aid>:<n>`). As models\ExecutionSlot: taking one is an atomic upsert on the _id (a free one is missing or expired), leased until
    `until`, renewed while the session is used (SessionClient), freed when it is closed or, when the process died, after LEASE. Global: the installation's slots are shared by the accounts
*/

namespace plugins\LocalMCP\models;

class LocalMcpSlot extends \sys\BaseModel {
    protected static $collection = 'localmcp_slots';

    //seconds a slot is held without being renewed
    const LEASE = 900;

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [ 'key' => 'server', 'type' => 'text', 'label' => 'Server' ],
	    [ 'key' => 'account', 'type' => 'text', 'label' => 'Account' ],
	    [ 'key' => 'pid', 'type' => 'number', 'label' => 'Process' ],
	    [ 'key' => 'since', 'type' => 'datetime', 'label' => 'Since' ],
	    [ 'key' => 'until', 'type' => 'datetime', 'label' => 'Until' ],
	];
    }

    //takes one of the $max slots `<$prefix>:<n>` (server $server, account $aid): its id, or null when all are taken
    public static function take($prefix, $max, $server, $aid) {
	$now = time();
	for($n = 0; $n < $max; $n++) {
	    $id = "{$prefix}:{$n}";
	    try {
		//a free slot is a missing or expired document; a held one fails the upsert (duplicate _id)
		\V::app()->om->updateOne('LocalMcpSlot', [ '_id' => $id, 'until' => [ '$lt' => new \MongoDB\BSON\UTCDateTime($now * 1000) ] ],
		    [ '$set' => [ 'server' => (string)$server, 'account' => (string)$aid, 'pid' => getmypid(), 'since' => new \MongoDB\BSON\UTCDateTime($now * 1000),
			'until' => new \MongoDB\BSON\UTCDateTime(($now + self::LEASE) * 1000) ] ], [ 'upsert' => true ]);
	    } catch (\MongoDB\Driver\Exception\BulkWriteException | \MongoDB\Driver\Exception\CommandException $e) {
		if ($e->getCode() == 11000)
		    continue;
		throw $e;
	    }
	    return $id;
	}
	return null;
    }

    //extends the lease of slots $ids
    public static function renew(array $ids) {
	foreach($ids as $id)
	    \V::app()->om->updateOne('LocalMcpSlot', [ '_id' => $id, 'pid' => getmypid() ], [ '$set' => [ 'until' => new \MongoDB\BSON\UTCDateTime((time() + self::LEASE) * 1000) ] ]);
    }

    //frees slots $ids
    public static function free(array $ids) {
	foreach($ids as $id)
	    \V::app()->om->deleteOne('LocalMcpSlot', [ '_id' => $id, 'pid' => getmypid() ]);
    }

    //the sessions open now: [ server => [ account => n ] ]
    public static function open() {
	$open = [];
	foreach(\V::app()->om->find('LocalMcpSlot', [ 'until' => [ '$gte' => new \MongoDB\BSON\UTCDateTime(time() * 1000) ], 'account' => [ '$ne' => '' ] ]) as $s) {
	    if (substr_count((string)$s->_id, ':') === 2)
		$open[(string)$s->server][(string)$s->account] = ($open[(string)$s->server][(string)$s->account] ?? 0) + 1;
	}
	return $open;
    }
}
