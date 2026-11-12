<?php

/*
    Hyper-V VM state - starts the workflow when a VM of a Hyper-V host stops running (or on any change of its state), or when its replication
    health turns Warning or Critical; optionally also when it recovers (running again, replication Normal). Hyper-V calls no webhook: the step reads the VMs on its schedule
    (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled; PowerShell over WinRM) and compares them with the last check's (models\TriggerState: VM ID =>
    { name, state, health, firedState, firedHealth }). Passing states (Starting, Stopping, Saving, ...) are not changes: the state before stays until a settled one is read. The first
    check only notes the VMs; a VM that appears later is noted, one that disappears is forgotten. The variable: the event { event: stopped | state | replication | recovery, vm: { name,
    id }, from, to, state, replicationHealth, time }, or { events, count } per check; at most MAX_RUNS per check
*/

namespace models\node;

class NodeTriggerHyperV extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'hyperv';
    public static $label = 'Hyper-V VM state';
    public static $icon = 'bi-windows';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const WHEN = [ 'stops' => 'It stops running', 'any' => 'Its state changes' ];
    const RUNS = [ 'each' => 'One run per event', 'batch' => 'One run per check with events' ];
    //the states Hyper-V passes through: not taken as a change
    const PASSING = [ 'Starting', 'Stopping', 'Saving', 'Pausing', 'Resuming', 'Reset', 'Other', 'FastSaving', 'Paused-Resuming' ];
    const BAD_HEALTH = [ 'Warning', 'Critical' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 90;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('hyperv', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Hyper-V credential',
		'choices' => $choices,
		'help' => 'The host whose VMs start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'names',
		'type' => 'text',
		'label' => 'VMs',
		'default' => '',
		'help' => 'Names, comma separated; * any text; empty: every VM',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'when',
		'type' => 'select',
		'label' => 'When a VM',
		'required' => true,
		'default' => 'stops',
		'choices' => self::WHEN,
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
		'key' => 'replication',
		'type' => 'switch',
		'label' => 'Replication health too',
		'default' => false,
		'help' => 'When it turns Warning or Critical',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'recoveries',
		'type' => 'switch',
		'label' => 'When it recovers too',
		'default' => false,
		'help' => 'Running again, replication Normal (event: recovery)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'hv',
		'help' => 'e.g. {{ hv.vm.name }}, {{ hv.event }}',
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'hyperv')))) {
	    $this->errors('config', 'Hyper-V VM state: choose a Hyper-V credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'hv') : 'hv';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ events, count }', 'fields' => [
		'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an event' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the event', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => 'stopped, state, replication or recovery' ], 'vm' => [ 'type' => 'object', 'info' => '{ name, id }' ],
	    'from' => [ 'type' => 'string', 'info' => 'the state (or replication health) before' ], 'to' => [ 'type' => 'string', 'info' => 'the one now' ],
	    'state' => [ 'type' => 'string', 'info' => 'Running, Off, Saved, Paused, ...' ], 'replicationHealth' => [ 'type' => 'string', 'info' => 'Normal, Warning, Critical, NotApplicable' ],
	    'time' => [ 'type' => 'string', 'info' => 'when it was seen, ISO 8601' ] ] ] ];
    }

    //the VMs now: [ { name, id, state, health } ]
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'hyperv');
	if (!$cred)
	    throw new \Exception('Hyper-V VM state: the credential is no longer available');
	$names = array_values(array_filter(array_map('trim', explode(',', (string)($cfg['names'] ?? '')))));
	$all = $cred->client($wf->aid, $timeout)->ps('hyperv-trigger:states', <<<'PS'
$out = foreach ($v in (Get-VM)) {
  if (@($P.names).Count -gt 0) {
    $hit = $false
    foreach ($n in @($P.names)) { if ($v.Name -like $n) { $hit = $true } }
    if (-not $hit) { continue }
  }
  [ordered]@{ name = $v.Name; id = "$($v.Id)"; state = "$($v.State)"; health = "$($v.ReplicationHealth)" }
}
,@($out)
PS, [ 'names' => $names ]);
	return is_array($all) ? array_values(array_filter($all, fn($v) => is_array($v) && !empty($v['id']))) : [];
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Hyper-V VM state: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { started: true, vms: { id: { name, state, health, firedState, firedHealth } } }, the executions started ]
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	$old = (array)($data['vms'] ?? []);
	$first = empty($data['started']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$when = ($cfg['when'] ?? 'stops') === 'any' ? 'any' : 'stops';
	$rep = !empty($cfg['replication']);
	$rec = !empty($cfg['recoveries']);
	$time = gmdate('Y-m-d\TH:i:s\Z');

	$events = [];
	$now = [];
	foreach(self::current($wf, $cfg, self::TIMEOUT) as $v) {
	    $id = (string)$v['id'];
	    $prev = $old[$id] ?? null;
	    $state = (string)$v['state'];
	    $health = (string)$v['health'];
	    //a passing state: the one before stays (a VM first seen passing: noted as it is)
	    if ($prev !== null && in_array($state, self::PASSING, true))
		$state = (string)$prev['state'];
	    $entry = [ 'name' => (string)$v['name'], 'state' => $state, 'health' => $health, 'firedState' => !empty($prev['firedState']), 'firedHealth' => !empty($prev['firedHealth']) ];
	    if ($prev !== null && !$first) {
		$was = (string)$prev['state'];
		if ($state !== $was) {
		    if ($state === 'Running' && $entry['firedState']) {
			$entry['firedState'] = false;
			if ($rec)
			    $events[] = self::vmEvent('recovery', $was, $state, $v, $id, $state, $health, $time);
		    } elseif ($when === 'any' || ($was === 'Running' && $state !== 'Running')) {
			$events[] = self::vmEvent($when === 'any' ? 'state' : 'stopped', $was, $state, $v, $id, $state, $health, $time);
			$entry['firedState'] = $state !== 'Running';
		    }
		}
		$wasHealth = (string)$prev['health'];
		if ($rep && $health !== $wasHealth) {
		    if (in_array($health, self::BAD_HEALTH, true)) {
			$events[] = self::vmEvent('replication', $wasHealth, $health, $v, $id, $state, $health, $time);
			$entry['firedHealth'] = true;
		    } elseif ($health === 'Normal' && $entry['firedHealth']) {
			$entry['firedHealth'] = false;
			if ($rec)
			    $events[] = self::vmEvent('recovery', $wasHealth, $health, $v, $id, $state, $health, $time);
		    }
		}
	    }
	    $now[$id] = $entry;
	}
	$events = array_slice($events, 0, self::MAX_RUNS);

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'hv';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'events' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $ev) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'hyperv', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $ev ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($ev['events']) ? 'Hyper-V: ' . \util\Format::plural($ev['count'], 'event') : "Hyper-V {$ev['event']}: {$ev['vm']['name']} {$ev['from']} -> {$ev['to']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'started' => true, 'vms' => (object)$now ], $started ];
    }

    //an event of VM $v (id $id): $type, from $from to $to, with its state, replication health and the time of the check
    private static function vmEvent($type, $from, $to, $v, $id, $state, $health, $time) {
	return [ 'event' => $type, 'vm' => [ 'name' => (string)$v['name'], 'id' => $id ], 'from' => $from, 'to' => $to, 'state' => $state,
	    'replicationHealth' => $health, 'time' => $time ];
    }

    //Run in the designer: one run with a VM that is not running (or the first VM), as a `stopped` event; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $vms = self::current($wf, $cfg, 30);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Hyper-V VM state: ' . $e->getMessage() ];
	}
	if (!$vms)
	    return [ 'executions' => [], 'message' => 'Hyper-V VM state: no VM matches' ];
	$v = array_values(array_filter($vms, fn($x) => $x['state'] !== 'Running'))[0] ?? $vms[0];
	$ev = [ 'event' => $v['state'] === 'Running' ? 'state' : 'stopped', 'vm' => [ 'name' => (string)$v['name'], 'id' => (string)$v['id'] ], 'from' => 'Running', 'to' => (string)$v['state'],
	    'state' => (string)$v['state'], 'replicationHealth' => (string)$v['health'], 'time' => gmdate('Y-m-d\TH:i:s\Z') ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'hv';
	$exe = \models\wf\WfExecution::startFor($wf, 'hyperv', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch' ? [ 'events' => [ $ev ], 'count' => 1 ] : $ev ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with VM {$v['name']} ({$v['state']})" ];
    }
}
