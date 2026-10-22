<?php

//CLD: 2026-10-20T10:00:00+03:00: new credential variant: Google Drive (OAuth); the Google app needs the Google Drive API enabled and the scope
//https://www.googleapis.com/auth/drive (all the files the account can open). Provides `gdrive` (the Google Drive step) and `http` (HTTP Request: a bearer token for other Google APIs)

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleDrive); core classes fully qualified
namespace plugins\GoogleIntegration\models;

class CredentialGoogleDrive extends \plugins\GoogleIntegration\models\CredentialGoogleOAuth2 {
    public static $label = 'Google Drive (OAuth)';
    public static $icon = 'bi-google';
    //CLD: 2026-10-20T11:00:00+03:00: + gsheets (the Google Sheets step: the Sheets API accepts the Drive scope)
    //CLD: 2026-10-20T15:00:00+03:00: + gdocs (the Google Docs step: the Docs API accepts the Drive scope)
    public static $provides = [ 'gdrive', 'gsheets', 'gdocs', 'http' ];

    public static function serviceScope() {
	return 'https://www.googleapis.com/auth/drive';
    }

    //the account and its storage, read from the Drive API
    public function test() {
	$about = static::getJson('https://www.googleapis.com/drive/v3/about?fields=' . rawurlencode('user(emailAddress),storageQuota(limit,usage)'),
	    [ 'Authorization' => 'Bearer ' . $this->accessToken() ]);
	$quota = $about['storageQuota'] ?? [];
	$used = isset($quota['usage']) ? round((float)$quota['usage'] / 1073741824, 2) . ' GB used' : '';
	$limit = isset($quota['limit']) ? ' of ' . round((float)$quota['limit'] / 1073741824, 2) . ' GB' : '';
	return 'Google Drive of ' . ($about['user']['emailAddress'] ?? ($this->email ?: '?')) . ($used !== '' ? " ({$used}{$limit})" : '');
    }
}
