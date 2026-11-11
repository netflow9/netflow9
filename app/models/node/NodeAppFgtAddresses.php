<?php

/*
    FortiGate Addresses - the firewall's address objects and address groups (cmdb/firewall/address, addrgrp; IPv6: address6, addrgrp6).
    Operations (setting `operation`):
	list      the addresses (name containing a text: filter name=@...)
	get       one address
	create    an address: a subnet (an IP, a CIDR, or "ip mask"), an FQDN or an IP range, with a comment
	delete    an address
	groups    the address groups
	add       an address added to a group (POST .../addrgrp/<group>/member)
	remove    an address removed from a group (DELETE .../addrgrp/<group>/member/<name>)
	block     the usual way to block IPs: each made an address (named <prefix><ip>; kept when it exists) and added to the group a deny policy uses (already in it: fine)
	unblock   each taken out of the group (not in it: fine); the address deleted too when chosen
    An IPv6 address goes to address6 / addrgrp6
*/

namespace models\node;

use util\FortiGate;

class NodeAppFgtAddresses extends NodeAppFortiGate {
    public static $name = 'fgt-addresses';
    public static $label = 'FortiGate Addresses';
    public static $icon = 'bi-diagram-2';
    public static $defaultOperation = 'block';
    public static $defaultVariable = 'addresses';

    const OPERATIONS = [
	'block' => 'Block IPs (address + group)',
	'unblock' => 'Unblock IPs',
	'list' => 'List addresses',
	'get' => 'Get an address',
	'create' => 'Create an address',
	'delete' => 'Delete an address',
	'groups' => 'List address groups',
	'add' => 'Add an address to a group',
	'remove' => 'Remove an address from a group',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'block', 'unblock', 'create', 'delete', 'add', 'remove' ];
    const LISTS = [ 'list', 'groups' ];
    const TYPES = [ 'ipmask' => 'Subnet / IP', 'fqdn' => 'FQDN', 'iprange' => 'IP range' ];
    const MAX_IPS = 200;

    protected static function operationFields() {
	return [
	    self::field('ips', 'IP addresses', [ 'block', 'unblock' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or separated by commas / lines (IPv4 or IPv6), e.g. {{ gz.srcip }}',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('group', 'Group', [ 'block', 'unblock', 'add', 'remove' ], [ 'required' => true, 'help' => 'The address group (used by a deny policy, to block)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('prefix', 'Name prefix', [ 'block', 'unblock' ], [ 'default' => 'r22-block-', 'help' => 'The addresses are named <prefix><ip>', 'ui' => [ 'width' => 6 ] ]),
	    self::field('deleteAddress', 'Delete the addresses too', [ 'unblock' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 6 ] ]),
	    self::field('contains', 'Name contains', [ 'list', 'groups' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ipv6', 'IPv6', [ 'list', 'groups' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('name', 'Address', [ 'get', 'create', 'delete', 'add', 'remove' ], [ 'required' => true, 'help' => 'The address object\'s name',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('addrType', 'Type', [ 'create' ], [ 'type' => 'select', 'default' => 'ipmask', 'choices' => self::TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('value', 'Value', [ 'create' ], [ 'required' => true, 'help' => 'Subnet: 10.1.2.3, 10.1.2.0/24 or "10.1.2.0 255.255.255.0"; FQDN: host.example.com; range: 10.1.2.10-10.1.2.20',
		'ui' => [ 'expression' => 'text' ] ]),
	    self::field('comment', 'Comment', [ 'create', 'block' ], [ 'help' => 'Shown on the FortiGate', 'ui' => [ 'expression' => 'text' ] ]),
	];
    }

    //"a.b.c.d mask" of an IPv4 address, CIDR or "ip mask"
    public static function subnet($v) {
	//parsed by util\Ip::network (IPv4 only here)
	$n = \util\Ip::network($v);
	if ($n === null || $n['v6'])
	    throw new \RuntimeException("Value: not a subnet: `" . mb_substr(trim((string)$v), 0, 64) . '`');
	return $n['ip'] . ' ' . ($n['mask'] ?? '255.255.255.255');
    }

    //FortiOS's answer to a change that may be done already (an object that exists, an entry already there, one that is not there): [ done already, error ]
    protected static function already(\RuntimeException $e, array $codes) {
	foreach($codes as $c) {
	    if (str_contains($e->getMessage(), "({$c})"))
		return true;
	}
	return false;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$v6 = (bool)$this->cfg('ipv6', false);
	switch($operation) {
	    case 'list':
		return $this->listOf('/api/v2/cmdb/firewall/' . ($v6 ? 'address6' : 'address'), $this->nameFilter(), 'address');
	    case 'groups':
		return $this->listOf('/api/v2/cmdb/firewall/' . ($v6 ? 'addrgrp6' : 'addrgrp'), $this->nameFilter(), 'group');
	    case 'get':
		$n = self::objName($value('name'), 'Address');
		return [ $this->get('/api/v2/cmdb/firewall/address/' . FortiGate::name($n)), "address {$n}" ];
	    case 'create':
		$n = self::objName($value('name'), 'Address');
		$type = isset(self::TYPES[(string)$this->cfg('addrType', 'ipmask')]) ? (string)$this->cfg('addrType', 'ipmask') : 'ipmask';
		$val = $text('value');
		$body = [ 'name' => $n, 'type' => $type, 'comment' => mb_substr($text('comment'), 0, 255) ];
		if ($type === 'ipmask')
		    $body['subnet'] = self::subnet($val);
		elseif ($type === 'fqdn') {
		    if (!preg_match('/^[A-Za-z0-9*]([A-Za-z0-9*.-]{0,253})$/', $val))
			throw new \RuntimeException("Value: not an FQDN: `" . mb_substr($val, 0, 64) . '`');
		    $body['fqdn'] = $val;
		} else {
		    //parsed by util\Ip::range (IPv4 only here)
		    $range = \util\Ip::range($val);
		    if ($range === null || str_contains($range[0], ':'))
			throw new \RuntimeException("Value: not an IP range: `" . mb_substr($val, 0, 64) . '`');
		    [ $body['start-ip'], $body['end-ip'] ] = $range;
		}
		return [ $this->change('POST', '/api/v2/cmdb/firewall/address', $body), "address {$n} created" ];
	    case 'delete':
		$n = self::objName($value('name'), 'Address');
		return [ $this->change('DELETE', '/api/v2/cmdb/firewall/address/' . FortiGate::name($n)), "address {$n} deleted" ];
	    case 'add':
	    case 'remove':
		$n = self::objName($value('name'), 'Address');
		$g = self::objName($value('group'), 'Group');
		$path = '/api/v2/cmdb/firewall/addrgrp/' . FortiGate::name($g) . '/member';
		$r = $operation === 'add' ? $this->change('POST', $path, [ 'name' => $n ]) : $this->change('DELETE', $path . '/' . FortiGate::name($n));
		return [ $r, "{$n} " . ($operation === 'add' ? 'added to' : 'removed from') . " {$g}" ];
	    case 'block':
	    case 'unblock':
		$ips = \util\Ip::addresses($value('ips'), 'IP addresses', self::MAX_IPS);
		$g = self::objName($value('group'), 'Group');
		$prefix = (string)$this->cfg('prefix', 'r22-block-');
		$done = [];
		foreach($ips as $ip) {
		    $isV6 = str_contains($ip, ':');
		    $addr = self::objName($prefix . $ip, 'Name prefix');
		    $base = '/api/v2/cmdb/firewall/' . ($isV6 ? 'address6' : 'address');
		    $member = '/api/v2/cmdb/firewall/' . ($isV6 ? 'addrgrp6' : 'addrgrp') . '/' . FortiGate::name($g) . '/member';
		    if ($operation === 'block') {
			try {
			    $this->change('POST', $base, $isV6 ? [ 'name' => $addr, 'ip6' => "{$ip}/128", 'comment' => mb_substr($text('comment'), 0, 255) ]
				: [ 'name' => $addr, 'type' => 'ipmask', 'subnet' => "{$ip} 255.255.255.255", 'comment' => mb_substr($text('comment'), 0, 255) ]);
			} catch (\RuntimeException $e) {
			    if (!self::already($e, [ -5, -15 ]))
				throw $e;
			}
			try {
			    $this->change('POST', $member, [ 'name' => $addr ]);
			} catch (\RuntimeException $e) {
			    if (!self::already($e, [ -5, -15 ]))
				throw $e;
			}
		    } else {
			try {
			    $this->change('DELETE', $member . '/' . FortiGate::name($addr));
			} catch (\RuntimeException $e) {
			    if (!self::already($e, [ -3 ]))
				throw $e;
			}
			if ($this->cfg('deleteAddress', true)) {
			    try {
				$this->change('DELETE', $base . '/' . FortiGate::name($addr));
			    } catch (\RuntimeException $e) {
				if (!self::already($e, [ -3 ]))
				    throw $e;
			    }
			}
		    }
		    $done[] = [ 'ip' => $ip, 'address' => $addr ];
		}
		return [ [ 'group' => $g, 'addresses' => $done, 'count' => count($done) ],
		    \util\Format::plural(count($done), 'IP') . ($operation === 'block' ? " blocked (group {$g})" : " unblocked (group {$g})") ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the API's filter for the setting Contains (the names holding it); none when empty
    private function nameFilter() {
	return $this->settingText('contains') !== '' ? [ 'filter' => 'name=@' . $this->settingText('contains') ] : [];
    }
}
