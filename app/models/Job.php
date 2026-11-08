<?php

/*
    the queue of work for the daemon Fields keep the names the daemon and worker use:
	job_spec        `Class::method` run by a worker as Class::method($job_data)
	job_data        array, given to the method as an object
	state           new -> run -> done | fail
	run_count, worker_pid, start_run_at, delete_on_done, error

    \models\Job::submit('\models\node\NodeTriggerEmail::deliver', [ 'aid' => $aid, 'project' => $project, ... ]);
*/
namespace models;

class Job extends \sys\BaseModel {
    protected static $collection = 'jobs';

    public static $indexes = [
	[ [ 'state' => 1, '_id' => 1 ], [] ],
	[ [ 'state' => 1, 'aid' => 1 ], [] ],
	[ [ 'state' => 1, 'project' => 1 ], [] ],
	[ [ 'key' => 1 ], [ 'unique' => true, 'sparse' => true ] ],
    ];


    //events are not objects of an account (`aid` is optional, inside job_data when the work belongs to one)
    public function _init() {
	$this->_config['global'] = true;
	parent::_init();
    }

    public function getSchema() {
	return [
	    [
		'key' => 'job_spec',
		'type' => 'text',
		'label' => 'Work',
		'required' => true
	    ],
	    [
		'key' => 'job_data',
		'type' => 'array',
		'label' => 'Data'
	    ],
	    [
		'key' => 'aid',
		'type' => 'object',
		'object' => 'Account',
		'label' => 'Account'
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'default' => 'new',
		'choices' => [
		    'new' => 'Waiting',
		    'run' => 'Running',
		    'done' => 'Done',
		    'fail' => 'Failed'
		],
	    ],
	    [
		'key' => 'run_count',
		'type' => 'number',
		'label' => 'Runs'
	    ],
	    [
		'key' => 'worker_pid',
		'type' => 'number',
		'label' => 'Worker'
	    ],
	    [
		'key' => 'start_run_at',
		'type' => 'datetime',
		'label' => 'Started at'
	    ],
	    [
		'key' => 'delete_on_done',
		'type' => 'checkbox',
		'label' => 'Delete when done'
	    ],
	    [
		'key' => 'error',
		'type' => 'text',
		'label' => 'Error'
	    ],
	    [
		'key' => 'finished_at',
		'type' => 'datetime',
		'label' => 'Finished at'
	    ],
	    //optional unique key (unique sparse index, created by the daemon): the same work is queued once
	    [
		'key' => 'key',
		'type' => 'text',
		'label' => 'Key'
	    ],
	];
    }

    //queue `Class::method` with $data; options: aid, project (default: $data['aid'] / $data['project']), delete_on_done, key (unique), notify
    //(default true: tell the daemon; false inside the daemon itself, which dispatches on its own); returns the event id.
    //The daemon is told at once (it also checks the queue every 10 s)
    //CLD: 2026-10-08T21:36:00+03:00: aid / project kept at the top level for the daemon's limits; no `mode`
    public static function submit($spec, $data = [], $options = []) {
	$data = (array)$data;
	$doc = [
	    'job_spec' => (string)$spec,
	    'job_data' => $data,
	    'aid' => \V::app()->om->to_oid($options['aid'] ?? $data['aid'] ?? null),
	    'project' => \V::app()->om->to_oid($options['project'] ?? $data['project'] ?? null),
	    'state' => 'new',
	    'run_count' => 0,
	    'delete_on_done' => !empty($options['delete_on_done']),
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	];
	//CLD: 2026-10-08T22:47:34+03:00: options['key']: null when an event with this key already exists (it is not queued again)
	if (!empty($options['key'])) {
	    $doc['key'] = (string)$options['key'];
	}
	//CLD: 2026-11-08T14:10:00+02:00: insertOne, not save(): save() rethrows any error as \ErrorException, the duplicate key (11000) of options['key'] would be lost
	try {
	    $id = \V::app()->om->insertOne('Job', \V::app()->om->create('Job', $doc));
	} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
	    if (!empty($doc['key']) && $e->getCode() == 11000)
		return null;
	    throw $e;
	}
	if (!empty($options['notify']) || !isset($options['notify']))
	    self::notify();
	return $id;
    }

    //where the daemon takes its commands: tcp://127.0.0.1:<app.daemon_port> (DAEMON_PORT of app.env)
    //CLD: 2026-11-01T19:00:00+02:00: $listen: the daemon's own address (app.daemon_listen), else where others reach it (app.daemon_host); both 127.0.0.1 unless set (Docker)
    public static function daemonAddress($listen = false) {
	$host = (string)\V::app()->config($listen ? 'app.daemon_listen' : 'app.daemon_host');
	return 'tcp://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . (int)\V::app()->config('app.daemon_port');
    }

    //wake the daemon (NEWJOB on its local port); nothing happens when it does not run
    public static function notify() {
	$conn = @stream_socket_client(self::daemonAddress(), $errno, $errstr, 1);
	if (!$conn)
	    return false;
	fwrite($conn, "NEWJOB\n");
	fclose($conn);
	return true;
    }

    //stop the worker running job $jid (KILLJOB on the daemon's port); nothing happens when the daemon does not run
    public static function kill($jid) {
	$conn = @stream_socket_client(self::daemonAddress(), $errno, $errstr, 1);
	if (!$conn)
	    return false;
	fwrite($conn, "KILLJOB:{$jid}\n");
	fclose($conn);
	return true;
    }

    //log an error and mail it to ERRORS_EMAIL (app.env) when set; $error: a message or a Throwable
    public static function reportError($subject, $error) {
	$text = $error instanceof \Throwable
	    ? get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getFile() . ':' . $error->getLine() . "\n\n" . $error->getTraceAsString()
	    : (string)$error;
	\V::app()->log->error($subject . ': ' . strtok($text, "\n"));

	if (!defined('ERRORS_EMAIL') || !defined('SMTP_HOST') || empty(ERRORS_EMAIL))
	    return;

	$host = gethostname() ?: 'localhost';
	$from = 'daemon@' . (defined('SMTP_DOMAIN') && SMTP_DOMAIN ? SMTP_DOMAIN : $host);
	$subjectLine = '[' . \V::app()->config('app.name') . ' ' . $host . '] ' . $subject;
	$headers = implode("\r\n", [
	    'From: ' . $from,
	    'To: ' . ERRORS_EMAIL,
	    'Subject: ' . mb_encode_mimeheader($subjectLine, 'UTF-8'),
	    'Date: ' . date(DATE_RFC2822),
	    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
	    'MIME-Version: 1.0',
	    'Content-Type: text/plain; charset=utf-8',
	    'Content-Transfer-Encoding: 8bit',
	]);
	//CRLF line ends (util\SMTP::data doubles leading dots itself)
	$body = str_replace([ "\r\n", "\n" ], "\r\n", $text) . "\r\n";

	try {
	    \util\EmailSend::send([ 'sender' => $from, 'to' => [ ERRORS_EMAIL => '' ], 'headers' => $headers, 'body' => $body ]);
	} catch (\Throwable $e) {
	    \V::app()->log->error('reportError: mail not sent: ' . $e->getMessage());
	}
    }
}
