<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new step: Veeam API - any request of the Veeam Backup & Replication REST API (what the other Veeam steps do not have: entire machine and file
    restores, other platforms, credentials, malware detection, configuration backup, ...): the method, the path (/api/v1/...), the query and the body (JSON objects, or expressions
    giving them; Other parameters is not used here). Its result: Veeam's answer as it is
*/

namespace models\node;

class NodeAppVeeamApi extends NodeAppVeeam {
    public static $name = 'veeam-api';
    public static $label = 'Veeam API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'veeam';

    const OPERATIONS = [ 'request' => 'Send a request' ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'e.g. /api/v1/backupInfrastructure/repositories ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/api/v1/...' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"limit": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
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
	$path = $text('path');
	$body = trim((string)$this->cfg('body', '')) === '' ? null : self::object($value('body'), 'Body');
	$r = $this->vbr->request($method, $path, self::object($value('query'), 'Query'), $body);
	return [ $r, "{$method} {$path}" ];
    }
}
