<?php

//Microsoft 365 / Outlook.com mailbox over IMAP with OAuth(XOAUTH2); the Entra app needs the delegated permission IMAP.AccessAsUser.All (Office 365 Exchange Online)
//a variant of models\Credential (base CredentialMicrosoftOAuth2);

namespace models\credential;

class CredentialMicrosoftMail extends \models\credential\CredentialMicrosoftOAuth2 {
    public static $label = 'Microsoft 365 Mail (OAuth)';
    public static $icon = 'bi-microsoft';
    public static $group = 'Email';
    public static $provides = [ 'imap' ];

    public static function serviceScope() {
	return 'https://outlook.office.com/IMAP.AccessAsUser.All';
    }

    public function openImap() {
	return \models\credential\ImapConnect::openXoauth2($this, 'outlook.office365.com');
    }

    public function test() {
	return \models\credential\ImapConnect::test($this);
    }
}
