<?php

/*
    Proxmox task - starts the workflow when tasks of a Proxmox VE cluster end: failed ones (a backup, a migration, a replication, ...)
    or every one, of the types chosen (vzdump, ...) and of a node or any. Proxmox calls no webhook: the step checks it on its schedule (models\wf\WfSchedule, every 5 minutes by default;
    util\Scheduler runs runScheduled), reading the cluster's recent tasks (GET /cluster/tasks) that ended since its last check. What it has read is kept in models\TriggerState
    (the last end time, and the tasks that ended at that time), locked during a check, as the Veeam event trigger. Its first check only notes the time. Runs: one per task (the
    variable: the task + failed + log, its last lines) or one per check with tasks ({ tasks, count }); at most MAX_RUNS per check
*/

namespace models\node;

class NodeTriggerProxmox extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'proxmox';
    public static $label = 'Proxmox task';
    public static $icon = 'bi-hdd-rack';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RESULTS = [ 'failed' => 'Failed', 'all' => 'Every result (OK, warnings, failed)' ];
    const RUNS = [ 'each' => 'One run per task', 'batch' => 'One run per check with tasks' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 60;
    //log lines kept in a task's variable
    const LOG_LINES = 20;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('proxmox', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Proxmox credential',
		'choices' => $choices,
		'help' => 'The cluster whose tasks start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'results',
		'type' => 'select',
		'label' => 'Tasks that ended',
		'required' => true,
		'default' => 'failed',
		'choices' => self::RESULTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'types',
		'type' => 'text',
		'label' => 'Types',
		'default' => 'vzdump',
		'help' => 'Comma separated, e.g. vzdump, qmigrate; empty: any',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'node',
		'type' => 'text',
		'label' => 'Node',
		'default' => '',
		'help' => 'Empty: every node',
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
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Task variable',
		'required' => true,
		'default' => 'task',
		'help' => 'e.g. {{ task.type }}, {{ task.status }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'proxmox')))) {
	    $this->errors('config', 'Proxmox task: choose a Proxmox VE credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'task') : 'task';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ tasks, count }', 'fields' => [
		'tasks' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a task' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the task', 'fields' => [
	    'upid' => [ 'type' => 'string' ], 'node' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'info' => 'e.g. vzdump, qmstart, qmigrate' ],
	    'id' => [ 'type' => 'string', 'info' => 'what it ran on, e.g. the VMID' ], 'user' => [ 'type' => 'string' ],
	    'status' => [ 'type' => 'string', 'info' => 'OK, WARNINGS: n, or the error' ], 'failed' => [ 'type' => 'boolean' ],
	    'start' => [ 'type' => 'string', 'info' => 'ISO 8601' ], 'end' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    'log' => [ 'type' => 'string', 'info' => 'its last log lines' ] ] ] ];
    }

    //the ended tasks matching the step's settings, oldest first (all results: the trigger keeps its own state)
    protected static function ended(\util\Proxmox $pve, array $cfg) {
	$types = array_values(array_filter(array_map('trim', explode(',', (string)($cfg['types'] ?? '')))));
	$node = trim((string)($cfg['node'] ?? ''));
	$failedOnly = ($cfg['results'] ?? 'failed') !== 'all';
	$list = array_values(array_filter((array)$pve->request('GET', '/cluster/tasks'), fn($t) => !empty($t['endtime']) && isset($t['upid'])
	    && (!$types || in_array((string)($t['type'] ?? ''), $types, true)) && ($node === '' || strcasecmp((string)($t['node'] ?? ''), $node) === 0)
	    && (!$failedOnly || NodeAppProxmoxCluster::failed($t))));
	usort($list, fn($a, $b) => [ (int)$a['endtime'], (string)$a['upid'] ] <=> [ (int)$b['endtime'], (string)$b['upid'] ]);
	return $list;
    }

    //a task for the variable: its fields, start and end as ISO 8601, failed, its last log lines
    protected static function value(\util\Proxmox $pve, array $t) {
	$log = '';
	try {
	    $lines = (array)$pve->request('GET', "/nodes/{$t['node']}/tasks/" . rawurlencode((string)$t['upid']) . '/log', [ 'start' => 0, 'limit' => 1000 ]);
	    $log = implode("\n", array_slice(array_map(fn($l) => (string)($l['t'] ?? ''), $lines), -self::LOG_LINES));
	} catch (\RuntimeException $e) {
	    //no log: none
	}
	return [ 'upid' => (string)$t['upid'], 'node' => (string)($t['node'] ?? ''), 'type' => (string)($t['type'] ?? ''), 'id' => (string)($t['id'] ?? ''),
	    'user' => (string)($t['user'] ?? ''), 'status' => (string)($t['status'] ?? ''), 'failed' => NodeAppProxmoxCluster::failed($t),
	    'start' => isset($t['starttime']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$t['starttime']) : null, 'end' => gmdate('Y-m-d\TH:i:s\Z', (int)$t['endtime']), 'log' => $log ];
    }

    //a task for the log
    public static function describe(array $v) {
	return trim("{$v['type']} {$v['id']} on {$v['node']}: {$v['status']}");
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Proxmox task: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data, the executions started ]; $data: { since (unix time), seen: [ UPIDs of the tasks that ended at `since` already started ] }
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($data['since']))
	    return [ [ 'since' => time(), 'seen' => [] ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'proxmox');
	if (!$cred)
	    throw new \Exception('Proxmox task: the credential is no longer available');
	$pve = $cred->client($wf->aid, self::TIMEOUT);
	$since = (int)$data['since'];
	$seen = array_flip(array_map('strval', (array)($data['seen'] ?? [])));
	$new = [];
	foreach(self::ended($pve, $cfg) as $t) {
	    if ((int)$t['endtime'] < $since || isset($seen[(string)$t['upid']]))
		continue;
	    $new[] = $t;
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	//the next check starts at the newest end time read, remembering the tasks that ended at that very time
	$last = $since;
	$lastIds = array_keys($seen);
	foreach($new as $t) {
	    if ((int)$t['endtime'] > $last) {
		$last = (int)$t['endtime'];
		$lastIds = [];
	    }
	    if ((int)$t['endtime'] === $last)
		$lastIds[] = (string)$t['upid'];
	}
	$tasks = array_map(fn($t) => self::value($pve, $t), $new);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'task';
	$values = !$tasks ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'tasks' => $tasks, 'count' => count($tasks) ] ] : $tasks);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'proxmox', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['tasks']) ? 'Proxmox: ' . \util\Format::plural($v['count'], 'task') . ' ended' : 'Proxmox: ' . self::describe($v));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'since' => $last, 'seen' => array_values(array_unique($lastIds)) ], $started ];
    }

    //Run in the designer: one run with the newest matching task; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'proxmox');
	if (!$cred)
	    return [ 'executions' => [], 'message' => 'Proxmox task: choose a Proxmox VE credential first' ];
	try {
	    $pve = $cred->client($wf->aid, 30);
	    $list = self::ended($pve, $cfg);
	    $t = $list ? self::value($pve, end($list)) : null;
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Proxmox task: ' . $e->getMessage() ];
	}
	if (!$t)
	    return [ 'executions' => [], 'message' => 'Proxmox task: no recent task matches' ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'task';
	$exe = \models\wf\WfExecution::startFor($wf, 'proxmox', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch' ? [ 'tasks' => [ $t ], 'count' => 1 ] : $t ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => 'Run with the newest matching task: ' . self::describe($t) ];
    }
}
