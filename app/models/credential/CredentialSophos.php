<?php

/*
    a Sophos Firewall (SFOS, the XML API), for the Sophos Firewall steps (util\Sophos): the firewall's address (its administration port, 4444 by
    default), an administrator's user name and password (encrypted; its profile sets what the steps may do), the firewall's certificate trusted or not (its own is self-signed).
    On the firewall: Backup & firmware > API, the API configuration on and Rail22's address in Allowed IP address. A firewall on a private address needs the account's
    "Integrations may connect to private network addresses". Test: the hostname and the API version. Provides `sophos`
*/

namespace models\credential;

class CredentialSophos extends \models\Credential {
    public static $label = 'Sophos Firewall';
    public static $icon = 'bi-fire';
    public static $group = 'Security';
    public static $provides = [ 'sophos' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Firewall',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its HTTPS administration address, with the port (4444 unless changed)',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<firewall>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://fw.example.local:4444' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'User name',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'An administrator whose profile allows what the workflows do',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the firewall\'s certificate',
		'default' => false,
		'help' => 'For the firewall\'s own self-signed certificate',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Sophos($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $a = $this->client($this->aid, 20)->about();
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$name = $a['hostname'] !== '' ? $a['hostname'] : (string)parse_url((string)$this->serverUrl, PHP_URL_HOST);
	return "connected to {$name}" . ($a['apiVersion'] !== '' ? ", API version {$a['apiVersion']}" : '');
    }
}
