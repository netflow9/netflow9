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
	\V::app()->om->registerObjects([
	    'CredentialMicrosoftMail' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftMail',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftOutlook' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftOutlook',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftCalendar',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftFiles' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftFiles',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftTeams' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialMicrosoftTeams',
		'variant-of' => 'Credential',
	    ],

	    'NodeAppOutlookMail' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOutlookMail',
		'wfNode' => true,
	    ],
	    'NodeAppOutlookCalendar' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOutlookCalendar',
		'wfNode' => true,
	    ],
	    'NodeAppOneDrive' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppOneDrive',
		'wfNode' => true,
	    ],
	    'NodeAppExcel' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppExcel',
		'wfNode' => true,
	    ],
	    'NodeAppTeams' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppTeams',
		'wfNode' => true,
	    ],
	]);
    }
}
