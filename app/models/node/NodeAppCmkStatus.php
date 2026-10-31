<?php

/*
    CLD: 2026-11-01T08:00:00+02:00: new step: Checkmk Status - what Checkmk monitors, now (Livestatus through the REST API: domain-types/host | service/collections/all). Operations:
	problems   the current problems: hosts DOWN / UNREACH and services WARN / CRIT / UNKNOWN (CRIT only: at least CRIT), of a host or all; acknowledged ones and those in a
	           downtime left out unless chosen: { items: [ { kind, host_name, service, state, stateText, output, since, acknowledged, inDowntime } ], ... }
	host       a host's state and its services: { host: { ... }, services: [ ... ] } (none: an error)
	services   the services of a host (or of all hosts), of the states chosen
	query      any Livestatus query: the table (host / service), a filter (JSON, e.g. {"op": "=", "left": "host_name", "right": "web01"}) and the columns
*/

namespace models\node;

use util\Checkmk;

class NodeAppCmkStatus extends NodeAppCheckmk {
    public static $name = 'cmk-status';
    public static $label = 'Checkmk Status';
    public static $icon = 'bi-heart-pulse';
    public static $defaultOperation = 'problems';
    public static $defaultVariable = 'status';

    const OPERATIONS = [
	'problems' => 'List current problems',
	'host' => 'Get a host\'s state',
	'services' => 'List services',
	'query' => 'Livestatus query',
    ];
    const LISTS = [ 'problems', 'services', 'query' ];
    const SERVICE_STATES = [ 0 => 'OK', 1 => 'WARN', 2 => 'CRIT', 3 => 'UNKNOWN' ];
    const HOST_STATES = [ 0 => 'UP', 1 => 'DOWN', 2 => 'UNREACH' ];
    const MIN = [ '1' => 'WARN and worse', '2' => 'CRIT and worse' ];
    const STATES = [ 'all' => 'All', 'problems' => 'Not OK', '0' => 'OK', '1' => 'WARN', '2' => 'CRIT', '3' => 'UNKNOWN' ];
    const TABLES = [ 'service' => 'Services', 'host' => 'Hosts' ];
    const SERVICE_COLUMNS = [ 'host_name', 'description', 'state', 'plugin_output', 'last_state_change', 'acknowledged', 'scheduled_downtime_depth' ];
    const HOST_COLUMNS = [ 'name', 'state', 'plugin_output', 'last_state_change', 'acknowledged', 'scheduled_downtime_depth' ];

    protected static function operationFields() {
	return [
	    self::field('host', 'Host', [ 'problems', 'host', 'services' ], [ 'help' => 'Its name; empty: every host (not for a host\'s state)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'host' ] ], 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('minState', 'Services', [ 'problems' ], [ 'type' => 'select', 'default' => '1', 'choices' => self::MIN, 'ui' => [ 'width' => 6 ] ]),
	    self::field('acknowledged', 'With acknowledged problems', [ 'problems' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('downtime', 'With problems in a downtime', [ 'problems' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'services' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::STATES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('table', 'Table', [ 'query' ], [ 'type' => 'select', 'default' => 'service', 'choices' => self::TABLES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('columns', 'Columns', [ 'query' ], [ 'help' => 'Separated by commas, e.g. host_name, description, state, plugin_output', 'ui' => [ 'width' => 8 ] ]),
	    self::field('filter', 'Filter', [ 'query' ], [ 'type' => 'textarea', 'help' => 'A Livestatus filter as JSON (empty: all), e.g. {"op": ">", "left": "state", "right": 0}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	];
    }

    //a row of the service / host table as a problem: kind, host_name, service, state, stateText, output, since (ISO 8601), acknowledged, inDowntime
    public static function problem(array $r, $kind) {
	$state = (int)($r['state'] ?? 0);
	return [
	    'kind' => $kind,
	    'host_name' => (string)($kind === 'host' ? ($r['name'] ?? '') : ($r['host_name'] ?? '')),
	    'service' => $kind === 'host' ? null : (string)($r['description'] ?? ''),
	    'state' => $state,
	    'stateText' => ($kind === 'host' ? self::HOST_STATES : self::SERVICE_STATES)[$state] ?? (string)$state,
	    'output' => (string)($r['plugin_output'] ?? ''),
	    'since' => isset($r['last_state_change']) && (int)$r['last_state_change'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int)$r['last_state_change']) : null,
	    'acknowledged' => !empty($r['acknowledged']),
	    'inDowntime' => (int)($r['scheduled_downtime_depth'] ?? 0) > 0,
	];
    }

    //the current problems ($cmk: a util\Checkmk): hosts not UP, services at least $min; [ problems, truncated ]
    public static function problems(Checkmk $cmk, $host, $min, $withAck, $withDowntime, $max) {
	$common = fn($hostColumn) => Checkmk::all([
	    $host !== null ? Checkmk::eq($hostColumn, $host) : null,
	    $withAck ? null : Checkmk::eq('acknowledged', 0),
	    $withDowntime ? null : Checkmk::eq('scheduled_downtime_depth', 0),
	]);
	[ $hosts, $t1 ] = $cmk->rows('host', Checkmk::all([ Checkmk::cmp('>', 'state', 0), $common('name') ]), self::HOST_COLUMNS, $max);
	[ $services, $t2 ] = $cmk->rows('service', Checkmk::all([ Checkmk::cmp('>=', 'state', max(1, (int)$min)), $common('host_name') ]), self::SERVICE_COLUMNS, $max);
	$all = array_merge(array_map(fn($r) => self::problem($r, 'host'), $hosts), array_map(fn($r) => self::problem($r, 'service'), $services));
	return [ array_slice($all, 0, $max), $t1 || $t2 || count($all) > $max ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$host = fn() => $text('host') !== '' ? self::host($value('host')) : null;
	switch($operation) {
	    case 'problems':
		[ $rows, $truncated ] = self::problems($this->cmk, $host(), (int)$this->cfg('minState', '1'), (bool)$this->cfg('acknowledged', false), (bool)$this->cfg('downtime', false), $max);
		return $this->listResult($rows, $truncated, 'problem');
	    case 'host':
		$h = self::host($value('host'));
		[ $hosts ] = $this->cmk->rows('host', Checkmk::eq('name', $h), self::HOST_COLUMNS, 1);
		if (!$hosts)
		    throw new \RuntimeException("Host: Checkmk monitors no host `{$h}`");
		[ $services ] = $this->cmk->rows('service', Checkmk::eq('host_name', $h), self::SERVICE_COLUMNS, self::MAX_ITEMS);
		$hostRow = self::problem($hosts[0], 'host');
		$bad = count(array_filter($services, fn($s) => (int)($s['state'] ?? 0) > 0));
		return [ [ 'host' => $hostRow, 'services' => array_map(fn($r) => self::problem($r, 'service'), $services) ],
		    "{$h}: {$hostRow['stateText']}, " . \util\Format::plural(count($services), 'service') . ($bad ? ", {$bad} not OK" : '') ];
	    case 'services':
		$state = (string)$this->cfg('state', 'all');
		$filter = Checkmk::all([
		    $host() !== null ? Checkmk::eq('host_name', $host()) : null,
		    $state === 'problems' ? Checkmk::cmp('>', 'state', 0) : (ctype_digit($state) ? Checkmk::eq('state', (int)$state) : null),
		]);
		[ $list, $summary ] = $this->rowsOf('service', $filter, self::SERVICE_COLUMNS, 'service');
		$list['items'] = array_map(fn($r) => self::problem($r, 'service'), $list['items']);
		return [ $list, $summary ];
	    case 'query':
		$table = isset(self::TABLES[(string)$this->cfg('table', 'service')]) ? (string)$this->cfg('table', 'service') : 'service';
		$columns = array_values(array_filter(array_map('trim', explode(',', $text('columns')))));
		foreach($columns as $c)
		    if (!preg_match('/^[a-z_0-9]+$/', $c))
			throw new \RuntimeException("Columns: not a column: `{$c}`");
		$filter = $value('filter');
		if (is_string($filter)) {
		    $filter = trim($filter) === '' ? null : json_decode($filter, true);
		    if (trim((string)$this->cfg('filter', '')) !== '' && !is_array($filter))
			throw new \RuntimeException('Filter: not a JSON object');
		}
		return $this->rowsOf($table, $filter, $columns ?: ($table === 'host' ? self::HOST_COLUMNS : self::SERVICE_COLUMNS), $table === 'host' ? 'host' : 'service');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
