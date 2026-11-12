<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: a Jira site, for the Jira steps and issue trigger (util\Jira): Jira Cloud (https://<site>.atlassian.net, the user's email and an API token) or
    Jira Data Center / Server (its address, a personal access token; the certificate trusted or not). Tokens encrypted. What the steps may do is what the user may in Jira.
    Test: the user and the site (serverInfo). Provides `jira`
*/

namespace models\credential;

class CredentialJira extends \models\Credential {
    public static $label = 'Jira';
    public static $icon = 'bi-kanban';
    public static $group = 'Work management';
    public static $provides = [ 'jira' ];
    public static $testable = true;

    const DEPLOYMENTS = [ 'cloud' => 'Jira Cloud (atlassian.net)', 'server' => 'Jira Data Center / Server' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'deployment',
		'type' => 'select',
		'label' => 'Jira',
		'required' => true,
		'default' => 'cloud',
		'choices' => self::DEPLOYMENTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'siteUrl',
		'type' => 'text',
		'label' => 'Site',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The address you open Jira at (Data Center: with its context path, e.g. https://jira.example.com/jira)',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/[^\s?#]*)?$', 'skipEmpty' => true, 'message' => 'https://<site>[/path]' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'https://example.atlassian.net' ],
	    ],
	    [
		'key' => 'email',
		'type' => 'text',
		'label' => 'Email',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Of the Atlassian account the API token belongs to',
		'validate' => [ [ 'email', 'skipEmpty' => true, 'message' => 'An email address' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'deployment', 'operator' => 'eq', 'value' => 'cloud' ] ],
	    ],
	    [
		'key' => 'apiToken',
		'type' => 'password',
		'label' => 'API token',
		'required' => true,
		'encrypt' => true,
		'help' => 'Cloud: id.atlassian.com > Security > API tokens. Data Center: Profile > Personal Access Tokens',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'Only for a self-signed certificate',
		'ui' => [ 'showIf' => [ 'key' => 'deployment', 'operator' => 'eq', 'value' => 'server' ] ],
	    ],
	]);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (($this->deployment ?? 'cloud') === 'cloud') {
	    if (trim((string)$this->email) === '') {
		$this->errors('email', 'required');
		$valid = false;
	    }
	    if (trim((string)$this->siteUrl) !== '' && !preg_match('~^https://[a-z0-9][a-z0-9-]*\.(atlassian\.net|jira\.com)/?$~i', trim((string)$this->siteUrl))) {
		$this->errors('siteUrl', 'Jira Cloud: https://<site>.atlassian.net');
		$valid = false;
	    }
	}
	return $valid;
    }

    public function client($aid, $timeout = 60) {
	return new \util\Jira($this, $aid, $timeout);
    }

    public function test() {
	$j = $this->client($this->aid, 20);
	try {
	    $me = (array)$j->request('GET', $j->api() . '/myself');
	    $info = (array)$j->request('GET', $j->api() . '/serverInfo');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to ' . ($info['serverTitle'] ?? 'Jira') . ' (' . ($j->isCloud() ? 'Cloud' : 'Data Center ' . ($info['version'] ?? '')) . ') as ' . ($me['displayName'] ?? '?')
	    . (!empty($me['emailAddress']) ? " <{$me['emailAddress']}>" : '');
    }
}
