<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: Zendesk Setup - what tickets refer to; changes nothing. Operations (setting `operation`):
	groups        the groups: { id, name, description, default }
	ticketFields  the ticket fields: { id, title, type, active, required, options } - a custom field's id for Custom fields
	views         the active views: { id, title, position }
	viewTickets   the tickets of a view (its id or title), in its order
	macros        the active macros: { id, title, description }
*/

namespace models\node;

class NodeAppZendeskSetup extends NodeAppZendesk {
    public static $name = 'zendesk-setup';
    public static $label = 'Zendesk Setup';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'groups';
    public static $defaultVariable = 'zendesk';

    const OPERATIONS = [
	'groups' => 'List groups',
	'ticketFields' => 'List ticket fields',
	'views' => 'List views',
	'viewTickets' => 'List a view\'s tickets',
	'macros' => 'List macros',
    ];
    const WRITES = [];
    const LISTS = [ 'groups', 'ticketFields', 'views', 'viewTickets', 'macros' ];

    protected static function operationFields() {
	return [
	    self::field('search', 'Name contains', [ 'groups', 'ticketFields', 'views', 'macros' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('view', 'View', [ 'viewTickets' ], [ 'required' => true, 'help' => 'Its id or title, or a view of a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$Z = $this->zendesk;
	$s = $text('search');
	$named = fn(array $list, $key) => array_values(array_filter($list, fn($x) => $s === '' || stripos((string)($x[$key] ?? ''), $s) !== false));
	switch($operation) {
	    case 'groups':
		[ $all ] = $Z->pages('/groups', 'groups', [], self::MAX_ITEMS);
		return $this->listResult($named(array_map(fn($g) => [ 'id' => $g['id'] ?? null, 'name' => $g['name'] ?? null, 'description' => $g['description'] ?? null,
		    'default' => (bool)($g['default'] ?? false) ], $all), 'name'), 'group');
	    case 'ticketFields':
		[ $all ] = $Z->pages('/ticket_fields', 'ticket_fields', [], self::MAX_ITEMS);
		return $this->listResult($named(array_map(fn($f) => [ 'id' => $f['id'] ?? null, 'title' => $f['title'] ?? null, 'type' => $f['type'] ?? null, 'active' => (bool)($f['active'] ?? true),
		    'required' => (bool)($f['required'] ?? false), 'options' => array_map(fn($o) => [ 'name' => $o['name'] ?? null, 'value' => $o['value'] ?? null ], (array)($f['custom_field_options'] ?? [])) ],
		    $all), 'title'), 'ticket field');
	    case 'views':
		[ $all ] = $Z->pages('/views/active', 'views', [], self::MAX_ITEMS);
		return $this->listResult($named(array_map(fn($v) => [ 'id' => $v['id'] ?? null, 'title' => $v['title'] ?? null, 'position' => $v['position'] ?? null ], $all), 'title'), 'view');
	    case 'macros':
		[ $all ] = $Z->pages('/macros/active', 'macros', [], self::MAX_ITEMS);
		return $this->listResult($named(array_map(fn($m) => [ 'id' => $m['id'] ?? null, 'title' => $m['title'] ?? null, 'description' => $m['description'] ?? null ], $all), 'title'), 'macro');
	    case 'viewTickets':
		$id = $this->refId($value('view'), 'View', function($t, $label) use ($Z) {
		    [ $all ] = $Z->pages('/views/active', 'views', [], self::MAX_ITEMS);
		    $found = array_values(array_filter($all, fn($v) => strcasecmp((string)($v['title'] ?? ''), $t) === 0));
		    if (count($found) !== 1)
			throw new \RuntimeException("{$label}: " . ($found ? count($found) . " views are named `{$t}`" : "no active view `{$t}`"));
		    return (int)$found[0]['id'];
		});
		if ($id === null)
		    throw new \RuntimeException('View: empty');
		[ $all, $truncated ] = $Z->pages("/views/{$id}/tickets", 'tickets', [], $this->maxItems());
		$items = array_map(fn($t) => NodeAppZendeskTickets::ticketOf((array)$t, $Z), $all);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'ticket') . ($truncated ? ', more left out' : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
