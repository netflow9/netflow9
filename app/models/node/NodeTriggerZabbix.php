<?php

/*
    Zabbix problem - starts the workflow when Zabbix reports a new problem of a severity and worse, of hosts / host groups, with tags;
    optionally also when one is resolved. It checks the server on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled): every current
    problem is read (acknowledged and suppressed ones too, so they are not taken as resolved) and compared with the last check's (models\TriggerState: eventid => { severity,
    fired, quiet }): a problem starts a run when it is new or its severity changed, unless it is acknowledged or suppressed and those are left out; a problem that started a run
    and is gone starts a `recovery` run when chosen. The first check only notes the current problems. The variable: the problem (NodeAppZbxProblems::problem + event: problem |
    recovery), or { problems, count } per check; at most MAX_RUNS per check
*/

namespace models\node;

class NodeTriggerZabbix extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'zabbix';
    public static $label = 'Zabbix problem';
    public static $icon = 'bi-speedometer2';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RUNS = [ 'each' => 'One run per problem', 'batch' => 'One run per check with problems' ];
    const MAX_RUNS = 200;
    const MAX_PROBLEMS = 5000;
    const TIMEOUT = 60;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('zabbix', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Zabbix credential',
		'choices' => $choices,
		'help' => 'The server whose problems start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'minSeverity',
		'type' => 'select',
		'label' => 'Severity',
		'required' => true,
		'default' => '3',
		'choices' => NodeAppZbxProblems::MIN,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'hosts',
		'type' => 'text',
		'label' => 'Hosts',
		'default' => '',
		'help' => 'Their technical names, separated by commas; empty: every host',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'groups',
		'type' => 'text',
		'label' => 'Host groups',
		'default' => '',
		'help' => 'Their names, separated by commas; empty: every group',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'tags',
		'type' => 'text',
		'label' => 'Tags',
		'default' => '',
		'help' => 'tag=value, separated by commas; all must match',
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
		'key' => 'suppressed',
		'type' => 'switch',
		'label' => 'Also suppressed problems',
		'default' => false,
		'help' => 'In a maintenance',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'recoveries',
		'type' => 'switch',
		'label' => 'When it is resolved too',
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
		'default' => 'zbx',
		'help' => 'e.g. {{ zbx.name }}, {{ zbx.host }}, {{ zbx.severityText }}, {{ zbx.eventid }}, {{ zbx.event }}',
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'zabbix')))) {
	    $this->errors('config', 'Zabbix problem: choose a Zabbix credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'zbx') : 'zbx';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ problems, count }', 'fields' => [
		'problems' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a problem (or a recovery)' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the problem', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => 'problem or recovery' ], 'eventid' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string', 'info' => 'the problem\'s name' ],
	    'severity' => [ 'type' => 'number', 'info' => '0 (Not classified) to 5 (Disaster)' ], 'severityText' => [ 'type' => 'string', 'info' => 'e.g. High' ],
	    'host' => [ 'type' => 'string', 'info' => 'the first host\'s technical name' ], 'hostName' => [ 'type' => 'string', 'info' => 'its visible name' ],
	    'hosts' => [ 'type' => 'array', 'info' => '[ { hostid, host, name } ]' ], 'since' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    'acknowledged' => [ 'type' => 'boolean' ], 'suppressed' => [ 'type' => 'boolean' ], 'opdata' => [ 'type' => 'string', 'info' => 'operational data' ],
	    'tags' => [ 'type' => 'object', 'info' => '{ tag: value }' ], 'triggerid' => [ 'type' => 'string' ] ] ] ];
    }

    //the current problems the step watches (acknowledged and suppressed too: they are left out of the runs, not of the state)
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'zabbix');
	if (!$cred)
	    throw new \Exception('Zabbix problem: the credential is no longer available');
	$z = $cred->client($wf->aid, $timeout);
	$hosts = NodeAppZabbix::names((string)($cfg['hosts'] ?? ''));
	$groups = NodeAppZabbix::names((string)($cfg['groups'] ?? ''));
	[ $problems ] = NodeAppZbxProblems::problems($z, (int)($cfg['minSeverity'] ?? 3), $hosts ? $z->hostIds($hosts) : [], $groups ? $z->groupIds($groups) : [],
	    NodeAppZabbix::tags((string)($cfg['tags'] ?? '')), true, true, self::MAX_PROBLEMS);
	return $problems;
    }

    //whether problem $p may start a run with $cfg
    protected static function eligible(array $p, array $cfg) {
	return (!empty($cfg['acknowledged']) || !$p['acknowledged']) && (!empty($cfg['suppressed']) || !$p['suppressed']);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Zabbix problem: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { started: true, problems: { eventid: { severity, fired, quiet, ... } } }, the executions started ]; fired: handled (a run started, or noted
    //by the first check); quiet: noted by the first check (no run: no recovery either), until its severity changes
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
	    $key = $p['eventid'];
	    $prev = (array)($old[$key] ?? []) ?: null;
	    $fire = !$first && self::eligible($p, $cfg) && ($prev === null || (int)$prev['severity'] !== $p['severity'] || empty($prev['fired']));
	    $same = $prev !== null && (int)$prev['severity'] === $p['severity'];
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
	    $now[$key] = [ 'severity' => $p['severity'], 'fired' => $fired, 'quiet' => $quiet, 'name' => $p['name'], 'host' => $p['host'], 'hostName' => $p['hostName'],
		'hosts' => $p['hosts'], 'severityText' => $p['severityText'], 'triggerid' => $p['triggerid'] ];
	}
	if (!empty($cfg['recoveries']) && !$first) {
	    foreach($old as $key => $prev) {
		$prev = (array)$prev;
		if (!isset($now[$key]) && !empty($prev['fired']) && empty($prev['quiet']) && count($events) < self::MAX_RUNS)
		    $events[] = [ 'event' => 'recovery', 'eventid' => (string)$key, 'name' => (string)($prev['name'] ?? ''), 'severity' => (int)($prev['severity'] ?? 0),
			'severityText' => (string)($prev['severityText'] ?? ''), 'hosts' => (array)($prev['hosts'] ?? []), 'host' => (string)($prev['host'] ?? ''),
			'hostName' => (string)($prev['hostName'] ?? ''), 'since' => gmdate('Y-m-d\TH:i:s\Z'), 'acknowledged' => false, 'suppressed' => false, 'opdata' => '',
			'tags' => (object)[], 'triggerid' => (string)($prev['triggerid'] ?? '') ];
	    }
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'zbx';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'problems' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'zabbix', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['problems']) ? 'Zabbix: ' . \util\Format::plural($v['count'], 'event')
		: "Zabbix {$v['event']}: {$v['host']}: {$v['name']} ({$v['severityText']})");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'started' => true, 'problems' => (object)$now ], $started ];
    }

    //Run in the designer: one run with the newest current problem the step would start a run for; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $problems = array_values(array_filter(self::current($wf, $cfg, 30), fn($p) => self::eligible($p, $cfg)));
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Zabbix problem: ' . $e->getMessage() ];
	}
	if (!$problems)
	    return [ 'executions' => [], 'message' => 'Zabbix problem: no current problem matches' ];
	$p = [ 'event' => 'problem' ] + $problems[0];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'zbx';
	$exe = \models\wf\WfExecution::startFor($wf, 'zabbix', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch' ? [ 'problems' => [ $p ], 'count' => 1 ] : $p ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with a current problem: {$p['host']}: {$p['name']} ({$p['severityText']})" ];
    }
}
