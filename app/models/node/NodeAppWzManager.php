<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new step: Wazuh Manager - the manager and its ruleset (the Server API). Operations (setting `operation`):
	status    its daemons (GET /manager/status)
	info      its version, type and paths (GET /manager/info)
	cluster   whether the cluster is on (GET /cluster/status)
	logs      its log (GET /manager/logs): of a level, a daemon
	rules     the rules (GET /rules): ids, a level or range, a group
	decoders  the decoders (GET /decoders)
	restart   the manager restarted (PUT /manager/restart: it takes no wait_for_complete)
*/

namespace models\node;

class NodeAppWzManager extends NodeAppWazuh {
    public static $name = 'wazuh-manager';
    public static $label = 'Wazuh Manager';
    public static $icon = 'bi-hdd-network';
    public static $defaultOperation = 'status';
    public static $defaultVariable = 'manager';

    const OPERATIONS = [
	'status' => 'Daemons\' status',
	'info' => 'Information',
	'cluster' => 'Cluster status',
	'logs' => 'Read the log',
	'rules' => 'List rules',
	'decoders' => 'List decoders',
	'restart' => 'Restart the manager',
    ];
    const WRITES = [ 'restart' ];
    const LISTS = [ 'logs', 'rules', 'decoders' ];
    const LOG_LEVELS = [ '' => 'Any', 'critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info', 'debug' => 'Debug' ];

    protected static function operationFields() {
	return [
	    self::field('logLevel', 'Level', [ 'logs' ], [ 'type' => 'select', 'default' => '', 'choices' => self::LOG_LEVELS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('tag', 'Daemon', [ 'logs' ], [ 'help' => 'e.g. wazuh-analysisd; empty: every daemon', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ruleIds', 'Rules', [ 'rules' ], [ 'help' => 'Their ids, separated by commas; empty: every rule', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('level', 'Level', [ 'rules' ], [ 'help' => 'One (10) or a range (7-10); empty: every level', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('group', 'Group', [ 'rules' ], [ 'help' => 'e.g. authentication_failed; empty: every group', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true ] ]),
	    self::field('search', 'Containing', [ 'logs', 'rules', 'decoders' ], [ 'help' => 'A text in any field; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'status':
		$d = $this->one('/manager/status', 'status');
		$down = array_keys(array_filter((array)$d, fn($s) => $s !== 'running' && $s !== 'stopped'));
		return [ $d, \util\Format::plural(count(array_filter((array)$d, fn($s) => $s === 'running')), 'daemon') . ' running' . ($down ? ', ' . implode(', ', $down) . ' in another state' : '') ];
	    case 'info':
		$d = $this->one('/manager/info', 'information');
		return [ $d, (string)($d['version'] ?? '') ];
	    case 'cluster':
		$d = (array)($this->wz->request('GET', '/cluster/status', self::WAIT)['data'] ?? []);
		return [ $d, 'enabled: ' . ($d['enabled'] ?? '?') . ', running: ' . ($d['running'] ?? '?') ];
	    case 'logs':
		$level = (string)$this->cfg('logLevel', '');
		return $this->listOf('/manager/logs', [ 'search' => $text('search'), 'tag' => $text('tag') ] + (($level !== '' && isset(self::LOG_LEVELS[$level])) ? [ 'level' => $level ] : []), 'line');
	    case 'rules':
		$ids = self::names($value('ruleIds'));
		foreach($ids as $id) {
		    if (!preg_match('/^\d+$/', $id))
			throw new \RuntimeException("Rules: not a rule's id: `" . mb_substr($id, 0, 64) . '`');
		}
		return $this->listOf('/rules', [ 'search' => $text('search'), 'level' => $text('level'), 'group' => $text('group'), 'rule_ids' => $ids ], 'rule');
	    case 'decoders':
		return $this->listOf('/decoders', [ 'search' => $text('search') ], 'decoder');
	    case 'restart':
		$r = (array)$this->wz->request('PUT', '/manager/restart');
		return [ self::affected($r), (string)($r['message'] ?? 'restart sent') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
