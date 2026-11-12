<?php

/*
    VMware alarm - starts the workflow when an alarm of a vCenter Server is triggered (red, or yellow and red) on a VM, host, datastore, ...
    or any object; optionally also when one clears. vCenter calls no webhook: the step checks it on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs
    runScheduled), reading the alarms triggered now (NodeAppVmwareAlarms::alarms, VI/JSON: vCenter 8.0 U1 and later; acknowledged ones too, so they are not taken as cleared) and
    comparing them with the last check's (models\TriggerState: key alarm|entity => { status, fired, quiet }), as the Checkmk problem trigger: an alarm starts a run when it is new
    or its status changed (yellow -> red), unless it is acknowledged and those are left out; an alarm that started a run and is gone starts a `recovery` run when chosen. The first
    check only notes the alarms. The variable: the alarm (NodeAppVmwareAlarms item + event: alarm | recovery), or { alarms, count } per check; at most MAX_RUNS per check
*/

namespace models\node;

class NodeTriggerVmware extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'vmware';
    public static $label = 'VMware alarm';
    public static $icon = 'bi-boxes';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RUNS = [ 'each' => 'One run per alarm', 'batch' => 'One run per check with alarms' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 90;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('vmware', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'VMware credential',
		'choices' => $choices,
		'help' => 'The vCenter whose alarms start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'status',
		'type' => 'select',
		'label' => 'Status',
		'required' => true,
		'default' => 'red',
		'choices' => NodeAppVmwareAlarms::STATUS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'entityType',
		'type' => 'select',
		'label' => 'Of',
		'default' => 'any',
		'choices' => NodeAppVmwareAlarms::ENTITIES,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'acknowledged',
		'type' => 'switch',
		'label' => 'Also acknowledged alarms',
		'default' => false,
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'recoveries',
		'type' => 'switch',
		'label' => 'When it clears too',
		'default' => false,
		'help' => 'A run when an alarm that started one is gone (event: recovery)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Alarm variable',
		'required' => true,
		'default' => 'alarm',
		'help' => 'e.g. {{ alarm.entity.name }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'vmware')))) {
	    $this->errors('config', 'VMware alarm: choose a VMware vCenter credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'alarm') : 'alarm';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ alarms, count }', 'fields' => [
		'alarms' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an alarm (or a recovery)' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the alarm', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => 'alarm or recovery' ], 'key' => [ 'type' => 'string', 'info' => 'alarm|entity, for Acknowledge' ],
	    'alarm' => [ 'type' => 'object', 'info' => '{ id, name }' ], 'entity' => [ 'type' => 'object', 'info' => '{ type, id, name }, e.g. VirtualMachine, vm-42, web01' ],
	    'status' => [ 'type' => 'string', 'info' => 'red or yellow (a recovery: the last one)' ], 'time' => [ 'type' => 'string', 'info' => 'when it was triggered, ISO 8601' ],
	    'acknowledged' => [ 'type' => 'boolean' ] ] ] ];
    }

    //the alarms triggered now (acknowledged too: they are left out of the runs, not of the state)
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'vmware');
	if (!$cred)
	    throw new \Exception('VMware alarm: the credential is no longer available');
	return NodeAppVmwareAlarms::alarms($cred->client($wf->aid, $timeout), (string)($cfg['status'] ?? 'red'), (string)($cfg['entityType'] ?? 'any'), true);
    }

    protected static function eligible(array $a, array $cfg) {
	return !empty($cfg['acknowledged']) || !$a['acknowledged'];
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("VMware alarm: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //one check: [ the new state data { started: true, alarms: { key: { status, fired, quiet, ... } } }, the executions started ]; fired: handled (a run started, or noted by
    //the first check); quiet: noted by the first check (no run: no recovery either), until its status changes
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	$old = (array)($data['alarms'] ?? []);
	$first = empty($data['started']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$current = self::current($wf, $cfg, self::TIMEOUT);

	$events = [];
	$now = [];
	foreach($current as $a) {
	    $key = $a['key'];
	    $prev = $old[$key] ?? null;
	    $fire = !$first && self::eligible($a, $cfg) && ($prev === null || (string)$prev['status'] !== $a['status'] || empty($prev['fired']));
	    $same = $prev !== null && (string)$prev['status'] === $a['status'];
	    $quiet = $first || ($same && !empty($prev['quiet']) && !$fire);
	    if ($fire && count($events) < self::MAX_RUNS) {
		$events[] = [ 'event' => 'alarm' ] + $a;
		$fired = true;
		$quiet = false;
	    } elseif ($fire)
		//over MAX_RUNS: not started, the next check starts it
		$fired = false;
	    else
		$fired = $first || ($same && !empty($prev['fired']));
	    $now[$key] = [ 'status' => $a['status'], 'fired' => $fired, 'quiet' => $quiet, 'alarm' => $a['alarm'], 'entity' => $a['entity'] ];
	}
	if (!empty($cfg['recoveries']) && !$first) {
	    foreach($old as $key => $prev) {
		if (!isset($now[$key]) && !empty($prev['fired']) && empty($prev['quiet']) && count($events) < self::MAX_RUNS)
		    $events[] = [ 'event' => 'recovery', 'key' => (string)$key, 'alarm' => (array)($prev['alarm'] ?? []), 'entity' => (array)($prev['entity'] ?? []),
			'status' => (string)$prev['status'], 'time' => gmdate('Y-m-d\TH:i:s\Z'), 'acknowledged' => false, 'acknowledgedBy' => null, 'acknowledgedTime' => null ];
	    }
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'alarm';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'alarms' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'vmware', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['alarms']) ? 'VMware: ' . \util\Format::plural($v['count'], 'event') : "VMware {$v['event']}: " . NodeAppVmwareAlarms::describe($v));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'started' => true, 'alarms' => (object)$now ], $started ];
    }

    //Run in the designer: one run with the first alarm triggered now that the step would start a run for; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $alarms = array_values(array_filter(self::current($wf, $cfg, 30), fn($a) => self::eligible($a, $cfg)));
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'VMware alarm: ' . $e->getMessage() ];
	}
	if (!$alarms)
	    return [ 'executions' => [], 'message' => 'VMware alarm: no alarm triggered now matches' ];
	$a = [ 'event' => 'alarm' ] + $alarms[0];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'alarm';
	$exe = \models\wf\WfExecution::startFor($wf, 'vmware', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch' ? [ 'alarms' => [ $a ], 'count' => 1 ] : $a ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => 'Run with an alarm triggered now: ' . NodeAppVmwareAlarms::describe($a) ];
    }
}
