<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: Zendesk Users - the users (end users, agents, admins) and organizations. Operations (setting `operation`):
	users                the users a search finds (users/search: a name, an email, role:agent, …)
	user                 a user (its id, email or name)
	saveUser             a user created or updated by its email (or external id): name, role, organization, phone, tags, user fields
	userTickets          the tickets a user requested, the newest first
	organizations        the organizations (a part of the name, or all)
	organization         an organization (its id or name)
	saveOrganization     an organization created or updated by its name (or external id): domains, tags, organization fields
	organizationTickets  an organization's tickets, the newest first
    A user: { id, url, name, email, role, organizationId, phone, active, suspended, tags, created, lastLogin, userFields, fields }; an organization: { id, url, name, domains,
    tags, created, organizationFields, fields }; a ticket as Zendesk Tickets gives it
*/

namespace models\node;

class NodeAppZendeskUsers extends NodeAppZendesk {
    public static $name = 'zendesk-users';
    public static $label = 'Zendesk Users';
    public static $icon = 'bi-people';
    public static $defaultOperation = 'users';
    public static $defaultVariable = 'users';

    const OPERATIONS = [
	'users' => 'Search users',
	'user' => 'Get a user',
	'saveUser' => 'Create or update a user',
	'userTickets' => 'List a user\'s tickets',
	'organizations' => 'List organizations',
	'organization' => 'Get an organization',
	'saveOrganization' => 'Create or update an organization',
	'organizationTickets' => 'List an organization\'s tickets',
    ];
    const WRITES = [ 'saveUser', 'saveOrganization' ];
    const LISTS = [ 'users', 'userTickets', 'organizations', 'organizationTickets' ];
    const ROLES = [ 'end-user' => 'End user', 'agent' => 'Agent', 'admin' => 'Admin' ];

    protected static function operationFields() {
	return [
	    self::field('search', 'Search', [ 'users', 'organizations' ], [ 'help' => 'Users: a name, an email, or Zendesk\'s search (role:agent, organization:acme); organizations: a part of the name (empty: all)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'users' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'User', [ 'user', 'userTickets' ], [ 'required' => true, 'help' => 'Its id, email or name, or a user of a search', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('organization', 'Organization', [ 'organization', 'organizationTickets', 'saveUser' ], [ 'help' => 'Its id or name, or an organization of a list',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'organization', 'organizationTickets' ] ], 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('email', 'Email', [ 'saveUser' ], [ 'help' => 'The user is found by it (or by External ID); required to create', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('name', 'Name', [ 'saveUser', 'saveOrganization' ], [ 'help' => 'Required to create. An organization is found by it (or by External ID)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'saveOrganization' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('role', 'Role', [ 'saveUser' ], [ 'type' => 'select', 'choices' => [ '' => '(unchanged / end user)' ] + self::ROLES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('phone', 'Phone', [ 'saveUser' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('externalId', 'External ID', [ 'saveUser', 'saveOrganization' ], [ 'help' => 'Your system\'s id of it', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('domains', 'Domains', [ 'saveOrganization' ], [ 'help' => 'Its email domains, separated by commas: their new users join it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('tags', 'Tags', [ 'saveUser', 'saveOrganization' ], [ 'help' => 'Separated by commas or spaces; replace its tags', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('customFields', 'Custom fields', [ 'saveUser', 'saveOrganization' ], [ 'type' => 'textarea', 'help' => 'A JSON object of the user fields / organization fields, by key, e.g. {"plan": "gold"}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function userOf(array $u, \util\Zendesk $z) {
	return [ 'id' => isset($u['id']) ? (int)$u['id'] : null, 'url' => isset($u['id']) ? $z->agentUrl('user', $u['id']) : null, 'name' => $u['name'] ?? null, 'email' => $u['email'] ?? null,
	    'role' => $u['role'] ?? null, 'organizationId' => $u['organization_id'] ?? null, 'phone' => $u['phone'] ?? null, 'active' => $u['active'] ?? null, 'suspended' => $u['suspended'] ?? null,
	    'tags' => array_values((array)($u['tags'] ?? [])), 'created' => $u['created_at'] ?? null, 'lastLogin' => $u['last_login_at'] ?? null, 'userFields' => (array)($u['user_fields'] ?? []), 'fields' => $u ];
    }

    public static function organizationOf(array $o, \util\Zendesk $z) {
	return [ 'id' => isset($o['id']) ? (int)$o['id'] : null, 'url' => isset($o['id']) ? $z->agentUrl('organization', $o['id']) : null, 'name' => $o['name'] ?? null,
	    'domains' => array_values((array)($o['domain_names'] ?? [])), 'tags' => array_values((array)($o['tags'] ?? [])), 'created' => $o['created_at'] ?? null,
	    'organizationFields' => (array)($o['organization_fields'] ?? []), 'fields' => $o ];
    }

    protected function tickets($path) {
	[ $all, $truncated ] = $this->zendesk->pages($path, 'tickets', [ 'sort' => '-created_at' ], $this->maxItems());
	$items = array_map(fn($t) => NodeAppZendeskTickets::ticketOf((array)$t, $this->zendesk), $all);
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'ticket') . ($truncated ? ', more left out' : '') ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$Z = $this->zendesk;
	switch($operation) {
	    case 'users':
		$s = $text('search');
		if ($s === '')
		    throw new \RuntimeException('Search: a name, an email or a search');
		$all = (array)($Z->request('GET', '/users/search', [ 'query' => $s ])['users'] ?? []);
		return $this->listResult(array_map(fn($u) => self::userOf((array)$u, $Z), $all), 'user');
	    case 'user':
		$u = self::userOf((array)($Z->request('GET', '/users/' . $this->userId($value('user'), 'User'))['user'] ?? []), $Z);
		return [ $u, "{$u['name']} <{$u['email']}>, {$u['role']}" ];
	    case 'userTickets':
		return $this->tickets('/users/' . $this->userId($value('user'), 'User') . '/tickets/requested');
	    case 'saveUser':
		$in = array_filter([ 'email' => $text('email'), 'name' => $text('name'), 'role' => isset(self::ROLES[$r = (string)$this->cfg('role', '')]) ? $r : '', 'phone' => $text('phone'),
		    'external_id' => $text('externalId') ], fn($v) => $v !== '');
		if (!isset($in['email']) && !isset($in['external_id']))
		    throw new \RuntimeException('Email / External ID: give one, the user is found by it');
		if (($o = $this->organizationId($value('organization'))) !== null)
		    $in['organization_id'] = $o;
		if (($tags = self::tags($value('tags'))) !== [])
		    $in['tags'] = $tags;
		if ($f = $this->jsonOf('customFields', 'Custom fields'))
		    $in['user_fields'] = $f;
		$u = self::userOf((array)($Z->request('POST', '/users/create_or_update', [], [ 'user' => $in ])['user'] ?? []), $Z);
		return [ $u, "user #{$u['id']} saved: {$u['name']}" ];
	    case 'organizations':
		$s = $text('search');
		if ($s !== '')
		    $all = (array)($Z->request('GET', '/organizations/autocomplete', [ 'name' => $s ])['organizations'] ?? []);
		else
		    [ $all ] = $Z->pages('/organizations', 'organizations', [], $this->maxItems() + 1);
		return $this->listResult(array_map(fn($o) => self::organizationOf((array)$o, $Z), $all), 'organization');
	    case 'organization':
		$o = self::organizationOf((array)($Z->request('GET', '/organizations/' . $this->organizationId($value('organization')))['organization'] ?? []), $Z);
		return [ $o, "organization #{$o['id']}: {$o['name']}" ];
	    case 'organizationTickets':
		return $this->tickets('/organizations/' . $this->organizationId($value('organization')) . '/tickets');
	    case 'saveOrganization':
		$in = array_filter([ 'name' => $text('name'), 'external_id' => $text('externalId') ], fn($v) => $v !== '');
		if (!isset($in['name']))
		    throw new \RuntimeException('Name: required');
		if (($d = array_values(array_filter(array_map('trim', explode(',', $text('domains')))))) !== [])
		    $in['domain_names'] = $d;
		if (($tags = self::tags($value('tags'))) !== [])
		    $in['tags'] = $tags;
		if ($f = $this->jsonOf('customFields', 'Custom fields'))
		    $in['organization_fields'] = $f;
		$o = self::organizationOf((array)($Z->request('POST', '/organizations/create_or_update', [], [ 'organization' => $in ])['organization'] ?? []), $Z);
		return [ $o, "organization #{$o['id']} saved: {$o['name']}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
