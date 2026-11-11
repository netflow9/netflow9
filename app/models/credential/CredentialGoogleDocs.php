<?php

//Google Docs (OAuth); the Google app needs the Google Docs API enabled and the scope
//https://www.googleapis.com/auth/documents (the documents the account can open). Provides `gdocs` (the Google Docs step) and `http` (HTTP Request: a bearer token).
//A Google Drive credential works for the Google Docs step too (the Docs API accepts the Drive scope) and is needed to copy a document (a template: the Drive API)

namespace models\credential;

class CredentialGoogleDocs extends \models\credential\CredentialGoogleOAuth2 {
    public static $label = 'Google Docs (OAuth)';
    public static $icon = 'bi-google';
    public static $provides = [ 'gdocs', 'http' ];

    public static function serviceScope() {
	return 'https://www.googleapis.com/auth/documents';
    }
}
