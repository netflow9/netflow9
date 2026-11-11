<?php

/*
    a VMware vCenter Server (vSphere 8; the REST API of 7.0 U2 and later, VI/JSON of 8.0 U1 and later), for the VMware steps and the VMware alarm
    trigger (util\VSphere): the server's address, a vCenter user (administrator@vsphere.local, or a user of a role with the privileges the workflows need) and its password
    (encrypted), the server's certificate trusted or not. A server on a private address needs the account's "Integrations may connect to private network addresses".
    Test: the product and version, the datacenters, and whether VI/JSON (snapshots, alarms, maintenance mode) is there. Provides `vmware`
*/

namespace models\credential;

class CredentialVmware extends \models\Credential {
    public static $label = 'VMware vCenter';
    public static $icon = 'bi-boxes';
    public static $group = 'Virtualization';
    public static $provides = [ 'vmware' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'vCenter Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its address, as for the vSphere Client: https://<vcenter>',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<vcenter>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://vcenter.example.local' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'help' => 'A vCenter user, e.g. automation@vsphere.local; its role sets what the steps may do',
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
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'For vCenter\'s own self-signed certificate (VMCA)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\VSphere($this, $aid, $timeout);
    }

    //signs in to both APIs: the product and version (when the user may read them), the datacenters; VI/JSON there or not
    public function test() {
	$v = $this->client($this->aid, 20);
	try {
	    $dcs = $v->request('GET', '/api/vcenter/datacenter');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$product = '';
	try {
	    $about = $v->vim('GET', 'ServiceInstance/ServiceInstance/content')['about'] ?? [];
	    $product = (string)($about['fullName'] ?? '');
	    $vim = 'snapshots, alarms and maintenance mode available';
	} catch (\RuntimeException $e) {
	    $vim = 'snapshots, alarms and maintenance mode not available (' . $e->getMessage() . ')';
	}
	if ($product === '') {
	    try {
		$ver = $v->request('GET', '/api/appliance/system/version');
		$product = trim(($ver['product'] ?? 'vCenter Server') . ' ' . ($ver['version'] ?? '') . (isset($ver['build']) ? " build {$ver['build']}" : ''));
	    } catch (\RuntimeException $e) {
		//the appliance API needs more privileges: the version is left out
	    }
	}
	$host = parse_url((string)$this->serverUrl, PHP_URL_HOST);
	return "connected to {$host}" . ($product !== '' ? ", {$product}" : '') . ', ' . \util\Format::plural(is_array($dcs) ? count($dcs) : 0, 'datacenter') . "; {$vim}";
    }
}
