<?php

/*
    CLD: 2026-10-20T18:00:00+03:00: new class: encrypts and compresses files on disk, for the FTP / SFTP Upload step (models\node\NodeAppFtpUpload) with an Encryption key
    credential (models\credential\CredentialEncryption): the other way of util\FileDecrypt, whose helpers it uses (the system's tools without a shell, secrets on fd 3,
    key files 0600 in a private temporary folder).
	- pgp:            OpenPGP to the recipients' public keys (one or several in the key text: the file can be opened by any of them); binary (.gpg) or armored (.asc)
	- pgp-symmetric:  OpenPGP with a passphrase (gpg -c, AES-256)
	- openssl-enc:    `openssl enc` with a password: cipher, key derivation (PBKDF2 + iterations, or the legacy MD5 / SHA-256), binary or base64 (.enc)
	- cms:            CMS / S/MIME enveloped data (DER, .p7m) for the recipients' certificates (AES-256)
	- compress():     gzip (.gz)
	Errors: \RuntimeException with the tool's message
*/

namespace util;

class FileEncrypt extends FileDecrypt {
    //the ending each method adds to the name (.gpg, .asc, .enc, .p7m)
    public static function ending($method, $armor = false) {
	return '.' . match($method) { 'pgp', 'pgp-symmetric' => $armor ? 'asc' : 'gpg', 'openssl-enc' => 'enc', 'cms' => 'p7m', default => 'bin' };
    }

    //$in encrypted into $out with credential $cred (method, publicKey, passphrase, certificate, cipher, kdf, iterations, armor)
    public static function encrypt($cred, $in, $out) {
	$method = (string)$cred->method;
	$work = self::workDir();
	try {
	    switch($method) {
		case 'pgp':
		case 'pgp-symmetric':
		    self::gpgEncrypt($cred, $method, $work, $in, $out);
		    break;
		case 'openssl-enc':
		    self::opensslEncEncrypt($cred, $in, $out);
		    break;
		case 'cms':
		    self::cmsEncrypt($cred, $work, $in, $out);
		    break;
		default:
		    throw new \RuntimeException("unknown encryption method `{$method}`");
	    }
	} finally {
	    self::removeDir($work);
	}
	clearstatcache(true, $out);
	if (!is_file($out))
	    throw new \RuntimeException('nothing was encrypted');
    }

    //the public keys of $text imported into keyring $home: their fingerprints (encryption keys only); an error when there are none
    //CLD: 2026-10-20T19:00:00+03:00: public (util\FileSign: signed and encrypted in one message)
    //CLD: 2026-10-20T20:00:00+03:00: + $capability: E (encrypt) or S (sign: util\FileVerify, the signers' keys)
    public static function importPublicKeys($base, $work, $text, $capability = 'E') {
	$file = self::secretFile($work, 'pub.' . bin2hex(random_bytes(4)) . '.asc', trim((string)$text) . "\n");
	[ $code, $err ] = self::run(array_merge($base, [ '--import', $file ]));
	if ($code !== 0)
	    throw new \RuntimeException('the public key could not be read: ' . self::toolMessage($err));
	//CLD: 2026-10-20T20:00:00+03:00: the keys of $text only (--show-keys), not of the whole keyring (was --list-keys): a private key imported into the same keyring
	//(decrypting, signing) must never count as a trusted signer or become a recipient
	[ , , $listing ] = self::run(array_merge($base, [ '--with-colons', '--show-keys', $file ]));
	$keys = [];
	//the key being read (an index in $keys), null for a key left out
	$i = null;
	foreach(preg_split('/\r?\n/', $listing) as $line) {
	    $f = explode(':', $line);
	    if ($f[0] === 'pub') {
		//revoked / expired keys are left out (field 2: r, e); field 12: what the key and its subkeys can do (E: encrypt)
		$i = null;
		if (!in_array($f[1] ?? '', [ 'r', 'e' ], true)) {
		    $keys[] = [ 'uids' => [], 'fpr' => null, 'encrypt' => str_contains(strtoupper($f[11] ?? ''), $capability) ];
		    $i = count($keys) - 1;
		}
	    } elseif ($f[0] === 'fpr' && $i !== null && $keys[$i]['fpr'] === null) {
		$keys[$i]['fpr'] = $f[9] ?? null;
	    } elseif ($f[0] === 'uid' && $i !== null && ($f[9] ?? '') !== '') {
		$keys[$i]['uids'][] = $f[9];
	    }
	}
	$keys = array_values(array_filter($keys, fn($k) => $k['fpr'] && $k['encrypt']));
	if (!$keys)
	    throw new \RuntimeException($capability === 'S' ? 'no public key that can sign (revoked, expired, or an encryption key only)' : 'no public key that can encrypt (revoked, expired, or a signing key only)');
	return $keys;
    }

    protected static function gpgEncrypt($cred, $method, $work, $in, $out) {
	$gpg = self::tool('gpg');
	$home = $work . '/g';
	mkdir($home, 0700);
	$base = [ $gpg, '--homedir', $home, '--batch', '--no-tty', '--quiet', '--no-permission-warning', '--no-auto-key-locate', '--no-auto-key-retrieve' ];
	try {
	    $args = array_merge($base, [ '--yes', '--output', $out ]);
	    if (!empty($cred->armor))
		$args[] = '--armor';
	    $secret = null;
	    if ($method === 'pgp') {
		$args = array_merge($args, [ '--trust-model', 'always' ]);
		foreach(self::importPublicKeys($base, $work, $cred->publicKey) as $key)
		    array_push($args, '--recipient', $key['fpr']);
		$args = array_merge($args, [ '--encrypt', $in ]);
	    } else {
		$secret = (string)$cred->passphrase;
		if ($secret === '')
		    throw new \RuntimeException('the credential has no passphrase');
		$args = array_merge($args, [ '--pinentry-mode', 'loopback', '--passphrase-fd', '3', '--cipher-algo', 'AES256', '--symmetric', $in ]);
	    }
	    [ $code, $err ] = self::run($args, $secret);
	    if ($code !== 0) {
		@unlink($out);
		throw new \RuntimeException('gpg: ' . self::toolMessage($err));
	    }
	} finally {
	    $gpgconf = self::tool('gpgconf', false);
	    if ($gpgconf)
		self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
	}
    }

    protected static function opensslEncEncrypt($cred, $in, $out) {
	$password = (string)$cred->passphrase;
	if ($password === '')
	    throw new \RuntimeException('the credential has no password');
	$cipher = (string)($cred->cipher ?: 'aes-256-cbc');
	if (!isset(self::CIPHERS[$cipher]))
	    throw new \RuntimeException("unknown cipher `{$cipher}`");
	$args = [ self::tool('openssl'), 'enc', '-e', '-' . $cipher, '-salt', '-in', $in, '-out', $out, '-pass', 'fd:3' ];
	$kdf = (string)($cred->kdf ?: 'pbkdf2');
	if ($kdf === 'pbkdf2')
	    array_push($args, '-pbkdf2', '-iter', (string)max(1, (int)($cred->iterations ?: 10000)));
	else
	    array_push($args, '-md', $kdf === 'sha256' ? 'sha256' : 'md5');
	if (!empty($cred->armor))
	    $args[] = '-a';
	[ $code, $err ] = self::run($args, $password);
	if ($code !== 0) {
	    @unlink($out);
	    throw new \RuntimeException('openssl: ' . self::toolMessage($err));
	}
    }

    protected static function cmsEncrypt($cred, $work, $in, $out) {
	$certs = self::certificates($cred->certificate);
	if (!$certs)
	    throw new \RuntimeException('the credential has no certificate');
	$args = [ self::tool('openssl'), 'cms', '-encrypt', '-binary', '-aes256', '-in', $in, '-out', $out, '-outform', 'DER' ];
	foreach($certs as $i => $pem)
	    $args[] = self::secretFile($work, "cert{$i}.pem", $pem . "\n");
	[ $code, $err ] = self::run($args);
	if ($code !== 0) {
	    @unlink($out);
	    throw new \RuntimeException('openssl cms: ' . self::toolMessage($err));
	}
    }

    //the PEM certificates of $text
    public static function certificates($text) {
	preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string)$text, $m);
	return $m[0];
    }

    //$in gzip-compressed into $out: its size
    public static function compress($in, $out) {
	$src = fopen($in, 'rb');
	$dst = @fopen('compress.zlib://' . $out, 'wb9');
	if (!$src || !$dst)
	    throw new \RuntimeException('the file could not be compressed');
	stream_copy_to_stream($src, $dst);
	fclose($src);
	fclose($dst);
	clearstatcache(true, $out);
	return filesize($out);
    }

    //the credential's own check: pgp: the public keys (their users); cms: the certificates (their subjects, expired ones refused); the others: the settings
    public static function checkKeys($cred) {
	$method = (string)$cred->method;
	if ($method === 'pgp') {
	    $work = self::workDir();
	    $home = $work . '/g';
	    mkdir($home, 0700);
	    try {
		$keys = self::importPublicKeys([ self::tool('gpg'), '--homedir', $home, '--batch', '--no-tty', '--no-permission-warning' ], $work, $cred->publicKey);
		$uids = array_merge(...array_map(fn($k) => $k['uids'] ?: [ substr($k['fpr'], -16) ], $keys));
		//CLD: 2026-10-24T16:30:00+03:00: Format::plural (was key(s))
		return Format::plural(count($keys), 'recipient key') . ': ' . implode(', ', array_slice($uids, 0, 5));
	    } finally {
		$gpgconf = self::tool('gpgconf', false);
		if ($gpgconf)
		    self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
		self::removeDir($work);
	    }
	}
	if ($method === 'cms') {
	    $names = [];
	    foreach(self::certificates($cred->certificate) as $pem) {
		$info = @openssl_x509_parse($pem);
		if (!$info)
		    throw new \RuntimeException('a certificate could not be read');
		$name = (string)($info['subject']['CN'] ?? $info['name'] ?? '?');
		if (($info['validTo_time_t'] ?? PHP_INT_MAX) < time())
		    throw new \RuntimeException("the certificate of {$name} has expired");
		$names[] = $name;
	    }
	    if (!$names)
		throw new \RuntimeException('no certificate');
	    //CLD: 2026-10-24T16:30:00+03:00: Format::plural (was certificate(s))
	    return Format::plural(count($names), 'recipient certificate') . ': ' . implode(', ', $names);
	}
	if ((string)$cred->passphrase === '')
	    throw new \RuntimeException('no ' . ($method === 'openssl-enc' ? 'password' : 'passphrase'));
	return 'settings saved';
    }
}
