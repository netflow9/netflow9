<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new: a Bitdefender GravityZone console (cloud or on-premises), for the GravityZone steps and the GravityZone event trigger (util\GravityZone):
    the console's API access URL (My Account > Control Center API), the key's kind (a personal API key: HTTP Basic; a service API key: X-API-Key) and the key (encrypted);
    trustCertificate: an on-premises console's self-signed certificate. An on-premises console on a private address needs the account's "Integrations may connect to private network
    addresses" (Account allow_private_addresses). Test: getApiKeyDetails (the key's APIs). Provides `gravityzone`
*/

namespace models\credential;

class CredentialGravityZone extends \models\Credential {
    public static $label = 'Bitdefender GravityZone';
    public static $icon = 'bi-shield-check';
    public static $group = 'Security';
    public static $provides = [ 'gravityzone' ];
    public static $testable = true;

    const KEY_TYPES = [ 'personal' => 'Personal API key', 'service' => 'Service API key' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'accessUrl',
		'type' => 'text',
		'label' => 'API access URL',
		'required' => true,
		'default' => 'https://cloud.gravityzone.bitdefender.com/api',
		'transform' => 'trim',
		'help' => 'As shown in GravityZone: My Account > Control Center API (an on-premises console: https://<console>/api)',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#]+$', 'skipEmpty' => true, 'message' => 'An https:// address, without ? or #' ] ],
	    ],
	    [
		'key' => 'keyType',
		'type' => 'select',
		'label' => 'Key',
		'required' => true,
		'default' => 'personal',
		'choices' => self::KEY_TYPES,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'With the APIs the steps use enabled (Network, Incidents, Quarantine, Reports, Investigation, Push, ...)',
		'ui' => [ 'width' => 8 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the console\'s certificate',
		'default' => false,
		'help' => 'Only for an on-premises console with a self-signed certificate',
	    ],
	]);
    }

    //the client of this console, for account $aid
    public function client($aid, $timeout = 60) {
	return new \util\GravityZone($this, $aid, $timeout);
    }

    //the key's details: the APIs enabled for it
    public function test() {
	try {
	    $r = $this->client($this->aid, 20)->call('general', 'getApiKeyDetails');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$apis = [];
	foreach((array)($r['enabledApis'] ?? []) as $k => $v)
	    $apis[] = is_string($k) ? ($v ? $k : null) : (string)$v;
	$apis = array_values(array_filter($apis));
	return 'connected to ' . parse_url((string)$this->accessUrl, PHP_URL_HOST) . ($apis ? '; APIs of the key: ' . implode(', ', $apis) : '');
    }
}
