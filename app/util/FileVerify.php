<?php

/*
    checks file signatures, for the FTP / SFTP step (models\node\NodeAppFtp) with a Verification key credential:
	the other way of util\FileSign; util\FileDecrypt's helpers (the system's tools without a shell, files 0600 in a private folder).
	- pgp:  the signers' PGP public keys: a signed message (attached: its content written out) or a detached signature (.sig / .asc). A signature counts only when it
		was made by one of these keys (its primary fingerprint), never because gpg knows the key some other way
	- cms:  the trusted certificates (the signer's own, or the CA that issued it): a signed .p7m (attached) or a detached .p7s; the chain is checked, at today's date
	- raw:  the signer's public key (RSA, EC, Ed25519; PEM): a detached signature (.sig, binary or base64)
	A bad signature (the file was changed), one by another key, an expired / revoked key or certificate: \RuntimeException. The result: [ signer ] (a user id, the
	certificate's subject, or the key's type)
*/

namespace util;

class FileVerify extends FileDecrypt {
    const METHODS = [
	'pgp' => 'PGP / GPG public key (the signer\'s)',
	'cms' => 'S/MIME / CMS: trusted certificate (the signer\'s, or its CA)',
	'raw' => 'Public key, plain signature (RSA, EC, Ed25519)',
    ];

    //the endings of a detached signature file, by method
    public static function signatureEndings($method) {
	return match($method) { 'pgp' => [ '.sig', '.asc' ], 'cms' => [ '.p7s' ], default => [ '.sig' ] };
    }

    //the name of the file inside an attached signature: the method's ending removed (.gpg / .pgp / .asc, .p7m)
    public static function unwrappedName($name, $method) {
	return $method === 'raw' ? $name : self::decryptedName($name, $method === 'cms' ? 'cms' : 'pgp');
    }

    // ------------------------------------------------------------------------------------

    //gpg's status lines ($status, --status-fd) checked against the trusted primary fingerprints $signers: [ signer ]; null when there is no signature
    public static function pgpResult($status, array $signers) {
	$signers = array_map('strtoupper', $signers);
	$lines = preg_split('/\r?\n/', (string)$status);
	$valid = [];
	$uid = null;
	$problem = null;
	foreach($lines as $line) {
	    if (!preg_match('/^\[GNUPG:\] (\S+) ?(.*)$/', $line, $m))
		continue;
	    [ , $kw, $rest ] = $m;
	    $f = explode(' ', $rest);
	    switch($kw) {
		case 'BADSIG':
		    $problem = $problem ?? 'bad signature: the file was changed after it was signed';
		    break;
		case 'ERRSIG':
		    //field 6: 9 = no public key
		    $problem = $problem ?? (($f[5] ?? '') === '9' ? 'signed by a key that is not one of the trusted keys (' . ($f[0] ?? '?') . ')' : 'the signature could not be checked');
		    break;
		case 'EXPKEYSIG':
		    $problem = $problem ?? 'signed with an expired key';
		    break;
		case 'REVKEYSIG':
		    $problem = $problem ?? 'signed with a revoked key';
		    break;
		case 'EXPSIG':
		    $problem = $problem ?? 'the signature has expired';
		    break;
		case 'GOODSIG':
		    $uid = trim(implode(' ', array_slice($f, 1)));
		    break;
		case 'VALIDSIG':
		    //the last field: the primary key's fingerprint (the signature may be made by a subkey)
		    $valid[] = strtoupper((string)end($f));
		    break;
	    }
	}
	if ($problem !== null)
	    throw new \RuntimeException($problem);
	if (!$valid)
	    return null;
	foreach($valid as $fpr) {
	    if (!in_array($fpr, $signers, true))
		throw new \RuntimeException('signed by a key that is not one of the trusted keys (' . substr($fpr, -16) . ')');
	}
	return [ 'signer' => $uid !== null && $uid !== '' ? $uid : substr($valid[0], -16) ];
    }

    //attached: $in (signed) checked, its content written to $out: [ signer ]; not signed: an error
    public static function attached($cred, $in, $out) {
	$method = (string)$cred->method;
	if ($method === 'raw')
	    throw new \RuntimeException('a plain signature is always a separate file');
	$work = self::workDir();
	try {
	    $result = $method === 'pgp' ? self::verifyPgp($cred, $work, [ '--output', $out, '--decrypt', $in ]) : self::verifyCms($cred, $work, $in, $out, null);
	} catch (\RuntimeException $e) {
	    @unlink($out);
	    throw $e;
	} finally {
	    self::removeDir($work);
	}
	if ($result === null) {
	    @unlink($out);
	    throw new \RuntimeException('the file is not signed');
	}
	return $result;
    }

    //detached: signature file $sig of file $data: [ signer ]
    public static function detached($cred, $data, $sig) {
	$method = (string)$cred->method;
	$work = self::workDir();
	try {
	    $result = match($method) {
		'pgp' => self::verifyPgp($cred, $work, [ '--verify', $sig, $data ]),
		'cms' => self::verifyCms($cred, $work, $sig, null, $data),
		'raw' => self::verifyRaw($cred, $work, $data, $sig),
		default => throw new \RuntimeException("unknown verification method `{$method}`"),
	    };
	} finally {
	    self::removeDir($work);
	}
	if ($result === null)
	    throw new \RuntimeException('the signature file holds no signature');
	return $result;
    }

    protected static function verifyPgp($cred, $work, array $what) {
	$home = $work . '/g';
	mkdir($home, 0700);
	$base = [ self::tool('gpg'), '--homedir', $home, '--batch', '--no-tty', '--quiet', '--no-permission-warning', '--no-auto-key-locate', '--no-auto-key-retrieve' ];
	try {
	    $signers = array_column(FileEncrypt::importPublicKeys($base, $work, $cred->publicKey, 'S'), 'fpr');
	    [ $code, $err, $status ] = self::run(array_merge($base, [ '--yes', '--trust-model', 'always', '--status-fd', '1' ], $what));
	    if (preg_match('/^\[GNUPG:\] (NODATA|DECRYPTION_FAILED)/m', $status) && !str_contains($status, 'VALIDSIG'))
		throw new \RuntimeException(str_contains($status, 'DECRYPTION_FAILED') ? 'the file is encrypted: decrypt it (Decrypt) with a key' : 'no PGP data: the file is not signed');
	    return self::pgpResult($status, $signers);
	} finally {
	    $gpgconf = self::tool('gpgconf', false);
	    if ($gpgconf)
		self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
	}
    }

    //CMS: attached ($out: the content) or detached ($content: the signed file); the trusted certificates are the CAs; the signer's subject
    protected static function verifyCms($cred, $work, $in, $out, $content) {
	$certs = FileEncrypt::certificates($cred->certificate);
	if (!$certs)
	    throw new \RuntimeException('the credential has no certificate');
	$head = (string)file_get_contents($in, false, null, 0, 4096);
	$form = str_starts_with(ltrim($head), '-----BEGIN') ? 'PEM' : (preg_match('/^(MIME-Version|Content-Type):/mi', $head) ? 'SMIME' : 'DER');
	$signerFile = $work . '/signer.pem';
	$args = [ self::tool('openssl'), 'cms', '-verify', '-binary', '-inform', $form, '-in', $in, '-CAfile', self::secretFile($work, 'ca.pem', implode("\n", $certs) . "\n"),
	    '-purpose', 'any', '-signer', $signerFile, '-out', $out ?? '/dev/null' ];
	if ($content !== null)
	    array_push($args, '-content', $content);
	[ $code, $err ] = self::run($args);
	if ($code !== 0) {
	    $message = match(true) {
		(bool)preg_match('/digest failure|content verify error|signature failure/i', $err) => 'bad signature: the file was changed after it was signed',
		(bool)preg_match('/unable to get local issuer|self.signed|unable to verify|certificate verify error/i', $err) => 'signed by a certificate that is not trusted',
		(bool)preg_match('/certificate has expired/i', $err) => 'signed with an expired certificate',
		(bool)preg_match('/no content type|not enveloped|wrong content type|type not signed/i', $err) => 'the file is not signed (S/MIME)',
		default => 'openssl cms: ' . self::toolMessage($err),
	    };
	    throw new \RuntimeException($message);
	}
	$info = is_file($signerFile) ? @openssl_x509_parse((string)file_get_contents($signerFile)) : null;
	return [ 'signer' => (string)($info['subject']['CN'] ?? $info['name'] ?? 'a trusted certificate') ];
    }

    //a plain signature (binary or base64) of $data with the public key
    protected static function verifyRaw($cred, $work, $data, $sig) {
	$pub = trim((string)$cred->publicKey);
	$pkey = @openssl_pkey_get_public($pub);
	if ($pkey === false)
	    throw new \RuntimeException('the public key could not be read');
	$details = openssl_pkey_get_details($pkey);
	$bytes = (string)file_get_contents($sig, false, null, 0, 65536);
	//base64 (a text of base64 characters only) decoded
	if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $bytes) && ($decoded = base64_decode(preg_replace('/\s+/', '', $bytes), true)) !== false && $decoded !== '') {
	    $sig = $work . '/sig.bin';
	    file_put_contents($sig, $decoded);
	}
	$keyFile = self::secretFile($work, 'pub.pem', $pub . "\n");
	$eddsa = in_array($details['type'] ?? null, array_filter([ defined('OPENSSL_KEYTYPE_ED25519') ? OPENSSL_KEYTYPE_ED25519 : null, defined('OPENSSL_KEYTYPE_ED448') ? OPENSSL_KEYTYPE_ED448 : null ], fn($t) => $t !== null), true);
	$digest = in_array((string)$cred->digest, array_keys(FileSign::DIGESTS), true) ? (string)$cred->digest : 'sha256';
	$args = $eddsa
	    ? [ self::tool('openssl'), 'pkeyutl', '-verify', '-pubin', '-inkey', $keyFile, '-rawin', '-in', $data, '-sigfile', $sig ]
	    : [ self::tool('openssl'), 'dgst', '-' . $digest, '-verify', $keyFile, '-signature', $sig, $data ];
	[ $code, $err, $outText ] = self::run($args);
	if ($code !== 0 || !preg_match('/Verified OK|Signature Verified Successfully/i', $outText . $err))
	    throw new \RuntimeException('bad signature: the file was changed after it was signed, or it was signed with another key' . ($eddsa ? '' : " (or not with {$digest})"));
	$types = [ OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_EC => 'EC' ] + (defined('OPENSSL_KEYTYPE_ED25519') ? [ OPENSSL_KEYTYPE_ED25519 => 'Ed25519' ] : []);
	return [ 'signer' => ($types[$details['type'] ?? -1] ?? 'the') . ' key' ];
    }

    //the credential's own check: pgp: the signing keys (their users); cms: the certificates (subjects, not expired); raw: the key's type
    public static function checkKeys($cred) {
	$method = (string)$cred->method;
	if ($method === 'pgp') {
	    $work = self::workDir();
	    $home = $work . '/g';
	    mkdir($home, 0700);
	    try {
		$keys = FileEncrypt::importPublicKeys([ self::tool('gpg'), '--homedir', $home, '--batch', '--no-tty', '--no-permission-warning' ], $work, $cred->publicKey, 'S');
		$uids = array_merge(...array_map(fn($k) => $k['uids'] ?: [ substr($k['fpr'], -16) ], $keys));
		return count($keys) . ' signer key(s): ' . implode(', ', array_slice($uids, 0, 5));
	    } finally {
		$gpgconf = self::tool('gpgconf', false);
		if ($gpgconf)
		    self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
		self::removeDir($work);
	    }
	}
	if ($method === 'cms')
	    return str_replace('recipient', 'trusted', FileEncrypt::checkKeys($cred));
	$pkey = @openssl_pkey_get_public(trim((string)$cred->publicKey));
	if ($pkey === false)
	    throw new \RuntimeException('the public key could not be read');
	$details = openssl_pkey_get_details($pkey);
	$types = [ OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_EC => 'EC' ] + (defined('OPENSSL_KEYTYPE_ED25519') ? [ OPENSSL_KEYTYPE_ED25519 => 'Ed25519' ] : []);
	return ($types[$details['type'] ?? -1] ?? 'a') . ' public key' . (!empty($details['bits']) ? ' of ' . $details['bits'] . ' bits' : '');
    }
}
