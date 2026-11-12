<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: Yousign Account - what the organization has. Operations (setting `operation`):
	templates    the templates (for Send from a template): { id, name, description, signers (their labels), created }
	workspaces   the workspaces: { id, name, default, created }
	users        the organization's users: { id, firstName, lastName, email, role, status }
	contacts     the contacts, optionally those a search finds: { id, firstName, lastName, email, phone, company }
	addContact   a contact added (first and last name, email, phone, company)
	webhooks     the webhook subscriptions (Yousign event triggers' among them): { id, endpoint, events, enabled, sandbox, description }
*/

namespace models\node;

class NodeAppYousignAccount extends NodeAppYousign {
    public static $name = 'yousign-account';
    public static $label = 'Yousign Account';
    public static $icon = 'bi-building-gear';
    public static $defaultOperation = 'templates';
    public static $defaultVariable = 'yousign';

    const OPERATIONS = [
	'templates' => 'List templates',
	'workspaces' => 'List workspaces',
	'users' => 'List users',
	'contacts' => 'List contacts',
	'addContact' => 'Add a contact',
	'webhooks' => 'List webhooks',
    ];
    const WRITES = [ 'addContact' ];
    const LISTS = [ 'templates', 'workspaces', 'users', 'contacts', 'webhooks' ];

    protected static function operationFields() {
	return [
	    self::field('search', 'Search', [ 'contacts' ], [ 'help' => 'A name or an email address; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('firstName', 'First name', [ 'addContact' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('lastName', 'Last name', [ 'addContact' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('email', 'Email', [ 'addContact' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('phone', 'Phone', [ 'addContact' ], [ 'help' => 'International, e.g. +40722123456', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('company', 'Company', [ 'addContact' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('locale', 'Language', [ 'addContact' ], [ 'type' => 'select', 'default' => 'en', 'choices' => NodeAppYousignRequests::LOCALES, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function contactOf(array $c) {
	return [ 'id' => $c['id'] ?? null, 'firstName' => $c['first_name'] ?? null, 'lastName' => $c['last_name'] ?? null, 'email' => $c['email'] ?? null,
	    'phone' => $c['phone_number'] ?? null, 'company' => $c['company_name'] ?? null, 'locale' => $c['locale'] ?? null ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'templates':
		return $this->pagedResult('/templates', [], 'template', fn($t) => [ 'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'description' => $t['description'] ?? null,
		    'signers' => array_values(array_filter(array_map(fn($s) => is_array($s) ? ($s['label'] ?? null) : null, (array)($t['signers'] ?? [])))), 'created' => $t['created_at'] ?? null ]);
	    case 'workspaces':
		return $this->pagedResult('/workspaces', [], 'workspace', fn($w) => [ 'id' => $w['id'] ?? null, 'name' => $w['name'] ?? null, 'default' => (bool)($w['is_default'] ?? $w['default'] ?? false),
		    'created' => $w['created_at'] ?? null ]);
	    case 'users':
		return $this->pagedResult('/users', [], 'user', fn($u) => [ 'id' => $u['id'] ?? null, 'firstName' => $u['first_name'] ?? null, 'lastName' => $u['last_name'] ?? null,
		    'email' => $u['email'] ?? null, 'role' => $u['role'] ?? null, 'status' => $u['status'] ?? null ]);
	    case 'contacts':
		return $this->pagedResult('/contacts', [ 'q' => $text('search') ], 'contact', fn($c) => self::contactOf((array)$c));
	    case 'addContact':
		[ , $info ] = NodeAppYousignRequests::signer([ 'firstName' => $text('firstName'), 'lastName' => $text('lastName'), 'email' => $text('email'), 'phone' => $text('phone') ],
		    (string)$this->cfg('locale', 'en'), 'Contact');
		$c = $this->yousign->request('POST', '/contacts', [], $info + array_filter([ 'company_name' => $text('company') ], 'strlen'));
		$c = self::contactOf($c);
		return [ $c, "{$c['firstName']} {$c['lastName']} <{$c['email']}> added" ];
	    case 'webhooks':
		$d = $this->yousign->request('GET', '/webhooks');
		return $this->listResult(array_map(fn($w) => [ 'id' => $w['id'] ?? null, 'endpoint' => $w['endpoint'] ?? null, 'events' => (array)($w['subscribed_events'] ?? []),
		    'enabled' => (bool)($w['enabled'] ?? false), 'sandbox' => (bool)($w['sandbox'] ?? false), 'description' => $w['description'] ?? null ],
		    array_values(array_filter(array_is_list($d) ? $d : (array)($d['data'] ?? []), 'is_array'))), 'webhook');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
