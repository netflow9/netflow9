<?php

namespace models\node;

class NodeTriggerSchedule extends \models\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'schedule';
    public static $label = 'Schedule Trigger';
    public static $icon = 'bi-clock-fill';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    public static $scheduled = true;

    //CLD: 2026-10-08T22:57:28+03:00: the schedule fields, matching and checks moved to models\WfSchedule (shared with the other scheduled steps, e.g. the Email
    //(IMAP) trigger); this step has nothing else
    //CLD: 2026-10-09T08:10:30+03:00: model field definitions with rules (WfSchedule::schema; was fields())
    public static function configSchema() {
	return \models\WfSchedule::schema();
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\WfSchedule::matches($cfg, $t);
    }

    //CLD: 2026-10-09T08:10:30+03:00: the config is checked by its schema's rules (parent::validates); here only the weekdays (a list)
    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	return $valid;
    }

    //CLD: 2026-10-08T22:57:28+03:00: on schedule: start the workflow at this step (was plugins\BaseNodes\Scheduler::run)
    //CLD: 2026-10-11T22:38:36+03:00: the variable a scheduled run starts with (runScheduled)
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

	$exe = \models\WfExecution::startFor($wf, 'schedule', [
	    'scheduled_at' => (string)$at,
	    'started_at' => new \MongoDB\BSON\UTCDateTime(),
	    'vars' => [ 'schedule_time' => (string)$at ],
	], $node['wfid']);
	$exe->log($node['wfid'], 'scheduled run ' . $at);
	$exe->save();
	$state = $exe->run();
	\V::app()->log->info("Scheduler: workflow `{$wf->name}`: execution {$exe->_id}: {$state}");
    }

    //CLD: 2026-10-12T20:33:47+03:00: Run: one execution now, as a scheduled run of the current minute (schedule_time = now; the schedule itself is not changed)
    public static function startNow($wf, array $node) {
	$at = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:00P');
	$exe = \models\WfExecution::startFor($wf, 'schedule', [
	    'scheduled_at' => $at,
	    'started_at' => new \MongoDB\BSON\UTCDateTime(),
	    'vars' => [ 'schedule_time' => $at ],
	], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }
}
