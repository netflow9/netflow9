<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: Salesforce Org - what the records refer to (reads only). Operations (setting `operation`):
	objects   the org's objects (sobjects): name (the API name), label, custom, queryable, createable; a search in names and labels
	describe  an object's fields (sobjects/<object>/describe): name, label, type, required, createable, updateable, externalId, the picklist's values, what a lookup refers to
	limits    the org's limits (limits): DailyApiRequests and the others, { Max, Remaining }
	user      the run-as user (oauth2/userinfo)
*/

namespace models\node;

class NodeAppSfOrg extends NodeAppSalesforce {
    public static $name = 'salesforce-org';
    public static $label = 'Salesforce Org';
    public static $icon = 'bi-building';
    public static $defaultOperation = 'describe';
    public static $defaultVariable = 'org';

    const OPERATIONS = [
	'objects' => 'List objects',
	'describe' => 'Describe an object',
	'limits' => 'Get the limits',
	'user' => 'Get the user',
    ];
    const WRITES = [];
    const LISTS = [ 'objects', 'describe' ];

    protected static function operationFields() {
	return [
	    self::field('object', 'Object', [ 'describe' ], [ 'required' => true, 'default' => 'Account', 'help' => 'Its API name, e.g. Lead, Invoice__c', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'objects', 'describe' ], [ 'help' => 'A text in the name or label; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->sf;
	$s = $text('search');
	$has = fn($a) => $s === '' || stripos((string)($a['name'] ?? ''), $s) !== false || stripos((string)($a['label'] ?? ''), $s) !== false;
	switch($operation) {
	    case 'objects':
		$all = array_values(array_filter(array_map(fn($o) => [ 'name' => $o['name'] ?? null, 'label' => $o['label'] ?? null, 'custom' => (bool)($o['custom'] ?? false),
		    'queryable' => (bool)($o['queryable'] ?? false), 'createable' => (bool)($o['createable'] ?? false), 'keyPrefix' => $o['keyPrefix'] ?? null ],
		    (array)($S->request('GET', '/sobjects')['sobjects'] ?? [])), $has));
		return $this->listResult($all, 'object');
	    case 'describe':
		$o = \util\Salesforce::name($text('object'), 'Object');
		$d = (array)$S->request('GET', "/sobjects/{$o}/describe");
		$all = array_values(array_filter(array_map(fn($f) => [ 'name' => $f['name'] ?? null, 'label' => $f['label'] ?? null, 'type' => $f['type'] ?? null, 'length' => $f['length'] ?? null,
		    'required' => !($f['nillable'] ?? true) && !($f['defaultedOnCreate'] ?? false) && ($f['createable'] ?? false), 'createable' => (bool)($f['createable'] ?? false),
		    'updateable' => (bool)($f['updateable'] ?? false), 'externalId' => (bool)($f['externalId'] ?? false), 'referenceTo' => $f['referenceTo'] ?? [],
		    'picklistValues' => array_map(fn($p) => [ 'value' => $p['value'] ?? null, 'label' => $p['label'] ?? null, 'active' => (bool)($p['active'] ?? true) ], (array)($f['picklistValues'] ?? [])) ],
		    (array)($d['fields'] ?? [])), $has));
		[ $res, $sum ] = $this->listResult($all, 'field');
		return [ $res + [ 'object' => $d['name'] ?? $o, 'label' => $d['label'] ?? null ], "{$o}: {$sum}" ];
	    case 'limits':
		$l = (array)$S->request('GET', '/limits');
		$api = $l['DailyApiRequests'] ?? [];
		return [ $l, 'API requests left today: ' . ($api['Remaining'] ?? '?') . ' of ' . ($api['Max'] ?? '?') ];
	    case 'user':
		$u = (array)$S->request('GET', '/services/oauth2/userinfo');
		return [ $u, (string)($u['preferred_username'] ?? $u['name'] ?? '?') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
