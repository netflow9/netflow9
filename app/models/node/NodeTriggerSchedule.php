<?php

namespace models\node;

class NodeTriggerSchedule extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'schedule';
    public static $label = 'Schedule Trigger';
    public static $icon = 'bi-clock-fill';
    public static $inputs = 0;
    public static $outputs = 1;

    public static $scheduled = true;

    //the schedule fields (model field definitions with rules), matching and checks are in models\wf\WfSchedule (WfSchedule::schema; shared with the other scheduled steps);
    //this step has nothing else
    public static function configSchema() {
	return \models\wf\WfSchedule::schema();
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    //the config is checked by its schema's rules (parent::validates); here only the weekdays (a list)
    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	return $valid;
    }

    //on schedule: start the workflow at this step; the variable a scheduled run starts with (runScheduled)
    public static function outputVariables($config) {
	return [
	    'schedule_time' => [
		'type' => 'string',
		'info' => 'the scheduled minute (ISO 8601)'
	    ]
	];
    }

    public static function runScheduled($wf, array $node, $at) {
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");

	$exe = \models\wf\WfExecution::startFor($wf, 'schedule', [
	    'scheduled_at' => (string)$at,
	    'started_at' => new \MongoDB\BSON\UTCDateTime(),
	    'vars' => [ 'schedule_time' => (string)$at ],
	], $node['wfid']);
	$exe->log($node['wfid'], 'scheduled run ' . $at);
	$exe->save();
	//log the start too
	\V::app()->log->info("Scheduler: workflow `{$wf->name}`: execution {$exe->_id}: start");
	$state = $exe->run();
	\V::app()->log->info("Scheduler: workflow `{$wf->name}`: execution {$exe->_id}: {$state}");
    }

    //Run: one execution now, as a scheduled run of the current minute (schedule_time = now; the schedule itself is not changed)
    public static function startNow($wf, array $node) {
	$at = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:00P');
	$exe = \models\wf\WfExecution::startFor($wf, 'schedule', [
	    'scheduled_at' => $at,
	    'started_at' => new \MongoDB\BSON\UTCDateTime(),
	    'vars' => [ 'schedule_time' => $at ],
	], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }
}
