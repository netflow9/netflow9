<?php

//CLD: 2026-10-08T14:34:02+03:00: new class: base of the Google OAuth credential types (Gmail, later Drive, ...): Google endpoints, offline access; subclasses
//give the scopes. The OAuth client (web application) is created in Google Cloud Console > APIs & Services > Credentials
//CLD: 2026-10-09T17:21:14+03:00: base of Google OAuth credential variants (was CredentialTypeGoogleOAuth2); instance methods (were static, given the credential)

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleOAuth2); core classes fully qualified
namespace plugins\GoogleIntegration\models;

abstract class CredentialGoogleOAuth2 extends \models\credential\CredentialOAuth2 {
    public static $group = 'Google';
    public static $icon = 'bi-google';

    //the service scopes (space separated); openid email are added for the account address
    abstract public static function serviceScope();

    public function authEndpoint() {
	return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public function tokenEndpoint() {
	return 'https://oauth2.googleapis.com/token';
    }

    public function scope() {
	return static::serviceScope() . ' openid email';
    }

    //refresh token every time (prompt=consent), else Google sends it only on the first consent
    public function authParams() {
	return [ 'access_type' => 'offline', 'prompt' => 'consent' ];
    }
}
