<?php

/*
    Checkmk problem - starts the workflow when Checkmk reports a new problem: a host DOWN / UNREACH, a service WARN / CRIT / UNKNOWN
    (or CRIT only), of one host or every host; optionally also when one clears. It checks the site on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler
    runs runScheduled): every current problem is read (acknowledged ones and those in a downtime too, so they are not taken as cleared) and compared with the last check's
    (models\TriggerState: key host|service => { state, fired }): a problem starts a run when it is new or its state changed, unless it is acknowledged or in a downtime and those
    are left out; a problem that started a run and is gone starts a `recovery` run when chosen. The first check only notes the current problems. The variable: the problem
    (NodeAppCmkStatus::problem + event: problem | recovery), or { problems, count } per check; at most MAX_RUNS per check
*/

namespace models\node;

class NodeTriggerCheckmk extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'checkmk';
    public static $label = 'Checkmk problem';
    public static $icon = 'bi-activity';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const MIN = [ '1' => 'WARN and worse', '2' => 'CRIT and worse' ];
    const RUNS = [ 'each' => 'One run per problem', 'batch' => 'One run per check with problems' ];
    const MAX_RUNS = 200;
    const MAX_PROBLEMS = 5000;
    const TIMEOUT = 60;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('checkmk', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Checkmk credential',
		'choices' => $choices,
		'help' => 'The site whose problems start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'Host',
		'default' => '',
		'help' => 'Its name; empty: every host',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]{1,253}$', 'skipEmpty' => true, 'message' => 'A host name' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'minState',
		'type' => 'select',
		'label' => 'Services',
		'required' => true,
		'default' => '2',
		'choices' => self::MIN,
		'help' => 'Hosts: DOWN and UNREACH',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'acknowledged',
		'type' => 'switch',
		'label' => 'Also acknowledged problems',
		'default' => false,
		'ui' => [ 'width' => 4, 'newRow' => true ],
	    ],
	    [
		'key' => 'downtime',
		'type' => 'switch',
		'label' => 'Also problems in a downtime',
		'default' => false,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'recoveries',
		'type' => 'switch',
		'label' => 'When it clears too',
		'default' => false,
		'help' => 'A run when a problem that started one is gone (event: recovery)',
		'ui' => [ 'width' => 4 ],
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
		'label' => 'Problem variable',
		'required' => true,
		'default' => 'cmk',
		'help' => 'e.g. {{ cmk.host_name }}, {{ cmk.service }}, {{ cmk.stateText }}, {{ cmk.event }}',
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'checkmk')))) {
	    $this->errors('config', 'Checkmk problem: choose a Checkmk credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'cmk') : 'cmk';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ problems, count }', 'fields' => [
		'problems' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a problem (or a recovery)' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the problem', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => 'problem or recovery' ], 'kind' => [ 'type' => 'string', 'info' => 'host or service' ],
	    'host_name' => [ 'type' => 'string' ], 'service' => [ 'type' => 'string', 'info' => 'null for a host problem' ],
	    'state' => [ 'type' => 'number' ], 'stateText' => [ 'type' => 'string', 'info' => 'e.g. CRIT, DOWN (a recovery: the last problem state)' ],
	    'output' => [ 'type' => 'string', 'info' => 'the check\'s output' ], 'since' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    'acknowledged' => [ 'type' => 'boolean' ], 'inDowntime' => [ 'type' => 'boolean' ] ] ] ];
    }

    //the key of a problem
    protected static function problemKey(array $p) {
	return $p['kind'] === 'host' ? $p['host_name'] : $p['host_name'] . '|' . $p['service'];
    }

    //the current problems of the step's site (acknowledged and in downtime too: they are left out of the runs, not of the state)
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'checkmk');
	if (!$cred)
	    throw new \Exception('Checkmk problem: the credential is no longer available');
	$host = trim((string)($cfg['host'] ?? ''));
	[ $problems ] = NodeAppCmkStatus::problems($cred->client($wf->aid, $timeout), $host !== '' ? $host : null, (int)($cfg['minState'] ?? 2), true, true, self::MAX_PROBLEMS);
	return $problems;
    }

    //whether problem $p may start a run with $cfg
    protected static function eligible(array $p, array $cfg) {
	return (!empty($cfg['acknowledged']) || !$p['acknowledged']) && (!empty($cfg['downtime']) || !$p['inDowntime']);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Checkmk problem: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { started: true, problems: { key: { state, fired, quiet } } }, the executions started ]; fired: handled (a run started, or noted by the
    //first check); quiet: noted by the first check (no run: no recovery either), until its state changes
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	$old = (array)($data['problems'] ?? []);
	$first = empty($data['started']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$current = self::current($wf, $cfg, self::TIMEOUT);

	$events = [];
	$now = [];
	foreach($current as $p) {
	    $key = self::problemKey($p);
	    $prev = $old[$key] ?? null;
	    $fire = !$first && self::eligible($p, $cfg) && ($prev === null || (int)$prev['state'] !== $p['state'] || empty($prev['fired']));
	    $same = $prev !== null && (int)$prev['state'] === $p['state'];
	    $quiet = $first || ($same && !empty($prev['quiet']) && !$fire);
	    if ($fire && count($events) < self::MAX_RUNS) {
		$events[] = [ 'event' => 'problem' ] + $p;
		$fired = true;
		$quiet = false;
	    } elseif ($fire)
		//over MAX_RUNS: not started, the next check starts it
		$fired = false;
	    else
		$fired = $first || ($same && !empty($prev['fired']));
	    $now[$key] = [ 'state' => $p['state'], 'fired' => $fired, 'quiet' => $quiet, 'kind' => $p['kind'], 'host_name' => $p['host_name'], 'service' => $p['service'],
		'stateText' => $p['stateText'] ];
	}
	if (!empty($cfg['recoveries']) && !$first) {
	    foreach($old as $key => $prev) {
		if (!isset($now[$key]) && !empty($prev['fired']) && empty($prev['quiet']) && count($events) < self::MAX_RUNS)
		    $events[] = [ 'event' => 'recovery', 'kind' => $prev['kind'] ?? 'service', 'host_name' => $prev['host_name'] ?? '', 'service' => $prev['service'] ?? null,
			'state' => (int)$prev['state'], 'stateText' => $prev['stateText'] ?? '', 'output' => '', 'since' => gmdate('Y-m-d\TH:i:s\Z'), 'acknowledged' => false, 'inDowntime' => false ];
	    }
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'cmk';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'problems' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'checkmk', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['problems']) ? 'Checkmk: ' . \util\Format::plural($v['count'], 'event')
		: "Checkmk {$v['event']}: {$v['host_name']}" . ($v['service'] !== null ? " / {$v['service']}" : '') . " {$v['stateText']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'started' => true, 'problems' => (object)$now ], $started ];
    }

    //Run in the designer: one run with the first current problem the step would start a run for; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $problems = array_values(array_filter(self::current($wf, $cfg, 30), fn($p) => self::eligible($p, $cfg)));
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Checkmk problem: ' . $e->getMessage() ];
	}
	if (!$problems)
	    return [ 'executions' => [], 'message' => 'Checkmk problem: no current problem matches' ];
	$p = [ 'event' => 'problem' ] + $problems[0];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'cmk';
	$exe = \models\wf\WfExecution::startFor($wf, 'checkmk', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch' ? [ 'problems' => [ $p ], 'count' => 1 ] : $p ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with a current problem: {$p['host_name']}" . ($p['service'] !== null ? " / {$p['service']}" : '') . " {$p['stateText']}" ];
    }
}
