<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: a Salesforce org's API access, for the Salesforce steps and record trigger (util\Salesforce): its My Domain address (a sandbox's too), an
    External Client App's (or Connected App's) consumer key and secret with the client credentials flow enabled - the steps act as its run-as user -, the API version.
    Secret encrypted. Test: the org, sandbox or not, and the run-as user. Provides `salesforce`
*/

namespace models\credential;

class CredentialSalesforce extends \models\Credential {
    public static $label = 'Salesforce';
    public static $icon = 'bi-cloud-fill';
    public static $group = 'CRM';
    public static $provides = [ 'salesforce' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'domainUrl',
		'type' => 'text',
		'label' => 'My Domain',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The org\'s address (Setup > My Domain), e.g. https://acme.my.salesforce.com or a sandbox\'s https://acme--uat.sandbox.my.salesforce.com',
		'validate' => [ [ 'regex', 'pattern' => '^https://[A-Za-z0-9.-]+\.my\.salesforce\.com/?$', 'skipEmpty' => true, 'message' => 'https://<domain>.my.salesforce.com' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'https://acme.my.salesforce.com' ],
	    ],
	    [
		'key' => 'apiVersion',
		'type' => 'text',
		'label' => 'API version',
		'required' => true,
		'default' => \util\Salesforce::DEFAULT_VERSION,
		'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => '^v?\d{2,3}\.0$', 'skipEmpty' => true, 'message' => 'e.g. ' . \util\Salesforce::DEFAULT_VERSION ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'clientId',
		'type' => 'text',
		'label' => 'Consumer key',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The app\'s (Setup > External Client App Manager > the app > Settings > OAuth Settings)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'clientSecret',
		'type' => 'password',
		'label' => 'Consumer secret',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Salesforce($this, $aid, $timeout);
    }

    public function test() {
	$s = $this->client($this->aid, 20);
	try {
	    [ $org ] = $s->query('SELECT Name, IsSandbox, OrganizationType FROM Organization', 1);
	    $me = (array)$s->request('GET', '/services/oauth2/userinfo');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$o = (array)($org[0] ?? []);
	return 'connected to ' . ($o['Name'] ?? 'Salesforce') . ' (' . (!empty($o['IsSandbox']) ? 'sandbox' : ($o['OrganizationType'] ?? 'production')) . '), API ' . $s->version()
	    . ' as ' . ($me['name'] ?? $me['preferred_username'] ?? '?') . (!empty($me['preferred_username']) && ($me['name'] ?? '') !== $me['preferred_username'] ? " <{$me['preferred_username']}>" : '');
    }
}
