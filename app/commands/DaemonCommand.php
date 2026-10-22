<?php

/*
    app/v.php daemon::run [--debug] [--logfile=daemon] [--worker-logfile=daemon_worker]
    app/v.php daemon::run --worker [--debug] [--logfile=...]       a worker (started by the master; talks to it over stdin/stdout)

    options:
	--min-workers=2            started at once and always kept
	--max-workers=2 x CPUs - 1 more are started while allowed jobs wait; the extra ones stop after --idle-timeout=60 s without work
	--max-per-account=max - 1  running jobs of one account (models\Job::aid); 0 = no limit
	--max-per-project=max - 1  running jobs of one project (models\Job::project); 0 = no limit
	--job-timeout=0            seconds a job may run before its worker is killed; 0 = no limit
	--max-worker-memory=256 (MB): a worker over it (after a job, or while idle) exits and the master starts a new one;
*/
namespace commands;

class DaemonCommand extends \sys\BaseController {
    //workers info: pid => [ process, state START|IDLE|ASSIGNED|BUSY|STOP, jid, aid, project, created_at, updated_at, idle_since, buffer ]
    public $workers = [];

    //the config
    public $config = [];

    //the jobs collection (raw)
    public $jobs_coll = null;

    //the worker command
    public $worker_cmd = '';

    //ReactPHP's loop
    public $loop = null;

    //for cron-like scheduler
    public $timer = null;

    //whether is dispatching
    public $dispatching = false;
    public $last_dispatch_time = 0;
    public $new_jobs_count = 0;

    //the daemon is shutting down: no new workers, no dispatching
    public $stopping = false;

    //for alerting
    public $prev_jobs_count = 0;

    //the job socket (stream server) and its connections (id => [ stream, buffer ])
    public $job_listener = null;
    public $job_conns = [];

    //worker mode
    public $pid = null;

    public $stdin;
    public $stdout;

    public $job_counter = 0;

    //the worker is leaving (over its memory limit)
    public $exiting = false;
    public $crt_job_id = null;

    public $base_process_title = '';

    public $master_last_seen = 0;

    //partial line from the master (worker mode)
    public $stdin_buffer = '';


    //-------------------------------------------------------------------
    //worker related functions: start, kill, dispatch jobs
    public function kill_worker($pid) {
	if (empty($this->workers[$pid]))
	    return;

	$process = $this->workers[$pid]['process'];
	$this->workers[$pid]['state'] = 'STOP';
	\V::app()->log->debug("WMGR: worker {$pid} will get SIGTERM");
	$process->terminate(SIGTERM);

	$this->loop->addTimer(2, function() use ($process, $pid) {
	    if ($process->isRunning()) {
		\V::app()->log->debug("WMGR: worker {$pid} still running after SIGTERM, send SIGKILL");
		posix_kill($pid, SIGKILL);
	    }
	});
    }

    //the worker's job goes back to the queue (state new) or fails after 3 runs; used when a worker exits
    //or is killed while it has a job
    protected function reap_job($jid, $reason) {
	if (empty($jid))
	    return;
	$job = $this->jobs_coll->findOne([ '_id' => \V::app()->om->to_oid($jid), 'state' => 'run' ]);
	if (empty($job))
	    return;

	$failed = (int)($job['run_count'] ?? 0) >= 3;
	\V::app()->log->warn("WMGR: job {$jid}: {$reason}, " . ($failed ? 'failed after 3 runs' : 'queued again'));
	//a job given up (its worker died or timed out 3 times) in the account's execution log
	if ($failed && !empty($job['aid']))
	    \models\ExecutionLog::record('job.failed', null, "job {$jid} (" . ($job['job_spec'] ?? '') . "): {$reason}, failed after 3 runs", [], null, $job['aid']);
	$this->jobs_coll->updateOne(
	    [ '_id' => $job['_id'], 'state' => 'run' ],
	    [ '$set' => $failed
		? [ 'state' => 'fail', 'error' => $reason, 'finished_at' => new \MongoDB\BSON\UTCDateTime() ]
		: [ 'state' => 'new', 'worker_pid' => null ] ],
	    [ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	);
    }

    public function start_worker() {
	\V::app()->log->info("WMGR: create new worker...");
	$process = new \React\ChildProcess\Process($this->worker_cmd);

	//start the process
	//CLD: 2026-10-08T21:36:05+03:00: stop here when it cannot start (the pipes below did not exist)
	try {
	    $process->start($this->loop);
	} catch (\RuntimeException $e) {
	    \V::app()->log->error("WMGR: could not create worker: " . $e->getMessage());
	    return false;
	}

	$pid = $process->getPid();

	//on exit
	$process->on('exit', function ($code, $term) use ($pid) {
	    if ($code > 0)
		\V::app()->log->warn("WMGR: worker with pid:{$pid} exited with code: $code");

	    //reap hung-up jobs
	    if (!empty($this->workers[$pid]['jid']))
		$this->reap_job($this->workers[$pid]['jid'], "worker {$pid} exited while running it");

	    unset($this->workers[$pid]);

	    //keep the minimum; its slot may let a waiting event run
	    if (!$this->stopping) {
		$this->ensure_workers();
		$this->dispatch_jobs();
	    }
	});

	$process->stdout->on('data', function($chunk) use ($pid) {
	    if (empty($this->workers[$pid]))
		return;
	    $this->workers[$pid]['buffer'] .= $chunk;
	    while (!empty($this->workers[$pid]) && ($nl = strpos($this->workers[$pid]['buffer'], "\n")) !== false) {
		$msg = trim(substr($this->workers[$pid]['buffer'], 0, $nl));
		$this->workers[$pid]['buffer'] = substr($this->workers[$pid]['buffer'], $nl + 1);
		if ($msg !== '')
		    $this->worker_message($pid, $msg);
	    }
	});

	$this->workers[$pid] = [
	    'process' => $process,
	    'state' => 'START',
	    'created_at' => microtime(true),
	    'updated_at' => microtime(true),
	    'idle_since' => null,
	    'jid' => null,
	    'aid' => null,
	    'project' => null,
	    'buffer' => '',
	];
	return true;
    }

    //one message of worker $pid (see the protocol at the top)
    protected function worker_message($pid, $msg) {
	$w = &$this->workers[$pid];
	$cmd = explode(':', $msg, 2);
	$jid = trim($cmd[1] ?? '');

	switch($cmd[0]) {
	    case 'PING':
		$w['process']->stdin->write("PONG\n");
		return;

	    //the worker took the job
	    case 'BUSY':
		if ($jid !== '' && $jid === $w['jid'] && $w['state'] != 'STOP') {
		    $w['state'] = 'BUSY';
		    $w['updated_at'] = microtime(true);
		}
		return;

	    //the event no longer exists / is not runnable: the worker skipped it
	    case 'RELEASE':
		\V::app()->log->warn("WMGR: worker {$pid} released job {$jid}");
		if ($jid !== '' && \util\Validator::rule('oid', $jid))
		    $this->jobs_coll->updateOne(
			[ '_id' => \V::app()->om->to_oid($jid), 'state' => 'run', 'worker_pid' => $pid ],
			[ '$set' => [ 'state' => 'new', 'worker_pid' => null ] ]
		    );
		//fall through: the worker is free
	    case 'DONE':
	    case 'IDLE':
		$w['jid'] = $w['aid'] = $w['project'] = null;
		//a worker being stopped gets no more work
		if ($w['state'] == 'STOP')
		    return;
		$w['state'] = 'IDLE';
		$w['updated_at'] = microtime(true);
		$w['idle_since'] = microtime(true);
		$this->dispatch_jobs();
		return;

	    //the worker leaves (over its memory limit): no more work for it; its exit starts a new one
	    case 'EXIT':
		\V::app()->log->info("WMGR: worker {$pid} is leaving (memory limit)");
		$w['state'] = 'STOP';
		return;

	    default:
		\V::app()->log->warn("WMGR: unknown message from worker {$pid}: {$msg}");
	}
    }

    //the query of events this daemon may run now: new, not of an account / project at its limit
    protected function allowed_jobs_query() {
	$query = [ 'state' => 'new' ];

	//running events per account / project (assigned or busy workers)
	$per_aid = $per_project = [];
	foreach($this->workers as $w) {
	    if (empty($w['jid']))
		continue;
	    if (!empty($w['aid']))
		$per_aid[$w['aid']] = ($per_aid[$w['aid']] ?? 0) + 1;
	    if (!empty($w['project']))
		$per_project[$w['project']] = ($per_project[$w['project']] ?? 0) + 1;
	}

	//$nin also matches events without aid / project (system work: not limited)
	$full = fn($counts, $limit) => $limit > 0 ? array_map(fn($id) => \V::app()->om->to_oid($id), array_keys(array_filter($counts, fn($n) => $n >= $limit))) : [];
	//CLD: 2026-10-22T23:00:00+03:00: + the suspended accounts (models\Account::isSuspended): their jobs wait in the queue
	$aids = array_merge($full($per_aid, (int)$this->config['max-per-account']), array_map(fn($id) => \V::app()->om->to_oid($id), array_keys(\models\Account::suspendedIds())));
	if ($aids)
	    $query['aid'] = [ '$nin' => $aids ];
	if ($projects = $full($per_project, (int)$this->config['max-per-project']))
	    $query['project'] = [ '$nin' => $projects ];

	return $query;
    }

    //hand out allowed events to idle workers until there is no idle worker or no allowed event
    //when events wait and no worker is idle, more workers are started (up to --max-workers)
    public function dispatch_jobs() {
	if ($this->stopping)
	    return;
	if ($this->dispatching) {
	    return;
	}

	//mark
	$this->dispatching = true;
	$this->last_dispatch_time = time();

	try {
	    while (true) {
		$idle_pids = array_keys(array_filter($this->workers, fn($w) => $w['state'] == 'IDLE'));

		if (empty($idle_pids)) {
		    $this->grow_workers();
		    break;
		}

		//claim the oldest allowed event for this worker
		$pid = $idle_pids[0];
		$job = $this->jobs_coll->findOneAndUpdate(
		    $this->allowed_jobs_query(),
		    [
			'$set' => [
			    'state' => 'run',
			    'worker_pid' => $pid,
			    'start_run_at' => new \MongoDB\BSON\UTCDateTime(),
			],
			'$inc' => [ 'run_count' => 1 ],
		    ],
		    [
			'sort' => [ '_id' => 1 ],
			'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY),
		    ]
		);
		if (!$job)
		    break;

		$jid = (string)$job['_id'];
		$this->workers[$pid]['state'] = 'ASSIGNED';
		$this->workers[$pid]['jid'] = $jid;
		$this->workers[$pid]['aid'] = empty($job['aid']) ? null : (string)$job['aid'];
		$this->workers[$pid]['project'] = empty($job['project']) ? null : (string)$job['project'];
		$this->workers[$pid]['updated_at'] = microtime(true);
		$this->workers[$pid]['idle_since'] = null;
		$this->workers[$pid]['process']->stdin->write("NEWJOB:{$jid}\n");
	    }
	} finally {
	    $this->dispatching = false;
	}
    }

    //--min-workers always run
    protected function ensure_workers() {
	if ($this->stopping)
	    return;
	while (count($this->workers) < $this->config['min-workers']) {
	    if (!$this->start_worker())
		break;
	}
    }

    //no idle worker: start one more per waiting allowed event (counting the ones still starting), up to --max-workers
    protected function grow_workers() {
	$room = (int)$this->config['max-workers'] - count($this->workers);
	if ($room <= 0 || $this->stopping)
	    return;
	$starting = count(array_filter($this->workers, fn($w) => $w['state'] == 'START'));
	$waiting = $this->jobs_coll->countDocuments($this->allowed_jobs_query(), [ 'limit' => $room + $starting ]);
	for ($i = 0; $i < min($room, $waiting - $starting); $i++) {
	    if (!$this->start_worker())
		break;
	}
    }

    //workers above --min-workers that were idle for --idle-timeout seconds stop; events running longer
    //than --job-timeout (when set) are given up: their worker is killed (the exit handler queues them again)
    protected function check_workers() {
	$now = microtime(true);
	$extra = count(array_filter($this->workers, fn($w) => $w['state'] != 'STOP')) - (int)$this->config['min-workers'];
	foreach($this->workers as $pid => $w) {
	    if ($extra > 0 && $w['state'] == 'IDLE' && $w['idle_since'] && $now - $w['idle_since'] > $this->config['idle-timeout']) {
		\V::app()->log->info("WMGR: worker {$pid} idle for more than {$this->config['idle-timeout']} s, stop it");
		$this->kill_worker($pid);
		$extra--;
		continue;
	    }
	    $timeout = (int)$this->config['job-timeout'];
	    if ($timeout > 0 && in_array($w['state'], [ 'ASSIGNED', 'BUSY' ]) && $now - $w['updated_at'] > $timeout) {
		\V::app()->log->warn("WMGR: worker {$pid}: job {$w['jid']} runs for more than {$timeout} s, kill it");
		$this->kill_worker($pid);
	    }
	}
    }

    private function timer_tick() {
	//each minute is now the `daemon:tick` hook;
	// Schedule triggers, sleeping executions, IMAP checks) attach to it and queue their work with \models\Job::submit()
	$now_ts = new \MongoDB\BSON\UTCDateTime();
	$time = time();
	//a failing handler must not stop the daemon; then hand out what the handlers queued
	try {
	    \V::app()->hooks->applyHook('daemon:tick', [ $now_ts ]);
	} catch (\Throwable $e) {
	    \models\Job::reportError('daemon:tick failed', $e);
	}
	$this->dispatch_jobs();

        if ($time % 60 != 0) {
    	    \V::app()->log->warn("Timer tick: needs re-align, offset=" . $time % 60);
    	    $this->scheduler();
    	}
    }

    //install a 60s periodic timer, aligned at second 0 of minute
    public function scheduler() {
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

	//seconds from time
        $second = time() % 60;

        if ($second != 0) {
            $this->timer = $this->loop->addTimer(60 - $second, function () {
                $this->timer = null;
                $this->scheduler();
            });
        } else {
    	    $this->timer = $this->loop->addPeriodicTimer(60, function () {
        	$this->timer_tick();
    	    });
    	    $this->timer_tick();
    	}
    }

    //the job socket is a plain unix stream server watched by the loop
    //one command per line: NEWJOB, KILLJOB:<event id>
    public function setup_job_listener() {
	//create the local unix-domain socket for job commands
	$job_socket = \V::app()->config('app.job_socket_path');

	//if file exists and job_listener
	if (file_exists($job_socket) && !empty($this->job_listener))
	    return;

	if (!empty($this->job_listener))
	    $this->close_job_listener();

	@unlink($job_socket);
	$server = @stream_socket_server('unix://' . $job_socket, $errno, $errstr);
	if (!$server) {
	    \V::app()->log->error("WMGR: cannot listen on {$job_socket}: {$errstr}");
	    return;
	}
	stream_set_blocking($server, false);
	$this->job_listener = $server;

	$this->loop->addReadStream($server, function($server) {
	    $conn = @stream_socket_accept($server, 0);
	    if (!$conn)
		return;
	    stream_set_blocking($conn, false);
	    $id = (int)$conn;
	    $this->job_conns[$id] = [ 'stream' => $conn, 'buffer' => '' ];

	    $this->loop->addReadStream($conn, function($conn) use ($id) {
		$chunk = fread($conn, 8192);
		if ($chunk === '' || $chunk === false) {
		    //closed: a last command may come without a newline
		    $rest = trim($this->job_conns[$id]['buffer'] ?? '');
		    $this->loop->removeReadStream($conn);
		    fclose($conn);
		    unset($this->job_conns[$id]);
		    if ($rest !== '')
			$this->job_command($rest);
		    return;
		}
		$this->job_conns[$id]['buffer'] .= $chunk;
		while (($nl = strpos($this->job_conns[$id]['buffer'], "\n")) !== false) {
		    $line = trim(substr($this->job_conns[$id]['buffer'], 0, $nl));
		    $this->job_conns[$id]['buffer'] = substr($this->job_conns[$id]['buffer'], $nl + 1);
		    if ($line !== '')
			$this->job_command($line);
		}
	    });
	});
	//change mode so that anyone can connect
	chmod($job_socket, 0777);
    }

    //one command from the job
    protected function job_command($message) {
	\V::app()->log->debug("WMGR: received command: $message");
	$cmd = explode(':', $message, 2);
	switch($cmd[0]) {
	    case 'KILLJOB':
		$jid = trim($cmd[1] ?? '');
		if ($jid === '') {
		    \V::app()->log->debug("WMGR: KILLJOB: no job to kill");
		    return;
		}
		foreach($this->workers as $pid => $w) {
		    if ($w['jid'] == $jid)
			$this->kill_worker($pid);
		}
		break;
	    case 'NEWJOB':
		//submit job
		$this->dispatch_jobs();
		break;

	    default:
		\V::app()->log->warn("WMGR: do not know how to handle command: $message");
		break;
	}
    }

    public function close_job_listener() {
	foreach($this->job_conns as $c) {
	    $this->loop->removeReadStream($c['stream']);
	    @fclose($c['stream']);
	}
	$this->job_conns = [];

	if (!empty($this->job_listener)) {
	    $this->loop->removeReadStream($this->job_listener);
	    @fclose($this->job_listener);
	    $this->job_listener = null;
	}

	//create the local unix-domain socket for job commands
	$job_socket = \V::app()->config('app.job_socket_path');
	@unlink($job_socket);
    }

    //number of CPUs (for the --max-workers default)
    protected static function cpu_count() {
	$n = 2;
	if (is_readable('/proc/cpuinfo'))
	    $n = preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo'));
	return max(1, $n);
    }

    public function run_master() {
	//what this process changes is recorded in the execution log as the daemon's
	\models\ExecutionLog::$source = 'daemon';
    	//parse params, set some defaults
	//min / max workers, per account / project limits, idle and job timeouts (were `workers` = 5 and the single / multi class limits)
	$this->config = [
	    'debug' => false,
	    'logfile' => 'daemon',
	    'worker-logfile' => 'daemon_worker',
	    'min-workers' => 2,
	    'max-workers' => 2 * self::cpu_count() - 1,
	    'max-per-account' => null,
	    'max-per-project' => null,
	    'idle-timeout' => 60,
	    'job-timeout' => 0,
	    //memory (MB) a worker may use before it is replaced
	    'max-worker-memory' => 256,
	];
	$params = [ 'logfile', 'worker-logfile', 'debug', 'min-workers', 'max-workers', 'max-per-account', 'max-per-project', 'idle-timeout', 'job-timeout', 'max-worker-memory' ];
	foreach($params as $p) {
	    if (isset(\V::app()->request->params[$p]))
		$this->config[$p] = \V::app()->request->params[$p];
	}

	//numbers; max >= min >= 1; per account / project default to max - 1 (at least 1), 0 = no limit
	foreach([ 'min-workers', 'max-workers', 'idle-timeout', 'job-timeout', 'max-worker-memory' ] as $k) {
	    if (!is_numeric($this->config[$k]) || $this->config[$k] < 0)
		throw new \ErrorException("--{$k} must be a number >= 0");
	    $this->config[$k] = (int)$this->config[$k];
	}

	$this->config['min-workers'] = max(1, $this->config['min-workers']);
	$this->config['max-workers'] = max($this->config['min-workers'], $this->config['max-workers']);
	foreach([ 'max-per-account', 'max-per-project' ] as $k) {
	    if ($this->config[$k] === null)
		$this->config[$k] = max(1, $this->config['max-workers'] - 1);
	    if (!is_numeric($this->config[$k]) || $this->config[$k] < 0)
		throw new \ErrorException("--{$k} must be a number >= 0 (0 = no limit)");
	    $this->config[$k] = (int)$this->config[$k];
	}

	//raise level to DEBUG if intructed
	\V::app()->log->setLevel('INFO');
	if ($this->config['debug'])
	    \V::app()->log->setLevel('DEBUG');

	\V::app()->log->setFacility($this->config['logfile']);
	\V::app()->log->prefixWithCaller(false);

	//workers are this command with --worker
	$this->worker_cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -f ' . escapeshellarg(BASE . '/app/v.php') . " daemon::run --worker --logfile=" . escapeshellarg($this->config['worker-logfile']);
	if ($this->config['max-worker-memory'] > 0)
	    $this->worker_cmd .= ' --max-memory=' . (int)$this->config['max-worker-memory'];
	if ($this->config['debug'])
	    $this->worker_cmd .= ' --debug';


	//jobs collection; use raw database access
	$this->jobs_coll = \V::app()->mongo->selectCollection(\models\Job::_database(), \models\Job::_collection());

	//show this process title
	cli_set_process_title('[' . \V::app()->config('app.name') . ' Daemon ' . gethostname() . ']');

	//restart hung-up jobs
	$this->jobs_coll->updateMany(
	    [ 'state' => 'run' ],
	    [ '$set' => [ 'state' => 'new', 'worker_pid' => null ] ],
	    [ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	);

	//create the loop runner
	$this->loop = \React\EventLoop\Loop::get();

	//setup signal handlers
	$sig_handler_stop = function($signo) use(&$sig_handler_stop) {
	    \V::app()->log->info("WMGR: got signal $signo. Stopping....");
	    $this->loop->removeSignal(SIGINT, $sig_handler_stop);
	    $this->loop->removeSignal(SIGTERM, $sig_handler_stop);
	    $this->loop->stop();
	};

	$this->loop->addSignal(SIGINT, $sig_handler_stop);
	$this->loop->addSignal(SIGTERM, $sig_handler_stop);

	//create initial workers via a 1s timer
    	$this->loop->addTimer(1, function() {
	    //setup job_listener
	    $this->setup_job_listener();

	    $this->ensure_workers();
    	});

	//add a 10s periodic timer
	// - keeps the minimum of workers, stops idle extra ones, gives up events over --job-timeout
	// - check if there are jobs in the queue and dispatch them
    	$this->loop->addPeriodicTimer(10, function() {
	    $this->ensure_workers();
	    $this->check_workers();

	    //CLD: 2026-10-08T21:36:05+03:00: countDocuments (count() is deprecated)
	    $this->new_jobs_count = $this->jobs_coll->countDocuments([ 'state' => 'new' ]);

	    $d_delta = time() - $this->last_dispatch_time;
	    if ($d_delta > 10 && !$this->dispatching && $this->new_jobs_count > 0) {
		\V::app()->log->info("WMGR: There are {$this->new_jobs_count} jobs in queue. Dispatch them...");
		$this->dispatch_jobs();
	    }

	    //check/setup job_listener
	    $this->setup_job_listener();
    	});

	//add a 5min periodic timer
	// - warn via email if there are too many jobs in queue
	// - report some stats
    	$this->loop->addPeriodicTimer(5*60, function() {
	    //count jobs in new state; if greater than limit and increasing versus last time, issue a warning
	    $new_jobs_count = $this->jobs_coll->countDocuments([ 'state' => 'new' ]);
	    if ($new_jobs_count > 100 && $this->prev_jobs_count > 100) {
		\V::app()->log->warn("WMGR: there are more than 100 jobs in queue for more than 5 minutes, now: {$new_jobs_count}");
		\models\Job::reportError('too many jobs in queue', "WARNING: {$new_jobs_count} jobs waiting for more than 5 minutes");
	    }
    	    //save
    	    $this->prev_jobs_count = $new_jobs_count;

	    //SC: 2024-11-20: added
	    //re-run failed jobs no more than 3 times
	    //CLD: 2026-10-08T21:36:05+03:00: by run_count (was `counter`, a field nothing sets: nothing was ever re-run), all events (no `mode`)
	    $result = $this->jobs_coll->updateMany(
		[ 'state' => 'fail', 'run_count' => [ '$lt' => 3 ] ],
		[ '$set' => [ 'state' => 'new', 'worker_pid' => null ] ],
		[ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	    );
	    \V::app()->log->info("re-run failed jobs count: " . $result->getModifiedCount());
	    if ($result->getModifiedCount() > 0)
		$this->dispatch_jobs();
    	});

	//add a 2-hour periodic timer: cleanup AuditLog and ExecutionLog
	$this->loop->addPeriodicTimer(2*60*60, function() {
	    $exe_log_coll = \V::app()->mongo->selectCollection(\models\ExecutionLog::_database(), \models\ExecutionLog::_collection());
	    //get all accounts
	    $accounts = \V::app()->om->find('Account');
	    foreach($accounts as $acct) {
		//audit log
		//CLD: 2026-10-22T00:00:00+03:00: AuditLog::prune: at least 1 year (AuditLog::MIN_RETENTION_DAYS), the seal keeps what the retention removed, and its state
		//is logged (was: the account's setting, as short as 30 days, applied here)
		\models\AuditLog::prune($acct);
		//execution log
		if ($acct->execution_log_retention > 0) {
		    $time_limit = new \MongoDB\BSON\UTCDateTime((time() - $acct->execution_log_retention * 60*60*24) * 1000);
		    $exe_log_coll->deleteMany([ 'aid' => $acct->_id, 'created_at' => [ '$lte' => $time_limit ] ]);
		}
	    }
	});


	//install 60s periodic timer, aligned to second 0
	$this->scheduler();

	\V::app()->log->info("WMGR: ready: min-workers={$this->config['min-workers']}, max-workers={$this->config['max-workers']}, "
	    . "max-per-account={$this->config['max-per-account']}, max-per-project={$this->config['max-per-project']}, max-worker-memory={$this->config['max-worker-memory']} MB");
	$this->loop->run();

	//stop the workers and wait (up to 5 s) for them to exit, so their running events are queued again
	\V::app()->log->info("WMGR: main loop exited, kill workers....");
	$this->stopping = true;
	$this->close_job_listener();
	if ($this->timer) {
	    $this->loop->cancelTimer($this->timer);
	    $this->timer = null;
	}
	foreach ($this->workers as $pid => $w)
	    $w['process']->terminate(SIGTERM);

	$deadline = microtime(true) + 5;
	while (!empty($this->workers) && microtime(true) < $deadline) {
	    $this->loop->addTimer(0.1, function() { $this->loop->stop(); });
	    $this->loop->run();
	}

	foreach ($this->workers as $pid => $w) {
	    posix_kill($pid, SIGKILL);
	    $this->reap_job($w['jid'], 'the daemon stopped');
	}

	\V::app()->log->info("WMGR: exiting.");
    }

    //-------------------------------------------------------------------
    //master or worker
    public function run() {
	if (!empty(\V::app()->request->params['worker']))
	    return $this->run_worker();
	return $this->run_master();
    }

    //-------------------------------------------------------------------
    //worker
    public function do_job(&$job) {
	$account = null;

	//logout any user as well
	\V::app()->user->logout();

	$job_data = $job->job_data;

	if (is_array($job_data))
	    $job_data = (object)$job_data;
	if (!is_object($job_data))
	    $job_data = new \stdClass();

	//CLD: 2026-10-08T21:36:05+03:00: the account is the event's (aid at the top level), else job_data's
	if (empty($job_data->aid) && !empty($job->aid))
	    $job_data->aid = $job->aid;

	if (!empty($job_data->aid)) {
	    $account = \V::app()->var('rt.account');
	    if (empty($account) || (string)$account->_id != (string)$job_data->aid) {
		$account = \V::app()->om->findById('Account', (string)$job_data->aid);
		if (empty($account))
		    throw new \Exception("account {$job_data->aid} not found");
		\V::app()->var('rt.account', $account);
	    }

	    $job_data->aid = \V::app()->om->to_oid($job_data->aid);
	}

	cli_set_process_title($this->base_process_title . ' ' . $job->job_spec . '::' . $this->crt_job_id);

	//handle job-spec
	//decode job_spec
	//a job that cannot run fails (exception -> state fail + error)
	$job_spec = explode('::', (string)$job->job_spec);
	if (count($job_spec) != 2)
	    throw new \Exception("invalid job_spec: {$job->job_spec}");

	$job_class = $job_spec[0];
	$job_method = $job_spec[1];

	if (!class_exists($job_class))
	    throw new \Exception("unknown class `{$job_class}`");

	if (!method_exists($job_class, $job_method))
	    throw new \Exception("unknown method `{$job_method}` in class `{$job_class}`");

	//ok, run it
	//each job starts without the step definitions cached by an earlier one (models\WfNode::getAll: per request)
	\models\WfNode::resetCache();
	$job_class::$job_method($job_data);
    }

    //one line from the master: NEWJOB:<id> or PONG
    protected function worker_command($msg) {
	$cmd = explode(':', $msg, 2);

	//keepalive
	if ($cmd[0] == 'PONG') {
	    $this->master_last_seen = time();
	    return;
	}

	//leaving: no new job (the master queues it again when this process ends)
	if ($this->exiting)
	    return;

	if ($cmd[0] != 'NEWJOB') {
	    //unknown command/message
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: unknown command: {$cmd[0]}");
	    return;
	}

	//---------------- NEWJOB -------------------
	$jobid = trim($cmd[1] ?? '');
	if ($jobid === '') {
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: invalid NEWJOB spec: missing `jobid`");
	    $this->stdout->write("IDLE\n");
	    return;
	}

	$job = \util\Validator::rule('oid', $jobid)
	    ? \V::app()->om->findById('Job', $jobid, [ 'readConcern' => new \MongoDB\Driver\ReadConcern(\MongoDB\Driver\ReadConcern::MAJORITY) ])
	    : null;
	//also when it is not (any more) assigned to this worker
	if (empty($job) || $job->state != 'run' || (int)$job->worker_pid !== (int)$this->pid) {
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: {$jobid} has gone");
	    $this->stdout->write("RELEASE:$jobid\n");
	    return;
	}

	//inform manager
	$this->stdout->write("BUSY:{$jobid}\n");
	$this->job_counter++;
	$this->crt_job_id = $jobid;

	//process the job
	$job_spec = $job->job_spec;
	try {
	    $start = microtime(true);
	    \V::app()->log->info("Worker[{$this->pid}]: {$job_spec}: START: --------------------------------------------------");

	    //moved to a function so that we can test it
	    $this->do_job($job);

	    //done, update status
	    if ($job->delete_on_done) {
		$job->delete();
	    } else {
		$job->state = 'done';
		$job->finished_at = new \MongoDB\BSON\UTCDateTime();
		$job->save([ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]);
	    }

	    //mark timing
	    $delta = sprintf("%01.3f", (microtime(true) - $start)*1000 );
	    $mem = (int)(memory_get_usage() / (1024*1024));
	    \V::app()->log->info("Worker[{$this->pid}]: {$job_spec}: STOP: $delta ms, $mem MB -----------------------------------");
	} catch(\Exception|\Error $e) {
	    \V::app()->log->info("Worker[{$this->pid}]: {$jobid} failed: " . $e->getMessage());
	    //if error, mark job failed + the error and when
	    try {
		$job->state = 'fail';
		$job->error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000);
		$job->finished_at = new \MongoDB\BSON\UTCDateTime();
		$job->save([ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]);
	    } catch (\Throwable $e2) {
		\V::app()->log->error("Worker[{$this->pid}]: {$jobid}: cannot save the failure: " . $e2->getMessage());
	    }

	    \models\Job::reportError("job {$jobid} ({$job_spec}) failed", $e);
	    //mark in the account's execution log (jobs without an account: daemon log only)
	    if (!empty($job->aid))
		\models\ExecutionLog::record('job.failed', null, "job {$jobid} ({$job_spec}): " . $e->getMessage(), [], null, $job->aid);
	} //try-catch block

	$this->crt_job_id = null;
	//reset for long running processes
	$this->master_last_seen = time();

	$leaving = $this->over_memory();
	if ($leaving)
	    $this->stdout->write("EXIT\n");
	$this->stdout->write("DONE:{$jobid}\n");
	cli_set_process_title($this->base_process_title);
	if ($leaving)
	    $this->leave();
    }

    //memory PHP holds from the system (MB) over --max-memory: exit once the pending output is written; the master
    //starts a new worker. Not while a job runs (that would lose it): checked after each job and while idle
    //split into over_memory() (the check) and leave() (EXIT is written by the callers, before DONE after a job)
    protected function over_memory() {
	$limit = (int)$this->config['max-memory'];
	$used = memory_get_usage(true) / 1048576;
	if ($limit <= 0 || $used <= $limit || !empty($this->crt_job_id) || $this->exiting)
	    return false;
	\V::app()->log->info(sprintf("Worker[%d]: uses %.1f MB, over the limit of %d MB: exit, the master starts a new worker", $this->pid, $used, $limit));
	return true;
    }

    protected function leave() {
	$this->exiting = true;
	$this->stdout->on('close', function() {
	    $this->loop->stop();
	});
	$this->stdout->end();
    }

    public function run_worker() {
	//what this process changes is recorded in the execution log as the daemon's (executions: their trigger)
	\models\ExecutionLog::$source = 'daemon';
    	//parse params, set some defaults
	$this->config = [
	    'debug'	=> false,
	    'logfile'	=> 'job_worker',
	    'max-memory' => 0,
	];
	$params = [ 'debug', 'logfile', 'max-memory' ];
	foreach($params as $p) {
	    if (isset(\V::app()->request->params[$p]))
		$this->config[$p] = \V::app()->request->params[$p];
	}

	//raise level to DEBUG if intructed
	\V::app()->log->setLevel('INFO');
	if ($this->config['debug'])
	    \V::app()->log->setLevel('DEBUG');
	\V::app()->log->setFacility($this->config['logfile']);
	\V::app()->log->prefixWithCaller(false);

	//get my pid
	$this->pid = getmypid();

	//show this process title
	$this->base_process_title = '[' . \V::app()->config('app.name') . ' Worker ' . gethostname() . ' ' . $this->pid . ']';
	cli_set_process_title($this->base_process_title);


	//create the loop runner
	$this->loop = \React\EventLoop\Loop::get();

	//setup signal handlers
	$sig_handler_stop = function($signo) use(&$sig_handler_stop) {
	    \V::app()->log->info("Worker[{$this->pid}]: got signal $signo. Stopping....");
	    $this->loop->removeSignal(SIGINT, $sig_handler_stop);
	    $this->loop->removeSignal(SIGTERM, $sig_handler_stop);
	    $this->loop->stop();
	};

	$this->loop->addSignal(SIGINT, $sig_handler_stop);
	$this->loop->addSignal(SIGTERM, $sig_handler_stop);

	$this->stdin = new \React\Stream\ReadableResourceStream(fopen('php://stdin', 'r+'), $this->loop);
	$this->stdout = new \React\Stream\WritableResourceStream(fopen('php://stdout', 'w+'), $this->loop);

	//lines are buffered across chunks and handled one by one (worker_command)
	$this->stdin->on('data', function ($chunk) {
	    $this->stdin_buffer .= $chunk;
	    while (($nl = strpos($this->stdin_buffer, "\n")) !== false) {
		$msg = trim(substr($this->stdin_buffer, 0, $nl));
		$this->stdin_buffer = substr($this->stdin_buffer, $nl + 1);
		if ($msg !== '')
		    $this->worker_command($msg);
	    }
	});
	//the master is gone (pipe closed)
	$this->stdin->on('close', function() {
	    \V::app()->log->info("Worker[{$this->pid}]: master closed the pipe, exit");
	    $this->loop->stop();
	});

	//add a 10sec periodic timer: keepalive + the memory check while idle
    	$this->loop->addPeriodicTimer(10, function() {
	    if ($this->exiting)
		return;
	    if ($this->over_memory()) {
		$this->stdout->write("EXIT\n");
		$this->leave();
		return;
	    }
	    //SC: 2025-01-09: added keepalive check - only if IDLE
	    if (empty($this->crt_job_id)) {
		if (time() - $this->master_last_seen > 30) {
		    \V::app()->log->error("Worker[{$this->pid}]: master not alive for 30 seconds:" . (time() - $this->master_last_seen));
		    $this->loop->stop();
		}
		$this->stdout->write("PING\n");
	    }
    	});

	\V::app()->log->info("Worker[{$this->pid}]: starting...");

	//init
	$this->master_last_seen = time();

	$this->stdout->write("IDLE\n");
        $this->loop->run();

	\V::app()->log->info("Worker[{$this->pid}]: exiting....");
    }
}
