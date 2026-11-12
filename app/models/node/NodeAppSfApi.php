<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: Salesforce API - any request of the org's REST API (what the other Salesforce steps do not have: composite, bulk, reports, Apex REST,
    approvals, …): the method, the path (below /services/data/v<version>, or from /services/), the query and the JSON body. The result: Salesforce's answer
*/

namespace models\node;

class NodeAppSfApi extends NodeAppSalesforce {
    public static $name = 'salesforce-api';
    public static $label = 'Salesforce API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'salesforce';

    const OPERATIONS = [ 'request' => 'An API request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Below /services/data/v<version>, e.g. /composite/tree/Account, or from /services/, e.g. /services/apexrest/orders',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/sobjects/…' ] ]),
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
	return [ $this->sf->request($method, $path, $query, $this->jsonOf('body', 'Body', false)), "{$method} {$path}" ];
    }
}
