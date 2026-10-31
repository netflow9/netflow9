<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall Users & VPN - the firewall's users and VPN connections. Operations (setting `operation`):
	users     the users (User; user name containing a text)
	get       a user, by user name
	enable    a user made active (read, Status Active, sent back whole)
	disable   a user made inactive (Status Inactive): they cannot sign in (VPN, captive portal, the user portal)
	ipsec     the IPsec connections (VPNIPSecConnection)
	sslvpn    the SSL VPN remote access policies (SSLVPNPolicy)
*/

namespace models\node;

class NodeAppSfosUsers extends NodeAppSophos {
    public static $name = 'sfos-users';
    public static $label = 'Sophos Firewall Users & VPN';
    public static $icon = 'bi-person-badge';
    public static $defaultOperation = 'disable';
    public static $defaultVariable = 'users';

    const OPERATIONS = [
	'users' => 'List users',
	'get' => 'Get a user',
	'enable' => 'Enable a user',
	'disable' => 'Disable a user',
	'ipsec' => 'List IPsec connections',
	'sslvpn' => 'List SSL VPN policies',
    ];
    const LISTS = [ 'users', 'ipsec', 'sslvpn' ];

    protected static function operationFields() {
	return [
	    self::field('contains', 'User name contains', [ 'users' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'User', [ 'get', 'enable', 'disable' ], [ 'required' => true, 'help' => 'The user name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'users':
		return $this->listOf('User', $text('contains'), 'user');
	    case 'get':
		$u = self::objName($value('user'), 'User');
		return [ $this->need('User', $u, 'the user'), "user {$u}" ];
	    case 'enable':
	    case 'disable':
		$u = self::objName($value('user'), 'User');
		$user = $this->need('User', $u, 'the user');
		$status = $operation === 'enable' ? 'Active' : 'Inactive';
		if ((string)($user['Status'] ?? '') === $status)
		    return [ [ 'user' => $u, 'status' => $status, 'message' => "{$operation}d already" ], "user {$u} {$operation}d already" ];
		$user['Status'] = $status;
		//the password is not given back by a read: an update without it keeps it
		unset($user['Password']);
		return [ [ 'user' => $u, 'status' => $status, 'message' => $this->change('update', 'User', $user) ], "user {$u} {$operation}d" ];
	    case 'ipsec':
		return $this->listOf('VPNIPSecConnection', '', 'connection');
	    case 'sslvpn':
		return $this->listOf('SSLVPNPolicy', '', 'policy');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
