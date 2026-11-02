<?php

/*
    CLD: 2026-11-02T14:00:00+02:00: new: the trace of an AI step's visit, what the designer's Executions panel shows: one document per visit (a step in a Loop: one per pass; written by util\AgentTracer), its id
    in the step's variable (trace_id). Apart from the execution: an execution is loaded and saved whole, often, and a trace can be megabytes. Rounds, each: n, model, text, text_length (only when
    cut: the full length), stop_reason, tokens { input, output }, cost { usd, credits }, duration_ms, calls: [ { tool, access (read | write | unknown), arguments (JSON text), arguments_length, result,
    result_length, error, duration_ms, images, refused (the limit), approval { state (asked | approved | rejected | expired | not asked), by, at, comment } } ].
    It goes with its execution (WfExecution::beforeDelete) and, like the execution log, after the account's `execution_log_retention` (prune(), the daemon): an execution outlives that setting.
    Written directly to the collection, not through save(): never in the audit or execution logs. Read as arrays
*/

namespace models\agent;

class AgentTrace extends \sys\BaseModel {
    protected static $collection = 'agent_traces';
    public static $indexes = [
	[ [ 'execution' => 1, '_id' => 1 ], [] ],
	[ [ 'aid' => 1, 'created_at' => 1 ], [] ],
    ];

    //the visits an execution's details list (a Loop may make thousands)
    const LIST_LIMIT = 50;
    const OPEN = [ 'running', 'waiting' ];

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'execution', 'type' => 'object', 'object' => 'WfExecution', 'label' => 'Execution', 'help' => false ],
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'help' => false ],
	    [ 'key' => 'project', 'type' => 'object', 'object' => 'Project', 'label' => 'Project', 'help' => false ],
	    [ 'key' => 'node', 'type' => 'text', 'label' => 'Step', 'help' => false ],
	    [ 'key' => 'state', 'type' => 'text', 'label' => 'State', 'help' => false ],
	    [ 'key' => 'rounds', 'type' => 'array', 'label' => 'Rounds', 'help' => false ],
	    [ 'key' => 'totals', 'type' => 'array', 'label' => 'Totals', 'help' => false ],
	], $this->getSystemSchema());
    }

    public static function coll() {
	return \V::app()->mongo->selectCollection(static::_database(), static::_collection());
    }

    //the visits of execution $exe, oldest first, without their rounds: [ the first LIST_LIMIT, how many there are ]
    public static function ofExecution($exe) {
	$query = [ 'execution' => $exe->_id, 'aid' => $exe->aid ];
	$list = iterator_to_array(static::coll()->find($query, \sys\Mongo::ARRAYS + [ 'projection' => [ 'rounds' => 0 ], 'sort' => [ '_id' => 1 ], 'limit' => self::LIST_LIMIT ]), false);
	return [ $list, count($list) < self::LIST_LIMIT ? count($list) : static::coll()->countDocuments($query) ];
    }

    //the trace $id, with its rounds; null: not of this account (and of execution $exe, when given)
    public static function read($id, $aid, $exe = null) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	return static::coll()->findOne([ '_id' => new \MongoDB\BSON\ObjectId((string)$id), 'aid' => $aid ] + ($exe ? [ 'execution' => $exe->_id ] : []), \sys\Mongo::ARRAYS);
    }

    //the execution was killed: its open traces end
    public static function stopOpen($exe) {
	static::coll()->updateMany([ 'execution' => $exe->_id, 'aid' => $exe->aid, 'state' => [ '$in' => self::OPEN ] ],
	    [ '$set' => [ 'state' => 'stopped', 'ended_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
    }

    //the execution is deleted
    public static function dropFor($exe) {
	static::coll()->deleteMany([ 'execution' => $exe->_id, 'aid' => $exe->aid ]);
    }

    //the traces of account $acct older than its `execution_log_retention` (days, 0: forever), by the daemon. One still waiting for an approval stays: its run goes on
    public static function prune($acct) {
	$days = (int)($acct->execution_log_retention ?? 0);
	if ($days <= 0)
	    return 0;
	$limit = new \MongoDB\BSON\UTCDateTime((time() - $days * 86400) * 1000);
	return static::coll()->deleteMany([ 'aid' => $acct->_id, 'created_at' => [ '$lte' => $limit ], 'state' => [ '$ne' => 'waiting' ] ])->getDeletedCount();
    }
}
