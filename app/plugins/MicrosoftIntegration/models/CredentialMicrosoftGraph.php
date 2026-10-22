<?php

//CLD: 2026-10-22T00:41:28+03:00: new class: base of the Microsoft Graph credentials (Outlook Mail, Outlook Calendar, OneDrive & SharePoint, Teams; not registered itself): the Graph delegated
//permissions of the subclass (static::$permissions) + User.Read; the test reads the signed-in account. The Entra app (App registrations, platform: Web) needs these delegated
//Microsoft Graph permissions; work / school accounts may need an admin's consent for some of them

namespace plugins\MicrosoftIntegration\models;

abstract class CredentialMicrosoftGraph extends \plugins\MicrosoftIntegration\models\CredentialMicrosoftOAuth2 {
    public static $group = 'Microsoft';
    public static $icon = 'bi-microsoft';
    public static $testable = true;

    //the Microsoft Graph delegated permissions (e.g. Mail.Send); the subclass's
    public static $permissions = [];

    const GRAPH = 'https://graph.microsoft.com';

    public static function serviceScope() {
	return implode(' ', array_map(fn($p) => self::GRAPH . '/' . $p, array_values(array_unique(array_merge([ 'User.Read' ], static::$permissions)))));
    }

    //the account signed in, read from Graph
    public function test() {
	$me = static::getJson(self::GRAPH . '/v1.0/me?$select=displayName,mail,userPrincipalName', [ 'Authorization' => 'Bearer ' . $this->accessToken() ]);
	return static::$label . ' of ' . ($me['mail'] ?? $me['userPrincipalName'] ?? ($this->email ?: '?')) . (!empty($me['displayName']) ? " ({$me['displayName']})" : '');
    }
}
