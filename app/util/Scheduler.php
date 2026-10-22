<?php

namespace util;

class Scheduler {

    //daemon:tick handler (master process): $ts is the tick time
    public static function tick($ts) {
	$tz = new \DateTimeZone(\V::app()->config('app.timezone') ?: date_default_timezone_get());
	$utc = $ts instanceof \MongoDB\BSON\UTCDateTime ? $ts->toDateTime() : new \DateTimeImmutable();
	//the minute being ticked, local time (seconds dropped)
	$t = \DateTimeImmutable::createFromInterface($utc)->setTimezone($tz);
	$t = $t->setTime((int)$t->format('G'), (int)$t->format('i'), 0);
	$minuteKey = $t->format('YmdHi');

	$types = self::scheduledTypes();
	if (empty($types))
	    return 0;

	$queued = 0;
	$wfs = [];
	foreach(\V::app()->om->find('Workflow', [ 'status' => \models\Workflow::PUBLISHED, 'version' => [ '$ne' => null ] ], [ 'projection' => [ 'aid' => 1, 'project' => 1, 'name' => 1, 'version' => 1 ] ]) as $w)
	    $wfs[] = [ '_id' => $w->_id, 'aid' => $w->aid, 'project' => $w->project, 'name' => $w->name, 'version' => $w->version ];
	$versionNodes = [];
	if ($wfs) {
	    foreach(\V::app()->om->find('WfVersion', [ '_id' => [ '$in' => array_column($wfs, 'version') ] ], [ 'projection' => [ 'nodes' => 1 ] ]) as $v)
		$versionNodes[(string)$v->_id] = is_array($v->nodes) ? $v->nodes : [];
	}
	foreach($wfs as $wf) {
	    foreach(self::scheduleNodes($versionNodes[(string)$wf['version']] ?? [], $types) as $node) {
		$cfg = is_array($node['config'] ?? null) ? $node['config'] : [];
		if (!\models\WfSchedule::matches($cfg, $t))
		    continue;

		//CLD: 2026-10-09T12:07:59+03:00: models\Job (was models\Event)
		$id = \models\Job::submit('\util\Scheduler::run', [
		    'wf' => (string)$wf['_id'],
		    'node' => (string)$node['wfid'],
		    'at' => $t->format(DATE_ATOM),
		], [
		    'aid' => $wf['aid'] ?? null,
		    'project' => $wf['project'] ?? null,
		    'key' => 'schedule:' . $wf['_id'] . ':' . $node['wfid'] . ':' . $minuteKey,
		    'delete_on_done' => true,
		    'notify' => false,
		]);
		if ($id !== null) {
		    $queued++;
		    \V::app()->log->info("Scheduler: {$t->format('Y-m-d H:i')}: workflow `" . ($wf['name'] ?? '') . "` ({$wf['_id']}), step {$node['wfid']} ({$node['fqname']})");
		}
	    }
	}
	return $queued;
    }

    //daemon:tick handler (master process): queue the sleeping executions whose wake_at has come (any trigger, drafts too)
    public static function wake($ts) {
	$now = $ts instanceof \MongoDB\BSON\UTCDateTime ? $ts : new \MongoDB\BSON\UTCDateTime();

	$queued = 0;
	foreach(\V::app()->om->find('WfExecution', [ 'state' => 'sleep', 'wake_at' => [ '$lte' => $now ] ], [ 'projection' => [ 'aid' => 1, 'project' => 1, 'wake_at' => 1 ] ]) as $exe) {
	    $id = \models\Job::submit('\util\Scheduler::resume', [
		'exe' => (string)$exe->_id,
	    ], [
		'aid' => $exe->aid,
		'project' => $exe->project,
		'key' => 'wake:' . $exe->_id . ':' . (string)$exe->wake_at,
		'delete_on_done' => true,
		'notify' => false,
	    ]);
	    if ($id !== null) {
		$queued++;
		\V::app()->log->info("Scheduler: wake execution {$exe->_id}");
	    }
	}
	return $queued;
    }

    //Job handler (worker): continue a sleeping execution; skipped when it is no longer sleeping (resumed or cancelled meanwhile)
    public static function resume($data) {
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => \V::app()->om->to_oid($data->exe ?? null), 'aid' => $data->aid ?? null ]);
	if (empty($exe) || $exe->state != 'sleep') {
	    \V::app()->log->info('Scheduler: execution ' . ($data->exe ?? '?') . ' is no longer sleeping, skipped');
	    return;
	}
	$exe->run();
    }

    //run an execution a web request handed over (WfExecution::queue: the account's sync executions were all
    //running); skipped when it can no longer run (cancelled meanwhile)
    public static function start($data) {
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => \V::app()->om->to_oid($data->exe ?? null), 'aid' => $data->aid ?? null ]);
	if (empty($exe) || !$exe->canRun()) {
	    \V::app()->log->info('Scheduler: execution ' . ($data->exe ?? '?') . ' can no longer run, skipped');
	    return;
	}
	$exe->run();
    }

    //CLD: 2026-10-08T22:57:28+03:00: scheduled step types: [ fqname => class ]
    public static function scheduledTypes() {
	$types = [];
	foreach(\models\WfNode::getAll() as $fqname => $def) {
	    if (!empty($def['scheduled']))
		$types[$fqname] = \V::app()->om->getObjectClass($def['objectName']);
	}
	return $types;
    }

    //scheduled steps of a workflow's nodes (the designer saves a list or a map); $types: scheduledTypes()
    //CLD: 2026-10-08T22:57:28+03:00: all scheduled types (was trigger::schedule only)
    public static function scheduleNodes($nodes, ?array $types = null) {
	$types ??= self::scheduledTypes();
	$found = [];
	foreach((is_array($nodes) ? $nodes : []) as $n) {
	    if (!empty($n['isNode']) && isset($types[$n['fqname'] ?? '']) && !empty($n['wfid']))
		$found[] = $n;
	}
	return $found;
    }

    //Job handler (worker): the scheduled step's work
    //CLD: 2026-10-08T22:57:28+03:00: done by the step's class (runScheduled); the step must still be there, with the same type
    public static function run($data) {
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => \V::app()->om->to_oid($data->wf ?? null), 'aid' => $data->aid ?? null ]);
	//CLD: 2026-10-11T14:05:19+03:00: deleted after the job was queued: skipped (was an error, reported by mail)
	if (empty($wf)) {
	    \V::app()->log->info('Scheduler: workflow ' . ($data->wf ?? '?') . ' no longer exists, skipped');
	    return;
	}
	$wf = $wf->liveCopy();
	if (empty($wf)) {
	    \V::app()->log->info("Scheduler: workflow {$data->wf} is not published, skipped");
	    return;
	}

	$types = self::scheduledTypes();
	$node = null;
	foreach(self::scheduleNodes($wf->nodes, $types) as $n) {
	    if ($n['wfid'] === ($data->node ?? null))
		$node = $n;
	}
	if (empty($node)) {
	    \V::app()->log->info("Scheduler: workflow {$wf->_id}: step {$data->node} is no longer a scheduled step, skipped");
	    return;
	}

	$class = $types[$node['fqname']];
	$class::runScheduled($wf, $node, (string)($data->at ?? ''));
    }
}
