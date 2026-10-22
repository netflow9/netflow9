<?php

//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\trigger)
namespace models\node;

class NodeTriggerManual extends \models\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'manual';
    public static $label = 'Manual Trigger';
    public static $icon = 'bi-play-fill';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    //CLD: 2026-10-12T20:33:47+03:00: Run: one execution started by the current user (was inline in WorkflowController::start)
    public static function startNow($wf, array $node) {
	$exe = \models\WfExecution::startFor($wf, 'manual', [ 'user' => (string)\V::app()->user->_id ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }

}
