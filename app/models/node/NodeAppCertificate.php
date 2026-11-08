<?php

/*
    CLD: 2026-11-08T12:00:00+02:00: new step: Read a certificate - decodes an SSL / TLS certificate from an address:
	- the server's certificate: a TLS connection to https://host[:port], host:port or another TLS address (ldaps, imaps, smtps, ...); the chain the server sends
	- a certificate file: a GET of a URL answering a PEM (one or more certificates) or a DER certificate (.crt, .cer, .pem)
    Public addresses only unless app.env HTTP_STEPS_ALLOW_PRIVATE (as the HTTP steps; the server's connection goes to the address checked).
    The certificate is read even when it is not valid (expired, self-signed, another name): the variable says so (trusted, hostnameMatches, expired, daysLeft); errors are
    no connection / no answer / nothing that decodes as a certificate (On error: fail the step, or continue on a second output `error`).
    The variable (default `cert`) gets { ok, source, url, host, port, subject, issuer, commonName, names, serialNumber, validFrom, validTo, daysLeft, expired, notYetValid, selfSigned,
	isCA, trusted, hostnameMatches, signatureAlgorithm, keyType, keyBits, sha256, sha1, keyUsage, extendedKeyUsage, ocsp, crl, protocol, cipher, pem, chain, error }.
*/

namespace models\node;

class NodeAppCertificate extends NodeAppHttp {
    public static $audience = 'basic';
    public static $category = 'web';
    public static $name = 'certificate';
    public static $label = 'Read a certificate';
    public static $icon = 'bi-shield-lock';

    const SOURCES = [ 'server' => 'The server\'s certificate (TLS)', 'file' => 'A certificate file (PEM or DER)' ];
    //the port of a TLS address without one, by its scheme
    const PORTS = [ 'https' => 443, 'ldaps' => 636, 'imaps' => 993, 'pop3s' => 995, 'smtps' => 465, 'ftps' => 990, 'tls' => 443, 'ssl' => 443 ];
    const MAX_FILE = 1024 * 1024;
    const MAX_CHAIN = 10;
    const KEY_TYPES = [ OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_DSA => 'DSA', OPENSSL_KEYTYPE_DH => 'DH', OPENSSL_KEYTYPE_EC => 'EC' ];

    public static function configSchema() {
	$http = [];
	foreach(parent::configSchema() as $f)
	    $http[$f['key']] = $f;

	$http['variable']['label'] = 'Certificate variable';
	$http['variable']['default'] = 'cert';
	$http['onError']['help'] = 'No connection or answer, no certificate';
	$http['url']['label'] = 'Address';
	$http['url']['help'] = 'https://host[:port], host:port, or the file\'s URL';
	$http['url']['ui']['placeholder'] = 'https://www.example.com';
	$http['url']['ui']['width'] = 8;
	$http['timeout']['default'] = 15;

	return [
	    $http['variable'],
	    $http['onError'],
	    [
		'key' => 'source',
		'type' => 'select',
		'label' => 'Read',
		'required' => true,
		'default' => 'server',
		'choices' => self::SOURCES,
		'ui' => [ 'width' => 4 ],
	    ],
	    $http['url'],
	    [
		'key' => 'serverName',
		'type' => 'text',
		'label' => 'Server name (SNI)',
		'help' => 'Empty: the address\'s host',
		'default' => '',
		'ui' => [ 'placeholder' => 'www.example.com', 'expression' => true, 'advanced' => true, 'width' => 6,
		    'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'server' ] ],
	    ],
	    [
		'key' => 'keepPem',
		'type' => 'switch',
		'label' => 'Keep the PEM text',
		'default' => false,
		'help' => 'Adds pem to the variable and its chain',
		'ui' => [ 'advanced' => true, 'width' => 6 ],
	    ],
	    $http['timeout'],
	];
    }

    //the fields of one decoded certificate (the variable's, and each chain item's)
    protected static function certFields() {
	return [
	    'subject' => [ 'type' => 'object', 'info' => 'CN, O, OU, C, ... (a list when repeated)' ], 'issuer' => [ 'type' => 'object', 'info' => 'CN, O, OU, C, ...' ],
	    'commonName' => [ 'type' => 'string', 'info' => 'the subject\'s CN' ], 'names' => [ 'type' => 'array', 'info' => 'the DNS names and IP addresses (Subject Alternative Name)' ],
	    'serialNumber' => [ 'type' => 'string', 'info' => 'hexadecimal' ], 'validFrom' => [ 'type' => 'string', 'info' => 'ISO 8601, UTC' ], 'validTo' => [ 'type' => 'string', 'info' => 'ISO 8601, UTC' ],
	    'daysLeft' => [ 'type' => 'number', 'info' => 'whole days until validTo (negative: expired)' ], 'expired' => [ 'type' => 'boolean' ], 'notYetValid' => [ 'type' => 'boolean' ],
	    'selfSigned' => [ 'type' => 'boolean' ], 'isCA' => [ 'type' => 'boolean' ], 'signatureAlgorithm' => [ 'type' => 'string' ], 'keyType' => [ 'type' => 'string', 'info' => 'RSA, EC, ...' ],
	    'keyBits' => [ 'type' => 'number' ], 'sha256' => [ 'type' => 'string', 'info' => 'fingerprint, hexadecimal' ], 'sha1' => [ 'type' => 'string', 'info' => 'fingerprint, hexadecimal' ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'cert') : 'cert';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [ $name => [ 'type' => 'object', 'info' => 'the certificate: { ok, host, commonName, names, validTo, daysLeft, expired, trusted, hostnameMatches, issuer, chain, ... }', 'fields' => [
	    'ok' => [ 'type' => 'boolean', 'info' => 'a certificate was read (not: it is valid)' ], 'source' => [ 'type' => 'string', 'info' => 'server or file' ], 'url' => [ 'type' => 'string' ],
	    'host' => [ 'type' => 'string' ], 'port' => [ 'type' => 'number' ] ]
	    + self::certFields() + [
	    'trusted' => [ 'type' => 'boolean', 'info' => 'the chain leads to a CA this server trusts' ],
	    'hostnameMatches' => [ 'type' => 'boolean', 'info' => 'the certificate is for the host (null for a file)' ],
	    'keyUsage' => [ 'type' => 'string' ], 'extendedKeyUsage' => [ 'type' => 'string' ], 'ocsp' => [ 'type' => 'string', 'info' => 'the OCSP responder\'s URL' ],
	    'crl' => [ 'type' => 'array', 'info' => 'the CRL URLs' ], 'protocol' => [ 'type' => 'string', 'info' => 'TLS version (server)' ], 'cipher' => [ 'type' => 'string', 'info' => 'server' ],
	    'pem' => [ 'type' => 'string', 'info' => 'when Keep the PEM text is on' ],
	    'chain' => [ 'type' => 'array', 'info' => 'the certificates after the first (intermediates, root): subject, issuer, validTo, daysLeft, sha256, ...' ],
	    'error' => [ 'type' => 'string' ],
	] ] ];
    }

    public function execute($edgeId = null) {
	$this->textVars = $this->exec->expressionVariables();
	$address = trim($this->textOf($this->cfg('url', '')));
	$source = $this->cfg('source', 'server') === 'file' ? 'file' : 'server';
	$timeout = max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 15)));
	$shown = preg_replace('/[?#].*$/', '', $address);

	$result = [ 'ok' => false, 'source' => $source, 'url' => $shown, 'host' => null, 'port' => null ];
	try {
	    $read = $source === 'file' ? $this->readFile($address, $timeout) : $this->readServer($address, $timeout);
	    $result = array_merge($result, $read['where']);
	    $certs = $read['certs'];
	    $leaf = self::decode($certs[0], !empty($this->cfg('keepPem', false)));
	    $result = array_merge($result, [ 'ok' => true ], $leaf, [
		'trusted' => self::trusted($certs, $source === 'server' ? X509_PURPOSE_SSL_SERVER : X509_PURPOSE_ANY),
		'hostnameMatches' => $source === 'server' ? self::matchesHost($leaf['names'], $leaf['commonName'], $read['name']) : null,
	    ], $read['tls'], [
		'chain' => array_map(fn($c) => self::decode($c, !empty($this->cfg('keepPem', false)), false), array_slice($certs, 1)),
		'error' => null,
	    ]);
	    $this->exec->log($this->wfid, "certificate {$shown}: {$leaf['commonName']}, valid to {$leaf['validTo']} ({$leaf['daysLeft']} days)" . ($result['trusted'] ? '' : ', not trusted')
		. ($result['hostnameMatches'] === false ? ', another name' : '') . (count($certs) > 1 ? ', chain of ' . count($certs) : ''));
	} catch (\RuntimeException $e) {
	    $result['error'] = $e->getMessage();
	}

	$name = (string)$this->cfg('variable', 'cert');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	if ($result['ok'])
	    return [ 0 ];
	$this->exec->log($this->wfid, "certificate {$shown}: {$result['error']}");
	if ($this->cfg('onError', 'fail') === 'output')
	    return [ 1 ];
	throw new \ErrorException("Read a certificate: {$shown}: {$result['error']}");
    }

    //the chain a TLS server sends: [ certs (PEM, the server's first), where { host, port }, the name checked, tls { protocol, cipher } ]
    protected function readServer($address, $timeout) {
	$parts = preg_match('~^[a-z][a-z0-9+.-]*://~i', $address) ? parse_url($address) : parse_url('tls://' . $address);
	$host = trim((string)($parts['host'] ?? ''), '[]');
	if ($parts === false || $host === '')
	    throw new \RuntimeException('give an address as https://host[:port] or host:port');
	$scheme = strtolower((string)($parts['scheme'] ?? 'tls'));
	$port = (int)($parts['port'] ?? self::PORTS[$scheme] ?? 0);
	if ($port < 1 || $port > 65535)
	    throw new \RuntimeException("no port for {$scheme}:// (write host:port)");

	$target = $host;
	if (!empty(self::guard()['public'])) {
	    [ $ips, $why ] = \util\Http::publicAddresses($host);
	    if ($why !== null)
		throw new \RuntimeException($why);
	    $target = $ips[0];
	}
	$sni = trim($this->textOf($this->cfg('serverName', ''))) ?: $host;
	$ctx = stream_context_create([ 'ssl' => [
	    'capture_peer_cert_chain' => true,
	    'verify_peer' => false,
	    'verify_peer_name' => false,
	    'allow_self_signed' => true,
	    'SNI_enabled' => !filter_var($sni, FILTER_VALIDATE_IP),
	    'peer_name' => $sni,
	] ]);
	$remote = 'ssl://' . (str_contains($target, ':') ? "[{$target}]" : $target) . ":{$port}";
	$errno = 0;
	$errstr = '';
	$s = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) {
	    $ssl = '';
	    while (($m = openssl_error_string()) !== false)
		$ssl = $m;
	    throw new \RuntimeException("no TLS connection to {$host}:{$port}" . ($errstr !== '' ? ": {$errstr}" : ($ssl !== '' ? ": {$ssl}" : ' (not a TLS port, or no answer)')));
	}
	$crypto = stream_get_meta_data($s)['crypto'] ?? [];
	$chain = stream_context_get_params($s)['options']['ssl']['peer_certificate_chain'] ?? [];
	fclose($s);
	$certs = [];
	foreach(array_slice($chain, 0, self::MAX_CHAIN) as $c) {
	    if (openssl_x509_export($c, $pem))
		$certs[] = $pem;
	}
	if (!$certs)
	    throw new \RuntimeException("{$host}:{$port} sent no certificate");
	return [ 'certs' => $certs, 'where' => [ 'host' => $host, 'port' => $port ], 'name' => $sni,
	    'tls' => [ 'protocol' => $crypto['protocol'] ?? null, 'cipher' => $crypto['cipher_name'] ?? null ] ];
    }

    //the certificates of a file at a URL (PEM: each one in it, in order; else one DER certificate)
    protected function readFile($url, $timeout) {
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \RuntimeException('the file\'s URL must start with http:// or https://');
	$r = \util\Http::request('GET', $url, self::guard() + [ 'timeout' => $timeout, 'maxBytes' => self::MAX_FILE, 'redirects' => 5 ]);
	if ($r['tooLarge'])
	    throw new \RuntimeException('the file is larger than ' . (self::MAX_FILE >> 10) . ' KB');
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['error']);
	if ($r['status'] >= 400)
	    throw new \RuntimeException("HTTP status {$r['status']}");
	$body = (string)$r['body'];
	$certs = [];
	if (preg_match_all('~-----BEGIN (?:X509 |TRUSTED )?CERTIFICATE-----.+?-----END (?:X509 |TRUSTED )?CERTIFICATE-----~s', $body, $m)) {
	    foreach(array_slice($m[0], 0, self::MAX_CHAIN) as $pem) {
		$pem = preg_replace('~-----(BEGIN|END) (?:X509 |TRUSTED )?CERTIFICATE-----~', '-----$1 CERTIFICATE-----', $pem);
		if (@openssl_x509_read($pem) !== false)
		    $certs[] = $pem;
	    }
	} elseif ($body !== '') {
	    $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($body), 64, "\n") . "-----END CERTIFICATE-----\n";
	    if (@openssl_x509_read($pem) !== false)
		$certs[] = $pem;
	}
	while (openssl_error_string() !== false);
	if (!$certs)
	    throw new \RuntimeException('the file holds no certificate (PEM or DER)');
	$parts = parse_url((string)($r['url'] ?: $url));
	return [ 'certs' => $certs, 'where' => [ 'host' => $parts['host'] ?? null, 'port' => null ], 'name' => null, 'tls' => [ 'protocol' => null, 'cipher' => null ] ];
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
	$key = @openssl_pkey_get_details(openssl_pkey_get_public($pem));
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
	    'keyType' => is_array($key) ? (self::KEY_TYPES[$key['type']] ?? 'other') : null,
	    'keyBits' => is_array($key) ? (int)$key['bits'] : null,
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
	while (openssl_error_string() !== false);
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

    //whether the chain (the server's first, then what it sent) leads to a CA of this server's trust store (the system's); $purpose: a TLS server's (server), any (a file: a CA too)
    protected static function trusted(array $certs, $purpose) {
	$file = null;
	if (count($certs) > 1) {
	    $file = tempnam(sys_get_temp_dir(), 'nfcert');
	    file_put_contents($file, implode("\n", array_slice($certs, 1)));
	}
	$ok = openssl_x509_checkpurpose($certs[0], $purpose, [], $file);
	if ($file !== null)
	    @unlink($file);
	while (openssl_error_string() !== false);
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
}
