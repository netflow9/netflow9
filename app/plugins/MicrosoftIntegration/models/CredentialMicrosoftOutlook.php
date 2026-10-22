<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: Outlook Mail over Microsoft Graph (OAuth); delegated permissions Mail.ReadWrite, Mail.Send. Provides `msmail` (the Outlook Mail step) and
//`http` (HTTP Request: a bearer token for Microsoft Graph). Reading a mailbox over IMAP is the Microsoft 365 Mail credential's (Read emails)

namespace plugins\MicrosoftIntegration\models;

class CredentialMicrosoftOutlook extends \plugins\MicrosoftIntegration\models\CredentialMicrosoftGraph {
    public static $label = 'Outlook Mail (Microsoft Graph)';
    public static $permissions = [ 'Mail.ReadWrite', 'Mail.Send' ];
    public static $provides = [ 'msmail', 'http' ];
}
