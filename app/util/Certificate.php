<?php

/*
    X.509 certificates and their keys (openssl), one place for what the Certificate step, SAML, the file signing / encryption steps,
    the validation and the TLS clients each did their own way:
	pemBlocks($text, $label)            the PEM / armored blocks of a text (any label: certificates, keys, PGP)
	certificates($text, $loose)         the certificates of a text, as PEM (CERTIFICATE, X509 CERTIFICATE, TRUSTED CERTIFICATE); $loose: else a bare base64 body or DER
	pem($body) / body($pem)             a PEM certificate of a base64 body / the base64 body of a PEM certificate (SAML metadata and messages)
	decode($pem, $keepPem, $full)       one certificate decoded: subject, issuer, names, validity, key, fingerprints (the Certificate step's variable)
	keyInfo($key)                       a key's type (RSA, EC, Ed25519, ...), bits, whether it is EdDSA
	privateKey($pem, $pass, $certPem)   a private key opened (and checked against its certificate), else a RuntimeException
	selfSigned($cn, $org, $days)        a new RSA key pair with a self-signed certificate: [ certificate PEM, key PEM ]
	trusted($certs, $purpose)           whether a chain leads to a CA of the system's trust store
	matchesHost($names, $cn, $host)     whether a certificate's names are for a host
	tempFile($pems)                     the certificates in a private temporary file (removed by the caller)
	systemCaFile()                      the system's CA bundle
	opensslError()                      the last message of openssl's error queue (the queue emptied)
*/

namespace util;

class Certificate {

    //the armored / PEM blocks of $text labelled $label (e.g. 'CERTIFICATE'), in order: [ 'label', 'pem' (the whole block),
    //'data' (the decoded body; null when it is not base64) ]; headers (Version:, Proc-Type:, DEK-Info:, ...) and the PGP checksum line are not part of the body
    public static function pemBlocks($text, $label) {
	$q = preg_quote($label, '/');
	preg_match_all('/-----BEGIN (' . $q . ')-----(.*?)-----END ' . $q . '-----/s', (string)$text, $m, PREG_SET_ORDER);
	$blocks = [];
	foreach($m as $match) {
	    $lines = [];
	    foreach(preg_split('/\R/', trim($match[2])) as $line) {
		$line = trim($line);
		if ($line === '' || str_contains($line, ':') || preg_match('/^=[A-Za-z0-9+\/]{4}$/', $line))
		    continue;
		$lines[] = $line;
	    }
	    $data = base64_decode(implode('', $lines), true);
	    $blocks[] = [ 'label' => $match[1], 'pem' => $match[0], 'data' => ($data === false || $data === '') ? null : $data ];
	}
	return $blocks;
    }

    //the certificates of $text, in order, as PEM (-----BEGIN CERTIFICATE-----, 64 characters a line; X509 / TRUSTED labels too); $loose: a text without one is a bare base64
    //body (copied from an XML X509Certificate) or a DER certificate. Not checked: openssl_x509_read tells whether one is readable
    public static function certificates($text, $loose = false) {
	$text = (string)$text;
	if (preg_match_all('~-----BEGIN (?:X509 |TRUSTED )?CERTIFICATE-----(.+?)-----END (?:X509 |TRUSTED )?CERTIFICATE-----~s', $text, $m))
	    return array_map(fn($body) => self::pem($body), $m[1]);
	if (!$loose || trim($text) === '')
	    return [];
	if (preg_match('/^[A-Za-z0-9+\/=\s]{100,}$/', trim($text)))
	    return [ self::pem($text) ];
	return [ self::pem(base64_encode($text)) ];
    }

    //a PEM certificate of a base64 body
    public static function pem($body) {
	return "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', (string)$body), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    //the base64 body of a PEM certificate
    public static function body($pem) {
	return preg_replace('/-----[^-]+-----|\s+/', '', (string)$pem);
    }

    //one certificate (PEM) decoded; $full: + the extensions the variable shows for the first one
    public static function decode($pem, $keepPem = false, $full = true) {
	$x = openssl_x509_parse($pem, false);
	if (!is_array($x))
	    throw new \RuntimeException('the certificate does not decode');
	$ext = (array)($x['extensions'] ?? []);
	$names = [];
	foreach(preg_split('/,\s*/', (string)($ext['subjectAltName'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $san) {
	    if (preg_match('/^(DNS|IP Address):(.+)$/', trim($san), $m))
		$names[] = trim($m[2]);
	}
	$from = (int)($x['validFrom_time_t'] ?? 0);
	$to = (int)($x['validTo_time_t'] ?? 0);
	$key = self::keyInfo($pem);
	$subject = self::dn($x['subject'] ?? []);
	$issuer = self::dn($x['issuer'] ?? []);
	$cert = [
	    'subject' => $subject,
	    'issuer' => $issuer,
	    'commonName' => is_array($subject['CN'] ?? null) ? reset($subject['CN']) : ($subject['CN'] ?? null),
	    'names' => $names,
	    'serialNumber' => strtoupper((string)($x['serialNumberHex'] ?? '')),
	    'validFrom' => gmdate('Y-m-d\TH:i:s\Z', $from),
	    'validTo' => gmdate('Y-m-d\TH:i:s\Z', $to),
	    'daysLeft' => (int)floor(($to - time()) / 86400),
	    'expired' => $to < time(),
	    'notYetValid' => $from > time(),
	    'selfSigned' => $subject === $issuer && openssl_x509_verify($pem, openssl_pkey_get_public($pem)) === 1,
	    'isCA' => (bool)preg_match('/CA:TRUE/i', (string)($ext['basicConstraints'] ?? '')),
	    'signatureAlgorithm' => $x['signatureTypeLN'] ?? $x['signatureTypeSN'] ?? null,
	    'keyType' => $key ? ($key['type'] ?? 'other') : null,
	    'keyBits' => $key ? $key['bits'] : null,
	    'sha256' => strtoupper((string)openssl_x509_fingerprint($pem, 'sha256')),
	    'sha1' => strtoupper((string)openssl_x509_fingerprint($pem, 'sha1')),
	];
	if ($full) {
	    $cert['keyUsage'] = $ext['keyUsage'] ?? null;
	    $cert['extendedKeyUsage'] = $ext['extendedKeyUsage'] ?? null;
	    $cert['ocsp'] = preg_match('/OCSP - URI:(\S+)/', (string)($ext['authorityInfoAccess'] ?? ''), $m) ? $m[1] : null;
	    $cert['crl'] = preg_match_all('/URI:(\S+)/', (string)($ext['crlDistributionPoints'] ?? ''), $m) ? $m[1] : [];
	}
	if ($keepPem)
	    $cert['pem'] = $pem;
	self::opensslError();
	return $cert;
    }

    //a subject / issuer by short name (CN, O, OU, C, ...: openssl gives long names with $shortnames false)
    protected static function dn(array $dn) {
	$short = [ 'commonName' => 'CN', 'organizationName' => 'O', 'organizationalUnitName' => 'OU', 'countryName' => 'C', 'stateOrProvinceName' => 'ST',
	    'localityName' => 'L', 'emailAddress' => 'emailAddress', 'serialNumber' => 'serialNumber', 'streetAddress' => 'street', 'postalCode' => 'postalCode' ];
	$out = [];
	foreach($dn as $k => $v)
	    $out[$short[$k] ?? str_replace([ '.', '$' ], '_', (string)$k)] = $v;
	return $out;
    }

    //a key (an openssl key, or a PEM public key / certificate): [ 'type' => RSA, DSA, DH, EC, Ed25519, Ed448 (null: another), 'bits', 'eddsa' (signs the whole message) ];
    //null: not readable
    public static function keyInfo($key) {
	if (is_string($key))
	    $key = @openssl_pkey_get_public($key);
	$d = $key ? @openssl_pkey_get_details($key) : false;
	if (!is_array($d))
	    return null;
	$types = [ OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_DSA => 'DSA', OPENSSL_KEYTYPE_DH => 'DH', OPENSSL_KEYTYPE_EC => 'EC' ];
	$eddsa = [];
	foreach([ 'OPENSSL_KEYTYPE_ED25519' => 'Ed25519', 'OPENSSL_KEYTYPE_ED448' => 'Ed448' ] as $c => $name) {
	    if (defined($c)) {
		$types[constant($c)] = $name;
		$eddsa[] = constant($c);
	    }
	}
	return [ 'type' => $types[$d['type'] ?? -1] ?? null, 'bits' => (int)($d['bits'] ?? 0), 'eddsa' => in_array($d['type'] ?? null, $eddsa, true) ];
    }

    //a PEM private key opened with $passphrase; $certPem: the key must be the certificate's
    public static function privateKey($pem, $passphrase = '', $certPem = null) {
	$key = @openssl_pkey_get_private(trim((string)$pem), (string)$passphrase);
	if ($key === false) {
	    self::opensslError();
	    throw new \RuntimeException('the private key could not be opened (is the passphrase right?)');
	}
	if ($certPem !== null && !openssl_x509_check_private_key($certPem, $key)) {
	    self::opensslError();
	    throw new \RuntimeException('the certificate does not belong to the private key');
	}
	return $key;
    }

    //a new RSA key pair (2048 bits) and its self-signed certificate (SHA-256, a random serial number) for $days: [ certificate PEM, private key PEM ]
    public static function selfSigned($commonName, $organization = null, $days = 3650) {
	$key = openssl_pkey_new([ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ]);
	$dn = [ 'commonName' => mb_substr((string)$commonName, 0, 64) ] + ((string)$organization !== '' ? [ 'organizationName' => mb_substr((string)$organization, 0, 64) ] : []);
	$csr = $key ? openssl_csr_new($dn, $key, [ 'digest_alg' => 'sha256' ]) : false;
	$cert = $csr ? openssl_csr_sign($csr, null, $key, $days, [ 'digest_alg' => 'sha256' ], random_int(1, PHP_INT_MAX)) : false;
	if (!$cert || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem))
	    throw new \RuntimeException('the key pair was not made: ' . self::opensslError());
	return [ $certPem, $keyPem ];
    }

    //whether the chain (the server's first, then what it sent) leads to a CA of this server's trust store (the system's); $purpose: a TLS server's (X509_PURPOSE_SSL_SERVER),
    //any (X509_PURPOSE_ANY: a file, a CA too)
    public static function trusted(array $certs, $purpose) {
	$file = count($certs) > 1 ? self::tempFile(array_slice($certs, 1)) : null;
	$ok = openssl_x509_checkpurpose($certs[0], $purpose, [], $file);
	if ($file !== null)
	    @unlink($file);
	self::opensslError();
	return $ok === true;
    }

    //whether a certificate of $names (SAN: DNS names, IP addresses; none: its CN) is for $host (a wildcard: one label, the left-most)
    public static function matchesHost(array $names, $commonName, $host) {
	$host = strtolower(rtrim(trim((string)$host, '[]'), '.'));
	if ($host === '')
	    return false;
	if (!$names && $commonName !== null)
	    $names = [ (string)$commonName ];
	foreach($names as $n) {
	    $n = strtolower(rtrim(trim($n), '.'));
	    if (filter_var($host, FILTER_VALIDATE_IP)) {
		if (filter_var($n, FILTER_VALIDATE_IP) && inet_pton($n) === inet_pton($host))
		    return true;
		continue;
	    }
	    if ($n === $host)
		return true;
	    if (str_starts_with($n, '*.') && substr_count($n, '.') >= 2) {
		$dot = strpos($host, '.');
		if ($dot !== false && $dot > 0 && substr($host, $dot) === substr($n, 1))
		    return true;
	    }
	}
	return false;
    }

    //the PEM certificates $pems in a temporary file only the app can read (removed by the caller)
    public static function tempFile(array $pems) {
	$file = tempnam(sys_get_temp_dir(), 'nfcert');
	chmod($file, 0600);
	file_put_contents($file, implode("\n", array_map('trim', $pems)) . "\n");
	return $file;
    }

    //the system's CA bundle (null: none found)
    public static function systemCaFile() {
	$default = function_exists('openssl_get_cert_locations') ? (string)(openssl_get_cert_locations()['default_cert_file'] ?? '') : '';
	foreach([ $default, '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem' ] as $f) {
	    if ($f !== '' && is_file($f))
		return $f;
	}
	return null;
    }

    //the last message of openssl's error queue ('' none); the queue is emptied, so the next call's messages are its own
    public static function opensslError() {
	$last = '';
	while (($m = openssl_error_string()) !== false)
	    $last = $m;
	return $last;
    }
}
