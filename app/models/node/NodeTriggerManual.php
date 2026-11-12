<?php

namespace models\node;

class NodeTriggerManual extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'manual';
    public static $label = 'Manual Trigger';
    public static $icon = 'bi-play-fill';
    public static $inputs = 0;
    public static $outputs = 1;

    //Run: one execution started by the current user
    public static function startNow($wf, array $node) {
	$exe = \models\wf\WfExecution::startFor($wf, 'manual', [ 'user' => (string)\V::app()->user->_id ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }

}
