<?php

/*
    Checkmk API - any request of the Checkmk REST API (what the other Checkmk steps do not have: folders, rules, users, contact groups,
    BI, the agent bakery, ...): the method, the path after /check_mk/api/1.0 (e.g. /domain-types/folder_config/collections/all), the query and the body (JSON objects, or
    expressions giving them; Other parameters is not used here) and an If-Match (the ETag Checkmk asks for when changing an object; * for any). Its result: Checkmk's answer
*/

namespace models\node;

class NodeAppCmkApi extends NodeAppCheckmk {
    public static $name = 'cmk-api';
    public static $label = 'Checkmk API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'cmk';

    const OPERATIONS = [ 'request' => 'Send a request' ];
    //the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'After /check_mk/api/1.0, e.g. /domain-types/folder_config/collections/all ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/domain-types/...' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"parent": "~"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('ifMatch', 'If-Match', [ 'request' ], [ 'help' => 'The object\'s ETag, for changes Checkmk guards (empty: none; * for any)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
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
	$ifMatch = $text('ifMatch');
	$r = $this->cmk->request($method, $path, self::object($value('query'), 'Query'), $body, $ifMatch !== '' ? [ 'If-Match' => $ifMatch ] : []);
	return [ $r, "{$method} {$path}" ];
    }
}
