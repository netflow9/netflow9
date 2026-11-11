<?php

/*
    Veeam Infrastructure - the backup infrastructure of a Veeam Backup & Replication server. Operations (setting `operation`):
	repositories   the repositories' states (GET /api/v1/backupInfrastructure/repositories/states: capacityGB, freeGB, usedSpaceGB, isOnline), each with freePercent added;
	               Low on space: only those with less free space than the percentage
	proxies        the backup proxies (GET .../proxies)
	servers        the managed servers (GET .../managedServers)
	license        the installed license (GET /api/v1/license)
	server         the backup server: name, version (GET /api/v1/serverInfo)
*/

namespace models\node;

class NodeAppVeeamInfrastructure extends NodeAppVeeam {
    public static $name = 'veeam-infrastructure';
    public static $label = 'Veeam Infrastructure';
    public static $icon = 'bi-hdd-rack';
    public static $defaultOperation = 'repositories';
    public static $defaultVariable = 'infra';

    const OPERATIONS = [
	'repositories' => 'List repositories (space)',
	'proxies' => 'List proxies',
	'servers' => 'List managed servers',
	'license' => 'Get the license',
	'server' => 'Get the backup server',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [];
    const LISTS = [ 'repositories', 'proxies', 'servers' ];

    protected static function operationFields() {
	return [
	    self::field('lowFree', 'Low on space (% free)', [ 'repositories' ], [ 'type' => 'number', 'default' => '', 'help' => 'Only repositories with less free space; empty: all',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 100, 'skipEmpty' => true, 'message' => 'from 0 to 100' ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'repositories':
		[ $list, ] = $this->listOf('/api/v1/backupInfrastructure/repositories/states', [], 'repository');
		foreach($list['items'] as &$r) {
		    $cap = (float)($r['capacityGB'] ?? 0);
		    $r['freePercent'] = $cap > 0 ? round((float)($r['freeGB'] ?? 0) / $cap * 100, 1) : null;
		}
		unset($r);
		$low = trim((string)$this->cfg('lowFree', ''));
		if ($low !== '') {
		    $list['items'] = array_values(array_filter($list['items'], fn($r) => $r['freePercent'] !== null && $r['freePercent'] < (float)$low));
		    $list['count'] = count($list['items']);
		}
		return [ $list, \util\Format::plural($list['count'], 'repository') . ($low !== '' ? " under {$low}% free" : '') ];
	    case 'proxies':
		return $this->listOf('/api/v1/backupInfrastructure/proxies', [], 'proxy');
	    case 'servers':
		return $this->listOf('/api/v1/backupInfrastructure/managedServers', [], 'server');
	    case 'license':
		return [ $this->get('/api/v1/license'), 'the license' ];
	    case 'server':
		$r = $this->get('/api/v1/serverInfo');
		return [ $r, (string)($r['name'] ?? 'the server') . (isset($r['buildVersion']) ? " {$r['buildVersion']}" : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
