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

    //CLD: 2026-10-25T11:40:00+02:00: when it continues: all = once every incoming branch arrived (as before; the default, so existing steps keep working), first = at
    //the first branch (the others are taken when they arrive, nothing continues for them)
    const MODES = [
	'all' => 'All incoming branches arrived',
	'first' => 'The first branch arrived (the others are ignored)',
    ];

    public static function configSchema() {
	return [
	    [
		'key' => 'mode',
		'type' => 'select',
		'label' => 'Continue when',
		'help' => 'All: waits for every branch that can still arrive; first: goes on at once, the later ones end there',
		'required' => true,
		'default' => 'all',
		'choices' => self::MODES,
	    ],
	];
    }

    //CLD: 2026-10-06T23:21:04+03:00: continues once a token arrived on every incoming edge; WfExecution calls release() when nothing else can
    //arrive (e.g. only one branch of an If reaches the Merge)
    //CLD: 2026-10-25T11:40:00+02:00: + mode first: the first token of a round continues, the others are taken; `arrived` holds the round's edges (not empty: the round
    //already continued); the round ends when every edge arrived, or at release()
    public function execute($edgeId = null) {
	$arrived = is_array($this->arrived) ? $this->arrived : [];
	$first = $arrived === [];
	if ($edgeId !== null && !in_array($edgeId, $arrived, true))
	    $arrived[] = $edgeId;
	$all = count(array_diff($this->exec->incomingEdges($this->wfid), $arrived)) == 0;

	if ($this->cfg('mode', 'all') === 'first') {
	    $this->arrived = $all ? [] : $arrived;
	    if ($first)
		return [ 0 ];
	    //a later branch: taken; done when it was the last one, else still waiting for the others (release() ends the round)
	    return $all ? [] : null;
	}

	if ($all) {
	    $this->arrived = [];
	    return [ 0 ];
	}

	$this->arrived = $arrived;
	return null;
    }

    //CLD: 2026-10-25T11:40:00+02:00: mode first: after continuing, the step keeps waiting for the round's other branches (release() then ends the round, so a next pass of a
    //loop starts a new one)
    public function receive($edgeId = null) {
	$result = parent::receive($edgeId);
	if (is_array($result) && $this->cfg('mode', 'all') === 'first' && !empty($this->arrived))
	    $this->state = 'wait';
	return $result;
    }

    public function release() {
	if (empty($this->arrived))
	    return null;

	//CLD: 2026-10-25T11:40:00+02:00: mode first: it continued at the first branch; the round ends, nothing continues
	if ($this->cfg('mode', 'all') === 'first') {
	    $this->exec->log($this->wfid, 'merge: continued at the first input; ' . count($this->arrived) . ' of ' . count($this->exec->incomingEdges($this->wfid)) . ' inputs arrived');
	    $this->arrived = [];
	    $this->state = 'done';
	    return null;
	}

	$this->exec->log($this->wfid, 'merge: continuing with ' . count($this->arrived) . ' of ' . count($this->exec->incomingEdges($this->wfid)) . ' inputs');
	$this->arrived = [];
	$this->state = 'done';
	$this->runs = (int)$this->runs + 1;
	$this->end_at = new \MongoDB\BSON\UTCDateTime();
	return [ 0 ];
    }

}
