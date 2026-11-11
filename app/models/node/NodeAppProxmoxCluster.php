<?php

/*
    Proxmox Cluster - the nodes, storage, tasks and state of a Proxmox VE cluster (or server). Operations (setting `operation`):
	nodes    the nodes (GET /nodes): { node, status, cpu, maxcpu, mem, maxmem, uptime, memPercent }
	storage  the storages of each node (GET /cluster/resources?type=storage): { storage, node, plugintype, status, disk, maxdisk, shared, freePercent, lowOnSpace }
	status   the cluster's state (GET /cluster/status): the cluster (name, quorate) and its nodes (online)
	tasks    the recent tasks of every node (GET /cluster/tasks): of a type, failed ones only when chosen: { upid, node, type, id, user, status, starttime, endtime, failed }
	ha       the HA manager's state (GET /cluster/ha/status/current)
	pools    the resource pools (GET /pools)
	version  the API server's version (GET /version)
*/

namespace models\node;

class NodeAppProxmoxCluster extends NodeAppProxmox {
    public static $name = 'proxmox-cluster';
    public static $label = 'Proxmox Cluster';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'nodes';
    public static $defaultVariable = 'cluster';

    const OPERATIONS = [
	'nodes' => 'List nodes',
	'storage' => 'List storage',
	'status' => 'Get the cluster\'s status',
	'tasks' => 'List recent tasks',
	'ha' => 'Get the HA status',
	'pools' => 'List pools',
	'version' => 'Get the version',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [];
    const LISTS = [ 'nodes', 'storage', 'tasks', 'pools' ];

    protected static function operationFields() {
	return [
	    self::field('lowPercent', 'Low on space under (%)', [ 'storage' ], [ 'type' => 'number', 'default' => 10, 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('node', 'Node', [ 'storage', 'tasks' ], [ 'help' => 'Empty: every node', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('taskType', 'Type', [ 'tasks' ], [ 'help' => 'e.g. vzdump, qmstart; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('failed', 'Failed ones only', [ 'tasks' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return match($op) {
	    'nodes' => '{ node, status, cpu, maxcpu, mem, maxmem, uptime, memPercent }',
	    'storage' => '{ storage, node, plugintype, status, disk, maxdisk, shared, freePercent, lowOnSpace }',
	    'tasks' => '{ upid, node, type, id, user, status, starttime, endtime, failed }',
	    'pools' => '{ poolid, comment }',
	    default => parent::itemInfo($op),
	};
    }

    protected static function resultInfo($op) {
	return match($op) {
	    'status' => [ 'type' => 'object', 'info' => '{ cluster, quorate, nodes }', 'fields' => [ 'cluster' => [ 'type' => 'string', 'info' => 'null: a server on its own' ],
		'quorate' => [ 'type' => 'boolean' ], 'nodes' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ name, online, ip, local }' ] ], 'offline' => [ 'type' => 'number' ] ] ],
	    'version' => [ 'type' => 'object', 'info' => '{ version, release, repoid }', 'fields' => [ 'version' => [ 'type' => 'string' ], 'release' => [ 'type' => 'string' ] ] ],
	    default => parent::resultInfo($op),
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$node = $text('node');
	switch($operation) {
	    case 'nodes':
		return $this->listOf('/nodes', [], 'node', null, function($n) {
		    $n['memPercent'] = !empty($n['maxmem']) ? round((float)($n['mem'] ?? 0) * 100 / (float)$n['maxmem'], 1) : null;
		    return $n;
		});
	    case 'storage':
		$low = max(0, min(100, (float)$this->cfg('lowPercent', 10)));
		return $this->listOf('/cluster/resources', [ 'type' => 'storage' ], 'storage', fn($s) => $node === '' || strcasecmp((string)($s['node'] ?? ''), $node) === 0,
		    fn($s) => self::withFree($s, $low));
	    case 'status':
		$list = (array)$this->call('GET', '/cluster/status');
		$cluster = null;
		$quorate = true;
		$nodes = [];
		foreach($list as $s) {
		    if (($s['type'] ?? '') === 'cluster') {
			$cluster = (string)($s['name'] ?? '');
			$quorate = !empty($s['quorate']);
		    } elseif (($s['type'] ?? '') === 'node')
			$nodes[] = [ 'name' => (string)($s['name'] ?? ''), 'online' => !empty($s['online']), 'ip' => $s['ip'] ?? null, 'local' => !empty($s['local']) ];
		}
		$offline = count(array_filter($nodes, fn($n) => !$n['online']));
		return [ [ 'cluster' => $cluster, 'quorate' => $quorate, 'nodes' => $nodes, 'offline' => $offline ],
		    ($cluster !== null ? "cluster {$cluster}" . ($quorate ? ', quorate' : ', NOT quorate') : 'a server on its own') . ', ' . \util\Format::plural(count($nodes), 'node') . ($offline ? ", {$offline} offline" : '') ];
	    case 'tasks':
		$type = $text('taskType');
		$failedOnly = (bool)$this->cfg('failed', false);
		return $this->listOf('/cluster/tasks', [], 'task', function($t) use ($node, $type, $failedOnly) {
		    return ($node === '' || strcasecmp((string)($t['node'] ?? ''), $node) === 0) && ($type === '' || ($t['type'] ?? '') === $type) && (!$failedOnly || self::failed($t));
		}, fn($t) => $t + [ 'failed' => self::failed($t) ]);
	    case 'ha':
		$r = $this->call('GET', '/cluster/ha/status/current');
		return [ $r, \util\Format::plural(is_array($r) ? count($r) : 0, 'HA entry', 'HA entries') ];
	    case 'pools':
		return $this->listOf('/pools', [], 'pool');
	    case 'version':
		$r = (array)$this->call('GET', '/version');
		return [ $r, 'Proxmox VE ' . ($r['version'] ?? '?') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //whether a stopped task failed (its status is not OK, nor WARNINGS)
    public static function failed(array $t) {
	$s = (string)($t['status'] ?? '');
	return !empty($t['endtime']) && $s !== '' && $s !== 'OK' && !str_starts_with($s, 'WARNINGS');
    }

    //a storage with freePercent and lowOnSpace
    public static function withFree($s, $low) {
	$s = (array)$s;
	$max = (float)($s['maxdisk'] ?? 0);
	$s['freePercent'] = $max > 0 ? round(($max - (float)($s['disk'] ?? 0)) * 100 / $max, 1) : null;
	$s['lowOnSpace'] = $s['freePercent'] !== null && $s['freePercent'] < $low;
	return $s;
    }
}
