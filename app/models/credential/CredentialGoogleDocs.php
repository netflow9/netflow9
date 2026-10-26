<?php

//CLD: 2026-10-20T15:00:00+03:00: new credential variant: Google Docs (OAuth); the Google app needs the Google Docs API enabled and the scope
//https://www.googleapis.com/auth/documents (the documents the account can open). Provides `gdocs` (the Google Docs step) and `http` (HTTP Request: a bearer token).
//A Google Drive credential works for the Google Docs step too (the Docs API accepts the Drive scope) and is needed to copy a document (a template: the Drive API)

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleDocs); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\credential;

class CredentialGoogleDocs extends \models\credential\CredentialGoogleOAuth2 {
    public static $label = 'Google Docs (OAuth)';
    public static $icon = 'bi-google';
    public static $provides = [ 'gdocs', 'http' ];

    public static function serviceScope() {
	return 'https://www.googleapis.com/auth/documents';
    }
}
