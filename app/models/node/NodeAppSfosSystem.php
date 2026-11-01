<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall System - what the XML API tells of the firewall. Operations (setting `operation`):
	info        the hostname (AdminSettings) and the API version (the answer's APIVersion: the firmware's)
	interfaces  the interfaces (Interface)
	zones       the zones (Zone)
	gateways    the gateways (GatewayConfiguration)
	The XML API has no configuration download: a backup is scheduled on the firewall (Backup & firmware), to FTP or email
*/

namespace models\node;

class NodeAppSfosSystem extends NodeAppSophos {
    public static $name = 'sfos-system';
    public static $label = 'Sophos Firewall System';
    public static $icon = 'bi-cpu';
    public static $defaultOperation = 'info';
    public static $defaultVariable = 'system';

    const OPERATIONS = [
	'info' => 'Get the hostname and API version',
	'interfaces' => 'List interfaces',
	'zones' => 'List zones',
	'gateways' => 'List gateways',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [];
    const LISTS = [ 'interfaces', 'zones', 'gateways' ];

    public static function outputVariables($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if ($op === 'info' && \util\Validator::isIdentifier($name))
	    return [ $name => [ 'type' => 'object', 'info' => '{ hostname, apiVersion }', 'fields' => [
		'hostname' => [ 'type' => 'string' ], 'apiVersion' => [ 'type' => 'string', 'info' => 'e.g. 2000.1 (SFOS 20.0)' ] ] ] ];
	return parent::outputVariables($config);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'info':
		$a = $this->sfos->about();
		return [ $a, ($a['hostname'] !== '' ? $a['hostname'] : 'the firewall') . ($a['apiVersion'] !== '' ? ", API {$a['apiVersion']}" : '') ];
	    case 'interfaces':
		return $this->listOf('Interface', '', 'interface');
	    case 'zones':
		return $this->listOf('Zone', '', 'zone');
	    case 'gateways':
		return $this->listOf('GatewayConfiguration', '', 'gateway');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
