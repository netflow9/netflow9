<?php

//Gmail / Google Workspace mailbox over IMAP with OAuth (XOAUTH2); the Google app needs the Gmail API scope https://mail.google.com/
//CLD: 2026-10-09T17:21:14+03:00: a variant of models\Credential (base CredentialGoogleOAuth2);

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleMail); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\credential;

class CredentialGoogleMail extends \models\credential\CredentialGoogleOAuth2 {
    public static $label = 'Google Mail (OAuth)';
    public static $icon = 'bi-google';
    public static $group = 'Email';
    //CLD: 2026-10-20T12:00:00+03:00: + gmail (the Gmail step, Gmail API: the scope https://mail.google.com/ covers it; the Google app needs the Gmail API enabled)
    public static $provides = [ 'imap', 'gmail' ];

    public static function serviceScope() {
	return 'https://mail.google.com/';
    }

    public function openImap() {
	return \models\credential\ImapConnect::openXoauth2($this, 'imap.gmail.com');
    }

    public function test() {
	return \models\credential\ImapConnect::test($this);
    }
}
