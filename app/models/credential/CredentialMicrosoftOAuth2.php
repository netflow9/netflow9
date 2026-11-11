<?php

//base of the Microsoft identity platform credential types (Microsoft 365 / Outlook.com): tenant field, endpoints, instance methods;
//subclasses give the scopes. The app is registered in Microsoft Entra admin center > App registrations (platform: Web)

namespace models\credential;

abstract class CredentialMicrosoftOAuth2 extends \models\credential\CredentialOAuth2 {
    public static $group = 'Microsoft';
    public static $icon = 'bi-microsoft';

    //the service scopes (space separated); offline_access openid email profile are added
    abstract public static function serviceScope();

    //tenant, after the client secret
    public function getSchema() {
	$fields = parent::getSchema();
	$at = array_search('clientSecret', array_column($fields, 'key'), true);
	array_splice($fields, $at === false ? count($fields) : $at + 1, 0, [ [
	    'key' => 'tenant',
	    'type' => 'text',
	    'label' => 'Tenant',
	    'required' => true,
	    'default' => 'common',
	    'help' => 'common (any account), organizations, or the tenant id / domain of a single-tenant app',
	    'validate' => [
		[ 'regex', 'pattern' => '^[A-Za-z0-9.-]+$', 'skipEmpty' => true, 'message' => 'A tenant id, a domain, common or organizations' ],
	    ],
	] ]);
	return $fields;
    }

    protected function base() {
	return 'https://login.microsoftonline.com/' . rawurlencode((string)($this->tenant ?: 'common')) . '/oauth2/v2.0';
    }

    public function authEndpoint() {
	return $this->base() . '/authorize';
    }

    public function tokenEndpoint() {
	return $this->base() . '/token';
    }

    public function scope() {
	return static::serviceScope() . ' offline_access openid email profile';
    }

    public function authParams() {
	return [ 'response_mode' => 'query', 'prompt' => 'select_account' ];
    }

    public function refreshParams() {
	return [ 'scope' => $this->scope() ];
    }
}
