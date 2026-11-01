<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Zabbix Acknowledge & Maintenance - updates of problems (event.acknowledge) and maintenance periods. Operations (setting `operation`):
	acknowledge         events acknowledged (action 2), with a message (4), closed (1, when the trigger allows manual close), their severity changed (8), as chosen
	unacknowledge       events unacknowledged (16), with a message when given
	message             a message added to events (4)
	maintenanceCreate   a maintenance of hosts / host groups (by name): from now (or Start) for Minutes, with data collection or without (maintenance.create; 6.0: hostids /
	                    groupids, from 6.2: hosts / groups objects)
	maintenances        the maintenances (maintenance.get; of hosts), with their hosts, groups and periods
	maintenanceDelete   maintenances removed (maintenance.delete, by id)
    Events: their ids, separated by commas, or a list (of ids, or of objects with `eventid`: e.g. Zabbix Problems' items, the trigger's variable)
*/

namespace models\node;

use util\Zabbix;

class NodeAppZbxAck extends NodeAppZabbix {
    public static $name = 'zbx-ack';
    public static $label = 'Zabbix Acknowledge & Maintenance';
    public static $icon = 'bi-bell-slash';
    public static $defaultOperation = 'acknowledge';
    public static $defaultVariable = 'ack';

    const OPERATIONS = [
	'acknowledge' => 'Acknowledge problems',
	'unacknowledge' => 'Unacknowledge problems',
	'message' => 'Add a message to problems',
	'maintenanceCreate' => 'Create a maintenance',
	'maintenances' => 'List maintenances',
	'maintenanceDelete' => 'Remove maintenances',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'acknowledge', 'unacknowledge', 'message', 'maintenanceCreate', 'maintenanceDelete' ];
    const LISTS = [ 'maintenances' ];
    //by name: a select keeps no '0'
    const SEVERITY = [ '' => 'Keep it', 'not_classified' => 'Not classified', 'information' => 'Information', 'warning' => 'Warning', 'average' => 'Average', 'high' => 'High', 'disaster' => 'Disaster' ];
    const SEVERITY_LEVEL = [ 'not_classified' => 0, 'information' => 1, 'warning' => 2, 'average' => 3, 'high' => 4, 'disaster' => 5 ];
    const MAX_MINUTES = 60 * 24 * 366;
    //event.acknowledge's action bits
    const CLOSE = 1, ACK = 2, MESSAGE = 4, SEVERITY_CHANGE = 8, UNACK = 16;

    protected static function operationFields() {
	$events = [ 'acknowledge', 'unacknowledge', 'message' ];
	return [
	    self::field('eventIds', 'Events', $events, [ 'type' => 'textarea', 'required' => true, 'help' => 'Their ids, separated by commas, or a list (e.g. {{ zbx.eventid }}, {{ problems.items }})',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('message', 'Message', $events, [ 'help' => 'Shown in Zabbix with the problem (Add a message: required)', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'message' ] ],
		'ui' => [ 'expression' => 'text' ] ]),
	    self::field('close', 'Close the problems', [ 'acknowledge' ], [ 'type' => 'switch', 'default' => false, 'help' => 'When their triggers allow manual close', 'ui' => [ 'width' => 6 ] ]),
	    self::field('severity', 'Change the severity to', [ 'acknowledge' ], [ 'type' => 'select', 'default' => '', 'choices' => self::SEVERITY, 'ui' => [ 'width' => 6 ] ]),
	    self::field('name', 'Name', [ 'maintenanceCreate' ], [ 'required' => true, 'help' => 'Unique in Zabbix', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('hosts', 'Hosts', [ 'maintenanceCreate', 'maintenances' ], [ 'help' => 'Their technical names, separated by commas (List: empty: all)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('groups', 'Host groups', [ 'maintenanceCreate' ], [ 'help' => 'Their names, separated by commas (hosts, groups or both)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('start', 'Start', [ 'maintenanceCreate' ], [ 'help' => 'A date / time, or e.g. +1 hour, tomorrow 22:00; empty: now', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('minutes', 'Minutes', [ 'maintenanceCreate' ], [ 'type' => 'number', 'default' => 60, 'required' => true,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_MINUTES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_MINUTES ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('collectData', 'Collect data', [ 'maintenanceCreate' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Off: no data is collected during the maintenance', 'ui' => [ 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'maintenanceCreate' ], [ 'type' => 'textarea', 'ui' => [ 'rows' => 2, 'expression' => 'text' ] ]),
	    self::field('maintenanceIds', 'Maintenances', [ 'maintenanceDelete' ], [ 'required' => true, 'help' => 'Their ids, separated by commas, or a list (e.g. {{ m.maintenanceids }})',
		'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$message = mb_substr($text('message'), 0, 2048);
	switch($operation) {
	    case 'acknowledge':
	    case 'unacknowledge':
	    case 'message':
		$ids = self::ids($value('eventIds'), 'Events');
		$params = [ 'eventids' => $ids ];
		if ($operation === 'acknowledge') {
		    $action = self::ACK;
		    if ($this->cfg('close', false))
			$action |= self::CLOSE;
		    $sev = (string)$this->cfg('severity', '');
		    if (isset(self::SEVERITY_LEVEL[$sev])) {
			$action |= self::SEVERITY_CHANGE;
			$params['severity'] = self::SEVERITY_LEVEL[$sev];
		    }
		} elseif ($operation === 'unacknowledge')
		    $action = self::UNACK;
		else {
		    if ($message === '')
			throw new \RuntimeException('Message: empty');
		    $action = 0;
		}
		if ($message !== '') {
		    $action |= self::MESSAGE;
		    $params['message'] = $message;
		}
		$r = (array)$this->call('event.acknowledge', [ 'action' => $action ] + $params);
		$done = [ 'acknowledge' => 'acknowledged', 'unacknowledge' => 'unacknowledged', 'message' => 'given a message' ][$operation];
		return [ [ 'eventids' => array_map('strval', (array)($r['eventids'] ?? $ids)), 'action' => $action ], \util\Format::plural(count($ids), 'event') . " {$done}" ];
	    case 'maintenanceCreate':
		$name = mb_substr($text('name'), 0, 128);
		if ($name === '')
		    throw new \RuntimeException('Name: empty');
		$hosts = self::names($value('hosts'));
		$groups = self::names($value('groups'));
		if (!$hosts && !$groups)
		    throw new \RuntimeException('Hosts / Host groups: give hosts, host groups or both');
		$start = $text('start') === '' ? time() : strtotime($text('start'));
		if ($start === false)
		    throw new \RuntimeException('Start: not a date / time: `' . mb_substr($text('start'), 0, 64) . '`');
		$minutes = max(1, min(self::MAX_MINUTES, (int)$this->cfg('minutes', 60)));
		$hostIds = $this->zbx->hostIds($hosts);
		$groupIds = $this->zbx->groupIds($groups);
		$params = [ 'name' => $name, 'active_since' => $start, 'active_till' => $start + $minutes * 60, 'maintenance_type' => $this->cfg('collectData', true) ? 0 : 1,
		    'description' => mb_substr($text('description'), 0, 2048), 'timeperiods' => [ [ 'timeperiod_type' => 0, 'start_date' => $start, 'period' => $minutes * 60 ] ] ];
		if ($this->zbx->atLeast('6.2')) {
		    if ($hostIds)
			$params['hosts'] = array_map(fn($id) => [ 'hostid' => $id ], $hostIds);
		    if ($groupIds)
			$params['groups'] = array_map(fn($id) => [ 'groupid' => $id ], $groupIds);
		} else {
		    if ($hostIds)
			$params['hostids'] = $hostIds;
		    if ($groupIds)
			$params['groupids'] = $groupIds;
		}
		$r = (array)$this->call('maintenance.create', $params);
		return [ [ 'maintenanceids' => array_map('strval', (array)($r['maintenanceids'] ?? [])), 'name' => $name, 'start' => Zabbix::iso($start), 'end' => Zabbix::iso($start + $minutes * 60) ],
		    "maintenance `{$name}`: {$minutes} min from " . Zabbix::iso($start) ];
	    case 'maintenances':
		$hosts = self::names($value('hosts'));
		$params = [ 'output' => 'extend', 'selectHosts' => [ 'hostid', 'host', 'name' ], 'selectTimeperiods' => 'extend', 'limit' => $this->maxItems() + 1 ];
		$params[$this->zbx->atLeast('6.2') ? 'selectHostGroups' : 'selectGroups'] = [ 'groupid', 'name' ];
		if ($hosts)
		    $params['hostids'] = $this->zbx->hostIds($hosts);
		$rows = array_map(function($m) {
		    $m['groups'] = $m['hostgroups'] ?? $m['groups'] ?? [];
		    unset($m['hostgroups']);
		    $m['start'] = Zabbix::iso($m['active_since'] ?? 0);
		    $m['end'] = Zabbix::iso($m['active_till'] ?? 0);
		    return $m;
		}, (array)$this->call('maintenance.get', $params));
		return $this->listResult($rows, 'maintenance');
	    case 'maintenanceDelete':
		$ids = self::ids($value('maintenanceIds'), 'Maintenances');
		$r = (array)$this->call('maintenance.delete', $ids);
		return [ [ 'maintenanceids' => array_map('strval', (array)($r['maintenanceids'] ?? $ids)) ], \util\Format::plural(count($ids), 'maintenance') . ' removed' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
