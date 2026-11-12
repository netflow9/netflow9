<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: a Shopify store's Admin API access, for the Shopify steps and webhook trigger (util\Shopify): the store (<shop>.myshopify.com), how the app signs in
	- Admin API access token: a custom app made in the store's admin (Settings > Apps > Develop apps), its shpat_… token; its API secret key, optional, signs the webhooks
	- Client ID and secret: an app made in the Dev Dashboard and installed on the store (client credentials grant: a token for about 24 hours, asked for when needed); the
	  secret also signs the webhooks
    and the API version (default util\Shopify::DEFAULT_VERSION). Secrets encrypted. Test: the store's name, plan and the scopes the token has. Provides `shopify`
*/

namespace models\credential;

class CredentialShopify extends \models\Credential {
    public static $label = 'Shopify';
    public static $icon = 'bi-bag';
    public static $group = 'E-commerce';
    public static $provides = [ 'shopify' ];
    public static $testable = true;

    const AUTH = [ 'token' => 'Admin API access token (a custom app of the store)', 'client' => 'Client ID and secret (a Dev Dashboard app)' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'shop',
		'type' => 'text',
		'label' => 'Store',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its myshopify address (the store\'s name works too): Shopify\'s API answers only there, not at the store\'s own domain',
		'validate' => [ [ 'regex', 'pattern' => '^(https?://)?(admin\.shopify\.com/store/)?[A-Za-z0-9][A-Za-z0-9-]*(\.myshopify\.com)?/?$', 'skipEmpty' => true,
		    'message' => '<shop>.myshopify.com' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'my-store.myshopify.com' ],
	    ],
	    [
		'key' => 'apiVersion',
		'type' => 'text',
		'label' => 'API version',
		'required' => true,
		'default' => \util\Shopify::DEFAULT_VERSION,
		'transform' => 'trim',
		'help' => 'Shopify\'s quarterly version (YYYY-01, -04, -07, -10); each is supported for at least 12 months',
		'validate' => [ [ 'regex', 'pattern' => '^\d{4}-(01|04|07|10)$', 'skipEmpty' => true, 'message' => 'e.g. ' . \util\Shopify::DEFAULT_VERSION ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'authMode',
		'type' => 'select',
		'label' => 'Sign in with',
		'required' => true,
		'default' => 'client',
		'choices' => self::AUTH,
		'help' => 'Since 2026 new apps are made in the Dev Dashboard (Client ID and secret); custom apps made earlier in the admin have an access token',
	    ],
	    [
		'key' => 'accessToken',
		'type' => 'password',
		'label' => 'Admin API access token',
		'encrypt' => true,
		'help' => 'shpat_…, shown once when the custom app is installed',
		'ui' => [ 'showIf' => [ 'key' => 'authMode', 'operator' => 'eq', 'value' => 'token' ] ],
	    ],
	    [
		'key' => 'clientId',
		'type' => 'text',
		'label' => 'Client ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The app\'s Settings in the Dev Dashboard',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_-]{8,128}$', 'skipEmpty' => true, 'message' => 'The app\'s Client ID' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'authMode', 'operator' => 'eq', 'value' => 'client' ] ],
	    ],
	    [
		'key' => 'clientSecret',
		'type' => 'password',
		'label' => 'Client secret',
		'encrypt' => true,
		'help' => 'Signs the webhooks too. With an access token: the app\'s API secret key, optional (only the Shopify event trigger needs it)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    //the secrets the sign-in needs, the store's address
    public function validates($options = []) {
	$valid = parent::validates($options);
	if (trim((string)$this->shop) !== '') {
	    try {
		$this->shop = \util\Shopify::shopDomain($this->shop);
	    } catch (\RuntimeException $e) {
		$this->errors('shop', '<shop>.myshopify.com');
		$valid = false;
	    }
	}
	if (($this->authMode ?? 'token') === 'client') {
	    foreach([ 'clientId', 'clientSecret' ] as $k) {
		if (trim((string)$this->$k) === '') {
		    $this->errors($k, 'required');
		    $valid = false;
		}
	    }
	} elseif (trim((string)$this->accessToken) === '') {
	    $this->errors('accessToken', 'required');
	    $valid = false;
	}
	return $valid;
    }

    public function client($aid, $timeout = 60) {
	return new \util\Shopify($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $d = $this->client($this->aid, 20)->query('{ shop { name myshopifyDomain currencyCode plan { displayName } } currentAppInstallation { accessScopes { handle } } }');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$scopes = array_column((array)($d['currentAppInstallation']['accessScopes'] ?? []), 'handle');
	return 'connected to ' . ($d['shop']['name'] ?? '?') . ' (' . ($d['shop']['myshopifyDomain'] ?? $this->shop) . (isset($d['shop']['plan']['displayName']) ? ", {$d['shop']['plan']['displayName']}" : '')
	    . '), API ' . $this->client($this->aid)->version() . ($scopes ? '; scopes: ' . implode(', ', $scopes) : '');
    }
}
