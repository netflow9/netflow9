<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new step: Wazuh Vulnerabilities - what the vulnerability detector found, read from the Wazuh indexer (wazuh-states-vulnerabilities-*, Wazuh 4.8 and
    later). Operations (setting `operation`):
	list      the vulnerabilities of agents, severities, a CVE, a package; the highest score first
	summary   how many, per severity
*/

namespace models\node;

class NodeAppWzVulnerabilities extends NodeAppWazuh {
    public static $name = 'wazuh-vulnerabilities';
    public static $label = 'Wazuh Vulnerabilities';
    public static $icon = 'bi-bug';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'vulnerabilities';

    const OPERATIONS = [ 'list' => 'List vulnerabilities', 'summary' => 'Count per severity' ];
    const WRITES = [];
    const LISTS = [ 'list' ];
    const SEVERITIES = [ 'Critical' => 'Critical', 'High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low' ];

    protected static function operationFields() {
	$all = [ 'list', 'summary' ];
	return [
	    self::field('agents', 'Agents', $all, [ 'help' => 'Their ids or names, separated by commas; empty: every agent', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('severities', 'Severities', $all, [ 'type' => 'select', 'multiple' => true, 'default' => [ 'Critical', 'High' ], 'choices' => self::SEVERITIES, 'help' => 'Empty: every severity', 'ui' => [ 'width' => 6 ] ]),
	    self::field('cve', 'CVE', $all, [ 'help' => 'Its id, e.g. CVE-2024-3094; empty: every one', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true ] ]),
	    self::field('package', 'Package', $all, [ 'help' => 'Its exact name; empty: every package', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$filters = NodeAppWzAlerts::filters([ 'agents' => $value('agents') ]);
	$severities = array_values(array_intersect(array_map('strval', (array)$this->cfg('severities', [])), array_keys(self::SEVERITIES)));
	if ($severities)
	    $filters[] = [ 'terms' => [ 'vulnerability.severity' => $severities ] ];
	foreach([ 'cve' => 'vulnerability.id', 'package' => 'package.name' ] as $key => $field) {
	    if ($text($key) !== '')
		$filters[] = [ 'term' => [ $field => $text($key) ] ];
	}
	$query = [ 'bool' => [ 'filter' => $filters ] ];
	if ($operation === 'summary') {
	    [ , $total, $aggs ] = $this->wz->search(\util\Wazuh::VULNERABILITIES, array_replace([ 'size' => 0, 'track_total_hits' => true, 'query' => $query, 'aggs' => [ 'severity' => [ 'terms' => [ 'field' => 'vulnerability.severity', 'size' => 10 ] ] ] ], $this->extraParams()));
	    $bySeverity = array_fill_keys(array_keys(self::SEVERITIES), 0);
	    foreach((array)($aggs['severity']['buckets'] ?? []) as $b)
		$bySeverity[(string)($b['key'] ?? '')] = (int)($b['doc_count'] ?? 0);
	    return [ [ 'total' => $total ] + $bySeverity, \util\Format::plural($total, 'vulnerability') ];
	}
	if ($operation !== 'list')
	    throw new \RuntimeException("unknown operation `{$operation}`");
	[ $hits, $total ] = $this->wz->search(\util\Wazuh::VULNERABILITIES, array_replace([ 'size' => $this->maxItems(), 'sort' => [ [ 'vulnerability.score.base' => [ 'order' => 'desc', 'unmapped_type' => 'float' ] ] ], 'track_total_hits' => true, 'query' => $query ], $this->extraParams()));
	$truncated = $total > count($hits);
	return [ [ 'items' => $hits, 'count' => count($hits), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($hits), 'vulnerability') . ($truncated ? " of {$total}" : '') ];
    }
}
