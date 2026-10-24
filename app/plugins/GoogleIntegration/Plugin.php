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
	//CLD: 2026-10-24T10:27:54+03:00: a feature of the accounts' tiers (util\Tiers: `google`; app/tiers.php: team, enterprise): without it, its credentials are not offered nor used, its steps are not
	//in the palette and do not run (`feature` of each; the saved credentials and steps are kept)
	\V::app()->hooks->addHook('tiers:features', function(\ArrayObject $features) {
	    $features['google'] = 'Google integration (credentials, steps)';
	});

	\V::app()->om->registerObjects([
	    'CredentialGoogleMail' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleMail',
		'variant-of' => 'Credential',
		'feature' => 'google',
	    ],
	    'CredentialGoogleDrive' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleDrive',
		'variant-of' => 'Credential',
		'feature' => 'google',
	    ],
	    'CredentialGoogleSheets' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleSheets',
		'variant-of' => 'Credential',
		'feature' => 'google',
	    ],
	    'CredentialGoogleCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleCalendar',
		'variant-of' => 'Credential',
		'feature' => 'google',
	    ],
	    'CredentialGoogleDocs' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialGoogleDocs',
		'variant-of' => 'Credential',
		'feature' => 'google',
	    ],

	    'NodeAppGoogleDrive' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleDrive',
		'wfNode' => true,
		'feature' => 'google',
	    ],
	    'NodeAppGoogleSheets' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleSheets',
		'wfNode' => true,
		'feature' => 'google',
	    ],
	    'NodeAppGmail' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGmail',
		'wfNode' => true,
		'feature' => 'google',
	    ],
	    'NodeAppGoogleCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleCalendar',
		'wfNode' => true,
		'feature' => 'google',
	    ],
	    'NodeAppGoogleDocs' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppGoogleDocs',
		'wfNode' => true,
		'feature' => 'google',
	    ],
	]);
    }
}
