<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall API - any request of the Sophos Firewall XML API (what the other Sophos steps do not have: services, NAT rules, web
    policies, IPS, DHCP, ...): the elements that follow <Login> in <Request>, as in the API reference (<Get>, <Set operation="add|update">, <Remove>), {{ }} allowed (a value put in
    the XML is not escaped); the login is added. Its result: the answer as JSON ({ APIVersion, <entity>: its fields or a Status, ... }); Other fields is not used here
*/

namespace models\node;

class NodeAppSfosApi extends NodeAppSophos {
    public static $name = 'sfos-api';
    public static $label = 'Sophos Firewall API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'sfos';

    const OPERATIONS = [ 'request' => 'Send a request' ];

    protected static function operationFields() {
	return [
	    self::field('xml', 'Request', [ 'request' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'What follows <Login> in <Request>, e.g. <Get><Services/></Get> (the login is added)',
		'ui' => [ 'rows' => 6, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$xml = $text('xml');
	$r = $this->sfos->send($xml);
	$what = array_keys(array_diff_key($r, [ 'APIVersion' => 1 ]));
	return [ $r, $what ? implode(', ', $what) : 'sent' ];
    }
}
