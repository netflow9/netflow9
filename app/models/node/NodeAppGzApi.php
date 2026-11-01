<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone API - any method of the GravityZone Public API (the ones the other GravityZone steps do not have: accounts, companies,
    licensing, packages, patch management, maintenance windows, integrations, ...): the API (area, e.g. network, licensing, quarantine/computers), the method, the parameters
    (a JSON object, or an expression giving one: Other parameters is not used here) and the API version. Its result: GravityZone's `result` as it is
*/

namespace models\node;

class NodeAppGzApi extends NodeAppGravityZone {
    public static $name = 'gz-api';
    public static $label = 'GravityZone API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'call';
    public static $defaultVariable = 'gz';

    const OPERATIONS = [ 'call' => 'Call a method' ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'call' ];

    protected static function operationFields() {
	return [
	    self::field('area', 'API', [ 'call' ], [ 'required' => true, 'default' => 'network', 'help' => 'e.g. network, licensing, quarantine/computers',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z][A-Za-z0-9]*(/[A-Za-z][A-Za-z0-9]*)?$', 'skipEmpty' => true, 'message' => 'An API name' ] ], 'ui' => [ 'width' => 4 ] ]),
	    self::field('method', 'Method', [ 'call' ], [ 'required' => true, 'help' => 'e.g. getLicenseInfo',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z][A-Za-z0-9]*$', 'skipEmpty' => true, 'message' => 'A method name' ] ], 'ui' => [ 'width' => 5 ] ]),
	    self::field('version', 'Version', [ 'call' ], [ 'default' => '1.0', 'validate' => [ [ 'regex', 'pattern' => '^\d+\.\d+$', 'skipEmpty' => true, 'message' => 'e.g. 1.0' ] ],
		'ui' => [ 'width' => 3 ] ]),
	];
    }

    //no Other parameters: the call's own parameters are the step's
    public static function configSchema() {
	$fields = array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
	array_splice($fields, 5, 0, [ [
	    'key' => 'callParams',
	    'type' => 'textarea',
	    'label' => 'Parameters',
	    'default' => '',
	    'help' => 'A JSON object (empty: none), or an expression giving one',
	    'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ],
	] ]);
	return $fields;
    }

    protected function extraParams() {
	return [];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$params = $value('callParams');
	if (is_string($params)) {
	    $params = trim($params) === '' ? [] : json_decode($params, true);
	    if (!is_array($params))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($params) || ($params && array_is_list($params)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	$version = $text('version') !== '' ? $text('version') : '1.0';
	$r = $this->gz->call($text('area'), $text('method'), $params, $version);
	return self::done($r, $text('area') . '.' . $text('method'));
    }
}
