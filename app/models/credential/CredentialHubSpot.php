<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: a HubSpot account's API access, for the HubSpot steps and record trigger (util\HubSpot): a private app's access token (encrypted; Settings >
    Integrations > Private apps: its scopes are what the steps may do). Test: the account (portal ID, time zone, currency, data hosting). Provides `hubspot`
*/

namespace models\credential;

class CredentialHubSpot extends \models\Credential {
    public static $label = 'HubSpot';
    public static $icon = 'bi-person-lines-fill';
    public static $group = 'CRM';
    public static $provides = [ 'hubspot' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'accessToken',
		'type' => 'password',
		'label' => 'Access token',
		'required' => true,
		'encrypt' => true,
		'help' => 'A private app\'s (Settings > Integrations > Private apps, pat-…), with the scopes the workflows need, e.g. crm.objects.contacts.read and .write',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\HubSpot($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $a = $this->client($this->aid, 20)->account();
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to HubSpot account ' . ($a['portalId'] ?? '?') . (!empty($a['accountType']) ? " ({$a['accountType']})" : '')
	    . (!empty($a['dataHostingLocation']) ? ", data in {$a['dataHostingLocation']}" : '') . (!empty($a['companyCurrency']) ? ", {$a['companyCurrency']}" : '')
	    . (!empty($a['timeZone']) ? ", {$a['timeZone']}" : '');
    }
}
