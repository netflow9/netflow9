<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new plugin: Microsoft integration. Enabled in app/plugins.php.
      - Credentials (OAuth through the core's /credential/connect; an Entra app: App registrations, platform Web, the redirect address of the credential form):
	models/CredentialMicrosoftOAuth2.php    the base (tenant, endpoints; was models\credential\CredentialMicrosoftOAuth2)
	Microsoft 365 Mail                      IMAP with OAuth, for Read emails (CredentialMicrosoftMail; was in the core, same object name: saved credentials load as before)
	models/CredentialMicrosoftGraph.php     the base of the Microsoft Graph ones: Outlook Mail, Outlook Calendar, OneDrive & SharePoint (also Excel), Microsoft Teams
      - Steps (models/NodeAppMicrosoft.php: the base, as the GoogleIntegration plugin's NodeAppGoogle): Outlook Mail, Outlook Calendar, OneDrive / SharePoint, Excel,
	Microsoft Teams (models/DriveItemsTrait.php: the drives and items of OneDrive / SharePoint and Excel)
      - Without the plugin: these credentials load as plain Credentials (not editable: "type not available") and these steps are unknown types
*/

namespace plugins\MicrosoftIntegration;

class Plugin {

    public function __construct() {
	//CLD: 2026-10-24T10:27:54+03:00: a feature of the accounts' tiers (util\Tiers: `microsoft`; app/tiers.php: team, enterprise): without it, its credentials are not offered nor used, its steps are not
	//in the palette and do not run (`feature` of each; the saved credentials and steps are kept)
	\V::app()->hooks->addHook('tiers:features', function(\ArrayObject $features) {
	    $features['microsoft'] = 'Microsoft integration (credentials, steps)';
	});

	\V::app()->om->registerObjects([
	    'CredentialMicrosoftMail' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftMail',
		'variant-of' => 'Credential',
		'feature' => 'microsoft',
	    ],
	    'CredentialMicrosoftOutlook' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftOutlook',
		'variant-of' => 'Credential',
		'feature' => 'microsoft',
	    ],
	    'CredentialMicrosoftCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftCalendar',
		'variant-of' => 'Credential',
		'feature' => 'microsoft',
	    ],
	    'CredentialMicrosoftFiles' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftFiles',
		'variant-of' => 'Credential',
		'feature' => 'microsoft',
	    ],
	    'CredentialMicrosoftTeams' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftTeams',
		'variant-of' => 'Credential',
		'feature' => 'microsoft',
	    ],

	    'NodeAppOutlookMail' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOutlookMail',
		'wfNode' => true,
		'feature' => 'microsoft',
	    ],
	    'NodeAppOutlookCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOutlookCalendar',
		'wfNode' => true,
		'feature' => 'microsoft',
	    ],
	    'NodeAppOneDrive' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOneDrive',
		'wfNode' => true,
		'feature' => 'microsoft',
	    ],
	    'NodeAppExcel' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppExcel',
		'wfNode' => true,
		'feature' => 'microsoft',
	    ],
	    'NodeAppTeams' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppTeams',
		'wfNode' => true,
		'feature' => 'microsoft',
	    ],
	]);
    }
}
