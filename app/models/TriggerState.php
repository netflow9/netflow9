<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new: what a polling trigger remembers between its checks (e.g. the Veeam event trigger: how far it has read the sessions), per workflow + step:
	`data` (the trigger's own), the last check, and a lock (`locked_until`) so two checks of the step never run at once (as models\RemoteFileState, models\MailPoll).
	Kept outside the workflow document: saving the workflow in the designer must not reset it. Removed with its workflow (hook workflow:before-delete)

    $state = TriggerState::acquire($wf, $nodeId)      the state, locked (made when missing); null while another check holds it
    $state->dataArray()                              the trigger's data ([] the first time)
    $state->finish($data)                            the data kept, the lock released
    $state->release()                                the lock released, the data unchanged (a failed check)
*/

namespace models;

class TriggerState extends \sys\BaseModel {
    protected static $collection = 'trigger_states';

    public static $indexes = [
	[ [ 'wf' => 1, 'node' => 1 ], [ 'unique' => true ] ],
    ];

    //a crashed check frees the step after this many seconds
    const LOCK_SECONDS = 900;

    public function getSchema() {
	return [
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'required' => true
	    ],
	    [
		'key' => 'node',
		'type' => 'text',
		'label' => 'Step',
		'required' => true
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	    [
		'key' => 'data',
		'type' => 'array',
		'label' => 'Data'
	    ],
	    [
		'key' => 'checked_at',
		'type' => 'datetime',
		'label' => 'Last check'
	    ],
	    [
		'key' => 'locked_until',
		'type' => 'datetime',
		'label' => 'Locked until'
	    ],
	];
    }

    public static function acquire($wf, $nodeId) {
	$now = new \MongoDB\BSON\UTCDateTime();
	try {
	    \V::app()->om->findOneAndUpdate('TriggerState',
		[ 'wf' => $wf->_id, 'node' => (string)$nodeId ],
		[ '$setOnInsert' => [ 'aid' => $wf->aid, 'project' => $wf->project, 'data' => (object)[], 'locked_until' => null, 'created_at' => $now ] ],
		[ 'upsert' => true, 'projection' => [ '_id' => 1 ] ]
	    );
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    if ($e->getCode() != 11000)
		throw $e;
	}
	return \V::app()->om->findOneAndUpdate('TriggerState',
	    [ 'wf' => $wf->_id, 'node' => (string)$nodeId, '$or' => [ [ 'locked_until' => null ], [ 'locked_until' => [ '$lt' => $now ] ] ] ],
	    [ '$set' => [ 'locked_until' => new \MongoDB\BSON\UTCDateTime((time() + self::LOCK_SECONDS) * 1000) ] ]
	);
    }

    public function dataArray() {
	$d = $this->data;
	if ($d instanceof \Traversable || is_object($d))
	    $d = json_decode(json_encode($d), true);
	return is_array($d) ? $d : [];
    }

    public function finish(array $data) {
	\V::app()->om->updateMany('TriggerState', [ '_id' => $this->_id ],
	    [ '$set' => [ 'data' => (object)$data, 'checked_at' => new \MongoDB\BSON\UTCDateTime(), 'locked_until' => null ] ]);
    }

    public function release() {
	\V::app()->om->updateMany('TriggerState', [ '_id' => $this->_id ], [ '$set' => [ 'locked_until' => null ] ]);
    }

    //hook workflow:before-delete (sys\Boot)
    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('TriggerState', [ 'wf' => $wf->_id ]) as $state)
	    $state->delete();
    }
}
