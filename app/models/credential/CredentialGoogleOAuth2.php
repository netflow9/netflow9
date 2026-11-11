<?php

//base of the Google OAuth credential types (Gmail, Drive, ...): Google endpoints, offline access, instance methods; subclasses
//give the scopes. The OAuth client (web application) is created in Google Cloud Console > APIs & Services > Credentials

namespace models\credential;

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
