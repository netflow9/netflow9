<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new step: Wazuh Alerts - the alerts, read from the Wazuh indexer (wazuh-alerts-*). Operations (setting `operation`):
	search   the alerts of a time range, a rule level and worse, agents, rules, groups, MITRE techniques and a query; newest first
	get      one alert, by its _id
	count    how many match
	top      the most frequent values of a field among those that match (rule, agent, group, technique, source IP)
    An alert: as Wazuh wrote it, with the indexer's _id and _index
*/

namespace models\node;

class NodeAppWzAlerts extends NodeAppWazuh {
    public static $name = 'wazuh-alerts';
    public static $label = 'Wazuh Alerts';
    public static $icon = 'bi-exclamation-triangle';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'alerts';

    const OPERATIONS = [
	'search' => 'Search alerts',
	'get' => 'Get an alert',
	'count' => 'Count alerts',
	'top' => 'Most frequent values',
    ];
    const WRITES = [];
    const LISTS = [ 'search' ];
    const MAX_LEVEL = 16;
    const FIELDS = [
	'rule.id' => 'Rule',
	'rule.description' => 'Rule description',
	'rule.groups' => 'Rule group',
	'rule.mitre.id' => 'MITRE technique',
	'agent.name' => 'Agent',
	'data.srcip' => 'Source IP',
	'data.dstuser' => 'Target user',
    ];

    //the settings that choose alerts, shared with the trigger: $ops null: always shown
    public static function filterFields($ops = null) {
	$f = fn($key, $label, $more) => array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'default' => '' ], $ops !== null ? [ 'ops' => $ops ] : [], $more);
	return [
	    $f('minLevel', 'Level and worse', [ 'type' => 'number', 'help' => 'Of the rule, 0 to ' . self::MAX_LEVEL . '; empty: every level',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => self::MAX_LEVEL, 'skipEmpty' => true, 'message' => 'from 0 to ' . self::MAX_LEVEL ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    $f('agents', 'Agents', [ 'help' => 'Their ids or names, separated by commas; empty: every agent', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    $f('ruleIds', 'Rules', [ 'help' => 'Their ids, separated by commas; empty: every rule', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    $f('groups', 'Rule groups', [ 'help' => 'Separated by commas, any of them, e.g. authentication_failed; empty: every group', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    $f('mitre', 'MITRE techniques', [ 'help' => 'Their ids, separated by commas, e.g. T1110; empty: all', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    $f('query', 'Query', [ 'help' => 'In Lucene syntax, e.g. data.srcip:"10.0.0.5" AND NOT agent.name:web*', 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	];
    }

    protected static function operationFields() {
	$range = [ 'search', 'count', 'top' ];
	return array_merge(self::filterFields($range), [
	    self::field('since', 'From', $range, [ 'help' => 'A date / time, or e.g. -1 hour, yesterday; empty: the last 24 hours', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true, 'placeholder' => '-24 hours' ] ]),
	    self::field('until', 'To', $range, [ 'help' => 'Empty: now', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('alertId', 'Alert', [ 'get' ], [ 'required' => true, 'help' => 'Its _id (Search alerts, or the Wazuh alert trigger)', 'ui' => [ 'expression' => true ] ]),
	    self::field('field', 'Of', [ 'top' ], [ 'type' => 'select', 'required' => true, 'default' => 'rule.id', 'choices' => self::FIELDS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('topSize', 'How many', [ 'top' ], [ 'type' => 'number', 'default' => 10, 'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 1000, 'skipEmpty' => true, 'message' => 'from 1 to 1000' ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	]);
    }

    //the indexer's filters of the settings: $v: minLevel, agents, ruleIds, groups, mitre, query
    public static function filters(array $v) {
	$filters = [];
	if (($v['minLevel'] ?? '') !== '' && $v['minLevel'] !== null)
	    $filters[] = [ 'range' => [ 'rule.level' => [ 'gte' => (int)$v['minLevel'] ] ] ];
	$agents = self::names($v['agents'] ?? '', [ 'id', 'name' ]);
	if ($agents) {
	    $ids = array_values(array_filter($agents, fn($a) => preg_match('/^\d+$/', $a)));
	    $names = array_values(array_diff($agents, $ids));
	    $any = [];
	    if ($ids)
		$any[] = [ 'terms' => [ 'agent.id' => array_map(fn($i) => str_pad($i, 3, '0', STR_PAD_LEFT), $ids) ] ];
	    if ($names)
		$any[] = [ 'terms' => [ 'agent.name' => $names ] ];
	    $filters[] = count($any) === 1 ? $any[0] : [ 'bool' => [ 'should' => $any, 'minimum_should_match' => 1 ] ];
	}
	foreach([ 'ruleIds' => 'rule.id', 'groups' => 'rule.groups', 'mitre' => 'rule.mitre.id' ] as $key => $field) {
	    $list = self::names($v[$key] ?? '');
	    if ($list)
		$filters[] = [ 'terms' => [ $field => $list ] ];
	}
	$query = trim(\util\Expression::toText($v['query'] ?? ''));
	if ($query !== '')
	    $filters[] = [ 'query_string' => [ 'query' => $query, 'analyze_wildcard' => true ] ];
	return $filters;
    }

    //a range of `timestamp` (the time of the alert; ISO 8601)
    public static function timeRange(array $bounds) {
	return [ 'range' => [ 'timestamp' => $bounds + [ 'format' => 'strict_date_optional_time' ] ] ];
    }

    //"level 10 on web01: the rule's description (rule 5712)"
    public static function describe(array $a) {
	return 'level ' . ($a['rule']['level'] ?? '?') . ' on ' . ($a['agent']['name'] ?? '?') . ': ' . mb_substr((string)($a['rule']['description'] ?? ''), 0, 200) . ' (rule ' . ($a['rule']['id'] ?? '?') . ')';
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'get') {
	    $id = $text('alertId');
	    if ($id === '' || !preg_match('/^[\w.:-]{1,512}$/', $id))
		throw new \RuntimeException('Alert: not an _id: `' . mb_substr($id, 0, 64) . '`');
	    [ $hits ] = $this->wz->search(\util\Wazuh::ALERTS, array_replace([ 'size' => 1, 'query' => [ 'ids' => [ 'values' => [ $id ] ] ] ], $this->extraParams()));
	    if (!$hits)
		throw new \RuntimeException("no alert `{$id}`");
	    return [ $hits[0], self::describe($hits[0]) ];
	}
	$filters = self::filters([ 'minLevel' => $this->cfg('minLevel', ''), 'agents' => $value('agents'), 'ruleIds' => $value('ruleIds'), 'groups' => $value('groups'), 'mitre' => $value('mitre'), 'query' => $text('query') ]);
	$bounds = [ 'gte' => self::when($text('since'), 'From', '-24 hours') ];
	$until = self::when($text('until'), 'To');
	if ($until !== '')
	    $bounds['lte'] = $until;
	$filters[] = self::timeRange($bounds);
	$query = [ 'bool' => [ 'filter' => $filters ] ];
	switch($operation) {
	    case 'search':
		$max = $this->maxItems();
		[ $hits, $total ] = $this->wz->search(\util\Wazuh::ALERTS, array_replace([ 'size' => $max, 'sort' => [ [ 'timestamp' => [ 'order' => 'desc' ] ] ], 'track_total_hits' => true, 'query' => $query ], $this->extraParams()));
		$truncated = $total > count($hits);
		return [ [ 'items' => $hits, 'count' => count($hits), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($hits), 'alert') . ($truncated ? " of {$total}" : '') ];
	    case 'count':
		[ , $total ] = $this->wz->search(\util\Wazuh::ALERTS, array_replace([ 'size' => 0, 'track_total_hits' => true, 'query' => $query ], $this->extraParams()));
		return [ [ 'count' => $total ], \util\Format::plural($total, 'alert') ];
	    case 'top':
		$field = (string)$this->cfg('field', 'rule.id');
		if (!isset(self::FIELDS[$field]))
		    throw new \RuntimeException("Of: unknown field `{$field}`");
		$size = max(1, min(1000, (int)($this->cfg('topSize', 10) ?: 10)));
		[ , $total, $aggs ] = $this->wz->search(\util\Wazuh::ALERTS, array_replace([ 'size' => 0, 'track_total_hits' => true, 'query' => $query, 'aggs' => [ 'top' => [ 'terms' => [ 'field' => $field, 'size' => $size ] ] ] ], $this->extraParams()));
		$items = array_map(fn($b) => [ 'value' => $b['key'] ?? '', 'count' => (int)($b['doc_count'] ?? 0) ], (array)($aggs['top']['buckets'] ?? []));
		return [ [ 'field' => $field, 'items' => $items, 'total' => $total ], \util\Format::plural(count($items), 'value') . " of {$field} in " . \util\Format::plural($total, 'alert') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
