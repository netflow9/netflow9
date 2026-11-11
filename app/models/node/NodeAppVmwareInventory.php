<?php

/*
    VMware Inventory - the infrastructure of a vCenter Server. Operations (setting `operation`):
	hosts / clusters / datacenters / networks / folders / resourcePools   the lists of the REST API (GET /api/vcenter/...), of a datacenter (hosts: of a cluster too)
	datastores        the datastores with their free space in percent and Low on space (free under the percent chosen): { datastore, name, type, capacity, free_space,
	                  freePercent, lowOnSpace }
	enterMaintenance  a host put in maintenance mode (VI/JSON EnterMaintenanceMode_Task: DRS moves its running VMs away; vCenter 8.0 U1 and later); in it already: done
	exitMaintenance   a host taken out of maintenance mode (ExitMaintenanceMode_Task)
	version           vCenter's product, version and build (GET /api/appliance/system/version)
    A host, cluster, datacenter: its id or its name (exactly)
*/

namespace models\node;

class NodeAppVmwareInventory extends NodeAppVmware {
    public static $name = 'vmware-inventory';
    public static $label = 'VMware Inventory';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'hosts';
    public static $defaultVariable = 'inventory';

    const OPERATIONS = [
	'hosts' => 'List hosts',
	'clusters' => 'List clusters',
	'datacenters' => 'List datacenters',
	'datastores' => 'List datastores',
	'networks' => 'List networks',
	'folders' => 'List folders',
	'resourcePools' => 'List resource pools',
	'enterMaintenance' => 'Enter maintenance mode (host)',
	'exitMaintenance' => 'Exit maintenance mode (host)',
	'version' => 'Get vCenter\'s version',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'enterMaintenance', 'exitMaintenance' ];
    const LISTS = [ 'hosts', 'clusters', 'datacenters', 'datastores', 'networks', 'folders', 'resourcePools' ];
    //the REST collection of each list, and what an item is
    const PATHS = [
	'hosts' => [ 'host', 'host', '{ host, name, connection_state, power_state }' ],
	'clusters' => [ 'cluster', 'cluster', '{ cluster, name, ha_enabled, drs_enabled }' ],
	'datacenters' => [ 'datacenter', 'datacenter', '{ datacenter, name }' ],
	'datastores' => [ 'datastore', 'datastore', '{ datastore, name, type, capacity, free_space, freePercent, lowOnSpace }' ],
	'networks' => [ 'network', 'network', '{ network, name, type }' ],
	'folders' => [ 'folder', 'folder', '{ folder, name, type }' ],
	'resourcePools' => [ 'resource-pool', 'resource pool', '{ resource_pool, name }' ],
    ];
    const FOLDER_TYPES = [ 'any' => 'Any', 'VIRTUAL_MACHINE' => 'VMs and templates', 'HOST' => 'Hosts and clusters', 'DATASTORE' => 'Datastores', 'NETWORK' => 'Networks', 'DATACENTER' => 'Datacenters' ];
    const MAINTENANCE = [ 'enterMaintenance', 'exitMaintenance' ];

    protected static function operationFields() {
	return [
	    self::field('datacenter', 'Datacenter', [ 'hosts', 'clusters', 'datastores', 'networks', 'folders', 'resourcePools' ], [ 'help' => 'Name or id; empty: every one',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cluster', 'Cluster', [ 'hosts', 'resourcePools' ], [ 'help' => 'Name or id; empty: every one', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('lowPercent', 'Low on space under (%)', [ 'datastores' ], [ 'type' => 'number', 'default' => 10, 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('folderType', 'Type', [ 'folders' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::FOLDER_TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('host', 'Host', self::MAINTENANCE, [ 'required' => true, 'help' => 'Its name (exactly) or its id (host-10)', 'ui' => [ 'expression' => true, 'width' => 8 ] ]),
	    self::field('wait', 'Wait for the task', self::MAINTENANCE, [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 4 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return self::PATHS[$op][2] ?? parent::itemInfo($op);
    }

    protected static function resultInfo($op) {
	if (in_array($op, self::MAINTENANCE, true))
	    return [ 'type' => 'object', 'info' => '{ host, inMaintenance, already, task }', 'fields' => [ 'host' => [ 'type' => 'string' ], 'inMaintenance' => [ 'type' => 'boolean' ],
		'already' => [ 'type' => 'boolean', 'info' => 'it was so already' ], 'task' => [ 'type' => 'string', 'info' => 'the vCenter task' ] ] ];
	if ($op === 'version')
	    return [ 'type' => 'object', 'info' => '{ product, version, build, type, releasedate }', 'fields' => [ 'product' => [ 'type' => 'string' ], 'version' => [ 'type' => 'string' ],
		'build' => [ 'type' => 'string' ] ] ];
	return parent::resultInfo($op);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if (isset(self::PATHS[$operation])) {
	    [ $kind, $what ] = self::PATHS[$operation];
	    $q = [];
	    if ($text('datacenter') !== '')
		$q['datacenters'] = $this->resolve('datacenter', $value('datacenter'));
	    if (in_array($operation, [ 'hosts', 'resourcePools' ], true) && $text('cluster') !== '')
		$q['clusters'] = $this->resolve('cluster', $value('cluster'));
	    if ($operation === 'folders' && isset(self::FOLDER_TYPES[(string)$this->cfg('folderType', 'any')]) && (string)$this->cfg('folderType', 'any') !== 'any')
		$q['type'] = (string)$this->cfg('folderType');
	    if ($operation !== 'datastores')
		return $this->listOf("/api/vcenter/{$kind}", $q, $what);
	    $low = max(0, min(100, (float)$this->cfg('lowPercent', 10)));
	    return $this->listOf('/api/vcenter/datastore', $q, $what, fn($d) => self::withFree($d, $low));
	}
	switch($operation) {
	    case 'enterMaintenance':
	    case 'exitMaintenance':
		return $this->maintenance($this->resolve('host', $value('host')), $operation === 'enterMaintenance');
	    case 'version':
		$r = $this->get('/api/appliance/system/version');
		return [ $r, trim(($r['product'] ?? 'vCenter Server') . ' ' . ($r['version'] ?? '') . (isset($r['build']) ? " build {$r['build']}" : '')) ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a datastore with freePercent and lowOnSpace
    public static function withFree($d, $low) {
	$d = (array)$d;
	$capacity = (float)($d['capacity'] ?? 0);
	$free = (float)($d['free_space'] ?? 0);
	$d['freePercent'] = $capacity > 0 ? round($free * 100 / $capacity, 1) : null;
	$d['lowOnSpace'] = $d['freePercent'] !== null && $d['freePercent'] < $low;
	return $d;
    }

    //a host into / out of maintenance mode; so already: done
    protected function maintenance($host, $enter) {
	$runtime = $this->vc->vim('GET', "HostSystem/{$host}/runtime");
	$now = (bool)($runtime['inMaintenanceMode'] ?? false);
	$what = $enter ? 'in maintenance mode' : 'out of maintenance mode';
	if ($now === $enter)
	    return [ [ 'host' => $host, 'inMaintenance' => $enter, 'already' => true, 'task' => null ], "host {$host}: {$what} already" ];
	$task = $enter ? $this->invoke("HostSystem/{$host}/EnterMaintenanceMode_Task", [ 'timeout' => 0, 'evacuatePoweredOffVms' => false ])
	    : $this->invoke("HostSystem/{$host}/ExitMaintenanceMode_Task", [ 'timeout' => 0 ]);
	$r = [ 'host' => $host, 'inMaintenance' => $enter, 'already' => false, 'task' => (string)($task['value'] ?? '') ];
	if (!$this->cfg('wait', true)) {
	    $r['inMaintenance'] = $now;
	    return [ $r, "host {$host}: going {$what} ({$r['task']})" ];
	}
	$this->vc->waitTask($task, ($enter ? 'entering' : 'exiting') . " maintenance mode on {$host}");
	return [ $r, "host {$host}: {$what}" ];
    }
}
