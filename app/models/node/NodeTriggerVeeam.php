<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new trigger: Veeam event - starts the workflow when job sessions of a Veeam Backup & Replication server end with the results chosen (Failed, Warning,
    Success), of a job or of every job. Veeam does not call other systems: the step checks the server on its schedule (models\WfSchedule, every 5 minutes by default; util\Scheduler
    runs runScheduled), reading the sessions ended since its last check (GET /api/v1/sessions: endedAfterFilter, stateFilter Stopped, resultFilter, jobIdFilter; oldest first).
    What it has read is kept in models\TriggerState (the last end time, and the sessions already started at that time), locked during a check. Its first check only notes the
    time: sessions that ended before the trigger was published do not start runs. Runs: one per session (the variable: the session, as Veeam gives it) or one per check with
    sessions ({ sessions, count }); at most MAX_RUNS per check (the others: the next check)
*/

namespace models\node;

class NodeTriggerVeeam extends \models\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'veeam';
    public static $label = 'Veeam event';
    public static $icon = 'bi-hdd-stack';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RESULTS = [ 'Failed' => 'Failed', 'Warning' => 'Warning', 'Success' => 'Success' ];
    const RUNS = [ 'each' => 'One run per session', 'batch' => 'One run per check with sessions' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 120;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('veeam', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Veeam credential',
		'choices' => $choices,
		'help' => 'The backup server whose sessions start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'results',
		'type' => 'select',
		'label' => 'Sessions ended with',
		'multiple' => true,
		'required' => true,
		'default' => [ 'Failed', 'Warning' ],
		'choices' => self::RESULTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'job',
		'type' => 'text',
		'label' => 'Job',
		'default' => '',
		'help' => 'Its name (exactly) or its id; empty: every job',
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
		'label' => 'Session variable',
		'required' => true,
		'default' => 'veeam',
		'help' => 'e.g. {{ veeam.name }}, {{ veeam.result.result }}, {{ veeam.result.message }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'veeam')))) {
	    $this->errors('config', 'Veeam event: choose a Veeam Backup & Replication credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'veeam') : 'veeam';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ sessions, count }', 'fields' => [
		'sessions' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a session, as Veeam gives it' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the session, as Veeam gives it', 'fields' => [
	    'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string', 'info' => 'the job\'s name' ], 'jobId' => [ 'type' => 'string' ],
	    'creationTime' => [ 'type' => 'string' ], 'endTime' => [ 'type' => 'string' ],
	    'result' => [ 'type' => 'object', 'info' => '{ result: Success / Warning / Failed, message, isCanceled }' ] ] ] ];
    }

    //on its schedule (util\Scheduler::run): the sessions ended since the last check
    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Veeam event: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data, the executions started ]; $data: { since (ISO 8601), seen: [ ids of the sessions ended at `since` already started ] }
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($data['since']))
	    return [ [ 'since' => gmdate('Y-m-d\TH:i:s\Z'), 'seen' => [] ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'veeam');
	if (!$cred)
	    throw new \Exception('Veeam event: the credential is no longer available');
	$vbr = $cred->client($wf->aid, self::TIMEOUT);

	$results = array_values(array_intersect(array_map('strval', (array)($cfg['results'] ?? [])), array_keys(self::RESULTS)));
	$q = [ 'endedAfterFilter' => (string)$data['since'], 'stateFilter' => 'Stopped', 'orderColumn' => 'EndTime', 'orderAsc' => true, 'resultFilter' => $results ?: array_keys(self::RESULTS) ];
	$job = trim((string)($cfg['job'] ?? ''));
	if ($job !== '')
	    $q['jobIdFilter'] = preg_match(NodeAppVeeam::UUID, $job) ? strtolower($job) : self::jobIdByName($vbr, $job);
	[ $sessions ] = $vbr->all('/api/v1/sessions', $q, 2000);

	$seen = array_flip(array_map('strval', (array)($data['seen'] ?? [])));
	$since = (string)$data['since'];
	$new = [];
	foreach($sessions as $s) {
	    $id = (string)($s['id'] ?? '');
	    $end = (string)($s['endTime'] ?? '');
	    if ($id === '' || isset($seen[$id]) || ($end !== '' && strtotime($end) < strtotime($since)))
		continue;
	    $new[] = $s;
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	//the next check starts at the newest end time read, remembering the sessions that ended at that very time
	$last = $since;
	$lastIds = array_keys($seen);
	foreach($new as $s) {
	    $end = (string)($s['endTime'] ?? $since);
	    if (strtotime($end) > strtotime($last)) {
		$last = $end;
		$lastIds = [];
	    }
	    if (strtotime($end) === strtotime($last))
		$lastIds[] = (string)$s['id'];
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'veeam';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'sessions' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\WfExecution::startFor($wf, 'veeam', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['sessions']) ? 'Veeam: ' . \util\Format::plural($v['count'], 'session') . ' ended' : 'Veeam: ' . NodeAppVeeamSessions::describe($v));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'since' => $last, 'seen' => array_values(array_unique($lastIds)) ], $started ];
    }

    //Run in the designer: one run with the newest session that ended with the results chosen (of the job), to try the workflow; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'veeam');
	if (!$cred)
	    return [ 'executions' => [], 'message' => 'Veeam event: choose a Veeam Backup & Replication credential first' ];
	try {
	    $vbr = $cred->client($wf->aid, 30);
	    $results = array_values(array_intersect(array_map('strval', (array)($cfg['results'] ?? [])), array_keys(self::RESULTS)));
	    $q = [ 'stateFilter' => 'Stopped', 'orderColumn' => 'EndTime', 'orderAsc' => false, 'resultFilter' => $results ?: array_keys(self::RESULTS), 'limit' => 1 ];
	    $job = trim((string)($cfg['job'] ?? ''));
	    if ($job !== '')
		$q['jobIdFilter'] = preg_match(NodeAppVeeam::UUID, $job) ? strtolower($job) : self::jobIdByName($vbr, $job);
	    $session = $vbr->request('GET', '/api/v1/sessions', $q)['data'][0] ?? null;
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Veeam event: ' . $e->getMessage() ];
	}
	if (!is_array($session))
	    return [ 'executions' => [], 'message' => 'Veeam event: no session ended with these results yet' ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'veeam';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'sessions' => [ $session ], 'count' => 1 ] : $session;
	$exe = \models\WfExecution::startFor($wf, 'veeam', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => 'Run with the newest matching session: ' . NodeAppVeeamSessions::describe($session) ];
    }

    protected static function jobIdByName($vbr, $name) {
	[ $jobs ] = $vbr->all('/api/v1/jobs', [ 'nameFilter' => $name ], 500);
	foreach($jobs as $j) {
	    if (strcasecmp((string)($j['name'] ?? ''), $name) === 0)
		return (string)$j['id'];
	}
	throw new \Exception("Veeam event: no job named `{$name}`");
    }
}
