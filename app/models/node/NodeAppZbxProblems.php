<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Zabbix Problems - what Zabbix reports now. Operations:
	problems   the current problems (problem.get: unresolved), of a severity and worse, of hosts / host groups (by name), with tags; acknowledged and suppressed ones left out
	           unless chosen; newest first; each with its hosts (event.get): { items: [ problem() ], ... }
	event      one event (event.get by id): problem() + acknowledges (who, when, message, action)
*/

namespace models\node;

use util\Zabbix;

class NodeAppZbxProblems extends NodeAppZabbix {
    public static $name = 'zbx-problems';
    public static $label = 'Zabbix Problems';
    public static $icon = 'bi-exclamation-octagon';
    public static $defaultOperation = 'problems';
    public static $defaultVariable = 'problems';

    const OPERATIONS = [
	'problems' => 'List current problems',
	'event' => 'Get an event',
    ];
    const LISTS = [ 'problems' ];
    //not 0: a select keeps no '0' (and keys 0..5 would be a list, read as labels)
    const MIN = [ 'any' => 'All severities', '1' => 'Information and worse', '2' => 'Warning and worse', '3' => 'Average and worse', '4' => 'High and worse', '5' => 'Disaster' ];

    protected static function operationFields() {
	return [
	    self::field('minSeverity', 'Severity', [ 'problems' ], [ 'type' => 'select', 'default' => '2', 'choices' => self::MIN, 'ui' => [ 'width' => 6 ] ]),
	    self::field('hosts', 'Hosts', [ 'problems' ], [ 'help' => 'Their technical names, separated by commas; empty: every host', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('groups', 'Host groups', [ 'problems' ], [ 'help' => 'Their names, separated by commas; empty: every group', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('tags', 'Tags', [ 'problems' ], [ 'help' => 'tag=value, separated by commas (a value: contains it; tag= or tag: has the tag); all must match',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('acknowledged', 'With acknowledged problems', [ 'problems' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('suppressed', 'With suppressed problems', [ 'problems' ], [ 'type' => 'switch', 'default' => false, 'help' => 'In a maintenance', 'ui' => [ 'width' => 6 ] ]),
	    self::field('eventId', 'Event', [ 'event' ], [ 'required' => true, 'help' => 'Its id, e.g. {{ zbx.eventid }}', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    //a problem or event of Zabbix as the steps and the trigger give it: eventid, name, severity, severityText, hosts [ { hostid, host, name } ], host (the first's
    //technical name), hostName (its visible name), since (ISO 8601), acknowledged, suppressed, opdata, tags { tag: value }, triggerid, url
    public static function problem(array $p, array $hosts = []) {
	$sev = (int)($p['severity'] ?? 0);
	$tags = [];
	foreach((array)($p['tags'] ?? []) as $t)
	    $tags[(string)($t['tag'] ?? '')] = (string)($t['value'] ?? '');
	$hosts = array_values(array_map(fn($h) => [ 'hostid' => (string)($h['hostid'] ?? ''), 'host' => (string)($h['host'] ?? ''), 'name' => (string)($h['name'] ?? $h['host'] ?? '') ], $hosts));
	return [
	    'eventid' => (string)($p['eventid'] ?? ''),
	    'name' => (string)($p['name'] ?? ''),
	    'severity' => $sev,
	    'severityText' => Zabbix::SEVERITIES[$sev] ?? (string)$sev,
	    'hosts' => $hosts,
	    'host' => $hosts[0]['host'] ?? '',
	    'hostName' => $hosts[0]['name'] ?? '',
	    'since' => Zabbix::iso($p['clock'] ?? 0),
	    'acknowledged' => (string)($p['acknowledged'] ?? '0') === '1',
	    'suppressed' => (string)($p['suppressed'] ?? '0') === '1',
	    'opdata' => (string)($p['opdata'] ?? ''),
	    'tags' => (object)$tags,
	    'triggerid' => (string)($p['objectid'] ?? ''),
	];
    }

    //the current problems ($z: a util\Zabbix): of severity $min and worse, of host ids / group ids, with tags ([ { tag, value } ]); newest first; with their hosts;
    //at most $limit (one more is read: [ problems, truncated ])
    public static function problems(Zabbix $z, $min, array $hostIds, array $groupIds, array $tags, $withAck, $withSuppressed, $limit, array $extra = []) {
	$params = [ 'output' => 'extend', 'selectTags' => 'extend', 'recent' => false, 'sortfield' => [ 'eventid' ], 'sortorder' => 'DESC', 'limit' => $limit + 1,
	    'severities' => range(max(0, min(5, (int)$min)), 5) ];
	if ($hostIds)
	    $params['hostids'] = $hostIds;
	if ($groupIds)
	    $params['groupids'] = $groupIds;
	if ($tags)
	    $params['tags'] = array_map(fn($t) => [ 'tag' => $t['tag'], 'value' => $t['value'], 'operator' => $t['value'] === '' ? 4 : 0 ], $tags);
	if (!$withAck)
	    $params['acknowledged'] = false;
	if (!$withSuppressed)
	    $params['suppressed'] = false;
	$rows = (array)$z->call('problem.get', array_replace_recursive($params, $extra));
	$truncated = count($rows) > $limit;
	$rows = array_slice($rows, 0, $limit);
	$hosts = [];
	if ($rows) {
	    foreach((array)$z->call('event.get', [ 'eventids' => array_column($rows, 'eventid'), 'output' => [ 'eventid' ], 'selectHosts' => [ 'hostid', 'host', 'name' ] ]) as $ev)
		$hosts[(string)$ev['eventid']] = (array)($ev['hosts'] ?? []);
	}
	return [ array_map(fn($p) => self::problem($p, $hosts[(string)$p['eventid']] ?? []), $rows), $truncated ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'problems':
		$hosts = self::names($value('hosts'));
		$groups = self::names($value('groups'));
		[ $rows, $truncated ] = self::problems($this->zbx, (int)$this->cfg('minSeverity', '2'), $hosts ? $this->zbx->hostIds($hosts) : [], $groups ? $this->zbx->groupIds($groups) : [],
		    self::tags($value('tags')), (bool)$this->cfg('acknowledged', false), (bool)$this->cfg('suppressed', false), $this->maxItems(), $this->extraParams());
		return [ [ 'items' => $rows, 'count' => count($rows), 'total' => count($rows), 'truncated' => $truncated ], \util\Format::plural(count($rows), 'problem') . ($truncated ? ' (truncated)' : '') ];
	    case 'event':
		$id = self::ids($value('eventId'), 'Event')[0];
		$ev = (array)$this->call('event.get', [ 'eventids' => [ $id ], 'output' => 'extend', 'selectHosts' => [ 'hostid', 'host', 'name' ], 'selectTags' => 'extend',
		    'selectAcknowledges' => [ 'clock', 'message', 'action', 'username', 'name', 'surname' ] ]);
		if (!$ev)
		    throw new \RuntimeException("Event: Zabbix has no event {$id}");
		$e = $ev[0];
		$out = self::problem($e, (array)($e['hosts'] ?? [])) + [
		    'value' => (int)($e['value'] ?? 1) === 1 ? 'problem' : 'ok',
		    'acknowledges' => array_map(fn($a) => [ 'clock' => Zabbix::iso($a['clock'] ?? 0), 'message' => (string)($a['message'] ?? ''), 'action' => (int)($a['action'] ?? 0),
			'user' => trim(($a['name'] ?? '') . ' ' . ($a['surname'] ?? '')) ?: (string)($a['username'] ?? '') ], (array)($e['acknowledges'] ?? [])),
		];
		return [ $out, "event {$id}: {$out['name']} ({$out['severityText']})" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
