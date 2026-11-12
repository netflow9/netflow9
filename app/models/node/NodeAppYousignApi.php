<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: Yousign API - any request of Yousign's API v3 with the credential's key (what the other Yousign steps do not have: approvers, followers,
    electronic seals, identity verifications, labels, custom fields, ...): the method, the path (e.g. /signature_requests/<id>/followers), the query and the JSON body
    (objects, or expressions giving them). The result: Yousign's answer
*/

namespace models\node;

class NodeAppYousignApi extends NodeAppYousign {
    public static $name = 'yousign-api';
    public static $label = 'Yousign API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'yousign';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'After /v3, e.g. /signature_requests/{{ signature.id }}/followers',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/signature_requests' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"limit": 50}; a filter: {"status": {"in": "done"}}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = preg_replace('~^https://api(-sandbox)?\.yousign\.app/v3~i', '', $text('path'));
	if ($path === '' || preg_match('~^https?://~i', $path))
	    throw new \RuntimeException('Path: after /v3, e.g. /signature_requests');
	$query = $this->jsonOf('query', 'Query');
	$body = $this->settingOf('body');
	if (is_string($body)) {
	    $body = trim($body) === '' ? null : json_decode($body, true);
	    if ($body === null && trim((string)$this->settingOf('body')) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	return [ $this->yousign->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
