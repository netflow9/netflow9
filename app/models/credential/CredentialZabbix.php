<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new: a Zabbix server (its API, Zabbix 6.0 LTS and later), for the Zabbix steps and the Zabbix problem trigger (util\Zabbix): the frontend's address
    (with its path when it has one, e.g. https://monitor.example/zabbix), an API token (Users > API tokens; encrypted), the server's certificate trusted or not. A server on a private
    address needs the account's "Integrations may connect to private network addresses". Test: the version and the hosts the token sees. Provides `zabbix`
*/

namespace models\credential;

class CredentialZabbix extends \models\Credential {
    public static $label = 'Zabbix';
    public static $icon = 'bi-speedometer2';
    public static $group = 'Monitoring';
    public static $provides = [ 'zabbix' ];
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
		'help' => 'The Zabbix frontend\'s address, with its path when it has one',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/[^\s?#]*)?$', 'skipEmpty' => true, 'message' => 'https://<server>[:port][/path]' ] ],
		'ui' => [ 'placeholder' => 'https://monitor.example.local/zabbix' ],
	    ],
	    [
		'key' => 'apiToken',
		'type' => 'password',
		'label' => 'API token',
		'required' => true,
		'encrypt' => true,
		'help' => 'Users > API tokens; the workflows can do what its user may do',
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
	return new \util\Zabbix($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $z = $this->client($this->aid, 20);
	    $version = $z->version();
	    $hosts = (int)$z->call('host.get', [ 'countOutput' => true ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return "connected to Zabbix {$version}, " . \util\Format::plural($hosts, 'host') . ' visible to the token';
    }
}
