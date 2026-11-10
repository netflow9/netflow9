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
	    $leaf = \util\Certificate::decode($certs[0], !empty($this->cfg('keepPem', false)));
	    $result = array_merge($result, [ 'ok' => true ], $leaf, [
		'trusted' => \util\Certificate::trusted($certs, $source === 'server' ? X509_PURPOSE_SSL_SERVER : X509_PURPOSE_ANY),
		'hostnameMatches' => $source === 'server' ? \util\Certificate::matchesHost($leaf['names'], $leaf['commonName'], $read['name']) : null,
	    ], $read['tls'], [
		'chain' => array_map(fn($c) => \util\Certificate::decode($c, !empty($this->cfg('keepPem', false)), false), array_slice($certs, 1)),
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
	    $ssl = \util\Certificate::opensslError();
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

    //the certificates of a file at a URL (PEM: each one in it, in order; else one certificate: a bare base64 body or DER)
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
	$certs = array_values(array_filter(array_slice(\util\Certificate::certificates($r['body'], true), 0, self::MAX_CHAIN), fn($pem) => @openssl_x509_read($pem) !== false));
	\util\Certificate::opensslError();
	if (!$certs)
	    throw new \RuntimeException('the file holds no certificate (PEM or DER)');
	$parts = parse_url((string)($r['url'] ?: $url));
	return [ 'certs' => $certs, 'where' => [ 'host' => $parts['host'] ?? null, 'port' => null ], 'name' => null, 'tls' => [ 'protocol' => null, 'cipher' => null ] ];
    }
}
