<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new step: Wazuh Inventory - what syscollector knows of an agent (GET /syscollector/<id>/...): its operating system and hardware (one item), its
    packages, processes, open ports, network addresses and Windows hotfixes (lists)
*/

namespace models\node;

class NodeAppWzInventory extends NodeAppWazuh {
    public static $name = 'wazuh-inventory';
    public static $label = 'Wazuh Inventory';
    public static $icon = 'bi-card-list';
    public static $defaultOperation = 'packages';
    public static $defaultVariable = 'inventory';

    const OPERATIONS = [
	'os' => 'Operating system',
	'hardware' => 'Hardware',
	'packages' => 'Packages',
	'processes' => 'Processes',
	'ports' => 'Open ports',
	'netaddr' => 'Network addresses',
	'hotfixes' => 'Windows hotfixes',
    ];
    const WRITES = [];
    const LISTS = [ 'packages', 'processes', 'ports', 'netaddr', 'hotfixes' ];
    const WHAT = [ 'packages' => 'package', 'processes' => 'process', 'ports' => 'port', 'netaddr' => 'address', 'hotfixes' => 'hotfix' ];

    protected static function operationFields() {
	return [
	    self::field('agent', 'Agent', array_keys(self::OPERATIONS), [ 'required' => true, 'help' => 'Its id; an agent or an alert works too', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('search', 'Containing', self::LISTS, [ 'help' => 'A text in any field; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$id = self::agentId($value('agent'));
	if (isset(self::WHAT[$operation]))
	    return $this->listOf("/syscollector/{$id}/{$operation}", [ 'search' => $text('search') ], self::WHAT[$operation]);
	return [ $this->one("/syscollector/{$id}/{$operation}", "{$operation} data of agent {$id}"), "agent {$id}" ];
    }
}
