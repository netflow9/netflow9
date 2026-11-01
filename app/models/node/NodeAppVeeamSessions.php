<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new step: Veeam Sessions - the job sessions (runs) of a Veeam Backup & Replication server. Operations (setting `operation`):
	list      the sessions (GET /api/v1/sessions; newest first): of a job, with these results, of this state, created since; all pages up to At most
	get       one session (GET /api/v1/sessions/{id}): state, progress, result { result, message, isCanceled }
	tasks     its task sessions: one per machine (GET .../taskSessions)
	logs      its log (GET .../logs)
	stop      a running session stopped (POST .../stop)
	wait      waits until the session has stopped (GET every POLL seconds, within the step's Timeout): the session; still running at the timeout: an error
*/

namespace models\node;

class NodeAppVeeamSessions extends NodeAppVeeam {
    public static $name = 'veeam-sessions';
    public static $label = 'Veeam Sessions';
    public static $icon = 'bi-list-check';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'sessions';

    const OPERATIONS = [
	'list' => 'List sessions',
	'get' => 'Get a session',
	'tasks' => 'List its task sessions',
	'logs' => 'Get its log',
	'stop' => 'Stop a session',
	'wait' => 'Wait for a session to finish',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'stop' ];
    const LISTS = [ 'list', 'tasks' ];
    const RESULTS = [ 'Success' => 'Success', 'Warning' => 'Warning', 'Failed' => 'Failed', 'None' => 'None (still running)' ];
    const STATES = [ '' => 'Any', 'Working' => 'Working', 'Stopped' => 'Stopped', 'Starting' => 'Starting', 'Stopping' => 'Stopping', 'Idle' => 'Idle', 'Postprocessing' => 'Postprocessing' ];
    //seconds between two reads of a session (wait)
    const POLL = 10;

    protected static function operationFields() {
	$one = [ 'get', 'tasks', 'logs', 'stop', 'wait' ];
	return [
	    self::field('job', 'Job', [ 'list' ], [ 'help' => 'Its name (exactly) or its id; empty: every job', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('since', 'Created since', [ 'list' ], [ 'help' => 'A date / time, or e.g. -24 hours, yesterday; empty: any time', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('results', 'Results', [ 'list' ], [ 'type' => 'select', 'multiple' => true, 'default' => [], 'choices' => self::RESULTS, 'help' => 'None chosen: any', 'ui' => [ 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'default' => '', 'choices' => self::STATES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('session', 'Session', $one, [ 'required' => true, 'help' => 'The session\'s id (e.g. {{ jobs.id }} after Start a job)', 'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $q = [ 'orderColumn' => 'CreationTime', 'orderAsc' => false ];
	    if ($text('job') !== '')
		$q['jobIdFilter'] = $this->jobId($value('job'));
	    if (($since = self::when($value('since'), 'Created since')) !== null)
		$q['createdAfterFilter'] = $since;
	    $results = array_values(array_intersect(array_map('strval', (array)$this->cfg('results', [])), array_keys(self::RESULTS)));
	    if ($results)
		$q['resultFilter'] = $results;
	    if (isset(self::STATES[(string)$this->cfg('state', '')]) && (string)$this->cfg('state', '') !== '')
		$q['stateFilter'] = (string)$this->cfg('state');
	    return $this->listOf('/api/v1/sessions', $q, 'session');
	}
	$id = self::uuid(is_array($value('session')) ? ($value('session')['id'] ?? '') : $value('session'), 'Session');
	switch($operation) {
	    case 'get':
		$r = $this->get("/api/v1/sessions/{$id}");
		return [ $r, self::describe($r) ];
	    case 'tasks':
		return $this->listOf("/api/v1/sessions/{$id}/taskSessions", [], 'task session');
	    case 'logs':
		$r = $this->get("/api/v1/sessions/{$id}/logs");
		return [ $r, \util\Format::plural(count((array)($r['records'] ?? [])), 'log record') ];
	    case 'stop':
		return [ $this->post("/api/v1/sessions/{$id}/stop"), "session {$id} stopping" ];
	    case 'wait':
		$deadline = time() + max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))) - 2;
		while(true) {
		    $r = $this->get("/api/v1/sessions/{$id}");
		    if ((string)($r['state'] ?? '') === 'Stopped')
			return [ $r, self::describe($r) ];
		    if (time() + self::POLL > $deadline)
			throw new \RuntimeException("session {$id} is still running (" . self::describe($r) . '): raise the step\'s Timeout');
		    sleep(self::POLL);
		}
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a session for the log: its name, state, progress and result
    public static function describe($s) {
	if (!is_array($s))
	    return 'session';
	$result = (string)($s['result']['result'] ?? '');
	return trim(($s['name'] ?? 'session') . ': ' . ($s['state'] ?? '?') . (isset($s['progressPercent']) && ($s['state'] ?? '') !== 'Stopped' ? " {$s['progressPercent']}%" : '')
	    . ($result !== '' && $result !== 'None' ? ", {$result}" : ''));
    }
}
