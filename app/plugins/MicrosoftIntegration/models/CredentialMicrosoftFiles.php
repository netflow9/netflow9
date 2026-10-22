<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: OneDrive and SharePoint files over Microsoft Graph (OAuth); delegated permissions Files.ReadWrite.All, Sites.ReadWrite.All (the document
//libraries of SharePoint sites). Provides `msfiles` (the OneDrive / SharePoint step), `msexcel` (the Excel step: workbooks are files) and `http`

namespace plugins\MicrosoftIntegration\models;

class CredentialMicrosoftFiles extends \plugins\MicrosoftIntegration\models\CredentialMicrosoftGraph {
    public static $label = 'OneDrive & SharePoint (Microsoft Graph)';
    public static $permissions = [ 'Files.ReadWrite.All', 'Sites.ReadWrite.All' ];
    public static $provides = [ 'msfiles', 'msexcel', 'http' ];

    //the account and its OneDrive's storage
    public function test() {
	$drive = static::getJson(self::GRAPH . '/v1.0/me/drive?$select=owner,quota,driveType', [ 'Authorization' => 'Bearer ' . $this->accessToken() ]);
	$quota = $drive['quota'] ?? [];
	$used = isset($quota['used']) ? round((float)$quota['used'] / 1073741824, 2) . ' GB used' : '';
	$total = isset($quota['total']) ? ' of ' . round((float)$quota['total'] / 1073741824, 2) . ' GB' : '';
	$owner = $drive['owner']['user']['email'] ?? $drive['owner']['user']['displayName'] ?? ($this->email ?: '?');
	return 'OneDrive of ' . $owner . ($used !== '' ? " ({$used}{$total})" : '');
    }
}
