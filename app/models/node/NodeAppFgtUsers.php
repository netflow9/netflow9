<?php

/*
    FortiGate Users & VPN - the firewall's local users, signed-in users and VPN tunnels. Operations (setting `operation`):
	users       the local users (cmdb/user/local)
	enable      a local user enabled (PUT status enable)
	disable     a local user disabled (PUT status disable)
	signedIn    the users signed in to the firewall (monitor/user/firewall)
	deauth      a signed-in user signed out, by IP (POST monitor/user/firewall/deauth: user_type firewall, ip)
	sslvpn      the SSL VPN sessions (monitor/vpn/ssl)
	ipsec       the IPsec tunnels and their state (monitor/vpn/ipsec)
*/

namespace models\node;

use util\FortiGate;

class NodeAppFgtUsers extends NodeAppFortiGate {
    public static $name = 'fgt-users';
    public static $label = 'FortiGate Users & VPN';
    public static $icon = 'bi-person-badge';
    public static $defaultOperation = 'disable';
    public static $defaultVariable = 'users';

    const OPERATIONS = [
	'users' => 'List local users',
	'enable' => 'Enable a local user',
	'disable' => 'Disable a local user',
	'signedIn' => 'List signed-in users',
	'deauth' => 'Sign out a user (by IP)',
	'sslvpn' => 'List SSL VPN sessions',
	'ipsec' => 'List IPsec tunnels',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'enable', 'disable', 'deauth' ];
    const LISTS = [ 'users', 'signedIn', 'sslvpn', 'ipsec' ];

    protected static function operationFields() {
	return [
	    self::field('user', 'User', [ 'enable', 'disable' ], [ 'required' => true, 'help' => 'The local user\'s name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ip', 'IP address', [ 'deauth' ], [ 'required' => true, 'help' => 'The signed-in user\'s address', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'users':
		return $this->listOf('/api/v2/cmdb/user/local', [], 'user');
	    case 'enable':
	    case 'disable':
		$u = self::objName($value('user'), 'User');
		return [ $this->change('PUT', '/api/v2/cmdb/user/local/' . FortiGate::name($u), [ 'status' => $operation ]), "user {$u} {$operation}d" ];
	    case 'signedIn':
		return $this->listOf('/api/v2/monitor/user/firewall', [], 'user');
	    case 'deauth':
		$ip = \util\Ip::addresses($value('ip'), 'IP address', 1)[0];
		return [ $this->change('POST', '/api/v2/monitor/user/firewall/deauth', [ 'user_type' => 'firewall', 'ip' => $ip, 'ip_version' => str_contains($ip, ':') ? 'ip6' : 'ip4' ]),
		    "user at {$ip} signed out" ];
	    case 'sslvpn':
		return $this->listOf('/api/v2/monitor/vpn/ssl', [], 'session');
	    case 'ipsec':
		return $this->listOf('/api/v2/monitor/vpn/ipsec', [], 'tunnel');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
