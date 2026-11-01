<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall Hosts - the firewall's IP hosts, FQDN hosts and IP host groups (IPHost, FQDNHost, IPHostGroup). Operations (setting `operation`):
	block     the usual way to block IPs: each made an IP host (named <prefix><ip>, an IPv6 one's colons as dashes; kept when it exists) and put in the host group a drop rule uses
	          (already in it: fine); IPv6 hosts go to the IPv6 group (a group is of one IP family, and its name is unique among the hosts and groups)
	unblock   each taken out of its group (not in it, or no such host: fine); the host removed too when chosen
	list      the IP hosts (name containing a text; IPv4 or IPv6)
	get       an IP host (or an FQDN host)
	create    an IP host: an IP, a network (CIDR, or "ip mask"), a range; or an FQDN host
	delete    an IP host, or an FQDN host
	groups    the IP host groups (IPv4 or IPv6)
	add       a host put in a group (the group's HostList read, changed, sent back)
	remove    a host taken out of a group
*/

namespace models\node;

class NodeAppSfosHosts extends NodeAppSophos {
    public static $name = 'sfos-hosts';
    public static $label = 'Sophos Firewall Hosts';
    public static $icon = 'bi-diagram-2';
    public static $defaultOperation = 'block';
    public static $defaultVariable = 'hosts';

    const OPERATIONS = [
	'block' => 'Block IPs (host + group)',
	'unblock' => 'Unblock IPs',
	'list' => 'List IP hosts',
	'get' => 'Get a host',
	'create' => 'Create a host',
	'delete' => 'Delete a host',
	'groups' => 'List IP host groups',
	'add' => 'Add a host to a group',
	'remove' => 'Remove a host from a group',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'block', 'unblock', 'create', 'delete', 'add', 'remove' ];
    const LISTS = [ 'list', 'groups' ];
    const TYPES = [ 'ip' => 'IP / network', 'range' => 'IP range', 'fqdn' => 'FQDN' ];
    const MAX_IPS = 200;

    protected static function operationFields() {
	return [
	    self::field('ips', 'IP addresses', [ 'block', 'unblock' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or separated by commas / lines (IPv4 or IPv6), e.g. {{ fgt.srcip }}',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('group', 'Group', [ 'block', 'unblock', 'add', 'remove' ], [ 'required' => true, 'help' => 'The IP host group (used by a drop rule, to block)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('group6', 'IPv6 group', [ 'block', 'unblock' ], [ 'help' => 'The IPv6 host group, for IPv6 addresses (empty: IPv6 addresses refused)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('prefix', 'Name prefix', [ 'block', 'unblock' ], [ 'default' => 'nf9-block-', 'help' => 'The hosts are named <prefix><ip> (an IPv6 address\'s colons as dashes)', 'ui' => [ 'width' => 6 ] ]),
	    self::field('deleteHost', 'Delete the hosts too', [ 'unblock' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 6 ] ]),
	    self::field('contains', 'Name contains', [ 'list', 'groups' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ipv6', 'IPv6', [ 'list', 'groups' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('name', 'Host', [ 'get', 'create', 'delete', 'add', 'remove' ], [ 'required' => true, 'help' => 'The host\'s name',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('hostType', 'Type', [ 'get', 'create', 'delete' ], [ 'type' => 'select', 'default' => 'ip', 'choices' => self::TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('value', 'Value', [ 'create' ], [ 'required' => true, 'help' => 'IP / network: 10.1.2.3, 10.1.2.0/24, "10.1.2.0 255.255.255.0" or 2001:db8::/64; range: 10.1.2.10-10.1.2.20; FQDN: host.example.com',
		'ui' => [ 'expression' => 'text' ] ]),
	    self::field('comment', 'Description', [ 'create', 'block' ], [ 'help' => 'Shown on the firewall', 'ui' => [ 'expression' => 'text' ] ]),
	];
    }

    //the host of an IP / a network: [ fields, family ] (IPHost: HostType IP or Network; IPv4's subnet a mask, IPv6's a prefix length)
    public static function ipHost($v) {
	$v = trim((string)$v);
	if (preg_match('~^(\S+?)(?:/(\d{1,3}))?$~', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
	    $v6 = str_contains($m[1], ':');
	    if (!isset($m[2]))
		return [ [ 'HostType' => 'IP', 'IPAddress' => $m[1] ], $v6 ? 'IPv6' : 'IPv4' ];
	    $bits = (int)$m[2];
	    if ($bits > ($v6 ? 128 : 32))
		throw new \RuntimeException("Value: not a network: `" . mb_substr($v, 0, 64) . '`');
	    return [ [ 'HostType' => 'Network', 'IPAddress' => $m[1], 'Subnet' => $v6 ? (string)$bits : long2ip($bits === 0 ? 0 : (0xffffffff << (32 - $bits)) & 0xffffffff) ], $v6 ? 'IPv6' : 'IPv4' ];
	}
	if (preg_match('~^(\S+)\s+(\S+)$~', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($m[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
	    return [ [ 'HostType' => 'Network', 'IPAddress' => $m[1], 'Subnet' => $m[2] ], 'IPv4' ];
	throw new \RuntimeException("Value: not an IP or a network: `" . mb_substr($v, 0, 64) . '`');
    }

    //the host name of a blocked IP
    public static function blockName($prefix, $ip) {
	return self::objName($prefix . str_replace(':', '-', $ip), 'Name prefix');
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$family = $this->cfg('ipv6', false) ? 'IPv6' : 'IPv4';
	$isFamily = fn($e) => (string)($e['IPFamily'] ?? 'IPv4') === $family;
	$hostTag = fn() => (string)$this->cfg('hostType', 'ip') === 'fqdn' ? 'FQDNHost' : 'IPHost';
	switch($operation) {
	    case 'list':
		return $this->listOf('IPHost', $text('contains'), 'host', $isFamily);
	    case 'groups':
		return $this->listOf('IPHostGroup', $text('contains'), 'group', $isFamily);
	    case 'get':
		$n = self::objName($value('name'), 'Host');
		return [ $this->need($hostTag(), $n, 'the host'), "host {$n}" ];
	    case 'create':
		$n = self::objName($value('name'), 'Host');
		$val = $text('value');
		$type = (string)$this->cfg('hostType', 'ip');
		$desc = mb_substr($text('comment'), 0, 255);
		if ($type === 'fqdn') {
		    if (!preg_match('/^[A-Za-z0-9*]([A-Za-z0-9*.-]{0,253})$/', $val))
			throw new \RuntimeException("Value: not an FQDN: `" . mb_substr($val, 0, 64) . '`');
		    $tag = 'FQDNHost';
		    $entity = [ 'Name' => $n, 'FQDN' => $val ] + ($desc !== '' ? [ 'Description' => $desc ] : []);
		} elseif ($type === 'range') {
		    $range = array_map('trim', explode('-', $val));
		    if (count($range) !== 2 || !filter_var($range[0], FILTER_VALIDATE_IP) || !filter_var($range[1], FILTER_VALIDATE_IP) || str_contains($range[0], ':') !== str_contains($range[1], ':'))
			throw new \RuntimeException("Value: not an IP range: `" . mb_substr($val, 0, 64) . '`');
		    $tag = 'IPHost';
		    $entity = [ 'Name' => $n, 'IPFamily' => str_contains($range[0], ':') ? 'IPv6' : 'IPv4', 'HostType' => 'IPRange', 'StartIPAddress' => $range[0], 'EndIPAddress' => $range[1] ];
		} else {
		    [ $fields, $fam ] = self::ipHost($val);
		    $tag = 'IPHost';
		    $entity = [ 'Name' => $n, 'IPFamily' => $fam ] + $fields;
		}
		if ($tag === 'IPHost' && $desc !== '')
		    $entity['Description'] = $desc;
		$msg = $this->change('add', $tag, $entity);
		return [ [ 'name' => $n, 'message' => $msg ], "host {$n} created" ];
	    case 'delete':
		$n = self::objName($value('name'), 'Host');
		return [ [ 'name' => $n, 'message' => $this->sfos->remove($hostTag(), $n) ], "host {$n} deleted" ];
	    case 'add':
	    case 'remove':
		$n = self::objName($value('name'), 'Host');
		$g = self::objName($value('group'), 'Group');
		[ $entity, $changed ] = self::withMembers($this->need('IPHostGroup', $g, 'the group'), 'HostList', 'Host', $operation === 'add' ? [ $n ] : [], $operation === 'add' ? [] : [ $n ]);
		$msg = $changed ? $this->change('update', 'IPHostGroup', $entity) : ($operation === 'add' ? 'in the group already' : 'not in the group');
		return [ [ 'name' => $n, 'group' => $g, 'message' => $msg ], "{$n} " . ($operation === 'add' ? 'added to' : 'removed from') . " {$g}" ];
	    case 'block':
	    case 'unblock':
		$ips = self::ips($value('ips'), 'IP addresses', self::MAX_IPS);
		$groups = [ 'IPv4' => self::objName($value('group'), 'Group'), 'IPv6' => $text('group6') !== '' ? self::objName($value('group6'), 'IPv6 group') : null ];
		$prefix = (string)$this->cfg('prefix', 'nf9-block-');
		$byFamily = [ 'IPv4' => [], 'IPv6' => [] ];
		$done = [];
		foreach($ips as $ip) {
		    $fam = str_contains($ip, ':') ? 'IPv6' : 'IPv4';
		    if ($groups[$fam] === null)
			throw new \RuntimeException("{$ip}: an IPv6 address, and no IPv6 group is set");
		    $byFamily[$fam][$ip] = self::blockName($prefix, $ip);
		}
		foreach($byFamily as $fam => $hosts) {
		    if (!$hosts)
			continue;
		    $g = $groups[$fam];
		    $group = $this->need('IPHostGroup', $g, 'the group');
		    if ($operation === 'block') {
			foreach($hosts as $ip => $h) {
			    if ($this->sfos->one('IPHost', $h) === null)
				$this->change('add', 'IPHost', [ 'Name' => $h, 'IPFamily' => $fam, 'HostType' => 'IP', 'IPAddress' => $ip ]
				    + ($text('comment') !== '' ? [ 'Description' => mb_substr($text('comment'), 0, 255) ] : []));
			}
			[ $group, $changed ] = self::withMembers($group, 'HostList', 'Host', array_values($hosts), []);
		    } else
			[ $group, $changed ] = self::withMembers($group, 'HostList', 'Host', [], array_values($hosts));
		    if ($changed)
			$this->sfos->set('update', 'IPHostGroup', $group);
		    if ($operation === 'unblock' && $this->cfg('deleteHost', true)) {
			foreach($hosts as $h) {
			    if ($this->sfos->one('IPHost', $h) !== null)
				$this->sfos->remove('IPHost', $h);
			}
		    }
		    foreach($hosts as $ip => $h)
			$done[] = [ 'ip' => $ip, 'host' => $h, 'group' => $g ];
		}
		$names = implode(', ', array_filter($groups));
		return [ [ 'group' => $groups['IPv4'], 'group6' => $groups['IPv6'], 'hosts' => $done, 'count' => count($done) ],
		    \util\Format::plural(count($done), 'IP') . ($operation === 'block' ? ' blocked' : ' unblocked') . " (group {$names})" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
