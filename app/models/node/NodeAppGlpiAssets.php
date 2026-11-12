<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: GLPI Assets - the inventory's items of one type (Computer, Monitor, NetworkEquipment, Printer, Phone, Peripheral, Software, …; any item type
    of GLPI's API). Operations (setting `operation`):
	list     the items, filtered by name, serial and inventory number (parts of them), the newest updated first
	get      an item, with what With names (network ports, software, disks, financial info, contracts, documents, tickets, connections, history)
	search   the items GLPI's search criteria find (a JSON list; the fields' numbers: GLPI Setup, List search options)
	create   an item: name, serial, inventory number, status, location, comment; the other fields in Other fields
	update   what is not empty of those; Other fields merged
	delete   to the trash, or purged
    An item: { id, url, and its fields as GLPI gives them (dropdowns by name: Operating system, Location, …) }
*/

namespace models\node;

class NodeAppGlpiAssets extends NodeAppGlpi {
    public static $name = 'glpi-assets';
    public static $label = 'GLPI Assets';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'assets';

    const OPERATIONS = [
	'list' => 'List items',
	'get' => 'Get an item',
	'search' => 'Search items',
	'create' => 'Create an item',
	'update' => 'Update an item',
	'delete' => 'Delete an item',
    ];
    const WRITES = [ 'create', 'update', 'delete' ];
    const LISTS = [ 'list', 'search' ];
    const ONE = [ 'get', 'update', 'delete' ];
    //Get an item's With: GLPI's with_<x> options
    const WITH = [ 'networkports', 'softwares', 'disks', 'infocoms', 'contracts', 'documents', 'tickets', 'problems', 'changes', 'connections', 'notes', 'logs' ];

    protected static function operationFields() {
	$edit = [ 'create', 'update' ];
	return [
	    self::field('itemtype', 'Item type', null, [ 'required' => true, 'default' => 'Computer', 'help' => 'As GLPI names it: Computer, Monitor, NetworkEquipment, Printer, Phone, Peripheral, Software, …',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('item', 'Item', self::ONE, [ 'required' => true, 'help' => 'Its id, its address, or an item of a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('name', 'Name', array_merge([ 'list' ], $edit), [ 'help' => 'List: a part of it. Required to create; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 4, 'newRow' => true ] ]),
	    self::field('serial', 'Serial number', array_merge([ 'list' ], $edit), [ 'help' => 'List: a part of it', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('otherserial', 'Inventory number', array_merge([ 'list' ], $edit), [ 'help' => 'List: a part of it', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('state', 'Status', $edit, [ 'help' => 'Its id or name, e.g. In use', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('location', 'Location', $edit, [ 'help' => 'Its id or complete name, e.g. HQ > Floor 2', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('entity', 'Entity', [ 'create' ], [ 'help' => 'Its id or complete name; empty: the session\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('comment', 'Comment', $edit, [ 'type' => 'textarea', 'ui' => [ 'rows' => 2, 'expression' => 'text' ] ]),
	    self::field('fields', 'Other fields', $edit, [ 'type' => 'textarea', 'help' => 'A JSON object merged into the input, as GLPI names the fields, e.g. {"users_id": 5, "manufacturers_id": 2}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('criteria', 'Criteria', [ 'search' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'GLPI\'s search criteria, a JSON list, e.g. [{"field": 1, "searchtype": "contains", "value": "srv"}]',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('with', 'With', [ 'get' ], [ 'help' => 'Separated by commas: ' . implode(', ', self::WITH), 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('purge', 'Purge', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Deleted for good; off: to the trash', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //the input of create / update: what is not empty; Other fields merged
    protected function itemInput(\Closure $text, \Closure $value, $create) {
	$in = [];
	foreach([ 'name', 'serial', 'otherserial', 'comment' ] as $k)
	    if (($t = $text($k)) !== '')
		$in[$k] = $t;
	if (($id = $this->refId('State', $value('state'), 'Status')) !== null)
	    $in['states_id'] = $id;
	if (($id = $this->refId('Location', $value('location'), 'Location')) !== null)
	    $in['locations_id'] = $id;
	if ($create && ($id = $this->refId('Entity', $value('entity'), 'Entity')) !== null)
	    $in['entities_id'] = $id;
	return array_replace($in, $this->jsonOf('fields', 'Other fields'));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->glpi;
	$type = \util\Glpi::itemtype($text('itemtype') ?: 'Computer');
	$expand = [ 'expand_dropdowns' => 'true' ];
	switch($operation) {
	    case 'list':
		$filters = array_filter([ 'name' => $text('name'), 'serial' => $text('serial'), 'otherserial' => $text('otherserial') ], fn($v) => $v !== '');
		[ $all, $truncated ] = $G->items("/{$type}", $expand + [ 'sort' => 'date_mod', 'order' => 'DESC' ] + ($filters ? [ 'searchText' => $filters ] : []), $this->maxItems());
		$items = array_map(fn($i) => self::itemOf($type, (array)$i, $G), $all);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'item') . " ({$type})" . ($truncated ? ', more left out' : '') ];
	    case 'search':
		$criteria = $this->jsonOf('criteria', 'Criteria', true);
		if (!$criteria)
		    throw new \RuntimeException('Criteria: empty');
		[ $rows, $total ] = $G->search($type, $criteria, $this->maxItems(), [ 'forcedisplay' => [ 2 ] ]);
		$items = array_map(fn($i) => self::itemOf($type, $i, $G), $G->many($type, array_column($rows, '2'), $expand));
		$truncated = $total > count($rows);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'item') . " ({$type})" . ($truncated ? " of {$total}" : '') ];
	    case 'create':
		$in = $this->itemInput($text, $value, true);
		if (trim((string)($in['name'] ?? '')) === '')
		    throw new \RuntimeException('Name: required to create an item');
		$made = (array)$G->request('POST', "/{$type}", [], [ 'input' => $in ]);
		$i = self::itemOf($type, (array)$G->request('GET', "/{$type}/" . \util\Glpi::itemId($made['id'] ?? '', $type), $expand), $G);
		return [ $i, "{$type} #{$i['id']} created: " . ($i['name'] ?? '') ];
	}
	$id = \util\Glpi::itemId($value('item'), 'Item');
	switch($operation) {
	    case 'get':
		$with = [];
		foreach(self::names($text('with')) as $w) {
		    $w = strtolower(preg_replace('/^with_/i', '', $w));
		    if (!in_array($w, self::WITH, true))
			throw new \RuntimeException("With: `{$w}` is not one of " . implode(', ', self::WITH));
		    $with["with_{$w}"] = 'true';
		}
		$i = self::itemOf($type, (array)$G->request('GET', "/{$type}/{$id}", $expand + $with), $G);
		return [ $i, "{$type} #{$id}: " . ($i['name'] ?? '') ];
	    case 'update':
		$in = $this->itemInput($text, $value, false);
		if (!$in)
		    throw new \RuntimeException('nothing to change');
		$G->request('PUT', "/{$type}/{$id}", [], [ 'input' => $in ]);
		$i = self::itemOf($type, (array)$G->request('GET', "/{$type}/{$id}", $expand), $G);
		return [ $i, "{$type} #{$id} updated: " . implode(', ', array_keys($in)) ];
	    case 'delete':
		$purge = (bool)$this->cfg('purge', false);
		$G->request('DELETE', "/{$type}/{$id}", [ 'force_purge' => $purge ? 'true' : 'false' ]);
		return [ [ 'itemtype' => $type, 'id' => $id, 'deleted' => true, 'purged' => $purge ], "{$type} #{$id} " . ($purge ? 'purged' : 'put in the trash') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
