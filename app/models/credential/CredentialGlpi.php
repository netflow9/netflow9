<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: a GLPI server, for the GLPI steps and ticket trigger (util\Glpi, GLPI's REST API apirest.php): its address, the user's API token (My settings >
    Remote access keys) or login and password, the API client's App-Token (Setup > General > API, when the client has one), a profile and an entity to work in (else the user's
    defaults), the certificate trusted or not (self-signed). Secrets encrypted. What the steps may do is what the user's profile may in GLPI. A server on a private address needs
    the account's "Integrations may connect to private network addresses". Test: the user, its profile and entity (getFullSession). Provides `glpi`
*/

namespace models\credential;

class CredentialGlpi extends \models\Credential {
    public static $label = 'GLPI';
    public static $icon = 'bi-headset';
    public static $group = 'Work management';
    public static $provides = [ 'glpi' ];
    public static $testable = true;

    const AUTHS = [ 'token' => 'The user\'s API token', 'password' => 'Login and password' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The address you open GLPI at, e.g. https://glpi.example.com or https://example.com/glpi',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/[^\s?#]*)?$', 'skipEmpty' => true, 'message' => 'https://<server>[/path]' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'https://glpi.example.com' ],
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
		'key' => 'userToken',
		'type' => 'password',
		'label' => 'API token',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'token' ] ],
		'encrypt' => true,
		'help' => 'The user\'s: My settings > Remote access keys > API token (Regenerate)',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'token' ] ],
	    ],
	    [
		'key' => 'login',
		'type' => 'text',
		'label' => 'Login',
		'default' => '',
		'transform' => 'trim',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'password' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'password' ] ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'password' ] ],
		'encrypt' => true,
		'help' => 'Setup > General > API must allow signing in with credentials',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'password' ] ],
	    ],
	    [
		'key' => 'appToken',
		'type' => 'password',
		'label' => 'App-Token',
		'encrypt' => true,
		'help' => 'Of the API client (Setup > General > API); empty when the client has none',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'profileId',
		'type' => 'text',
		'label' => 'Profile ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The profile to work with (e.g. 4: Super-Admin, 6: Technician); empty: the user\'s default',
		'validate' => [ [ 'regex', 'pattern' => '^\d{1,9}$', 'skipEmpty' => true, 'message' => 'A number' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		'key' => 'entityId',
		'type' => 'text',
		'label' => 'Entity ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The entity to work in (0: the root); empty: the user\'s default',
		'validate' => [ [ 'regex', 'pattern' => '^\d{1,9}$', 'skipEmpty' => true, 'message' => 'A number' ] ],
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'entityRecursive',
		'type' => 'switch',
		'label' => 'With its sub-entities',
		'default' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'Only for a self-signed certificate',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Glpi($this, $aid, $timeout);
    }

    public function test() {
	$g = $this->client($this->aid, 20);
	try {
	    $s = (array)($g->request('GET', '/getFullSession')['session'] ?? []);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	} finally {
	    $g->close();
	}
	$name = trim(($s['glpifirstname'] ?? '') . ' ' . ($s['glpirealname'] ?? '')) ?: ($s['glpiname'] ?? '?');
	return "connected to GLPI as {$name}"
	    . (!empty($s['glpiactiveprofile']['name']) ? ", profile {$s['glpiactiveprofile']['name']}" : '')
	    . (isset($s['glpiactive_entity_name']) ? ', entity ' . strip_tags((string)$s['glpiactive_entity_name']) : '');
    }
}
