<?php

/*
    a Checkmk site (REST API, Checkmk 2.2 and later), for the Checkmk steps and the Checkmk problem trigger (util\Checkmk): the server's address,
    the site, an automation user (Setup > Users: Automation secret for machine accounts) and its secret (encrypted), the server's certificate trusted or not. A server on a private
    address needs the account's "Integrations may connect to private network addresses". Test: the site and its Checkmk version and edition. Provides `checkmk`
*/

namespace models\credential;

class CredentialCheckmk extends \models\Credential {
    public static $label = 'Checkmk';
    public static $icon = 'bi-activity';
    public static $group = 'Monitoring';
    public static $provides = [ 'checkmk' ];
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
		'help' => 'The Checkmk server\'s address, without the site',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<server>[:port], without a path' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'https://monitoring.example.local' ],
	    ],
	    [
		'key' => 'site',
		'type' => 'text',
		'label' => 'Site',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'As in its address: https://<server>/<site>/',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'skipEmpty' => true, 'message' => 'A site name: letters, digits, _ -' ] ],
		'ui' => [ 'width' => 4, 'placeholder' => 'mysite' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Automation user',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'A user with an automation secret (Setup > Users), with the roles the workflows need',
		'validate' => [ [ 'regex', 'pattern' => '^[^\s]{1,128}$', 'skipEmpty' => true, 'message' => 'A user name, without spaces' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'password',
		'label' => 'Automation secret',
		'required' => true,
		'encrypt' => true,
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
	return new \util\Checkmk($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $v = (array)$this->client($this->aid, 20)->request('GET', '/version');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$version = (string)($v['versions']['checkmk'] ?? '');
	$edition = (string)($v['edition'] ?? '');
	return 'connected to site ' . ($v['site'] ?? $this->site) . ($version !== '' ? ", Checkmk {$version}" : '') . ($edition !== '' ? " ({$edition})" : '');
    }
}
