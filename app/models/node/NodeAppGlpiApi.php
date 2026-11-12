<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: GLPI API - any request of GLPI's REST API, in the step's session (what the other GLPI steps do not have: problems, changes, contracts,
    knowledge base, validations, sub-items such as /Computer/12/NetworkPort, …): the method, the path (after apirest.php), the query and the JSON body (objects, or expressions
    giving them). The result: GLPI's answer
*/

namespace models\node;

class NodeAppGlpiApi extends NodeAppGlpi {
    public static $name = 'glpi-api';
    public static $label = 'GLPI API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'glpi';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'After apirest.php, e.g. /Problem, /Ticket/12/TicketValidation or /search/Computer',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/Ticket/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"range": "0-49", "expand_dropdowns": true}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none); GLPI\'s add / update take {"input": {…}}', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = '/' . ltrim(preg_replace('~^.*?/apirest\.php~i', '', $text('path')), '/');
	if (in_array(strtolower(rtrim($path, '/')), [ '/initsession', '/killsession' ], true))
	    throw new \RuntimeException('Path: the session is opened and closed by the step');
	$query = $this->jsonOf('query', 'Query');
	array_walk_recursive($query, function(&$v) {
	    if (is_bool($v))
		$v = $v ? 'true' : 'false';
	});
	$body = $this->settingOf('body');
	if (is_string($body)) {
	    $body = trim($body) === '' ? null : json_decode($body, true);
	    if ($body === null && trim((string)$this->settingOf('body')) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	return [ $this->glpi->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
