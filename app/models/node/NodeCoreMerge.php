<?php

namespace models\node;

class NodeCoreMerge extends \models\WfNode {
    //static data/structure
    public static $type = 'core';
    public static $name = 'merge';
    public static $label = 'Merge';
    public static $icon = 'bi-signpost-2-fill';
    public static $color = 'var(--wf-color-merge)';
    public static $inputs = 2;
    public static $outputs = 1;

    //CLD: 2026-10-06T23:21:04+03:00: continues once a token arrived on every incoming edge; WfExecution calls release() when nothing else can
    //arrive (e.g. only one branch of an If reaches the Merge)
    public function execute($edgeId = null) {
	$arrived = is_array($this->arrived) ? $this->arrived : [];
	if ($edgeId !== null && !in_array($edgeId, $arrived, true))
	    $arrived[] = $edgeId;

	if (count(array_diff($this->exec->incomingEdges($this->wfid), $arrived)) == 0) {
	    $this->arrived = [];
	    return [ 0 ];
	}

	$this->arrived = $arrived;
	return null;
    }

    public function release() {
	if (empty($this->arrived))
	    return null;

	$this->exec->log($this->wfid, 'merge: continuing with ' . count($this->arrived) . ' of ' . count($this->exec->incomingEdges($this->wfid)) . ' inputs');
	$this->arrived = [];
	$this->state = 'done';
	$this->runs = (int)$this->runs + 1;
	$this->end_at = new \MongoDB\BSON\UTCDateTime();
	return [ 0 ];
    }

}
