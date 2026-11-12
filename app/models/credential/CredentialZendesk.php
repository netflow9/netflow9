<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: a Zendesk Support account, for the Zendesk steps and ticket trigger (util\Zendesk): its subdomain, and an agent's email with an API token
    (Admin Center > Apps and integrations > APIs > Zendesk API) or an OAuth access token; tokens encrypted. What the steps may do is what that agent may (an admin for the users,
    organizations and groups). Test: the account and the agent (users/me). Provides `zendesk`
*/

namespace models\credential;

class CredentialZendesk extends \models\Credential {
    public static $label = 'Zendesk';
    public static $icon = 'bi-life-preserver';
    public static $group = 'Work management';
    public static $provides = [ 'zendesk' ];
    public static $testable = true;

    const AUTHS = [ 'token' => 'An agent\'s email and API token', 'oauth' => 'An OAuth access token' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'subdomain',
		'type' => 'text',
		'label' => 'Subdomain',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Of https://<subdomain>.zendesk.com, e.g. acme (or the whole address)',
		'validate' => [ [ 'regex', 'pattern' => '^(https://)?[A-Za-z0-9][A-Za-z0-9-]*(\.zendesk\.com/?)?$', 'skipEmpty' => true, 'message' => 'acme, or https://acme.zendesk.com' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'acme' ],
	    ],
	    [
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Sign in with',
		'required' => true,
		'default' => 'token',
		'choices' => self::AUTHS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'email',
		'type' => 'text',
		'label' => 'Email',
		'default' => '',
		'transform' => 'trim',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'token' ] ],
		'help' => 'Of the agent the steps act as',
		'validate' => [ [ 'email', 'skipEmpty' => true, 'message' => 'An email address' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'token' ] ],
	    ],
	    [
		'key' => 'apiToken',
		'type' => 'password',
		'label' => 'API token',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'token' ] ],
		'encrypt' => true,
		'help' => 'Admin Center > Apps and integrations > APIs > Zendesk API: token access on, Add API token',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'token' ] ],
	    ],
	    [
		'key' => 'accessToken',
		'type' => 'password',
		'label' => 'Access token',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'oauth' ] ],
		'encrypt' => true,
		'help' => 'An OAuth access token (scopes read write) of an OAuth client of the account',
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'oauth' ] ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\Zendesk($this, $timeout);
    }

    public function test() {
	try {
	    $me = (array)($this->client(20)->request('GET', '/users/me')['user'] ?? []);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	if (($me['id'] ?? null) === null || ($me['role'] ?? '') === 'end-user')
	    throw new \Exception('signed in as ' . (($me['id'] ?? null) === null ? 'an anonymous user: the email or token is wrong' : 'an end user, not an agent'));
	return 'connected to ' . \util\Zendesk::subdomain($this->subdomain) . '.zendesk.com as ' . ($me['name'] ?? '?') . (!empty($me['email']) ? " <{$me['email']}>" : '') . ", {$me['role']}";
    }
}
