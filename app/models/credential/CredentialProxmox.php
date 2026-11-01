<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new: a Proxmox VE cluster or server (PVE 7 and 8), for the Proxmox steps and the Proxmox task trigger (util\Proxmox): a node's address (port 8006),
    an API token (Datacenter > Permissions > API Tokens: user@realm!tokenid) and its secret (encrypted), the certificate trusted or not. A server on a private address needs the
    account's "Integrations may connect to private network addresses". Test: the version, the cluster and its nodes. Provides `proxmox`
*/

namespace models\credential;

class CredentialProxmox extends \models\Credential {
    public static $label = 'Proxmox VE';
    public static $icon = 'bi-hdd-rack';
    public static $group = 'Virtualization';
    public static $provides = [ 'proxmox' ];
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
		'help' => 'A node\'s address, as for the web interface: https://<node>:8006',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<node>:8006, without a path' ] ],
		'ui' => [ 'placeholder' => 'https://pve1.example.local:8006' ],
	    ],
	    [
		'key' => 'tokenId',
		'type' => 'text',
		'label' => 'Token ID',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'user@realm!token, e.g. automation@pve!rail22',
		'validate' => [ [ 'regex', 'pattern' => '^[^\s@!=]+@[A-Za-z0-9._-]+![A-Za-z][A-Za-z0-9._-]*$', 'skipEmpty' => true, 'message' => 'user@realm!token' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'password',
		'label' => 'Secret',
		'required' => true,
		'encrypt' => true,
		'help' => 'Shown once when the token is made',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'For Proxmox\'s own self-signed certificate',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Proxmox($this, $aid, $timeout);
    }

    //the version; the cluster's name and its nodes (or the server on its own)
    public function test() {
	$p = $this->client($this->aid, 20);
	try {
	    $ver = (array)$p->request('GET', '/version');
	    $status = (array)$p->request('GET', '/cluster/status');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$cluster = null;
	$nodes = [];
	foreach($status as $s) {
	    if (($s['type'] ?? '') === 'cluster')
		$cluster = (string)($s['name'] ?? '');
	    elseif (($s['type'] ?? '') === 'node')
		$nodes[] = (string)($s['name'] ?? '') . (empty($s['online']) ? ' (offline)' : '');
	}
	return 'connected: Proxmox VE ' . ($ver['version'] ?? '?') . ($cluster ? ", cluster {$cluster}" : '') . ', ' . \util\Format::plural(count($nodes), 'node')
	    . ($nodes ? ': ' . implode(', ', $nodes) : '');
    }
}
