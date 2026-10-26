<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: Outlook Mail over Microsoft Graph (OAuth); delegated permissions Mail.ReadWrite, Mail.Send. Provides `msmail` (the Outlook Mail step) and
//`http` (HTTP Request: a bearer token for Microsoft Graph). Reading a mailbox over IMAP is the Microsoft 365 Mail credential's (Read emails)

//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\MicrosoftIntegration\models): the Microsoft credentials and steps are core, for every account (no plan feature)
namespace models\credential;

class CredentialMicrosoftOutlook extends \models\credential\CredentialMicrosoftGraph {
    public static $label = 'Outlook Mail (Microsoft Graph)';
    public static $permissions = [ 'Mail.ReadWrite', 'Mail.Send' ];
    public static $provides = [ 'msmail', 'http' ];
}
