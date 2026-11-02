<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new step: VMware API - any request of a vCenter Server (what the other VMware steps do not have: VM hardware, content library deployments, tags,
    vMotion, ...): the REST API (/api/...: the method, the path, the query and the body) or VI/JSON (<type>/<id>/<property> with GET, <type>/<id>/<method> with POST and its
    parameters as the body; managed objects as { "_typeName": "ManagedObjectReference", "type": ..., "value": ... }); Other parameters is not used here. Optionally a task
    returned (VI/JSON) waited for: its info. Its result: vCenter's answer as it is
*/

namespace models\node;

class NodeAppVmwareApi extends NodeAppVmware {
    public static $name = 'vmware-api';
    public static $label = 'VMware API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'rest';
    public static $defaultVariable = 'vmware';

    const OPERATIONS = [ 'rest' => 'REST API request', 'vim' => 'VI/JSON request' ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'rest', 'vim' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'rest', 'vim' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'help' => 'VI/JSON: GET a property, POST a method',
		'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'rest' ], [ 'required' => true, 'help' => 'e.g. /api/vcenter/vm/vm-42/hardware ({{ }} allowed)', 'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/api/...' ] ]),
	    self::field('vimPath', 'Path', [ 'vim' ], [ 'required' => true, 'help' => '<type>/<id>/<property or method>, e.g. VirtualMachine/vm-42/runtime', 'ui' => [ 'expression' => 'text', 'width' => 9,
		'placeholder' => 'VirtualMachine/vm-42/runtime' ] ]),
	    self::field('query', 'Query', [ 'rest' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"power_states": "POWERED_ON"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'rest', 'vim' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('waitTask', 'Wait for the task it returns', [ 'vim' ], [ 'type' => 'switch', 'default' => false, 'help' => 'A *_Task method: its info when it ends', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    //a JSON object of a setting ([] when empty)
    protected static function object($v, $label) {
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON object");
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$body = trim((string)$this->cfg('body', '')) === '' ? null : self::object($value('body'), 'Body');
	if ($operation === 'vim') {
	    $path = $text('vimPath');
	    $r = $this->vc->vim($method, $path, $body ?? ($method === 'POST' ? [] : null));
	    if ($this->cfg('waitTask', false) && is_array($r) && ($r['type'] ?? '') === 'Task')
		$r = $this->vc->waitTask($r, $path);
	    return [ $r, "{$method} {$path}" ];
	}
	$path = $text('path');
	$r = $this->vc->request($method, $path, self::object($value('query'), 'Query'), $body);
	return [ $r, "{$method} {$path}" ];
    }
}
