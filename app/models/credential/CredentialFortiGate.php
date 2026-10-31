<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new: a FortiGate firewall (FortiOS 7.x REST API), for the FortiGate steps (util\FortiGate): the firewall's address (its HTTPS administration port),
    the API token of a REST API administrator (System > Administrators > REST API Admin; encrypted), the VDOM (empty: the administrator's default), the firewall's certificate trusted
    or not (FortiGate's own is self-signed). A firewall on a private address needs the account's "Integrations may connect to private network addresses"; the REST API
    administrator's Trusted Hosts must include Rail22's address. Test: the hostname, model, version and serial number. Provides `fortigate`
*/

namespace models\credential;

class CredentialFortiGate extends \models\Credential {
    public static $label = 'FortiGate';
    public static $icon = 'bi-bricks';
    public static $group = 'Security';
    public static $provides = [ 'fortigate' ];
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
		'help' => 'Its HTTPS administration address, with the port when it is not 443',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<firewall>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://fw.example.local:8443' ],
	    ],
	    [
		'key' => 'apiToken',
		'type' => 'password',
		'label' => 'API token',
		'required' => true,
		'encrypt' => true,
		'help' => 'Of a REST API administrator (System > Administrators > Create New > REST API Admin), whose Trusted Hosts include this server',
	    ],
	    [
		'key' => 'vdom',
		'type' => 'text',
		'label' => 'VDOM',
		'default' => '',
		'transform' => 'trim',
		'help' => 'With VDOMs: the one the steps work in; empty: the administrator\'s',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]{1,31}$', 'skipEmpty' => true, 'message' => 'A VDOM name' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'root' ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the firewall\'s certificate',
		'default' => false,
		'help' => 'For FortiGate\'s own self-signed certificate',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\FortiGate($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $d = $this->client($this->aid, 20)->request('GET', '/api/v2/monitor/system/status', [], null, true);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$r = (array)($d['results'] ?? []);
	$name = (string)($r['hostname'] ?? parse_url((string)$this->serverUrl, PHP_URL_HOST));
	$model = trim((string)($r['model_name'] ?? '') . ' ' . (string)($r['model_number'] ?? ''));
	return "connected to {$name}" . ($model !== '' ? " ({$model})" : '') . (isset($d['version']) ? ", FortiOS {$d['version']}" . (isset($d['build']) ? " build {$d['build']}" : '') : '')
	    . (isset($d['serial']) ? ", serial {$d['serial']}" : '') . (isset($d['vdom']) ? ", VDOM {$d['vdom']}" : '');
    }
}
