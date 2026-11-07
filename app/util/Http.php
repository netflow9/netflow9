<?php

/*
    the HTTP requests of the app (curl), one place for what each caller repeated: the protocols, timeouts, the User-Agent,
    the largest answer kept, the answer streamed to a file, an upload from a stream, redirects, the response headers, a public-address guard (no request to this machine
    or its network: each address the host resolves to is checked, the one checked is the one connected to, for each redirect too).

    $r = \util\Http::request('POST', 'https://host/api', [ 'headers' => [ 'Authorization' => 'Bearer ...' ], 'json' => [ ... ], 'timeout' => 30 ]);
    if ($r['error'] !== null) ...          //no answer: a curl error, too large, refused by the guard
    elseif ($r['status'] >= 400) ...       //an answer with an error status
    $data = json_decode($r['body'], true);

    Options:
	headers         [ name => value ] or [ "Name: value" ] (CR / LF in values replaced by spaces); a User-Agent is added unless one is given
	body            the request body (text), or
	json            a value sent as JSON (util\JSON::encode, slashes and Unicode unescaped; Content-Type: application/json unless one is given), or
	form            [ name => value ] sent urlencoded (Content-Type unless one is given), or
	upload          [ stream, size ]: the body read from a stream (not kept in memory)
	timeout         seconds for the whole request, redirects included (default 30); connectTimeout (default the smaller of 10 and timeout)
	maxBytes        the largest answer kept (default MAX_BYTES); larger: error, tooLarge
	sink            a stream the answer is written to (body then ''; size counts it)
	redirects       how many redirects are followed (default 0); 303 (and 301 / 302 of a POST) continue as GET without a body; Authorization / Cookie dropped for another host
	https           https:// only (redirects too)
	public          the public-address guard
	private         with `public`: addresses of a private network (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) are accepted too; never this machine (its own
	                addresses, loopback), link-local or reserved ones (CLD: 2026-11-01T00:30:00+02:00: on-premises servers, e.g. a GravityZone console: the account's allow_private_addresses)
	insecure        the server's TLS certificate is not checked (a self-signed one) (CLD: 2026-11-01T00:30:00+02:00)
	auth            [ 'ntlm' | 'basic', user, password ]: curl signs in (NTLM: its challenge / response, e.g. Windows Remote Management); not sent to another host after a redirect
	                (CLD: 2026-11-01T16:00:00+02:00)
	compressed      accept gzip / deflate / br (decoded)

    The answer: ok (no error, status 1..399), status, reason (the status line's text after the code; CLD: 2026-11-01T14:00:00+02:00), error (null when there is an answer), tooLarge, headers [ lower-case name => value ] (the last of a repeated one),
    headerList [ [ name, value ] ] (all, in order), body, size, contentType (as sent), mime (lower case, no parameters), url (the last address, after redirects), time_ms
*/

namespace util;

class Http {
    const USER_AGENT = 'Rail22-Workflow';
    const MAX_BYTES = 5 * 1024 * 1024;
    const REDIRECT_STATUSES = [ 301, 302, 303, 307, 308 ];

    public static function request($method, $url, array $o = []) {
	$method = strtoupper((string)$method);
	$url = (string)$url;
	$timeout = max(1, (int)($o['timeout'] ?? 30));
	$connectTimeout = max(1, min($timeout, (int)($o['connectTimeout'] ?? 10)));
	$maxBytes = (int)($o['maxBytes'] ?? self::MAX_BYTES);
	$https = !empty($o['https']);
	$redirects = max(0, (int)($o['redirects'] ?? 0));
	$sink = $o['sink'] ?? null;
	$upload = $o['upload'] ?? null;

	//headers and body
	$headers = self::headerLines((array)($o['headers'] ?? []));
	$body = isset($o['body']) ? (string)$o['body'] : null;
	if (array_key_exists('json', $o)) {
	    $body = JSON::encode($o['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	    $headers = self::withDefault($headers, 'Content-Type', 'application/json');
	} elseif (isset($o['form'])) {
	    $body = http_build_query((array)$o['form'], '', '&');
	    $headers = self::withDefault($headers, 'Content-Type', 'application/x-www-form-urlencoded');
	}
	$headers = self::withDefault($headers, 'User-Agent', self::USER_AGENT);
	//no "Expect: 100-continue" wait before a body
	$headers = self::withDefault($headers, 'Expect', '');

	$r = [ 'ok' => false, 'status' => 0, 'reason' => '', 'error' => null, 'tooLarge' => false, 'headers' => [], 'headerList' => [], 'body' => '', 'size' => 0, 'contentType' => '', 'mime' => '', 'url' => $url, 'time_ms' => 0 ];
	$start = microtime(true);
	for ($hop = 0; ; $hop++) {
	    $left = $timeout * 1000 - (int)((microtime(true) - $start) * 1000);
	    if ($left <= 0) {
		$r['error'] = "the request took more than {$timeout} s";
		break;
	    }
	    $follow = $hop < $redirects && $upload === null;
	    //CLD: 2026-11-01T00:30:00+02:00: + private, insecure
	    $answer = self::once($method, $r['url'], $headers, $body, $upload, $sink, $maxBytes, $https, !empty($o['public']) ? (!empty($o['private']) ? 'private' : 'public') : false,
		!empty($o['compressed']), $left, $connectTimeout, $follow, !empty($o['insecure']),
		//CLD: 2026-11-01T16:00:00+02:00: + auth, for the first host only
		isset($o['auth']) && $hop > 0 && parse_url($r['url'], PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST) ? null : ($o['auth'] ?? null));
	    $location = $answer['headers']['location'] ?? null;
	    $r = array_merge($r, $answer);
	    if ($answer['error'] !== null || $location === null || !in_array($answer['status'], self::REDIRECT_STATUSES, true) || !$follow)
		break;

	    //the next address; the method (and body) as browsers and curl change them
	    $next = self::resolveUrl($r['url'], $location);
	    if ($next === null) {
		$r['error'] = 'a redirect to an address that is not http:// or https://';
		break;
	    }
	    if ($answer['status'] === 303 || (in_array($answer['status'], [ 301, 302 ], true) && $method === 'POST')) {
		if ($method !== 'HEAD')
		    $method = 'GET';
		$body = null;
		$headers = array_values(array_filter($headers, fn($l) => !preg_match('/^content-(type|length):/i', $l)));
	    }
	    if (strtolower((string)parse_url($next, PHP_URL_HOST)) !== strtolower((string)parse_url($r['url'], PHP_URL_HOST)))
		$headers = array_values(array_filter($headers, fn($l) => !preg_match('/^(authorization|cookie):/i', $l)));
	    $r['url'] = $next;
	}
	if ($redirects > 0 && $r['error'] === null && isset($r['headers']['location']) && in_array($r['status'], self::REDIRECT_STATUSES, true) && $upload === null)
	    $r['error'] = "more than {$redirects} redirects";
	$r['time_ms'] = (int)round((microtime(true) - $start) * 1000);
	$r['ok'] = $r['error'] === null && $r['status'] > 0 && $r['status'] < 400;
	return $r;
    }

    //GET (or POST) with a JSON answer: request()'s answer + data (the decoded JSON; null when it is not JSON)
    public static function json($method, $url, array $o = []) {
	$o['headers'] = self::withDefault(self::headerLines((array)($o['headers'] ?? [])), 'Accept', 'application/json');
	$r = self::request($method, $url, $o);
	$r['data'] = $r['body'] !== '' ? json_decode($r['body'], true) : null;
	return $r;
    }

    //"Name: value" lines of [ name => value ] or of lines as they are
    public static function headerLines(array $headers) {
	$lines = [];
	foreach($headers as $name => $value)
	    $lines[] = is_int($name) ? (string)$value : $name . ': ' . str_replace([ "\r", "\n" ], ' ', (string)$value);
	return $lines;
    }

    //the address without its query and fragment (for messages: a key in the query is not shown)
    public static function shown($url) {
	return preg_replace('/[?#].*$/', '', (string)$url);
    }

    //the addresses $host resolves to, when all are public: [ ips, null ]; else [ [], the reason ]
    //CLD: 2026-11-01T00:30:00+02:00: + $private: addresses of a private network accepted too (isPrivateNetworkIp)
    public static function publicAddresses($host, $private = false) {
	$host = trim((string)$host, '[]');
	if ($host === '')
	    return [ [], 'no host' ];
	if (filter_var($host, FILTER_VALIDATE_IP))
	    $ips = [ $host ];
	else {
	    //IPv4 through the system's resolver (as curl: /etc/hosts too), IPv6 from DNS
	    $ips = array_merge(@gethostbynamel($host) ?: [], array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
	    if (!$ips)
		return [ [], "the host {$host} is not found" ];
	}
	foreach($ips as $ip) {
	    if (!self::isPublicIp($ip) && !($private && self::isPrivateNetworkIp($ip)))
		return [ [], "the host {$host} is not " . ($private ? 'a public or private network address (this machine, link-local and reserved ones are refused)' : 'a public address') ];
	}
	return [ $ips, null ];
    }

    //CLD: 2026-11-01T00:30:00+02:00: an address of a private network (RFC 1918, shared 100.64/10, IPv6 unique local fc00::/7), not one of this machine's own (its interfaces), not loopback,
    //link-local or reserved; IPv4-mapped IPv6 by its IPv4 address
    public static function isPrivateNetworkIp($ip) {
	$bin = @inet_pton((string)$ip);
	if ($bin === false)
	    return false;
	if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff"))
	    return self::isPrivateNetworkIp(inet_ntop(substr($bin, 12)));
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE))
	    return false;
	if (!array_filter([ '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7' ], fn($cidr) => self::inNetwork($bin, $cidr)))
	    return false;
	return !in_array(inet_ntop($bin), self::ownAddresses(), true);
    }

    //CLD: 2026-11-07T01:14:34+02:00: whether address $bin (inet_pton) is in network $cidr
    private static function inNetwork($bin, $cidr) {
	[ $net, $bits ] = explode('/', $cidr);
	$n = inet_pton($net);
	if (strlen($n) !== strlen($bin))
	    return false;
	$bytes = intdiv((int)$bits, 8);
	$rest = (int)$bits % 8;
	if (substr($n, 0, $bytes) !== substr($bin, 0, $bytes))
	    return false;
	return $rest === 0 || ((ord($n[$bytes]) ^ ord($bin[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0;
    }

    //CLD: 2026-11-01T00:30:00+02:00: this machine's addresses (its interfaces), normalized (inet_ntop)
    public static function ownAddresses() {
	static $own = null;
	if ($own === null) {
	    $own = [];
	    foreach(function_exists('net_get_interfaces') ? (array)@net_get_interfaces() : [] as $if) {
		foreach((array)($if['unicast'] ?? []) as $u) {
		    $b = isset($u['address']) ? @inet_pton((string)$u['address']) : false;
		    if ($b !== false)
			$own[] = inet_ntop($b);
		}
	    }
	    foreach(@gethostbynamel((string)gethostname()) ?: [] as $a)
		$own[] = inet_ntop(inet_pton($a));
	    $own = array_values(array_unique($own));
	}
	return $own;
    }

    //an address of the internet (not this machine, a private network, link-local, reserved, ...); NAT64 (64:ff9b::/96) by the IPv4 address it holds
    public static function isPublicIp($ip) {
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))
	    return false;
	$bin = @inet_pton($ip);
	if (strlen((string)$bin) === 16 && str_starts_with($bin, hex2bin('0064ff9b0000000000000000')))
	    return self::isPublicIp(inet_ntop(substr($bin, 12)));
	return true;
    }

    // ------------------------------------------------------------------------------------

    //one exchange (no redirect followed): the answer's status, error, tooLarge, headers, headerList, body, size, contentType, mime; $follow: a redirect's body is not kept (it is followed)
    //CLD: 2026-11-01T00:30:00+02:00: $public: false, 'public' or 'private' (private network addresses accepted too); + $insecure: the certificate not checked
    //CLD: 2026-11-01T16:00:00+02:00: + $auth: [ ntlm | basic, user, password ] or null
    protected static function once($method, $url, array $headers, $body, $upload, $sink, $maxBytes, $https, $public, $compressed, $timeoutMs, $connectTimeout, $follow, $insecure = false, $auth = null) {
	$r = [ 'status' => 0, 'reason' => '', 'error' => null, 'tooLarge' => false, 'headers' => [], 'headerList' => [], 'body' => '', 'size' => 0, 'contentType' => '', 'mime' => '' ];
	$parts = parse_url($url);
	$scheme = strtolower((string)($parts['scheme'] ?? ''));
	if (!in_array($scheme, $https ? [ 'https' ] : [ 'http', 'https' ], true) || empty($parts['host'])) {
	    $r['error'] = $https ? 'not an https:// address' : 'not an http:// or https:// address';
	    return $r;
	}

	$opts = [
	    CURLOPT_HTTPHEADER => $headers,
	    CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_FOLLOWLOCATION => false,
	    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
	    CURLOPT_TIMEOUT_MS => $timeoutMs,
	];
	if ($public) {
	    //the address checked is the one connected to (pinned: no second lookup)
	    [ $ips, $why ] = self::publicAddresses($parts['host'], $public === 'private');
	    if ($why !== null) {
		$r['error'] = $why;
		return $r;
	    }
	    $host = trim($parts['host'], '[]');
	    if (!filter_var($host, FILTER_VALIDATE_IP)) {
		$port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
		$opts[CURLOPT_RESOLVE] = [ "{$host}:{$port}:" . (str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0]) ];
	    }
	}
	if ($compressed)
	    $opts[CURLOPT_ENCODING] = '';
	if ($insecure) {
	    $opts[CURLOPT_SSL_VERIFYPEER] = false;
	    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
	}
	//CLD: 2026-11-01T16:00:00+02:00: curl signs in
	if (is_array($auth) && count($auth) === 3) {
	    $opts[CURLOPT_HTTPAUTH] = $auth[0] === 'ntlm' ? CURLAUTH_NTLM : CURLAUTH_BASIC;
	    $opts[CURLOPT_USERPWD] = (string)$auth[1] . ':' . (string)$auth[2];
	}

	if ($method === 'GET')
	    $opts[CURLOPT_HTTPGET] = true;
	elseif ($method === 'HEAD')
	    $opts[CURLOPT_NOBODY] = true;
	else
	    $opts[CURLOPT_CUSTOMREQUEST] = $method;
	if ($upload !== null) {
	    [ $stream, $size ] = $upload;
	    $opts[CURLOPT_UPLOAD] = true;
	    $opts[CURLOPT_INFILESIZE_LARGE] = (int)$size;
	    $opts[CURLOPT_READFUNCTION] = fn($ch, $fd, $length) => (string)fread($stream, $length);
	    if ($method !== 'PUT')
		$opts[CURLOPT_CUSTOMREQUEST] = $method;
	} elseif ($body !== null)
	    $opts[CURLOPT_POSTFIELDS] = $body;

	//the headers of the answer; the body of a redirect followed is not kept
	$redirect = false;
	$opts[CURLOPT_HEADERFUNCTION] = function($ch, $line) use (&$r) {
	    if (preg_match('~^HTTP/\S+\s+(\d+)(?:[ \t]+([^\r\n]*))?~', $line, $m)) {
		//a new answer (e.g. after 100 Continue): its headers only
		$r['status'] = (int)$m[1];
		//CLD: 2026-11-01T14:00:00+02:00: + the reason phrase (Proxmox VE puts its error messages there; none in HTTP/2)
		$r['reason'] = trim((string)($m[2] ?? ''));
		$r['headers'] = $r['headerList'] = [];
	    } elseif (($p = strpos($line, ':')) !== false) {
		$name = trim(substr($line, 0, $p));
		$value = trim(substr($line, $p + 1));
		$r['headerList'][] = [ $name, $value ];
		$r['headers'][strtolower($name)] = $value;
	    }
	    return strlen($line);
	};
	$opts[CURLOPT_WRITEFUNCTION] = function($ch, $chunk) use (&$r, &$redirect, $sink, $maxBytes, $follow) {
	    if ($redirect || ($follow && in_array($r['status'], self::REDIRECT_STATUSES, true) && isset($r['headers']['location']))) {
		$redirect = true;
		return strlen($chunk);
	    }
	    if ($r['size'] + strlen($chunk) > $maxBytes) {
		$r['tooLarge'] = true;
		return 0;
	    }
	    $r['size'] += strlen($chunk);
	    if ($sink !== null)
		return fwrite($sink, $chunk);
	    $r['body'] .= $chunk;
	    return strlen($chunk);
	};

	$ch = curl_init($url);
	curl_setopt_array($ch, $opts);
	$ok = curl_exec($ch);
	$r['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$r['contentType'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$r['mime'] = strtolower(trim(explode(';', $r['contentType'])[0]));
	if ($r['tooLarge'])
	    $r['error'] = 'the answer is larger than ' . self::mb($maxBytes);
	elseif ($ok === false)
	    $r['error'] = curl_error($ch) ?: 'no answer';
	curl_close($ch);
	return $r;
    }

    //$location (absolute, or relative to $base) as an absolute http(s) address; null for another scheme
    protected static function resolveUrl($base, $location) {
	$location = trim((string)$location);
	if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $location, $m))
	    return in_array(strtolower($m[1]), [ 'http', 'https' ], true) ? $location : null;
	$b = parse_url($base);
	$origin = $b['scheme'] . '://' . (str_contains($b['host'], ':') && $b['host'][0] !== '[' ? '[' . $b['host'] . ']' : $b['host']) . (isset($b['port']) ? ':' . $b['port'] : '');
	if (str_starts_with($location, '//'))
	    return $b['scheme'] . ':' . $location;
	if (str_starts_with($location, '/'))
	    return $origin . $location;
	if (str_starts_with($location, '?'))
	    return $origin . ($b['path'] ?? '/') . $location;
	$dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
	return $origin . $dir . $location;
    }

    //$lines with "$name: $value" added unless a header of that name is there
    protected static function withDefault(array $lines, $name, $value) {
	foreach($lines as $l) {
	    if (stripos($l, $name . ':') === 0)
		return $lines;
	}
	$lines[] = $value === '' ? $name . ':' : $name . ': ' . $value;
	return $lines;
    }

    //CLD: 2026-10-24T16:30:00+03:00: util\Format::bytes
    protected static function mb($bytes) {
	return Format::bytes($bytes);
    }
}
