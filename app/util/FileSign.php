<?php

/*
    signs files on disk, for the FTP / SFTP Upload step (models\node\NodeAppFtpUpload) with a Signing key credential
    util\FileDecrypt's helpers (the system's tools without a shell, secrets on fd 3, key files 0600 in a private folder).
	- pgp:  OpenPGP with a private key (+ passphrase; `keyId` picks one when the text has several): attached (a signed message, .gpg / .asc; with PGP encryption:
		signed and encrypted in one message) or detached (.sig / .asc)
	- cms:  CMS / S/MIME with an X.509 private key + its certificate (+ the chain): attached (.p7m, the content inside) or detached (.p7s)
	- raw:  a plain signature with a private key (RSA, EC or Ed25519, PEM; openssl dgst / pkeyutl): detached only (.sig, binary or base64)
	Errors: \RuntimeException with the tool's message
*/

namespace util;

class FileSign extends FileDecrypt {
    const METHODS = [
	'pgp' => 'PGP / GPG private key',
	'cms' => 'S/MIME / CMS: X.509 private key + certificate',
	'raw' => 'Private key, plain signature (RSA, EC, Ed25519)',
    ];
    const DIGESTS = [
	'sha256' => 'SHA-256',
	'sha384' => 'SHA-384',
	'sha512' => 'SHA-512'
    ];
    const MODES = [
	'attached' => 'In the file (a signed file)',
	'detached' => 'A separate signature file, next to it'
    ];

    //the ending of a signed file (attached) or of the signature file (detached)
    public static function ending($method, $mode, $armor = false) {
	if ($method === 'pgp')
	    return $mode === 'attached' ? ($armor ? '.asc' : '.gpg') : ($armor ? '.asc' : '.sig');
	if ($method === 'cms')
	    return $mode === 'attached' ? '.p7m' : '.p7s';
	return '.sig';
    }

    //whether $method can sign in the file itself
    public static function canAttach($method) {
	return $method !== 'raw';
    }

    //$in signed into $out ($mode: attached = $out is the signed file, detached = $out is the signature of $in); $encryptTo: PGP only, an Encryption key credential with
    //PGP public keys to encrypt to in the same message (attached)
    public static function sign($cred, $in, $out, $mode, $encryptTo = null) {
	$method = (string)$cred->method;
	if ($mode === 'attached' && !self::canAttach($method))
	    throw new \RuntimeException('a plain signature is always a separate file (detached)');
	$work = self::workDir();
	try {
	    match($method) {
		'pgp' => self::pgpSign($cred, $work, $in, $out, $mode, $encryptTo),
		'cms' => self::cmsSign($cred, $work, $in, $out, $mode),
		'raw' => self::rawSign($cred, $work, $in, $out),
		default => throw new \RuntimeException("unknown signing method `{$method}`"),
	    };
	} finally {
	    self::removeDir($work);
	}
	clearstatcache(true, $out);
	if (!is_file($out) || filesize($out) === 0)
	    throw new \RuntimeException('nothing was signed');
    }

    protected static function digest($cred) {
	$d = (string)($cred->digest ?: 'sha256');
	return isset(self::DIGESTS[$d]) ? $d : 'sha256';
    }

    protected static function pgpSign($cred, $work, $in, $out, $mode, $encryptTo) {
	$gpg = self::tool('gpg');
	$home = $work . '/g';
	mkdir($home, 0700);
	$base = [ $gpg, '--homedir', $home, '--batch', '--no-tty', '--quiet', '--no-permission-warning', '--no-auto-key-locate', '--no-auto-key-retrieve' ];
	try {
	    $key = trim((string)$cred->privateKey);
	    if ($key === '')
		throw new \RuntimeException('the credential has no private key');
	    [ $code, $err ] = self::run(array_merge($base, [ '--import', self::secretFile($work, 'key.asc', $key . "\n") ]));
	    if ($code !== 0)
		throw new \RuntimeException('the private key could not be read: ' . self::toolMessage($err));
	    $args = array_merge($base, [ '--yes', '--pinentry-mode', 'loopback', '--passphrase-fd', '3', '--digest-algo', strtoupper(self::digest($cred)), '--output', $out ]);
	    if (trim((string)$cred->keyId) !== '')
		array_push($args, '--local-user', trim((string)$cred->keyId));
	    //one signed and encrypted message: armored as the encryption key says (it names the file: .gpg / .asc)
	    $combined = $mode === 'attached' && $encryptTo && $encryptTo->method === 'pgp';
	    if ($combined ? !empty($encryptTo->armor) : !empty($cred->armor))
		$args[] = '--armor';
	    if ($mode === 'detached') {
		$args[] = '--detach-sign';
	    } elseif ($combined) {
		//signed and encrypted in one message, to the recipients' public keys (imported into this keyring)
		$args[] = '--trust-model';
		$args[] = 'always';
		foreach(self::recipientKeys($base, $work, $encryptTo->publicKey) as $fpr)
		    array_push($args, '--recipient', $fpr);
		array_push($args, '--sign', '--encrypt');
	    } else {
		$args[] = '--sign';
	    }
	    $args[] = $in;
	    [ $code, $err ] = self::run($args, (string)$cred->passphrase);
	    if ($code !== 0) {
		@unlink($out);
		throw new \RuntimeException('gpg: ' . (preg_match('/bad passphrase|no passphrase given/i', $err) ? 'wrong passphrase for the signing key' : self::toolMessage($err)));
	    }
	} finally {
	    $gpgconf = self::tool('gpgconf', false);
	    if ($gpgconf)
		self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
	}
    }

    //the fingerprints of the public keys in $text, imported into the keyring of $base (FileEncrypt's check: encryption keys, not revoked / expired)
    protected static function recipientKeys(array $base, $work, $text) {
	return array_column(FileEncrypt::importPublicKeys($base, $work, $text), 'fpr');
    }

    protected static function cmsSign($cred, $work, $in, $out, $mode) {
	$key = trim((string)$cred->privateKey);
	$cert = FileEncrypt::certificates($cred->certificate)[0] ?? null;
	if ($key === '' || $cert === null)
	    throw new \RuntimeException('the credential needs the private key and its certificate');
	$args = [ self::tool('openssl'), 'cms', '-sign', '-binary', '-md', self::digest($cred), '-in', $in, '-out', $out, '-outform', 'DER',
	    '-signer', self::secretFile($work, 'cert.pem', $cert . "\n"), '-inkey', self::secretFile($work, 'key.pem', $key . "\n"), '-passin', 'fd:3' ];
	$chain = FileEncrypt::certificates($cred->chain);
	if ($chain)
	    array_push($args, '-certfile', self::secretFile($work, 'chain.pem', implode("\n", $chain) . "\n"));
	if ($mode === 'attached')
	    $args[] = '-nodetach';
	[ $code, $err ] = self::run($args, (string)$cred->passphrase);
	if ($code !== 0) {
	    @unlink($out);
	    throw new \RuntimeException('openssl cms: ' . self::toolMessage($err));
	}
    }

    protected static function rawSign($cred, $work, $in, $out) {
	$key = trim((string)$cred->privateKey);
	$pkey = @openssl_pkey_get_private($key, (string)$cred->passphrase);
	if ($pkey === false)
	    throw new \RuntimeException('the private key could not be opened (is the passphrase right?)');
	$type = openssl_pkey_get_details($pkey)['type'] ?? null;
	$keyFile = self::secretFile($work, 'key.pem', $key . "\n");
	$sig = !empty($cred->armor) ? $work . '/sig.bin' : $out;
	//EdDSA signs the whole message (no digest of it: pkeyutl -rawin); RSA / EC: a digest (dgst, read as a stream)
	$eddsa = in_array($type, array_filter([ defined('OPENSSL_KEYTYPE_ED25519') ? OPENSSL_KEYTYPE_ED25519 : null, defined('OPENSSL_KEYTYPE_ED448') ? OPENSSL_KEYTYPE_ED448 : null ], fn($t) => $t !== null), true);
	$args = $eddsa
	    ? [ self::tool('openssl'), 'pkeyutl', '-sign', '-rawin', '-inkey', $keyFile, '-passin', 'fd:3', '-in', $in, '-out', $sig ]
	    : [ self::tool('openssl'), 'dgst', '-' . self::digest($cred), '-sign', $keyFile, '-passin', 'fd:3', '-out', $sig, $in ];
	[ $code, $err ] = self::run($args, (string)$cred->passphrase);
	if ($code !== 0) {
	    @unlink($sig);
	    throw new \RuntimeException('openssl: ' . self::toolMessage($err));
	}
	if ($sig !== $out) {
	    file_put_contents($out, base64_encode((string)file_get_contents($sig)) . "\n");
	    @unlink($sig);
	}
    }

    //the credential's own check: the key opens; pgp: its signing users; cms: the key belongs to the certificate, not expired; raw: the key's type
    public static function checkKey($cred) {
	$method = (string)$cred->method;
	if ($method === 'pgp') {
	    $work = self::workDir();
	    $home = $work . '/g';
	    mkdir($home, 0700);
	    $base = [ self::tool('gpg'), '--homedir', $home, '--batch', '--no-tty', '--no-permission-warning' ];
	    try {
		[ $code, $err ] = self::run(array_merge($base, [ '--import', self::secretFile($work, 'key.asc', trim((string)$cred->privateKey) . "\n") ]));
		if ($code !== 0)
		    throw new \RuntimeException('the private key could not be read: ' . self::toolMessage($err));
		//a signature of a few bytes: the passphrase is right, the key can sign
		file_put_contents($work . '/t', 'test');
		$args = array_merge($base, [ '--yes', '--pinentry-mode', 'loopback', '--passphrase-fd', '3', '--output', $work . '/t.sig', '--detach-sign' ]);
		if (trim((string)$cred->keyId) !== '')
		    array_push($args, '--local-user', trim((string)$cred->keyId));
		[ $code, $err ] = self::run(array_merge($args, [ $work . '/t' ]), (string)$cred->passphrase);
		if ($code !== 0)
		    throw new \RuntimeException(preg_match('/bad passphrase|no passphrase given/i', $err) ? 'wrong passphrase' : 'the key cannot sign: ' . self::toolMessage($err));
		[ , , $listing ] = self::run(array_merge($base, [ '--with-colons', '--list-secret-keys' ]));
		preg_match_all('/^uid:[^:]*:[^:]*:[^:]*:[^:]*:[^:]*:[^:]*:[^:]*:[^:]*:([^:]+):/m', $listing, $m);
		return 'signs as ' . ($m[1] ? implode(', ', array_slice($m[1], 0, 3)) : 'an unnamed user');
	    } finally {
		$gpgconf = self::tool('gpgconf', false);
		if ($gpgconf)
		    self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
		self::removeDir($work);
	    }
	}
	$pkey = @openssl_pkey_get_private(trim((string)$cred->privateKey), (string)$cred->passphrase);
	if ($pkey === false)
	    throw new \RuntimeException('the private key could not be opened (is the passphrase right?)');
	$details = openssl_pkey_get_details($pkey);
	if ($method === 'cms') {
	    $cert = FileEncrypt::certificates($cred->certificate)[0] ?? null;
	    if ($cert === null)
		throw new \RuntimeException('no certificate');
	    if (!openssl_x509_check_private_key($cert, $pkey))
		throw new \RuntimeException('the certificate does not belong to the private key');
	    $info = openssl_x509_parse($cert);
	    if (($info['validTo_time_t'] ?? PHP_INT_MAX) < time())
		throw new \RuntimeException('the certificate has expired');
	    //CLD: 2026-10-24T16:30:00+03:00: the account's date format (Format::date; was Y-m-d)
	    return 'signs as ' . (string)($info['subject']['CN'] ?? $info['name'] ?? '?') . ' (until ' . Format::date((int)$info['validTo_time_t']) . ')';
	}
	$types = [ OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_EC => 'EC' ] + (defined('OPENSSL_KEYTYPE_ED25519') ? [ OPENSSL_KEYTYPE_ED25519 => 'Ed25519' ] : []);
	return ($types[$details['type'] ?? -1] ?? 'a') . ' key' . (!empty($details['bits']) ? ' of ' . $details['bits'] . ' bits' : '');
    }
}
