<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud Users - the server's users and groups (the OCS provisioning API, cloud/users, cloud/groups): for an administrator, or a group's
    subadmin (its groups' users). Operations (setting `operation`):
	list         the users (cloud/users/details), a search on ID, name and email, paged; each with its details
	get          a user: id, displayname, email, enabled, groups, quota { used, total, relative }, lastLogin, language, ...
	create       a user: ID, name, email, groups, quota; without a password Nextcloud emails the user a link to set one (the email is then required)
	update       its name, email, quota (those not empty; one request each, as the API takes them)
	enable       the user may sign in again / not any more
	disable
	addToGroup   added to / removed from groups
	removeFromGroup
	delete       the user deleted, with its files
	groups       the groups (cloud/groups), a search
*/

namespace models\node;

class NodeAppNxUsers extends NodeAppNextcloud {
    public static $name = 'nextcloud-users';
    public static $label = 'Nextcloud Users';
    public static $icon = 'bi-people';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'users';

    const OPERATIONS = [
	'list' => 'List users',
	'get' => 'Get a user',
	'create' => 'Create a user',
	'update' => 'Update a user',
	'enable' => 'Enable a user',
	'disable' => 'Disable a user',
	'addToGroup' => 'Add to groups',
	'removeFromGroup' => 'Remove from groups',
	'delete' => 'Delete a user',
	'groups' => 'List groups',
    ];
    const WRITES = [ 'create', 'update', 'enable', 'disable', 'addToGroup', 'removeFromGroup', 'delete' ];
    const LISTS = [ 'list', 'groups' ];
    const ONE = [ 'get', 'update', 'enable', 'disable', 'addToGroup', 'removeFromGroup', 'delete' ];
    const PAGE = 100;

    protected static function operationFields() {
	return [
	    self::field('search', 'Search', [ 'list', 'groups' ], [ 'help' => 'In IDs, names and emails; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'User', self::ONE, [ 'required' => true, 'help' => 'Its ID (login name), or a user of a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('userid', 'User ID', [ 'create' ], [ 'required' => true, 'help' => 'The login name: letters, digits, spaces and _.@-\'', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('displayName', 'Name', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('email', 'Email', [ 'create', 'update' ], [ 'help' => 'Create without a password: required, Nextcloud sends the welcome email', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('password', 'Password', [ 'create' ], [ 'help' => 'Empty: the user sets one from the welcome email', 'ui' => [ 'expression' => 'text', 'width' => 6, 'type' => 'password' ] ]),
	    self::field('groupList', 'Groups', [ 'create', 'addToGroup', 'removeFromGroup' ], [ 'help' => 'Group IDs, separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('quota', 'Quota', [ 'create', 'update' ], [ 'help' => 'e.g. 5 GB, none (unlimited), default', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function userId(\Closure $value) {
	$v = $value('user');
	if (is_array($v))
	    $v = $v['id'] ?? '';
	$t = trim(\util\Expression::toText($v));
	if ($t === '' || !preg_match('/^[A-Za-z0-9 _.@\-\']{1,64}$/', $t))
	    throw new \RuntimeException('User: not a user ID: `' . mb_substr($t, 0, 64) . '`');
	return $t;
    }

    //a user's details, with its ID
    protected function user($id) {
	return [ 'id' => $id ] + (array)$this->nc->ocs('GET', '/cloud/users/' . rawurlencode($id));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$N = $this->nc;
	switch($operation) {
	    case 'list':
		$max = $this->maxItems();
		$all = [];
		for ($offset = 0; ; $offset += self::PAGE) {
		    $d = (array)$N->ocs('GET', '/cloud/users/details', [ 'search' => $text('search'), 'limit' => self::PAGE, 'offset' => $offset ]);
		    $users = (array)($d['users'] ?? []);
		    foreach($users as $id => $u)
			$all[] = [ 'id' => (string)($u['id'] ?? $id) ] + (array)$u;
		    if (count($users) < self::PAGE || count($all) > $max)
			break;
		}
		return $this->listResult($all, 'user');
	    case 'groups':
		$d = (array)$N->ocs('GET', '/cloud/groups', [ 'search' => $text('search') ]);
		return $this->listResult(array_map(fn($g) => [ 'id' => (string)$g ], (array)($d['groups'] ?? [])), 'group');
	    case 'create':
		$id = $text('userid');
		if (!preg_match('/^[A-Za-z0-9 _.@\-\']{1,64}$/', $id))
		    throw new \RuntimeException('User ID: letters, digits, spaces and _.@-\' only');
		$p = [ 'userid' => $id, 'password' => $text('password') ?: null, 'displayName' => $text('displayName') ?: null, 'email' => $text('email') ?: null,
		    'quota' => $text('quota') ?: null ];
		if ($p['password'] === null && $p['email'] === null)
		    throw new \RuntimeException('Email: required when there is no password (Nextcloud sends the welcome email to it)');
		if (($groups = self::names($value('groupList'))) !== [])
		    $p['groups'] = $groups;
		$N->ocs('POST', '/cloud/users', $p);
		return [ $this->user($id), "{$id} created" . ($groups ? ' in ' . implode(', ', $groups) : '') ];
	}
	$id = $this->userId($value);
	$path = '/cloud/users/' . rawurlencode($id);
	switch($operation) {
	    case 'get':
		$u = $this->user($id);
		return [ $u, "{$id}: " . (!empty($u['enabled']) ? 'enabled' : 'disabled') . ', ' . \util\Format::plural(count((array)($u['groups'] ?? [])), 'group') ];
	    case 'update':
		$changes = array_filter([ 'displayname' => $text('displayName'), 'email' => $text('email'), 'quota' => $text('quota') ], fn($v) => $v !== '');
		if (!$changes)
		    throw new \RuntimeException('nothing to change');
		foreach($changes as $key => $v)
		    $N->ocs('PUT', $path, [ 'key' => $key, 'value' => $v ]);
		return [ $this->user($id), "{$id} updated: " . implode(', ', array_keys($changes)) ];
	    case 'enable':
	    case 'disable':
		$N->ocs('PUT', "{$path}/{$operation}");
		return [ [ 'id' => $id, 'enabled' => $operation === 'enable' ], "{$id} {$operation}d" ];
	    case 'addToGroup':
	    case 'removeFromGroup':
		$groups = self::names($value('groupList'));
		if (!$groups)
		    throw new \RuntimeException('Groups: empty');
		foreach($groups as $g)
		    $N->ocs($operation === 'addToGroup' ? 'POST' : 'DELETE', "{$path}/groups", [ 'groupid' => $g ]);
		return [ [ 'id' => $id, 'groups' => $groups ], "{$id} " . ($operation === 'addToGroup' ? 'added to ' : 'removed from ') . implode(', ', $groups) ];
	    case 'delete':
		$N->ocs('DELETE', $path);
		return [ [ 'id' => $id, 'deleted' => true ], "{$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
