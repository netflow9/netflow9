<?php

/*
    what the FTP / SFTP step (models\node\NodeAppFtp, "Only new files") has already taken from a server, per workflow + step:
	`seen`: md5(remote path) => "<modified>|<size>" (a file is new when it is not there, or was changed since), the last check, and a lock (`locked_until`) so two runs
	of the step never download the same files at once.
	Kept outside the workflow document (as models\MailPoll): saving the workflow in the designer must not reset it. Removed with its workflow (hook workflow:before-delete)
*/

namespace models;

class RemoteFileState extends \sys\BaseModel {
    protected static $collection = 'remote_file_states';

    public static $indexes = [
	[ [ 'wf' => 1, 'node' => 1 ], [ 'unique' => true ] ],
    ];

    //a crashed run frees the step after this many seconds
    const LOCK_SECONDS = 1800;
    //the most files remembered (the oldest dropped first)
    const MAX_SEEN = 20000;

    public function getSchema() {
	return [
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'required' => true ],
	    [ 'key' => 'node', 'type' => 'text', 'label' => 'Step', 'required' => true ],
	    [ 'key' => 'project', 'type' => 'object', 'object' => 'Project', 'label' => 'Project' ],
	    [ 'key' => 'seen', 'type' => 'array', 'label' => 'Files taken' ],
	    [ 'key' => 'checked_at', 'type' => 'datetime', 'label' => 'Last check' ],
	    [ 'key' => 'locked_until', 'type' => 'datetime', 'label' => 'Locked until' ],
	];
    }

    //the key of a remote file in `seen`, and its version
    public static function fileKey($path) {
	return md5((string)$path);
    }

    //the time as listed (`listed`: an SFTP / LIST time is only to the minute, a stat would make it exact) and the size
    public static function fileVersion($entry) {
	return (int)($entry['listed'] ?? $entry['modified'] ?? 0) . '|' . (int)($entry['size'] ?? -1);
    }

    //the state of step $nodeId of workflow $wfId, locked (created when missing); null when another run holds it (as MailPoll::acquire)
    public static function acquire($wfId, $nodeId, $aid, $project) {
	$now = new \MongoDB\BSON\UTCDateTime();
	try {
	    \V::app()->om->findOneAndUpdate('RemoteFileState',
		[ 'wf' => $wfId, 'node' => (string)$nodeId ],
		[ '$setOnInsert' => [ 'aid' => $aid, 'project' => $project, 'seen' => [], 'locked_until' => null, 'created_at' => $now ] ],
		[ 'upsert' => true, 'projection' => [ '_id' => 1 ] ]
	    );
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    if ($e->getCode() != 11000)
		throw $e;
	}
	return \V::app()->om->findOneAndUpdate('RemoteFileState',
	    [ 'wf' => $wfId, 'node' => (string)$nodeId, '$or' => [ [ 'locked_until' => null ], [ 'locked_until' => [ '$lt' => $now ] ] ] ],
	    [ '$set' => [ 'locked_until' => new \MongoDB\BSON\UTCDateTime((time() + self::LOCK_SECONDS) * 1000) ] ]
	);
    }

    public function seenMap() {
	$seen = $this->seen;
	if ($seen instanceof \Traversable)
	    $seen = iterator_to_array($seen);
	return is_array($seen) ? $seen : [];
    }

    public function isNew($entry) {
	$seen = $this->seenMap();
	$key = self::fileKey($entry['path']);
	return !isset($seen[$key]) || (string)$seen[$key] !== self::fileVersion($entry);
    }

    //$taken (entries) remembered, the lock released
    public function finish(array $taken) {
	$seen = $this->seenMap();
	foreach($taken as $entry) {
	    $key = self::fileKey($entry['path']);
	    unset($seen[$key]);
	    $seen[$key] = self::fileVersion($entry);
	}
	if (count($seen) > self::MAX_SEEN)
	    $seen = array_slice($seen, -self::MAX_SEEN, null, true);
	\V::app()->om->updateMany('RemoteFileState', [ '_id' => $this->_id ],
	    [ '$set' => [ 'seen' => (object)$seen, 'checked_at' => new \MongoDB\BSON\UTCDateTime(), 'locked_until' => null ] ]);
    }

    public function release() {
	\V::app()->om->updateMany('RemoteFileState', [ '_id' => $this->_id ], [ '$set' => [ 'locked_until' => null ] ]);
    }

    //hook workflow:before-delete (sys\Boot)
    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('RemoteFileState', [ 'wf' => $wf->_id ]) as $state)
	    $state->delete();
    }
}
