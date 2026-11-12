<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: a Microsoft Intune tenant, for the Intune steps and device trigger (util\Intune, Microsoft Graph app-only): the tenant, an app registration's client
    ID and secret (encrypted). The app needs Graph application permissions with an administrator's consent: what the steps used need (see util\Intune); without a user, it acts
    as itself (Intune's audit log names the app). Test: the tenant's enrolled devices (managedDeviceOverview). Provides `intune`
*/

namespace models\credential;

class CredentialIntune extends \models\Credential {
    public static $label = 'Microsoft Intune';
    public static $icon = 'bi-phone';
    public static $group = 'Microsoft';
    public static $provides = [ 'intune' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'tenant',
		'type' => 'text',
		'label' => 'Tenant',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The tenant ID (Entra admin center > Overview) or its domain, e.g. contoso.onmicrosoft.com',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9.-]{2,}$', 'skipEmpty' => true, 'message' => 'A tenant ID or domain' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'clientId',
		'type' => 'text',
		'label' => 'Client ID',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The app registration\'s Application (client) ID',
		'validate' => [ [ 'regex', 'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$', 'skipEmpty' => true, 'message' => 'A GUID' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'clientSecret',
		'type' => 'password',
		'label' => 'Client secret',
		'required' => true,
		'encrypt' => true,
		'help' => 'Certificates & secrets > New client secret: its Value. The app\'s API permissions: Microsoft Graph application permissions DeviceManagement…, granted by an administrator',
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\Intune($this, $timeout);
    }

    public function test() {
	try {
	    $o = $this->client(20)->request('GET', '/deviceManagement/managedDeviceOverview');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$os = (array)($o['deviceOperatingSystemSummary'] ?? []);
	$parts = array_filter([ 'Windows' => (int)($os['windowsCount'] ?? 0), 'macOS' => (int)($os['macOSCount'] ?? 0), 'iOS / iPadOS' => (int)($os['iosCount'] ?? 0),
	    'Android' => (int)($os['androidCount'] ?? 0), 'Linux' => (int)($os['linuxCount'] ?? 0) ]);
	return 'connected to Intune: ' . \util\Format::plural((int)($o['enrolledDeviceCount'] ?? 0), 'enrolled device')
	    . ($parts ? ' (' . implode(', ', array_map(fn($k, $n) => "{$n} {$k}", array_keys($parts), $parts)) . ')' : '');
    }
}
