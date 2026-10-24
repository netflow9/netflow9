<?php

/*
    decrypts and decompresses files on disk, for the FTP / SFTP step (models\node\NodeAppFtp) with a Decryption key credential
    (models\credential\CredentialDecryption). The work is done by the system's tools, run without a shell; secrets go to them on a pipe (fd 3), never on the command line
    or in the environment; key files are written 0600 in a private temporary folder, removed afterwards.
	- pgp:            OpenPGP (gpg, `.gpg` / `.pgp` / `.asc`) with a private key (+ its passphrase): an own, temporary keyring per call
	- pgp-symmetric:  OpenPGP encrypted with a passphrase only (gpg -c)
	- openssl-enc:    `openssl enc` files (Salted__ header, binary or base64) with a password: cipher, key derivation (PBKDF2 + iterations, or the legacy MD5 / SHA-256)
	- cms:            CMS / S/MIME / PKCS#7 enveloped data (`.p7m`, `.cms`; DER, PEM or S/MIME) with the recipient's private key (+ certificate, optional)
	- decompress():   gzip (`.gz`) / bzip2 (`.bz2`), with a size limit
	Errors: \RuntimeException with the tool's message
*/

namespace util;

class FileDecrypt {
    const METHODS = [
	'pgp' => 'PGP / GPG private key',
	'pgp-symmetric' => 'PGP / GPG passphrase (symmetric)',
	'openssl-enc' => 'OpenSSL password (openssl enc)',
	'cms' => 'S/MIME / CMS private key (.p7m)',
    ];

    //the file name endings each method removes (the decrypted file's name); a name with none of them keeps its name
    const EXTENSIONS = [
	'pgp' => [ 'gpg', 'pgp', 'asc' ],
	'pgp-symmetric' => [ 'gpg', 'pgp', 'asc' ],
	'openssl-enc' => [ 'enc', 'aes', 'bin' ],
	'cms' => [ 'p7m', 'p7', 'cms', 'smime', 'p7e' ],
    ];
    const COMPRESSED = [ 'gz' => 'gzip', 'gzip' => 'gzip', 'bz2' => 'bzip2' ];

    //openssl enc ciphers offered (openssl's names)
    const CIPHERS = [
	'aes-256-cbc' => 'AES-256-CBC',
	'aes-192-cbc' => 'AES-192-CBC',
	'aes-128-cbc' => 'AES-128-CBC',
	'aes-256-ctr' => 'AES-256-CTR',
	'aes-128-ctr' => 'AES-128-CTR',
	'des-ede3-cbc' => '3DES (DES-EDE3-CBC)',
	'chacha20' => 'ChaCha20',
    ];
    const KDF = [ 'pbkdf2' => 'PBKDF2 (-pbkdf2)', 'md5' => 'Legacy, MD5 (OpenSSL 1.0 default)', 'sha256' => 'Legacy, SHA-256 (OpenSSL 1.1 without -pbkdf2)' ];

    //seconds a tool may run
    const TIMEOUT = 300;

    //the name of the decrypted file: the method's ending removed (report.csv.gpg -> report.csv)
    public static function decryptedName($name, $method) {
	return self::stripEnding($name, self::EXTENSIONS[$method] ?? []);
    }

    //whether $name ends with one of the method's endings
    public static function looksEncrypted($name, $method) {
	return self::decryptedName($name, $method) !== $name;
    }

    //the compression of a file by its name ending (gzip / bzip2), or null
    public static function compression($name) {
	return self::COMPRESSED[strtolower(pathinfo((string)$name, PATHINFO_EXTENSION))] ?? null;
    }

    public static function decompressedName($name) {
	return self::stripEnding($name, array_keys(self::COMPRESSED));
    }

    protected static function stripEnding($name, array $endings) {
	$ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
	if ($ext === '' || !in_array($ext, $endings, true))
	    return $name;
	$base = substr($name, 0, -(strlen($ext) + 1));
	return $base !== '' ? $base : $name;
    }

    // ------------------------------------------------------------------------------------

    //$in decrypted into $out with credential $cred (its method's fields: privateKey, passphrase, certificate, cipher, kdf, iterations); larger than $maxBytes: an error
    //CLD: 2026-10-20T20:00:00+03:00: + $verifyCred: a PGP Verification key credential: a signature in the message is checked in the same pass (util\FileVerify::pgpResult):
    //returns [ signer ] when it was signed by one of its keys, null when the message is not signed (the caller checks the inside); a bad or untrusted signature: an error
    public static function decrypt($cred, $in, $out, $maxBytes, $verifyCred = null) {
	$method = (string)$cred->method;
	$work = self::workDir();
	$signature = null;
	try {
	    switch($method) {
		case 'pgp':
		case 'pgp-symmetric':
		    $signature = self::gpg($cred, $method, $work, $in, $out, $verifyCred && (string)$verifyCred->method === 'pgp' ? $verifyCred : null);
		    break;
		case 'openssl-enc':
		    self::opensslEnc($cred, $in, $out);
		    break;
		case 'cms':
		    self::cms($cred, $work, $in, $out);
		    break;
		default:
		    throw new \RuntimeException("unknown decryption method `{$method}`");
	    }
	} finally {
	    self::removeDir($work);
	}
	clearstatcache(true, $out);
	if (!is_file($out))
	    throw new \RuntimeException('nothing was decrypted');
	if (filesize($out) > $maxBytes) {
	    @unlink($out);
	    throw new \RuntimeException('the decrypted file is larger than ' . round($maxBytes / 1048576, 1) . ' MB');
	}
	return $signature;
    }

    protected static function gpg($cred, $method, $work, $in, $out, $verifyCred = null) {
	$gpg = self::tool('gpg');
	$home = $work . '/g';
	mkdir($home, 0700);
	$base = [ $gpg, '--homedir', $home, '--batch', '--no-tty', '--quiet', '--no-permission-warning', '--no-auto-key-locate', '--no-auto-key-retrieve' ];
	try {
	    if ($method === 'pgp') {
		$key = trim((string)$cred->privateKey);
		if ($key === '')
		    throw new \RuntimeException('the credential has no private key');
		$keyFile = self::secretFile($work, 'key.asc', $key . "\n");
		[ $code, $err ] = self::run(array_merge($base, [ '--import', $keyFile ]));
		if ($code !== 0)
		    throw new \RuntimeException('the private key could not be read: ' . self::toolMessage($err));
	    }
	    $passphrase = (string)$cred->passphrase;
	    if ($method === 'pgp-symmetric' && $passphrase === '')
		throw new \RuntimeException('the credential has no passphrase');
	    //CLD: 2026-10-20T20:00:00+03:00: the signers' public keys (only theirs count: our own private key is in this keyring too); gpg's status lines on stdout
	    $signers = $verifyCred ? array_column(\util\FileEncrypt::importPublicKeys($base, $work, $verifyCred->publicKey, 'S'), 'fpr') : null;
	    $args = array_merge($base, [ '--yes', '--trust-model', 'always', '--pinentry-mode', 'loopback' ]);
	    if ($signers !== null)
		array_push($args, '--status-fd', '1');
	    //an empty passphrase on fd 3: a key without one
	    $args = array_merge($args, [ '--passphrase-fd', '3', '--output', $out, '--decrypt', $in ]);
	    [ $code, $err, $status ] = self::run($args, $passphrase);
	    if ($signers !== null) {
		clearstatcache(true, $out);
		if (preg_match('/^\[GNUPG:\] (DECRYPTION_FAILED|NODATA|BADMDC)/m', $status) || !is_file($out)) {
		    @unlink($out);
		    throw new \RuntimeException('gpg: ' . self::toolMessage($err));
		}
		try {
		    return \util\FileVerify::pgpResult($status, $signers);
		} catch (\RuntimeException $e) {
		    @unlink($out);
		    throw $e;
		}
	    }
	    //CLD: 2026-10-20T19:00:00+03:00: a signed file whose signer's public key is not known: gpg decrypts it but ends with 2; that is not an error here (signatures are
	    //not checked by this step); a bad signature or a failed decryption still is
	    clearstatcache(true, $out);
	    $unverifiable = $code === 2 && is_file($out) && str_contains($err, "Can't check signature: No public key") && !preg_match('/BAD signature|decryption failed|no valid OpenPGP data/i', $err);
	    if ($code !== 0 && !$unverifiable) {
		@unlink($out);
		throw new \RuntimeException('gpg: ' . self::toolMessage($err));
	    }
	    return null;
	} finally {
	    //the keyring's agent, started by gpg for this folder
	    $gpgconf = self::tool('gpgconf', false);
	    if ($gpgconf)
		self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
	}
    }

    protected static function opensslEnc($cred, $in, $out) {
	$password = (string)$cred->passphrase;
	if ($password === '')
	    throw new \RuntimeException('the credential has no password');
	$cipher = (string)($cred->cipher ?: 'aes-256-cbc');
	if (!isset(self::CIPHERS[$cipher]))
	    throw new \RuntimeException("unknown cipher `{$cipher}`");
	$args = [ self::tool('openssl'), 'enc', '-d', '-' . $cipher, '-in', $in, '-out', $out, '-pass', 'fd:3' ];
	$kdf = (string)($cred->kdf ?: 'pbkdf2');
	if ($kdf === 'pbkdf2')
	    array_push($args, '-pbkdf2', '-iter', (string)max(1, (int)($cred->iterations ?: 10000)));
	else
	    array_push($args, '-md', $kdf === 'sha256' ? 'sha256' : 'md5');
	//base64 (openssl enc -a): starts with the base64 of `Salted__`
	$head = (string)file_get_contents($in, false, null, 0, 16);
	if (str_starts_with(ltrim($head), 'U2FsdGVkX1'))
	    $args[] = '-a';
	[ $code, $err ] = self::run($args, $password);
	if ($code !== 0) {
	    @unlink($out);
	    throw new \RuntimeException('openssl: ' . (preg_match('/bad decrypt|wrong final block/i', $err) ? 'wrong password or settings (cipher, key derivation)' : self::toolMessage($err)));
	}
    }

    protected static function cms($cred, $work, $in, $out) {
	$key = trim((string)$cred->privateKey);
	if ($key === '')
	    throw new \RuntimeException('the credential has no private key');
	$args = [ self::tool('openssl'), 'cms', '-decrypt', '-binary', '-in', $in, '-out', $out, '-inkey', self::secretFile($work, 'key.pem', $key . "\n"), '-passin', 'fd:3' ];
	if (trim((string)$cred->certificate) !== '')
	    array_push($args, '-recip', self::secretFile($work, 'cert.pem', trim((string)$cred->certificate) . "\n"));
	$head = (string)file_get_contents($in, false, null, 0, 4096);
	$form = str_starts_with(ltrim($head), '-----BEGIN') ? 'PEM' : (preg_match('/^(MIME-Version|Content-Type):/mi', $head) ? 'SMIME' : 'DER');
	array_push($args, '-inform', $form);
	[ $code, $err ] = self::run($args, (string)$cred->passphrase);
	if ($code !== 0) {
	    @unlink($out);
	    throw new \RuntimeException('openssl cms: ' . self::toolMessage($err));
	}
    }

    //$in (gzip / bzip2) decompressed into $out; more than $maxBytes: an error
    public static function decompress($in, $out, $kind, $maxBytes) {
	$src = @fopen(($kind === 'bzip2' ? 'compress.bzip2://' : 'compress.zlib://') . $in, 'rb');
	if (!$src)
	    throw new \RuntimeException("not a {$kind} file");
	$dst = fopen($out, 'wb');
	$size = 0;
	try {
	    while(!feof($src)) {
		$chunk = fread($src, 1048576);
		if ($chunk === false)
		    throw new \RuntimeException("not a valid {$kind} file");
		$size += strlen($chunk);
		if ($size > $maxBytes)
		    throw new \RuntimeException('the decompressed file is larger than ' . round($maxBytes / 1048576, 1) . ' MB');
		fwrite($dst, $chunk);
	    }
	} catch (\RuntimeException $e) {
	    fclose($dst);
	    @unlink($out);
	    throw $e;
	} finally {
	    fclose($src);
	}
	fclose($dst);
	return $size;
    }

    //the credential's own check: pgp: the key is read (its ids); cms: the key opens with the passphrase; the others: the settings
    public static function check($cred) {
	$method = (string)$cred->method;
	if ($method === 'pgp') {
	    $work = self::workDir();
	    try {
		$home = $work . '/g';
		mkdir($home, 0700);
		$base = [ self::tool('gpg'), '--homedir', $home, '--batch', '--no-tty', '--no-permission-warning' ];
		[ $code, $err ] = self::run(array_merge($base, [ '--import', self::secretFile($work, 'key.asc', trim((string)$cred->privateKey) . "\n") ]));
		if ($code !== 0)
		    throw new \RuntimeException('the private key could not be read: ' . self::toolMessage($err));
		[ $code, $err, $listing ] = self::run(array_merge($base, [ '--with-colons', '--list-secret-keys' ]));
		$uids = [];
		$secret = false;
		foreach(preg_split('/\r?\n/', $listing) as $line) {
		    $f = explode(':', $line);
		    if (in_array($f[0], [ 'sec', 'ssb' ], true))
			$secret = true;
		    if ($f[0] === 'uid' && ($f[9] ?? '') !== '')
			$uids[] = $f[9];
		}
		if (!$secret)
		    throw new \RuntimeException('no private key found (a public key cannot decrypt)');
		$gpgconf = self::tool('gpgconf', false);
		if ($gpgconf)
		    self::run([ $gpgconf, '--homedir', $home, '--kill', 'all' ]);
		return 'private key of ' . ($uids ? implode(', ', array_slice($uids, 0, 3)) : 'an unnamed user');
	    } finally {
		self::removeDir($work);
	    }
	}
	if ($method === 'cms') {
	    $key = @openssl_pkey_get_private(trim((string)$cred->privateKey), (string)$cred->passphrase);
	    if ($key === false)
		throw new \RuntimeException('the private key could not be opened (is the passphrase right?)');
	    $details = openssl_pkey_get_details($key);
	    if (trim((string)$cred->certificate) !== '' && !openssl_x509_check_private_key(trim((string)$cred->certificate), $key))
		throw new \RuntimeException('the certificate does not belong to the private key');
	    return 'private key of ' . (int)($details['bits'] ?? 0) . ' bits';
	}
	if ((string)$cred->passphrase === '')
	    throw new \RuntimeException('no ' . ($method === 'openssl-enc' ? 'password' : 'passphrase'));
	return 'settings saved (a file decrypts only when the ' . ($method === 'openssl-enc' ? 'password and cipher are' : 'passphrase is') . ' right)';
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //a private temporary folder
    public static function workDir() {
	$dir = rtrim(sys_get_temp_dir(), '/') . '/netflow9-' . bin2hex(random_bytes(8));
	if (!@mkdir($dir, 0700))
	    throw new \RuntimeException('no temporary folder could be made');
	return $dir;
    }

    public static function removeDir($dir) {
	if (!is_dir($dir) || is_link($dir))
	    return;
	foreach(scandir($dir) as $f) {
	    if ($f === '.' || $f === '..')
		continue;
	    $p = $dir . '/' . $f;
	    if (is_dir($p) && !is_link($p))
		self::removeDir($p);
	    else
		@unlink($p);
	}
	@rmdir($dir);
    }

    //a file only the app can read
    public static function secretFile($dir, $name, $content) {
	$path = $dir . '/' . $name;
	$old = umask(0077);
	file_put_contents($path, $content);
	umask($old);
	chmod($path, 0600);
	return $path;
    }

    //the path of a tool; required: an error when it is not installed
    //CLD: 2026-10-20T17:10:00+03:00: public (was protected): the initial setup (util\Setup::checks) looks the tools up the same way
    public static function tool($name, $required = true) {
	foreach([ '/usr/bin/', '/usr/local/bin/', '/bin/' ] as $dir) {
	    if (is_executable($dir . $name))
		return $dir . $name;
	}
	if ($required)
	    throw new \RuntimeException("`{$name}` is not installed on the server");
	return null;
    }

    //runs $args (no shell); $secret (when not null) written on fd 3: [ exit code, stderr, stdout ]
    protected static function run(array $args, $secret = null) {
	$spec = [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
	if ($secret !== null)
	    $spec[3] = [ 'pipe', 'r' ];
	$proc = proc_open($args, $spec, $pipes, null, [ 'PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'HOME' => sys_get_temp_dir() ]);
	if (!is_resource($proc))
	    throw new \RuntimeException('could not run ' . basename($args[0]));
	if ($secret !== null) {
	    fwrite($pipes[3], $secret . "\n");
	    fclose($pipes[3]);
	}
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$stdout = $stderr = '';
	$deadline = time() + self::TIMEOUT;
	while(true) {
	    $read = [ $pipes[1], $pipes[2] ];
	    $write = $except = null;
	    if (@stream_select($read, $write, $except, 1) === false)
		break;
	    foreach($read as $r) {
		$chunk = (string)fread($r, 65536);
		if ($r === $pipes[1])
		    $stdout .= strlen($stdout) < 1048576 ? $chunk : '';
		else
		    $stderr .= strlen($stderr) < 65536 ? $chunk : '';
	    }
	    if (feof($pipes[1]) && feof($pipes[2]))
		break;
	    if (time() > $deadline) {
		proc_terminate($proc, 9);
		$stderr .= "\nstopped after " . self::TIMEOUT . ' s';
		break;
	    }
	}
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	return [ $code, $stderr, $stdout ];
    }

    //the useful part of a tool's error output
    protected static function toolMessage($stderr) {
	$lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$stderr)), fn($l) => $l !== '' && !preg_match('/^gpg: (WARNING|keybox|.*created)|^\d+:error:.*:(DECODER|OSSL_STORE)/', $l)));
	$text = implode('; ', array_slice($lines, 0, 3));
	return $text !== '' ? mb_substr($text, 0, 300) : 'failed';
    }
}
