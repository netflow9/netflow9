<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: GLPI Setup - what tickets and items refer to; changes nothing. Operations (setting `operation`):
	users          the users whose login contains Search (or all): id, name (login), realname, firstname, active, entity
	user           a user (its id, login or email) with its emails, groups and profiles
	groups         the groups (Search: in their names): id, name, completename, entity
	entities       the entities the session sees
	categories     the ITIL categories (of tickets, problems, changes)
	locations      the locations
	searchOptions  an item type's search options: the fields' numbers of the search engine's criteria (Ticket: 12 status, 7 category, …)
*/

namespace models\node;

class NodeAppGlpiSetup extends NodeAppGlpi {
    public static $name = 'glpi-setup';
    public static $label = 'GLPI Setup';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'users';
    public static $defaultVariable = 'glpi';

    const OPERATIONS = [
	'users' => 'List users',
	'user' => 'Get a user',
	'groups' => 'List groups',
	'entities' => 'List entities',
	'categories' => 'List categories',
	'locations' => 'List locations',
	'searchOptions' => 'List search options',
    ];
    const WRITES = [];
    const LISTS = [ 'users', 'groups', 'entities', 'categories', 'locations', 'searchOptions' ];
    //the lists of dropdowns: operation => item type
    const DROPDOWNS = [ 'groups' => 'Group', 'entities' => 'Entity', 'categories' => 'ITILCategory', 'locations' => 'Location' ];

    protected static function operationFields() {
	return [
	    self::field('search', 'Search', [ 'users', 'groups', 'entities', 'categories', 'locations', 'searchOptions' ], [ 'help' => 'A part of the login / name; search options: of their names',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'User', [ 'user' ], [ 'required' => true, 'help' => 'Its id, login or email', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('itemtype', 'Item type', [ 'searchOptions' ], [ 'required' => true, 'default' => 'Ticket', 'help' => 'e.g. Ticket, Computer, User', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->glpi;
	$s = $text('search');
	$expand = [ 'expand_dropdowns' => 'true' ];
	switch($operation) {
	    case 'users':
		[ $all, $truncated ] = $G->items('/User', $expand + ($s !== '' ? [ 'searchText' => [ 'name' => $s ] ] : []), $this->maxItems());
		$items = array_map(fn($u) => [ 'id' => (int)($u['id'] ?? 0), 'name' => $u['name'] ?? null, 'realname' => $u['realname'] ?? null, 'firstname' => $u['firstname'] ?? null,
		    'active' => (bool)($u['is_active'] ?? true), 'entity' => isset($u['entities_id']) ? self::decoded($u['entities_id']) : null ], $all);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'user') . ($truncated ? ', more left out' : '') ];
	    case 'user':
		$id = $this->userId($value('user'), 'User');
		$u = (array)$G->request('GET', "/User/{$id}", $expand);
		unset($u['password'], $u['api_token'], $u['personal_token'], $u['cookie_token'], $u['password_forget_token'], $u['links']);
		$emails = array_map(fn($e) => [ 'email' => $e['email'] ?? null, 'default' => (bool)($e['is_default'] ?? false) ], (array)$G->request('GET', "/User/{$id}/UserEmail"));
		$groups = array_map(fn($g) => self::decoded($g['groups_id'] ?? ''), (array)$G->request('GET', "/User/{$id}/Group_User", $expand));
		$profiles = array_map(fn($p) => [ 'profile' => self::decoded($p['profiles_id'] ?? ''), 'entity' => self::decoded($p['entities_id'] ?? ''), 'recursive' => (bool)($p['is_recursive'] ?? false) ],
		    (array)$G->request('GET', "/User/{$id}/Profile_User", $expand));
		$r = [ 'id' => $id, 'url' => $G->formUrl('User', $id), 'name' => $u['name'] ?? null, 'realname' => $u['realname'] ?? null, 'firstname' => $u['firstname'] ?? null,
		    'emails' => $emails, 'groups' => $groups, 'profiles' => $profiles, 'fields' => $u ];
		return [ $r, "user #{$id}: " . ($r['name'] ?? '') ];
	    case 'searchOptions':
		$type = \util\Glpi::itemtype($text('itemtype') ?: 'Ticket');
		$all = [];
		foreach((array)$G->request('GET', "/listSearchOptions/{$type}") as $num => $o)
		    if (is_numeric($num) && is_array($o) && ($s === '' || stripos((string)($o['name'] ?? ''), $s) !== false))
			$all[] = [ 'field' => (int)$num, 'name' => $o['name'] ?? null, 'table' => $o['table'] ?? null, 'column' => $o['field'] ?? null, 'datatype' => $o['datatype'] ?? null,
			    'searchtypes' => array_values((array)($o['available_searchtypes'] ?? [])) ];
		return $this->listResult($all, 'search option');
	}
	if (isset(self::DROPDOWNS[$operation])) {
	    $type = self::DROPDOWNS[$operation];
	    [ $all, $truncated ] = $G->items("/{$type}", $expand + ($s !== '' ? [ 'searchText' => [ 'name' => $s ] ] : []), $this->maxItems());
	    $items = array_map(fn($d) => [ 'id' => (int)($d['id'] ?? 0), 'name' => self::decoded($d['name'] ?? ''), 'completename' => isset($d['completename']) ? self::decoded($d['completename']) : null,
		'entity' => isset($d['entities_id']) ? self::decoded($d['entities_id']) : null, 'comment' => $d['comment'] ?? null ], $all);
	    return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), strtolower($type) === 'itilcategory' ? 'category' : strtolower($type)) . ($truncated ? ', more left out' : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
