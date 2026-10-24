<?php

//connection helpers shared by the IMAP credential types (password and OAuth / XOAUTH2)
namespace models\credential;

class ImapConnect {
    //connect + sign in ($signIn($imap)); the result (ok / error) is kept on the credential
    public static function open($cred, $host, $port, $security, callable $signIn) {
	$imap = new \util\ImapClient([ 'timeout' => 30 ]);
	try {
	    $imap->connect($host, $port, $security);
	    $signIn($imap);
	} catch (\Exception $e) {
	    $imap->close();
	    $cred->setStatus('error', $e->getMessage());
	    throw $e;
	}
	$cred->setStatus('ok', 'connected to ' . $host);
	return $imap;
    }

    //sign in and list the folders
    public static function test($cred) {
	$imap = $cred->openImap();
	$folders = array_column($imap->listFolders(), 'name');
	$imap->logout();
	$shown = array_slice($folders, 0, 15);
	//CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was (s))
	return \util\Format::plural(count($folders), 'folder') . ': ' . implode(', ', $shown) . (count($folders) > count($shown) ? ', ...' : '');
    }

    //OAuth types: XOAUTH2 with the credential's access token and account
    //CLD: 2026-10-09T17:21:14+03:00: the account is the credential's `email` field, the token from its accessToken() (were value('email') and the type's static accessToken)
    public static function openXoauth2($cred, $host) {
	return self::open($cred, $host, 993, 'ssl', function($imap) use ($cred) {
	    if ((string)$cred->email === '')
		throw new \Exception('the credential is not connected: use Connect on it');
	    $imap->authenticateXoauth2((string)$cred->email, $cred->accessToken());
	});
    }
}
