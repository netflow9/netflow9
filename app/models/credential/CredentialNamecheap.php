<?php

/*
    a Namecheap account's API access, for the Namecheap steps (util\Namecheap): the API user, its API key (encrypted), the user the commands act
    for (empty: the API user), the client IP whitelisted for the API in Namecheap (Namecheap answers no other address), the sandbox or production. Test: the account's balance.
    Provides `namecheap`
*/

namespace models\credential;

class CredentialNamecheap extends \models\Credential {
    public static $label = 'Namecheap';
    public static $icon = 'bi-globe2';
    public static $group = 'Domains & DNS';
    public static $provides = [ 'namecheap' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiUser',
		'type' => 'text',
		'label' => 'API user',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The Namecheap user name with API access (Profile > Tools > API Access)',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9._-]{1,64}$', 'skipEmpty' => true, 'message' => 'A Namecheap user name' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Acts for user',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The user whose domains the commands manage; empty: the API user',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9._-]{1,64}$', 'skipEmpty' => true, 'message' => 'A Namecheap user name' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'clientIp',
		'type' => 'text',
		'label' => 'Client IP',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The public IPv4 address this server\'s requests come from, whitelisted in API Access: Namecheap answers no other',
		'validate' => [ [ 'regex', 'pattern' => '^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$', 'skipEmpty' => true, 'message' => 'An IPv4 address, such as 203.0.113.10' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => '203.0.113.10' ],
	    ],
	    [
		'key' => 'sandbox',
		'type' => 'switch',
		'label' => 'Sandbox',
		'default' => false,
		'help' => 'api.sandbox.namecheap.com, with a sandbox account and its own key: for trying workflows without real domains or charges',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Namecheap($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $b = \util\Namecheap::attrs($this->client($this->aid, 20)->call('namecheap.users.getBalances')->UserGetBalancesResult);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return rtrim('connected to Namecheap' . ($this->sandbox ? ' (sandbox)' : '') . ' as ' . (trim((string)$this->username) ?: trim((string)$this->apiUser))
	    . (isset($b['AvailableBalance']) ? "; balance {$b['AvailableBalance']} " . ($b['Currency'] ?? '') : ''));
    }
}
