<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: HubSpot CRM - what the records refer to (reads only). Operations (setting `operation`):
	properties  an object type's properties (crm/v3/properties): name (what the steps take), label, type, fieldType, the choices of an enumeration
	pipelines   the pipelines of deals or tickets with their stages (crm/v3/pipelines): the stage IDs dealstage / hs_pipeline_stage take
	owners      the owners (crm/v3/owners): id (what hubspot_owner_id takes), email, name, teams; one by email
*/

namespace models\node;

class NodeAppHsCrm extends NodeAppHubSpot {
    public static $name = 'hubspot-crm';
    public static $label = 'HubSpot CRM';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'properties';
    public static $defaultVariable = 'crm';

    const OPERATIONS = [
	'properties' => 'List properties',
	'pipelines' => 'List pipelines and stages',
	'owners' => 'List owners',
    ];
    const WRITES = [];
    const LISTS = [ 'properties', 'pipelines', 'owners' ];
    const PIPELINE_TYPES = [ 'deals' => 'Deals', 'tickets' => 'Tickets' ];

    protected static function operationFields() {
	return [
	    self::field('objectType', 'Of', [ 'properties' ], [ 'help' => 'contacts, companies, deals, tickets, or a custom object\'s type ID', 'default' => 'contacts', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'properties' ], [ 'help' => 'A text in the name or label; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('pipelineType', 'Of', [ 'pipelines' ], [ 'type' => 'select', 'default' => 'deals', 'choices' => self::PIPELINE_TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('email', 'Email', [ 'owners' ], [ 'help' => 'One owner\'s; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$H = $this->hs;
	switch($operation) {
	    case 'properties':
		$type = strtolower($text('objectType') ?: 'contacts');
		if (!preg_match(NodeAppHsRecords::TYPE_PATTERN, $type))
		    throw new \RuntimeException("Of: not an object type: `{$type}`");
		$s = $text('search');
		$all = array_values(array_filter(array_map(fn($p) => [ 'name' => $p['name'] ?? null, 'label' => $p['label'] ?? null, 'type' => $p['type'] ?? null, 'fieldType' => $p['fieldType'] ?? null,
		    'groupName' => $p['groupName'] ?? null, 'options' => array_map(fn($o) => [ 'value' => $o['value'] ?? null, 'label' => $o['label'] ?? null ], (array)($p['options'] ?? [])),
		    'readOnly' => (bool)($p['modificationMetadata']['readOnlyValue'] ?? false) ], (array)($H->request('GET', '/crm/v3/properties/' . rawurlencode($type))['results'] ?? [])),
		    fn($p) => $s === '' || stripos((string)$p['name'], $s) !== false || stripos((string)$p['label'], $s) !== false));
		return $this->listResult($all, 'property');
	    case 'pipelines':
		$type = isset(self::PIPELINE_TYPES[(string)$this->cfg('pipelineType', 'deals')]) ? (string)$this->cfg('pipelineType', 'deals') : 'deals';
		$all = array_map(fn($p) => [ 'id' => $p['id'] ?? null, 'label' => $p['label'] ?? null, 'stages' => array_map(fn($s) => [ 'id' => $s['id'] ?? null, 'label' => $s['label'] ?? null,
		    'displayOrder' => $s['displayOrder'] ?? null, 'metadata' => $s['metadata'] ?? null ], (array)($p['stages'] ?? [])) ], (array)($H->request('GET', "/crm/v3/pipelines/{$type}")['results'] ?? []));
		return $this->listResult($all, 'pipeline');
	    case 'owners':
		$all = [];
		$after = null;
		do {
		    $d = (array)$H->request('GET', '/crm/v3/owners', [ 'email' => $text('email'), 'limit' => 100, 'after' => $after ]);
		    foreach((array)($d['results'] ?? []) as $o)
			$all[] = [ 'id' => (string)($o['id'] ?? ''), 'email' => $o['email'] ?? null, 'firstName' => $o['firstName'] ?? null, 'lastName' => $o['lastName'] ?? null,
			    'userId' => $o['userId'] ?? null, 'teams' => array_map(fn($t) => $t['name'] ?? null, (array)($o['teams'] ?? [])), 'archived' => (bool)($o['archived'] ?? false) ];
		    $after = $d['paging']['next']['after'] ?? null;
		} while ($after !== null && count($all) <= $this->maxItems());
		return $this->listResult($all, 'owner');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
