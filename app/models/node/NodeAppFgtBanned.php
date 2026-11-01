<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new step: FortiGate Banned IPs - the firewall's quarantine of IP addresses (the banned users list: monitor/user/banned): traffic from them is
    dropped until the ban expires. Operations (setting `operation`):
	ban        IPs banned (add_users: ip_addresses, expiry in seconds; 0: until unbanned)
	list       the banned IPs (GET monitor/user/banned)
	unban      IPs unbanned (clear_users)
	clear      every ban lifted (clear_all)
*/

namespace models\node;

class NodeAppFgtBanned extends NodeAppFortiGate {
    public static $name = 'fgt-banned';
    public static $label = 'FortiGate Banned IPs';
    public static $icon = 'bi-sign-stop';
    public static $defaultOperation = 'ban';
    public static $defaultVariable = 'banned';

    const OPERATIONS = [
	'ban' => 'Ban IPs',
	'list' => 'List banned IPs',
	'unban' => 'Unban IPs',
	'clear' => 'Unban every IP',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'ban', 'unban', 'clear' ];
    const LISTS = [ 'list' ];
    const MAX_IPS = 1000;

    protected static function operationFields() {
	return [
	    self::field('ips', 'IP addresses', [ 'ban', 'unban' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or separated by commas / lines (IPv4 or IPv6)',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('minutes', 'For (minutes)', [ 'ban' ], [ 'type' => 'number', 'default' => 60, 'help' => '0: until unbanned',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 525600, 'skipEmpty' => true, 'message' => 'from 0 to 525600 (a year)' ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf('/api/v2/monitor/user/banned', [], 'banned IP');
	    case 'ban':
		$ips = self::ips($value('ips'), 'IP addresses', self::MAX_IPS);
		$minutes = max(0, (int)$this->cfg('minutes', 60));
		$r = $this->change('POST', '/api/v2/monitor/user/banned/add_users', [ 'ip_addresses' => $ips, 'expiry' => $minutes * 60 ]);
		return [ [ 'ips' => $ips, 'count' => count($ips), 'minutes' => $minutes, 'results' => $r ],
		    \util\Format::plural(count($ips), 'IP') . ' banned ' . ($minutes ? "for {$minutes} min" : 'until unbanned') ];
	    case 'unban':
		$ips = self::ips($value('ips'), 'IP addresses', self::MAX_IPS);
		$r = $this->change('POST', '/api/v2/monitor/user/banned/clear_users', [ 'ip_addresses' => $ips ]);
		return [ [ 'ips' => $ips, 'count' => count($ips), 'results' => $r ], \util\Format::plural(count($ips), 'IP') . ' unbanned' ];
	    case 'clear':
		return [ $this->change('POST', '/api/v2/monitor/user/banned/clear_all'), 'every ban lifted' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
