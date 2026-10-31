<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new step: FortiGate Policies - the firewall policies (cmdb/firewall/policy). Operations (setting `operation`):
	list      the policies (name containing a text)
	get       one policy, by its ID
	enable    a policy enabled (PUT status enable)
	disable   a policy disabled (PUT status disable)
	stats     the policies' hit counts, bytes and last use (monitor/firewall/policy)
*/

namespace models\node;

class NodeAppFgtPolicies extends NodeAppFortiGate {
    public static $name = 'fgt-policies';
    public static $label = 'FortiGate Policies';
    public static $icon = 'bi-shield-lock';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'policies';

    const OPERATIONS = [
	'list' => 'List policies',
	'get' => 'Get a policy',
	'enable' => 'Enable a policy',
	'disable' => 'Disable a policy',
	'stats' => 'Policy hit counts',
    ];
    const LISTS = [ 'list', 'stats' ];

    protected static function operationFields() {
	return [
	    self::field('contains', 'Name contains', [ 'list' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('policyId', 'Policy ID', [ 'get', 'enable', 'disable' ], [ 'required' => true, 'help' => 'The policy\'s number (its ID in the policy list)',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$id = function() use ($text) {
	    $v = $text('policyId');
	    if (!ctype_digit($v))
		throw new \RuntimeException("Policy ID: not a number: `" . mb_substr($v, 0, 32) . '`');
	    return (int)$v;
	};
	switch($operation) {
	    case 'list':
		return $this->listOf('/api/v2/cmdb/firewall/policy', $text('contains') !== '' ? [ 'filter' => 'name=@' . $text('contains') ] : [], 'policy');
	    case 'stats':
		return $this->listOf('/api/v2/monitor/firewall/policy', [], 'policy');
	    case 'get':
		$n = $id();
		return [ $this->get("/api/v2/cmdb/firewall/policy/{$n}"), "policy {$n}" ];
	    case 'enable':
	    case 'disable':
		$n = $id();
		return [ $this->change('PUT', "/api/v2/cmdb/firewall/policy/{$n}", [ 'status' => $operation ]), "policy {$n} {$operation}d" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
