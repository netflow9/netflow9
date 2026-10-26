<?php

//CLD: 2026-10-20T11:00:00+03:00: new credential variant: Google Sheets (OAuth); the Google app needs the Google Sheets API enabled and the scope
//https://www.googleapis.com/auth/spreadsheets (all the spreadsheets the account can open). Provides `gsheets` (the Google Sheets step) and `http` (HTTP Request: a bearer token).
//A Google Drive credential works for the Google Sheets step too (the Sheets API accepts the Drive scope)

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleSheets); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\credential;

class CredentialGoogleSheets extends \models\credential\CredentialGoogleOAuth2 {
    public static $label = 'Google Sheets (OAuth)';
    public static $icon = 'bi-google';
    public static $provides = [ 'gsheets', 'http' ];

    public static function serviceScope() {
	return 'https://www.googleapis.com/auth/spreadsheets';
    }
}
