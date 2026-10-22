<?php

/*
    CLD: 2026-10-20T16:00:00+03:00: new class: a client for FTP, FTPS (explicit: AUTH TLS on port 21, implicit: TLS from the start, port 990) and SFTP (SSH), over PHP's curl
    (libcurl with libssh for SFTP; no ssh2 / ftp extension needed). One curl handle per client: the connection is reused between the calls.
	- list($folder): the entries of a folder: [ { name, path, type (file / dir / link), size, modified (Unix time, UTC, or null), exact (whether `modified` is to the
	    second) } ]. FTP: MLSD (exact, UTC), else LIST (Unix or Windows style: minutes at best, the server's time); SFTP: the server's `ls -l` lines (minutes at best)
	- stat($path): [ size, modified ] of a file, to the second (FTP: SIZE / MDTM, SFTP: stat)
	- download($path, $stream, $maxBytes): the file written to $stream; an error when it is larger than $maxBytes
	- rename($from, $to), delete($path), mkdir($path)
	- upload($path, $stream, $size, $createFolders), exists($path) (CLD: 2026-10-20T18:00:00+03:00: for the FTP / SFTP Upload step)
	Paths: `/x/y` from the server's root, `x/y` from the folder the user starts in (the home folder; FTP: the login folder)
	SFTP: the server's key is checked against the MD5 fingerprint given (`hostKey`); without one the first connection is refused by libcurl and the fingerprint the server
	showed is in hostKeySeen (CredentialFtp learns it: trust on first use)
	Errors: \RuntimeException with curl's message
*/

namespace util;

class FtpClient {
    const PROTOCOLS = [ 'sftp', 'ftp', 'ftps', 'ftps-implicit' ];
    const DEFAULT_PORTS = [ 'sftp' => 22, 'ftp' => 21, 'ftps' => 21, 'ftps-implicit' => 990 ];

    //the largest folder listing read
    const MAX_LISTING = 8 * 1024 * 1024;

    protected $protocol;
    protected $host;
    protected $port;
    protected $options;
    protected $ch = null;
    //SFTP: the fingerprint the server showed when it was refused (no or another fingerprint given)
    public $hostKeySeen = null;

    //$options: username, password, privateKeyFile (SFTP: a file with the private key), passphrase (of the key), hostKey (SFTP: MD5 fingerprint, 32 hex digits),
    //verifyTls (FTPS: check the server's certificate, default true), timeout (seconds, of each call; default 60), connectTimeout (default 15)
    public function __construct($protocol, $host, $port, array $options = []) {
	if (!in_array($protocol, self::PROTOCOLS, true))
	    throw new \InvalidArgumentException("unknown protocol `{$protocol}`");
	$host = trim((string)$host);
	if ($host === '' || preg_match('~[\s/@?#]~', $host))
	    throw new \InvalidArgumentException("not a valid server name `{$host}`");
	$this->protocol = $protocol;
	$this->host = $host;
	$this->port = (int)$port ?: self::DEFAULT_PORTS[$protocol];
	$this->options = $options + [ 'username' => '', 'password' => '', 'privateKeyFile' => '', 'passphrase' => '', 'hostKey' => '', 'verifyTls' => true, 'timeout' => 60, 'connectTimeout' => 15 ];
    }

    public function isSftp() {
	return $this->protocol === 'sftp';
    }

    public function close() {
	if ($this->ch)
	    curl_close($this->ch);
	$this->ch = null;
    }

    public function __destruct() {
	$this->close();
    }

    // ------------------------------------------------------------------------------------
    //paths

    //a path in the client's form: `/` separated, no `.` / empty parts, `..` kept as it is; absolute (`/...`) or relative (to the start folder; '' = the start folder)
    public static function normalize($path) {
	$path = str_replace('\\', '/', trim((string)$path));
	$absolute = str_starts_with($path, '/');
	$parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== '' && $p !== '.'));
	return ($absolute ? '/' : '') . implode('/', $parts);
    }

    public static function join($folder, $name) {
	$folder = self::normalize($folder);
	return $folder === '' ? $name : ($folder === '/' ? '/' . $name : $folder . '/' . $name);
    }

    //the URL of $path (a folder ends with /): SFTP relative paths under /~/ (the home folder), FTP absolute paths with %2F (curl: from the server's root)
    protected function url($path, $folder = false) {
	$path = self::normalize($path);
	$scheme = match($this->protocol) { 'sftp' => 'sftp', 'ftps-implicit' => 'ftps', default => 'ftp' };
	$absolute = str_starts_with($path, '/');
	$parts = array_map('rawurlencode', array_values(array_filter(explode('/', $path), fn($p) => $p !== '')));
	$encoded = implode('/', $parts);
	if ($this->isSftp())
	    $prefix = $absolute ? '/' : '/~/';
	else
	    $prefix = $absolute ? '/%2F' : '/';
	$url = "{$scheme}://" . (str_contains($this->host, ':') ? "[{$this->host}]" : $this->host) . ":{$this->port}{$prefix}{$encoded}";
	if ($folder && $encoded !== '')
	    $url .= '/';
	//an absolute root: /%2F/ is not a valid FTP folder for curl
	return str_ends_with($url, '/%2F') ? substr($url, 0, -3) . '%2F/' : $url;
    }

    //$path as a command's argument: SFTP: quoted, relative under /~/ ; FTP: as it is (relative to the login folder: the commands are sent from it)
    protected function arg($path) {
	$path = self::normalize($path);
	if (!$this->isSftp())
	    return $path === '' ? '.' : $path;
	$path = str_starts_with($path, '/') ? $path : '/~/' . $path;
	return '"' . str_replace([ '\\', '"' ], [ '\\\\', '\\"' ], $path) . '"';
    }

    // ------------------------------------------------------------------------------------
    //requests

    //one request on the client's handle: [ body or true, info ]; \RuntimeException on an error
    protected function request($url, array $opts = []) {
	if (!$this->ch)
	    $this->ch = curl_init();
	else
	    curl_reset($this->ch);
	$o = $this->options;
	$base = [
	    CURLOPT_URL => $url,
	    CURLOPT_CONNECTTIMEOUT => (int)$o['connectTimeout'],
	    CURLOPT_TIMEOUT => (int)$o['timeout'],
	    CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS | CURLPROTO_SFTP,
	    CURLOPT_USERNAME => (string)$o['username'],
	    CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_NONE,
	];
	if ($this->isSftp()) {
	    $base[CURLOPT_SSH_AUTH_TYPES] = (string)$o['privateKeyFile'] !== '' ? CURLSSH_AUTH_PUBLICKEY : (CURLSSH_AUTH_PASSWORD | CURLSSH_AUTH_KEYBOARD);
	    if ((string)$o['privateKeyFile'] !== '') {
		$base[CURLOPT_SSH_PRIVATE_KEYFILE] = (string)$o['privateKeyFile'];
		if ((string)$o['passphrase'] !== '')
		    $base[CURLOPT_KEYPASSWD] = (string)$o['passphrase'];
	    } else {
		$base[CURLOPT_PASSWORD] = (string)$o['password'];
	    }
	    //no fingerprint: a made-up one, so the server's is told in the error (never accepted unchecked)
	    $base[CURLOPT_SSH_HOST_PUBLIC_KEY_MD5] = self::isFingerprint($o['hostKey']) ? self::fingerprint($o['hostKey']) : str_repeat('0', 32);
	} else {
	    $base[CURLOPT_PASSWORD] = (string)$o['password'];
	    if ($this->protocol !== 'ftp') {
		if ($this->protocol === 'ftps')
		    $base[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
		$base[CURLOPT_SSL_VERIFYPEER] = !empty($o['verifyTls']);
		$base[CURLOPT_SSL_VERIFYHOST] = !empty($o['verifyTls']) ? 2 : 0;
	    }
	}
	curl_setopt_array($this->ch, $opts + $base);
	$result = curl_exec($this->ch);
	$info = [ 'filetime' => (int)curl_getinfo($this->ch, CURLINFO_FILETIME), 'size' => (int)curl_getinfo($this->ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T), 'code' => (int)curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE) ];
	if ($result === false) {
	    $error = curl_error($this->ch);
	    if (preg_match('/mismatch md5 fingerprint\. Remote ([0-9a-f]{32})/i', $error, $m)) {
		$this->hostKeySeen = strtolower($m[1]);
		$error = self::isFingerprint($o['hostKey'])
		    ? 'the server\'s key changed: its fingerprint is ' . self::fingerprint($this->hostKeySeen, true) . ', the credential expects ' . self::fingerprint($o['hostKey'], true)
		    : 'the server\'s key is not known yet (fingerprint ' . self::fingerprint($this->hostKeySeen, true) . ')';
		$this->close();
	    }
	    throw new \RuntimeException($error !== '' ? $error : 'the request failed');
	}
	return [ $result, $info ];
    }

    //an MD5 fingerprint: 32 hex digits, with or without colons, `MD5:` prefix allowed
    public static function isFingerprint($value) {
	return preg_match('/^[0-9a-f]{32}$/', self::fingerprint($value)) === 1;
    }

    //a fingerprint as libcurl wants it (32 lower-case hex digits), or as people read it (aa:bb:...)
    public static function fingerprint($value, $shown = false) {
	$hex = strtolower(preg_replace('/^md5:|[^0-9a-f]/i', '', trim((string)$value)));
	return $shown ? implode(':', str_split($hex, 2)) : $hex;
    }

    // ------------------------------------------------------------------------------------
    //operations

    //the entries of $folder (no `.` / `..`)
    public function list($folder) {
	$folder = self::normalize($folder);
	$read = function($command = null) use ($folder) {
	    $data = '';
	    $opts = [ CURLOPT_RETURNTRANSFER => false, CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$data) {
		if (strlen($data) + strlen($chunk) > self::MAX_LISTING)
		    return 0;
		$data .= $chunk;
		return strlen($chunk);
	    } ];
	    if ($command !== null)
		$opts[CURLOPT_CUSTOMREQUEST] = $command;
	    $this->request($this->url($folder, true), $opts);
	    return $data;
	};

	if (!$this->isSftp()) {
	    //MLSD (RFC 3659): exact times; servers without it answer 500 / 502
	    try {
		$entries = self::parseMlsd($read('MLSD'), $folder);
		if ($entries !== null)
		    return $entries;
	    } catch (\RuntimeException $e) {
		if (!preg_match('/\b(500|501|502|504)\b|RETR response|not understood|not implemented/i', $e->getMessage()))
		    throw $e;
	    }
	}
	return self::parseList($read(), $folder);
    }

    //[ size, modified ] of file $path (null when the server does not tell)
    public function stat($path) {
	[ , $info ] = $this->request($this->url($path), [ CURLOPT_NOBODY => true, CURLOPT_FILETIME => true, CURLOPT_RETURNTRANSFER => true ]);
	return [ $info['size'] >= 0 ? $info['size'] : null, $info['filetime'] > 0 ? $info['filetime'] : null ];
    }

    //file $path written to $stream: the bytes written; larger than $maxBytes: \RuntimeException
    public function download($path, $stream, $maxBytes) {
	$size = 0;
	$tooLarge = false;
	try {
	    $this->request($this->url($path), [ CURLOPT_RETURNTRANSFER => false, CURLOPT_WRITEFUNCTION => function($ch, $chunk) use ($stream, &$size, &$tooLarge, $maxBytes) {
		if ($size + strlen($chunk) > $maxBytes) {
		    $tooLarge = true;
		    return 0;
		}
		$size += strlen($chunk);
		return fwrite($stream, $chunk);
	    } ]);
	} catch (\RuntimeException $e) {
	    if ($tooLarge)
		throw new \RuntimeException('the file is larger than ' . round($maxBytes / 1048576, 1) . ' MB');
	    throw $e;
	}
	return $size;
    }

    //CLD: 2026-10-20T18:00:00+03:00: $stream ($size bytes) written to file $path (replaced when it exists); $createFolders: its missing folders made (FTP and SFTP)
    public function upload($path, $stream, $size, $createFolders = false) {
	rewind($stream);
	$this->request($this->url($path), [
	    CURLOPT_UPLOAD => true,
	    CURLOPT_INFILE => $stream,
	    CURLOPT_INFILESIZE_LARGE => (int)$size,
	    CURLOPT_RETURNTRANSFER => true,
	    CURLOPT_FTP_CREATE_MISSING_DIRS => $createFolders ? CURLFTP_CREATE_DIR_RETRY : CURLFTP_CREATE_DIR_NONE,
	]);
    }

    //CLD: 2026-10-20T18:00:00+03:00: whether file $path exists: [ size, modified ] when it does, null when the server says there is no such file; other errors are thrown
    public function exists($path) {
	try {
	    return $this->stat($path);
	} catch (\RuntimeException $e) {
	    if (preg_match('/no such file|not found|does not exist|\b550\b|could not open remote file|given directory|RETR response|SIZE|MDTM/i', $e->getMessage()))
		return null;
	    throw $e;
	}
    }

    //commands sent from the start folder (FTP: the login folder; no transfer)
    protected function commands(array $commands) {
	$this->request($this->url('', true), [ CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_QUOTE => $commands ]);
    }

    public function rename($from, $to) {
	$this->commands($this->isSftp() ? [ 'rename ' . $this->arg($from) . ' ' . $this->arg($to) ] : [ 'RNFR ' . $this->arg($from), 'RNTO ' . $this->arg($to) ]);
    }

    public function delete($path) {
	$this->commands([ ($this->isSftp() ? 'rm ' : 'DELE ') . $this->arg($path) ]);
    }

    //a folder; one that exists already is not an error
    public function mkdir($path) {
	$this->commands([ '*' . ($this->isSftp() ? 'mkdir ' : 'MKD ') . $this->arg($path) ]);
    }

    // ------------------------------------------------------------------------------------
    //listings

    //MLSD lines (facts; name): the entries; null when the text is not an MLSD listing
    public static function parseMlsd($text, $folder) {
	$entries = [];
	foreach(preg_split('/\r?\n/', (string)$text) as $line) {
	    if (trim($line) === '')
		continue;
	    if (!preg_match('/^((?:[A-Za-z0-9.\-]+=[^;]*;)+) (.+)$/', $line, $m))
		return null;
	    $facts = [];
	    foreach(explode(';', rtrim($m[1], ';')) as $fact) {
		[ $k, $v ] = array_pad(explode('=', $fact, 2), 2, '');
		$facts[strtolower($k)] = $v;
	    }
	    $type = strtolower($facts['type'] ?? '');
	    if ($type === 'cdir' || $type === 'pdir' || $m[2] === '.' || $m[2] === '..')
		continue;
	    $type = match(true) { $type === 'dir' => 'dir', $type === 'file' => 'file', str_contains($type, 'slink') || str_contains($type, 'symlink') => 'link', default => 'other' };
	    $modified = null;
	    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $facts['modify'] ?? '', $t))
		$modified = gmmktime((int)$t[4], (int)$t[5], (int)$t[6], (int)$t[2], (int)$t[3], (int)$t[1]);
	    $size = $facts['size'] ?? $facts['sizd'] ?? null;
	    $entries[] = [ 'name' => $m[2], 'path' => self::join($folder, $m[2]), 'type' => $type, 'size' => is_numeric($size) ? (int)$size : null, 'modified' => $modified, 'exact' => $modified !== null ];
	}
	return $entries;
    }

    //LIST / `ls -l` lines (Unix style, or Windows / IIS style): the entries (times to the minute at best, read as UTC); unknown lines are skipped
    //$now: the time the listing is read at (a date without a year: this year, or the last one when it would be in the future)
    public static function parseList($text, $folder, $now = null) {
	$now = $now ?? time();
	$months = [ 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 ];
	$entries = [];
	foreach(preg_split('/\r?\n/', (string)$text) as $line) {
	    $line = rtrim($line);
	    $entry = null;
	    //-rw-r--r--   1 owner group   1234 Oct 20 10:47 name   /   ... Jan  1  2025 name
	    if (preg_match('/^([\-dlbcps])[\w\-]{9}\S*\s+\d+\s+\S+(?:\s+\S+)?\s+(\d+)\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s(.+)$/', $line, $m)) {
		$month = $months[strtolower($m[3])] ?? 0;
		if (!$month)
		    continue;
		if (str_contains($m[5], ':')) {
		    [ $h, $i ] = array_map('intval', explode(':', $m[5]));
		    $year = (int)gmdate('Y', $now);
		    $modified = gmmktime($h, $i, 0, $month, (int)$m[4], $year);
		    if ($modified > $now + 86400)
			$modified = gmmktime($h, $i, 0, $month, (int)$m[4], $year - 1);
		} else {
		    $modified = gmmktime(0, 0, 0, $month, (int)$m[4], (int)$m[5]);
		}
		$name = $m[6];
		$type = match($m[1]) { 'd' => 'dir', 'l' => 'link', '-' => 'file', default => 'other' };
		if ($type === 'link' && ($p = strpos($name, ' -> ')) !== false)
		    $name = substr($name, 0, $p);
		$entry = [ 'name' => $name, 'type' => $type, 'size' => (int)$m[2], 'modified' => $modified ];
	    }
	    //10-20-26  10:47AM       <DIR>          name   /   10-20-2026  22:47       1234 name
	    elseif (preg_match('/^(\d{2})-(\d{2})-(\d{2}|\d{4})\s+(\d{1,2}):(\d{2})\s*([AP]M)?\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
		$year = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
		$h = (int)$m[4] % 12 + (strtoupper($m[6]) === 'PM' ? 12 : 0);
		if ($m[6] === '')
		    $h = (int)$m[4];
		$entry = [ 'name' => $m[8], 'type' => strtoupper($m[7]) === '<DIR>' ? 'dir' : 'file', 'size' => strtoupper($m[7]) === '<DIR>' ? null : (int)$m[7],
		    'modified' => gmmktime($h, (int)$m[5], 0, (int)$m[1], (int)$m[2], $year) ];
	    }
	    if ($entry === null || $entry['name'] === '.' || $entry['name'] === '..')
		continue;
	    $entries[] = $entry + [ 'path' => self::join($folder, $entry['name']), 'exact' => false ];
	}
	return $entries;
    }
}
