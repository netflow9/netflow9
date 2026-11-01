<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new step: Veeam Jobs - the jobs of a Veeam Backup & Replication server. Operations (setting `operation`):
	list      the jobs (GET /api/v1/jobs: name pattern, type; all pages up to At most)
	states    the jobs' states (GET /api/v1/jobs/states: status, last result, last and next run)
	get       one job (GET /api/v1/jobs/{id})
	start     a job started (POST .../start: an active full or not): the session started
	stop      a running job stopped (POST .../stop: gracefully or at once)
	retry     a job retried (POST .../retry): the failed machines of its last run
	enable / disable
    The job: its id, or its name (exactly; NodeAppVeeam::jobId)
*/

namespace models\node;

class NodeAppVeeamJobs extends NodeAppVeeam {
    public static $name = 'veeam-jobs';
    public static $label = 'Veeam Jobs';
    public static $icon = 'bi-play-btn';
    public static $defaultOperation = 'states';
    public static $defaultVariable = 'jobs';

    const OPERATIONS = [
	'list' => 'List jobs',
	'states' => 'List job states',
	'get' => 'Get a job',
	'start' => 'Start a job',
	'stop' => 'Stop a job',
	'retry' => 'Retry a job',
	'enable' => 'Enable a job',
	'disable' => 'Disable a job',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'start', 'stop', 'retry', 'enable', 'disable' ];
    const LISTS = [ 'list', 'states' ];
    const ON_JOB = [ 'get', 'start', 'stop', 'retry', 'enable', 'disable' ];

    protected static function operationFields() {
	return [
	    self::field('nameFilter', 'Name', [ 'list', 'states' ], [ 'help' => 'Only jobs whose name matches; * at the start and / or end: any text (Daily*)', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('job', 'Job', self::ON_JOB, [ 'required' => true, 'help' => 'Its name (exactly) or its id', 'ui' => [ 'expression' => true ] ]),
	    self::field('activeFull', 'Active full backup', [ 'start' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('gracefully', 'Gracefully', [ 'stop' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Let the machines being processed finish', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$name = fn() => array_filter([ 'nameFilter' => $text('nameFilter') ]);
	switch($operation) {
	    case 'list':
		return $this->listOf('/api/v1/jobs', $name(), 'job');
	    case 'states':
		return $this->listOf('/api/v1/jobs/states', $name(), 'job');
	}
	$id = $this->jobId($value('job'));
	$what = 'job ' . $text('job');
	switch($operation) {
	    case 'get':
		$r = $this->get("/api/v1/jobs/{$id}");
		return [ $r, 'job ' . ($r['name'] ?? $id) ];
	    case 'start':
		$r = $this->post("/api/v1/jobs/{$id}/start", [ 'performActiveFull' => (bool)$this->cfg('activeFull', false) ]);
		return [ $r, "{$what} started" . (isset($r['id']) ? ", session {$r['id']}" : '') ];
	    case 'stop':
		return [ $this->post("/api/v1/jobs/{$id}/stop", [ 'gracefully' => (bool)$this->cfg('gracefully', true) ]), "{$what} stopping" ];
	    case 'retry':
		$r = $this->post("/api/v1/jobs/{$id}/retry", []);
		return [ $r, "{$what} retried" . (isset($r['id']) ? ", session {$r['id']}" : '') ];
	    case 'enable':
		return [ $this->post("/api/v1/jobs/{$id}/enable"), "{$what} enabled" ];
	    case 'disable':
		return [ $this->post("/api/v1/jobs/{$id}/disable"), "{$what} disabled" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
