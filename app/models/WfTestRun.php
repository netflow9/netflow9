<?php

/*
    CLD: 2026-11-02T15:46:06+02:00: new: one run of a workflow's test cases (util\AgentEval: by the daemon). The latest KEEP are kept per workflow, each with its executions (and their traces).
	state    queued, running; then passed, failed (a check failed or a case could not run), stopped, error (the run itself could not go on)
	draft_hash, cases_hash: what it ran (util\AgentEval::draftHash, casesHash): the publish gate wants them to be the current ones
	cases    [ { case, name, state (pass | fail | error | stopped), execution, trace, error, answer, tokens { input, output }, cost { usd, credits }, duration_ms,
		   checks: [ { type, label, pass, detail } ], notes } ], in the order they ran
	totals   { cases, passed, failed, tokens { input, output }, cost { usd, credits } }
    Written directly to the collection (as models\AgentTrace): never in the audit or execution logs. Read as arrays
*/

namespace models;

class WfTestRun extends \sys\BaseModel {
    protected static $collection = 'wf_test_runs';
    public static $indexes = [
	[ [ 'wf' => 1, '_id' => -1 ], [] ],
    ];

    const KEEP = 20;
    const ACTIVE = [ 'queued', 'running' ];
    const ARRAYS = [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ];

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'help' => false ],
	    [ 'key' => 'project', 'type' => 'object', 'object' => 'Project', 'label' => 'Project', 'help' => false ],
	    [ 'key' => 'state', 'type' => 'text', 'label' => 'State', 'help' => false ],
	    [ 'key' => 'cases', 'type' => 'array', 'label' => 'Cases', 'help' => false ],
	    [ 'key' => 'totals', 'type' => 'array', 'label' => 'Totals', 'help' => false ],
	], $this->getSystemSchema());
    }

    public static function coll() {
	return \V::app()->mongo->selectCollection(static::_database(), static::_collection());
    }

    //the executions of a run go with it (every way of deleting one comes here)
    public function beforeDelete() {
	parent::beforeDelete();
	foreach(\V::app()->om->find('WfExecution', [ 'aid' => $this->aid, 'wf' => $this->wf, 'test_run' => (string)$this->_id ], [ 'projection' => [ '_id' => 1, 'aid' => 1 ] ]) as $exe)
	    $exe->delete();
    }

    //run $id of workflow $wf; null: not of it
    public static function read($id, $wf) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	return static::fresh(static::coll()->findOne([ '_id' => new \MongoDB\BSON\ObjectId((string)$id), 'wf' => $wf->_id, 'aid' => $wf->aid ], self::ARRAYS));
    }

    //the runs of workflow $wf, newest first, without their cases
    public static function ofWorkflow($wf, $limit = self::KEEP) {
	$list = [];
	foreach(static::coll()->find([ 'wf' => $wf->_id, 'aid' => $wf->aid ], self::ARRAYS + [ 'projection' => [ 'cases' => 0 ], 'sort' => [ '_id' => -1 ], 'limit' => $limit ]) as $run)
	    $list[] = static::fresh($run);
	return $list;
    }

    //the latest run of workflow $wf, without its cases; null: none
    public static function latest($wf) {
	return static::ofWorkflow($wf, 1)[0] ?? null;
    }

    //the run before $run that finished: what a run is compared with
    public static function previous(array $run) {
	return static::coll()->findOne([ 'wf' => $run['wf'], 'aid' => $run['aid'], '_id' => [ '$lt' => $run['_id'] ], 'state' => [ '$in' => [ 'passed', 'failed' ] ] ], self::ARRAYS + [ 'sort' => [ '_id' => -1 ] ]);
    }

    //$run as it really is: an active run whose job is gone (its worker died three times, the job was removed) is over
    public static function fresh($run) {
	if (!$run || !in_array($run['state'] ?? '', self::ACTIVE, true) || empty($run['job']))
	    return $run;
	$jobs = \V::app()->mongo->selectCollection(\models\Job::_database(), \models\Job::_collection());
	if ($jobs->countDocuments([ '_id' => $run['job'], 'state' => [ '$in' => [ 'new', 'run' ] ] ]))
	    return $run;
	$changes = [ 'state' => 'error', 'error' => 'the daemon stopped working on this test run', 'ended_at' => new \MongoDB\BSON\UTCDateTime() ];
	static::coll()->updateOne([ '_id' => $run['_id'], 'state' => [ '$in' => self::ACTIVE ] ], [ '$set' => $changes ]);
	$now = static::coll()->findOne([ '_id' => $run['_id'] ], self::ARRAYS);
	return $now ?: $changes + $run;
    }

    //the runs of workflow $wf past the latest KEEP, with their executions
    public static function prune($wf) {
	$old = \V::app()->om->find('WfTestRun', [ 'wf' => $wf->_id, 'aid' => $wf->aid, 'state' => [ '$nin' => self::ACTIVE ] ], [ 'sort' => [ '_id' => -1 ], 'skip' => self::KEEP, 'projection' => [ '_id' => 1, 'aid' => 1, 'wf' => 1 ] ]);
	foreach($old as $run)
	    $run->delete();
    }
}
