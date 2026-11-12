<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud API - any request of Nextcloud's OCS API (what the other Nextcloud steps do not have: user status, notifications, apps' OCS
    endpoints, ...): the method, the path below /ocs/v2.php (e.g. /apps/user_status/api/v1/user_status) and its parameters (a JSON object: the query of a GET / DELETE, the form
    of a POST / PUT). The result: the answer's ocs.data
*/

namespace models\node;

class NodeAppNxApi extends NodeAppNextcloud {
    public static $name = 'nextcloud-api';
    public static $label = 'Nextcloud API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'nextcloud';

    const OPERATIONS = [ 'request' => 'An OCS request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Below /ocs/v2.php, e.g. /cloud/user or /apps/notifications/api/v2/notifications',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/cloud/user' ] ]),
	    self::field('parameters', 'Parameters', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"search": "ana"}; lists are sent as name[]',
		'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$p = $value('parameters');
	if (is_string($p)) {
	    $p = trim($p) === '' ? [] : json_decode($p, true);
	    if (!is_array($p))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($p) || ($p && array_is_list($p)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	foreach($p as $k => $x)
	    if (is_array($x) && !array_is_list($x))
		throw new \RuntimeException("Parameters: `{$k}`: a text, a number, true / false or a list");
	$path = $text('path');
	return [ $this->nc->ocs($method, $path, $p), "{$method} {$path}" ];
    }
}
