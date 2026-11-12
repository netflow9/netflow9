<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune API - any request of Microsoft Graph as the Intune credential's app (what the other Intune steps do not have: the settings catalog's
    configurationPolicies, scripts, update rings, reports, … in beta): the method, the path (from /v1.0/ or /beta/; else below /v1.0), the query and the JSON body (objects, or
    expressions giving them). The result: Graph's answer; a list's further pages are not read (its @odata.nextLink is in the answer)
*/

namespace models\node;

class NodeAppIntuneApi extends NodeAppIntune {
    public static $name = 'intune-api';
    public static $label = 'Intune API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'intune';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'From /v1.0/ or /beta/, e.g. /beta/deviceManagement/configurationPolicies or /v1.0/deviceManagement/deviceManagementScripts',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/v1.0/deviceManagement/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"$top": 50, "$select": "id,displayName"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = preg_replace('~^https://graph\.microsoft\.com~i', '', $text('path'));
	if ($path === '' || preg_match('~^https?://~i', $path))
	    throw new \RuntimeException('Path: from /v1.0/ or /beta/');
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
	return [ $this->intune->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
