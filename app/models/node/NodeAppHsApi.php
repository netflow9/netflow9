<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: HubSpot API - any request of HubSpot's API (what the other HubSpot steps do not have: emails, meetings, lists, forms, files, marketing,
    batch requests, …): the method, the path (e.g. /crm/v3/objects/contacts/batch/read), the query and the JSON body (objects, or expressions giving them). The result: HubSpot's answer
*/

namespace models\node;

class NodeAppHsApi extends NodeAppHubSpot {
    public static $name = 'hubspot-api';
    public static $label = 'HubSpot API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'hubspot';

    const OPERATIONS = [ 'request' => 'An API request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'e.g. /crm/v3/objects/contacts/batch/read or /crm/v3/lists/search',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/crm/v3/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"maxResults": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    //a JSON setting: [] / null when empty
    protected function jsonOf($key, $label, $object) {
	$v = $this->settingValue((string)$this->cfg($key, ''), $this->settingVars);
	if (is_string($v)) {
	    if (trim($v) === '')
		return null;
	    $v = json_decode($v, true);
	    if ($v === null)
		throw new \RuntimeException("{$label}: not JSON");
	}
	if ($object && (!is_array($v) || ($v && array_is_list($v))))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	$query = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(',', $v) : $v), (array)$this->jsonOf('query', 'Query', true));
	return [ $this->hs->request($method, $path, $query, $this->jsonOf('body', 'Body', false)), "{$method} {$path}" ];
    }
}
