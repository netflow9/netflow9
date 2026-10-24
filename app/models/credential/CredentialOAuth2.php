<?php

/*
    base of the OAuth 2.0 credential types (authorization code + PKCE). The OAuth app (client id /secret) is part of the credential;
    Connect (/credential/connect) sends the user to the provider,
    /credential/oauth_callback stores the tokens (secrets access_token, refresh_token, expires_at) and the account address (config `email`, from the id_token).
    Subclasses set the endpoints and scopes (e.g. CredentialTypeGoogleOAuth2 + a scope); not registered itself
*/

namespace models\credential;

abstract class CredentialOAuth2 extends \models\Credential {
    public static $testable = true;

    //the redirect URI to register in the provider's app (same for every OAuth credential)
    public static function redirectUri() {
	return \V::app()->createAbsoluteUrl('/credential/oauth_callback');
    }

    abstract public function authEndpoint();
    abstract public function tokenEndpoint();
    abstract public function scope();

    //more parameters of the consent page (e.g. access_type=offline)
    public function authParams() {
	return [];
    }

    //more parameters of a refresh request (e.g. scope for Microsoft)
    public function refreshParams() {
	return [];
    }

    public static function isOAuth() {
	return true;
    }

    //the OAuth app; the account is set from the provider (perm:update nobody)
    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'clientId',
		'type' => 'text',
		'label' => 'Client ID',
		'required' => true,
		'default' => '',
		'help' => 'Of the OAuth app (web application) at the provider',
	    ],
	    [
		'key' => 'clientSecret',
		'type' => 'password',
		'label' => 'Client secret',
		'required' => true,
		'encrypt' => true,
	    ],
	    [
		'key' => 'email',
		'type' => 'text',
		'label' => 'Account',
		'default' => '',
		'help' => 'Filled in after Connect',
		'perm:update' => 'nobody',
	    ],
	    //set by Connect / refresh; never shown or posted
	    [
		'key' => 'access_token',
		'type' => 'text',
		'label' => 'Access token',
		'encrypt' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'refresh_token',
		'type' => 'text',
		'label' => 'Refresh token',
		'encrypt' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'expires_at',
		'type' => 'number',
		'label' => 'Access token expires',
		'encrypt' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	]);
    }

    //+ the redirect URI to register at the provider, after the client secret (a note, not a value)
    public function formFields() {
	$fields = parent::formFields();
	$at = array_search('clientSecret', array_column($fields, 'key'), true);
	array_splice($fields, $at === false ? count($fields) : $at + 1, 0, [ [
	    'type' => 'title', 'key' => '__redirect', 'level' => 'h6', 'label' => 'Redirect URI',
	    'subtitle' => static::redirectUri() . ' (add it to the app at the provider; after saving, use Connect to sign in)',
	] ]);
	return $fields;
    }

    //the consent was given
    public function isAuthorized() {
	return !empty($this->refresh_token);
    }

    public function authUrl($state, $verifier) {
	return \util\OAuth2::authUrl($this->authEndpoint(), [
	    'client_id' => $this->clientId,
	    'redirect_uri' => static::redirectUri(),
	    'response_type' => 'code',
	    'scope' => $this->scope(),
	    'state' => $state,
	    'code_challenge' => \util\OAuth2::challenge($verifier),
	    'code_challenge_method' => 'S256',
	    'login_hint' => (string)$this->email,
	] + $this->authParams());
    }

    //code from the callback -> tokens + account address stored on the credential (not saved)
    public function exchangeCode($code, $verifier) {
	$tokens = \util\OAuth2::token($this->tokenEndpoint(), [
	    'grant_type' => 'authorization_code',
	    'code' => $code,
	    'redirect_uri' => static::redirectUri(),
	    'code_verifier' => $verifier,
	    'client_id' => $this->clientId,
	    'client_secret' => $this->clientSecret,
	]);
	if (empty($tokens['refresh_token']))
	    throw new \Exception('the provider sent no refresh token (offline access)');
	$this->storeTokens($tokens);
	$email = \util\OAuth2::emailFromIdToken($tokens['id_token'] ?? null);
	if (!empty($email))
	    $this->email = $email;
    }

    //a valid access token, refreshed (and saved) when it expires within a minute
    public function accessToken() {
	if (!empty($this->access_token) && (int)$this->expires_at > time() + 60)
	    return $this->access_token;
	if (!$this->isAuthorized())
	    throw new \Exception('the credential is not connected: use Connect on it');

	$tokens = \util\OAuth2::token($this->tokenEndpoint(), [
	    'grant_type' => 'refresh_token',
	    'refresh_token' => $this->refresh_token,
	    'client_id' => $this->clientId,
	    'client_secret' => $this->clientSecret,
	] + $this->refreshParams());
	$this->storeTokens($tokens);
	$this->save();
	return $this->access_token;
    }

    protected function storeTokens(array $tokens) {
	$this->access_token = (string)$tokens['access_token'];
	$this->expires_at = time() + (int)($tokens['expires_in'] ?? 3600);
	//providers may rotate the refresh token
	if (!empty($tokens['refresh_token']))
	    $this->refresh_token = (string)$tokens['refresh_token'];
    }

    //forget the consent (e.g. the app changed)
    public function disconnect() {
	foreach([ 'access_token', 'refresh_token', 'expires_at' ] as $key)
	    $this->$key = null;
    }

    //default test: a token can be obtained
    public function test() {
	$this->accessToken();
	return 'access token obtained' . ($this->email ? ' for ' . $this->email : '');
    }

    //HTTP use: bearer access token
    public function httpAuth() {
	return [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->accessToken() ], 'query' => [] ];
    }
}
