<?php

/*
    GravityZone Investigation - live response on an endpoint (Investigation API; the key needs it enabled). Operations:
	command     a command run on the endpoint (startCommandExecutionOnEndpoint)
	package     an investigation package collected from the endpoint (collectInvestigationPackage)
	retrieve    a file of the endpoint retrieved (startRetrieveInvestigationFileFromEndpoint)
	fileUrl     the download address of a retrieved file / package (getInvestigationFileUrl)
	kill        a process killed (killProcess)
    These act on endpoints with GravityZone's rights: an advanced step. The API area is `investigation` (TO VERIFY against a console; Other parameters adds what differs)
*/

namespace models\node;

class NodeAppGzInvestigation extends NodeAppGravityZone {
    public static $name = 'gz-investigation';
    public static $label = 'GravityZone Investigation';
    public static $icon = 'bi-terminal';
    public static $audience = 'advanced';
    public static $defaultOperation = 'command';
    public static $defaultVariable = 'investigation';

    const OPERATIONS = [
	'command' => 'Run a command',
	'package' => 'Collect an investigation package',
	'retrieve' => 'Retrieve a file',
	'fileUrl' => 'Get a retrieved file\'s address',
	'kill' => 'Kill a process',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'command', 'package', 'retrieve', 'kill' ];
    const AREA = 'investigation';

    protected static function operationFields() {
	return [
	    self::field('endpointId', 'Endpoint', [ 'command', 'package', 'retrieve', 'kill' ], [ 'required' => true, 'help' => 'The endpoint\'s id', 'ui' => [ 'expression' => true ] ]),
	    self::field('command', 'Command', [ 'command' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Run by GravityZone\'s agent on the endpoint',
		'ui' => [ 'rows' => 2, 'expression' => 'text', 'code' => true ] ]),
	    self::field('filePath', 'File', [ 'retrieve' ], [ 'required' => true, 'help' => 'The file\'s path on the endpoint', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('taskId', 'Task', [ 'fileUrl' ], [ 'required' => true, 'help' => 'The id of the retrieve / collect task', 'ui' => [ 'expression' => true ] ]),
	    self::field('processId', 'Process', [ 'kill' ], [ 'required' => true, 'help' => 'The process id (PID)', 'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'command':
		$command = \util\Expression::toText($value('command'));
		if (trim($command) === '')
		    throw new \RuntimeException('Command: empty');
		return self::done($this->rpc(self::AREA, 'startCommandExecutionOnEndpoint', $this->endpointRef() + [ 'command' => $command ]), 'command started on ' . $text('endpointId'));
	    case 'package':
		return self::done($this->rpc(self::AREA, 'collectInvestigationPackage', $this->endpointRef()), 'package collection started on ' . $text('endpointId'));
	    case 'retrieve':
		if ($text('filePath') === '')
		    throw new \RuntimeException('File: empty');
		return self::done($this->rpc(self::AREA, 'startRetrieveInvestigationFileFromEndpoint', $this->endpointRef() + [ 'filePath' => $text('filePath') ]), 'retrieving ' . $text('filePath'));
	    case 'fileUrl':
		return self::done($this->rpc(self::AREA, 'getInvestigationFileUrl', [ 'taskId' => self::id($value('taskId'), 'Task') ]), 'address of task ' . $text('taskId'));
	    case 'kill':
		$pid = $text('processId');
		if (!ctype_digit($pid))
		    throw new \RuntimeException("Process: not a process id: `{$pid}`");
		return self::done($this->rpc(self::AREA, 'killProcess', $this->endpointRef() + [ 'processId' => (int)$pid ]), "process {$pid} killed on " . $text('endpointId'));
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the endpoint of the setting Endpoint
    private function endpointRef() {
	return [ 'endpointId' => self::id($this->settingOf('endpointId'), 'Endpoint') ];
    }
}
