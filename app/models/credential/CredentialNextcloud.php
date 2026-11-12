<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: a Nextcloud user's access, for the Nextcloud steps and file trigger (util\Nextcloud): the server's address (its path too, e.g.
    https://cloud.example.com/nextcloud), the user, an app password (encrypted; Personal settings > Security > Devices & sessions > Create new app password), the certificate
    trusted or not (self-signed). What the steps may do is what the user may: an administrator (or a group's subadmin) for the users and groups. A server on a private address
    needs the account's "Integrations may connect to private network addresses". Test: the server's version, the user and its quota. Provides `nextcloud`
*/

namespace models\credential;

class CredentialNextcloud extends \models\Credential {
    public static $label = 'Nextcloud';
    public static $icon = 'bi-cloud';
    public static $group = 'Files & collaboration';
    public static $provides = [ 'nextcloud' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The address you open Nextcloud at, with its path when it has one',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/[^\s?#]*)?$', 'skipEmpty' => true, 'message' => 'https://<server>[/path]' ] ],
		'ui' => [ 'placeholder' => 'https://cloud.example.com' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'User',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The login name (an administrator for the users and groups)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'appPassword',
		'type' => 'password',
		'label' => 'App password',
		'required' => true,
		'encrypt' => true,
		'help' => 'Personal settings > Security > Devices & sessions: Create new app password',
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
	return new \util\Nextcloud($this, $aid, $timeout);
    }

    public function test() {
	$n = $this->client($this->aid, 20);
	try {
	    $caps = (array)$n->ocs('GET', '/cloud/capabilities');
	    $me = (array)$n->ocs('GET', '/cloud/user');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$quota = (array)($me['quota'] ?? []);
	$used = isset($quota['used']) ? \util\Format::bytes((int)$quota['used']) : null;
	$total = isset($quota['total']) && (int)$quota['total'] > 0 ? \util\Format::bytes((int)$quota['total']) : null;
	return 'connected to Nextcloud ' . ($caps['version']['string'] ?? '?') . ' as ' . ($me['id'] ?? $n->user()) . (!empty($me['displayname']) && $me['displayname'] !== ($me['id'] ?? '') ? " ({$me['displayname']})" : '')
	    . ($used !== null ? "; {$used} used" . ($total !== null ? " of {$total}" : '') : '');
    }
}
