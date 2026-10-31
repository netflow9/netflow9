<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new step: FortiGate API - any request of the FortiOS REST API (what the other FortiGate steps do not have: services, schedules, VIPs, routes,
    web filter, logs, ...): the method, the path (/api/v2/cmdb/..., /api/v2/monitor/..., /api/v2/log/...), the query and the body (JSON objects, or expressions giving them; Other
    parameters is not used here). Its result: FortiOS's whole answer ({ results, status, http_status, vdom, ... })
*/

namespace models\node;

class NodeAppFgtApi extends NodeAppFortiGate {
    public static $name = 'fgt-api';
    public static $label = 'FortiGate API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'fgt';

    const OPERATIONS = [ 'request' => 'Send a request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'e.g. /api/v2/cmdb/firewall/service/custom ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/api/v2/...' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"filter": "name=@web"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
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
	$r = $this->fgt->request($method, $path, self::object($value('query'), 'Query'), $body, true);
	return [ $r, "{$method} {$path}" ];
    }
}
