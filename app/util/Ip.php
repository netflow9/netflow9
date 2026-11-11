<?php
/*
    IP addresses (static):
	addresses()         a list of IP addresses (v4 or v6) from a list or a text, each checked: FortiGate, Sophos Firewall, Checkmk steps
	network()           an IP or a network (CIDR, IPv4 "address mask") parsed: FortiGate addresses, Sophos Firewall hosts
	range()             "start-end" of one family parsed
	isPublic()          an address of the internet (util\Http's public-address guard)
	isPrivateNetwork()  an address of a private network, not this machine's (the guard's `private`)
	ownAddresses()      this machine's addresses
*/
namespace util;

class Ip {

    //IP addresses (v4 or v6): a list, or text separated by commas / spaces / line breaks (a list of objects: their `ip`, else `srcip`); each checked,
    //duplicates dropped; none, or more than $max, is an error
    public static function addresses($value, $label, $max = 1000) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? ($v['ip'] ?? $v['srcip'] ?? '') : $v, array_values($value)) : preg_split('/[\s,;]+/', Expression::toText($value));
	$ips = [];
	foreach($parts as $p) {
	    $p = trim(Expression::toText($p));
	    if ($p === '')
		continue;
	    if (!filter_var($p, FILTER_VALIDATE_IP))
		throw new \RuntimeException("{$label}: not an IP address: `" . mb_substr($p, 0, 64) . '`');
	    $ips[] = $p;
	}
	$ips = array_values(array_unique($ips));
	if (!$ips)
	    throw new \RuntimeException("{$label}: empty");
	if (count($ips) > $max)
	    throw new \RuntimeException("{$label}: " . count($ips) . " addresses, at most {$max} in one step");
	return $ips;
    }

    //an IP or a network: 10.1.2.3, a CIDR (10.1.2.0/24, 2001:db8::/64) or IPv4's "address mask" (10.1.2.0 255.255.255.0):
    //[ ip, bits (the CIDR's prefix length, else null), mask (IPv4: the dotted mask of a CIDR or the one given, else null), v6 ]; null when it is none of these
    public static function network($v) {
	$v = trim((string)$v);
	if (preg_match('~^(\S+?)(?:/(\d{1,3}))?$~', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
	    $v6 = str_contains($m[1], ':');
	    $bits = isset($m[2]) ? (int)$m[2] : null;
	    if ($bits !== null && $bits > ($v6 ? 128 : 32))
		return null;
	    $mask = $v6 || $bits === null ? null : long2ip($bits === 0 ? 0 : (0xffffffff << (32 - $bits)) & 0xffffffff);
	    return [ 'ip' => $m[1], 'bits' => $bits, 'mask' => $mask, 'v6' => $v6 ];
	}
	if (preg_match('~^(\S+)\s+(\S+)$~', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($m[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
	    return [ 'ip' => $m[1], 'bits' => null, 'mask' => $m[2], 'v6' => false ];
	return null;
    }

    //"start-end" of two addresses of one family: [ start, end ]; null when it is not
    public static function range($v) {
	$range = array_map('trim', explode('-', (string)$v));
	if (count($range) !== 2 || !filter_var($range[0], FILTER_VALIDATE_IP) || !filter_var($range[1], FILTER_VALIDATE_IP) || str_contains($range[0], ':') !== str_contains($range[1], ':'))
	    return null;
	return $range;
    }

    //an address of a private network (RFC 1918, shared 100.64/10, IPv6 unique local fc00::/7), not one of this machine's own (its interfaces), not loopback,
    //link-local or reserved; IPv4-mapped IPv6 by its IPv4 address
    public static function isPrivateNetwork($ip) {
	$bin = @inet_pton((string)$ip);
	if ($bin === false)
	    return false;
	if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff"))
	    return self::isPrivateNetwork(inet_ntop(substr($bin, 12)));
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE))
	    return false;
	if (!array_filter([ '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7' ], fn($cidr) => self::inNetwork($bin, $cidr)))
	    return false;
	return !in_array(inet_ntop($bin), self::ownAddresses(), true);
    }

    //whether address $bin (inet_pton) is in network $cidr
    private static function inNetwork($bin, $cidr) {
	[ $net, $bits ] = explode('/', $cidr);
	$n = inet_pton($net);
	if (strlen($n) !== strlen($bin))
	    return false;
	$bytes = intdiv((int)$bits, 8);
	$rest = (int)$bits % 8;
	if (substr($n, 0, $bytes) !== substr($bin, 0, $bytes))
	    return false;
	return $rest === 0 || ((ord($n[$bytes]) ^ ord($bin[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0;
    }

    //this machine's addresses (its interfaces), normalized (inet_ntop)
    public static function ownAddresses() {
	static $own = null;
	if ($own === null) {
	    $own = [];
	    foreach(function_exists('net_get_interfaces') ? (array)@net_get_interfaces() : [] as $if) {
		foreach((array)($if['unicast'] ?? []) as $u) {
		    $b = isset($u['address']) ? @inet_pton((string)$u['address']) : false;
		    if ($b !== false)
			$own[] = inet_ntop($b);
		}
	    }
	    foreach(@gethostbynamel((string)gethostname()) ?: [] as $a)
		$own[] = inet_ntop(inet_pton($a));
	    $own = array_values(array_unique($own));
	}
	return $own;
    }

    //an address of the internet (not this machine, a private network, link-local, reserved, ...); NAT64 (64:ff9b::/96) by the IPv4 address it holds
    public static function isPublic($ip) {
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))
	    return false;
	$bin = @inet_pton($ip);
	if (strlen((string)$bin) === 16 && str_starts_with($bin, hex2bin('0064ff9b0000000000000000')))
	    return self::isPublic(inet_ntop(substr($bin, 12)));
	return true;
    }
}
