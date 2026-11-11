<?php

//Outlook Mail over Microsoft Graph (OAuth); delegated permissions Mail.ReadWrite, Mail.Send. Provides `msmail` (the Outlook Mail step) and
//`http` (HTTP Request: a bearer token for Microsoft Graph). Reading a mailbox over IMAP is the Microsoft 365 Mail credential's (Read emails)

namespace models\credential;

class CredentialMicrosoftOutlook extends \models\credential\CredentialMicrosoftGraph {
    public static $label = 'Outlook Mail (Microsoft Graph)';
    public static $permissions = [ 'Mail.ReadWrite', 'Mail.Send' ];
    public static $provides = [ 'msmail', 'http' ];
}
