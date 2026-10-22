<?php

/*
    CLD: 2026-10-22T00:35:40+03:00: new plugin: Google integration (was in the core: models\credential\CredentialGoogle*, models\node\NodeAppGoogle*, NodeAppGmail).
    Enabled in app/plugins.php. The object names are the core's were (CredentialGoogleMail, NodeAppGoogleDrive, ...): the saved credentials (__variant) and the steps
    of the workflows load as before.
      - Credentials (OAuth, consent through the core's /credential/connect): models/CredentialGoogleOAuth2.php (the base), Google Mail (IMAP for Read emails, and the
	Gmail step), Google Drive, Google Sheets, Google Calendar, Google Docs
      - Steps: models/NodeAppGoogle.php (the base: credential, operation run, API calls, On error), Google Drive, Google Sheets, Gmail, Google Calendar, Google Docs
      - Without the plugin: these credentials load as plain Credentials (not editable: "type not available") and these steps are unknown types
*/

namespace plugins\GoogleIntegration;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'CredentialGoogleMail' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleMail',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleDrive' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleDrive',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleSheets' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleSheets',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleCalendar',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleDocs' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleDocs',
		'variant-of' => 'Credential',
	    ],

	    'NodeAppGoogleDrive' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleDrive',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleSheets' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleSheets',
		'wfNode' => true,
	    ],
	    'NodeAppGmail' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGmail',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleCalendar',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleDocs' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleDocs',
		'wfNode' => true,
	    ],
	]);
    }
}
