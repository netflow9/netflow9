<?php

/*
    minimal IMAP4rev1 client in pure PHP (no ext-imap: it cannot do XOAUTH2),
    used by the Mail plugin to fetch raw messages (parsed by \util\MailParser).
    TLS / STARTTLS, LOGIN, AUTHENTICATE XOAUTH2 (Google, Microsoft 365),LIST, SELECT, UID SEARCH / FETCH / STORE / MOVE (COPY + delete when MOVE is missing), literals in both directions.

    $imap = new \util\ImapClient([ 'timeout' => 30 ]);
    $imap->connect('imap.example.com', 993, 'ssl');		//ssl | starttls | none
    $imap->login($user, $password);				//or $imap->authenticateXoauth2($user, $accessToken)
    $box = $imap->select('INBOX');				//[ exists, uidvalidity, uidnext ]
    $uids = $imap->uidSearch('UNSEEN');
    $raw = $imap->fetchMessage($uids[0]);
    $imap->logout();

    errors are thrown as \util\ImapException (message = the server's answer when there is one)
*/

namespace util;

class ImapException extends \Exception {
}

class ImapClient {
    //largest literal (message) read from the server; larger ones abort the connection
    const MAX_LITERAL = 64 * 1024 * 1024;

    //longest response line (without literals)
    const MAX_LINE = 1024 * 1024;

    public $timeout = 30;

    //verify the server's certificate (keep on; off only for test servers with self-signed certificates)
    public $verifyPeer = true;

    //optional callable($direction 'C'|'S', $line) for debugging; credentials are never passed to it
    public $debug = null;

    //capabilities announced by the server (upper case)
    public $capabilities = [];

    protected $stream = null;
    protected $tagNo = 0;
    protected $host = '';
    protected $secure = false;

    public function __construct($options = []) {
	foreach([ 'timeout', 'verifyPeer', 'debug' ] as $key) {
	    if (array_key_exists($key, $options))
		$this->$key = $options[$key];
	}
    }

    public function __destruct() {
	$this->close();
    }

    //open the connection and read the greeting; $security: ssl (implicit TLS, port 993), starttls (port 143), none
    public function connect($host, $port = 993, $security = 'ssl') {
	$this->host = $host;
	$this->secure = ($security === 'ssl');
	$ctx = stream_context_create([ 'ssl' => [
	    'verify_peer' => (bool)$this->verifyPeer,
	    'verify_peer_name' => (bool)$this->verifyPeer,
	    'allow_self_signed' => !$this->verifyPeer,
	    'SNI_enabled' => true,
	    'peer_name' => $host,
	] ]);

	$remote = ($security === 'ssl' ? 'tls' : 'tcp') . '://' . $host . ':' . (int)$port;
	$errno = 0;
	$errstr = '';
	$stream = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
	if (!$stream)
	    throw new ImapException("cannot connect to {$host}:{$port}: " . ($errstr ?: 'connection failed'));
	$this->stream = $stream;
	stream_set_timeout($this->stream, (int)$this->timeout);

	$greeting = $this->readLogical();
	if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting['line']))
	    throw new ImapException('unexpected greeting: ' . substr($greeting['line'], 0, 200));
	$this->parseCapabilities($greeting['line']);

	if ($security === 'starttls') {
	    $this->capability();
	    if (!$this->hasCapability('STARTTLS'))
		throw new ImapException('the server does not offer STARTTLS');
	    $this->command('STARTTLS');
	    $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
	    if ($ok !== true)
		throw new ImapException('TLS negotiation failed');
	    $this->secure = true;
	    //capabilities before TLS must not be trusted
	    $this->capabilities = [];
	}

	if (empty($this->capabilities))
	    $this->capability();
	return $this;
    }

    public function isConnected() {
	return is_resource($this->stream) && !feof($this->stream);
    }

    public function capability() {
	$r = $this->command('CAPABILITY');
	foreach($r['untagged'] as $u)
	    $this->parseCapabilities($u['line']);
	return $this->capabilities;
    }

    public function hasCapability($name) {
	return in_array(strtoupper($name), $this->capabilities, true);
    }

    //plain LOGIN (username / password or app password)
    public function login($user, $password) {
	//CLD: 2026-10-09T13:11:44+03:00: say why: over TLS the server refuses passwords altogether (e.g. Outlook.com / Microsoft 365: OAuth only); the message
	//said "use TLS" even on TLS connections
	if ($this->hasCapability('LOGINDISABLED')) {
	    if (!$this->secure)
		throw new ImapException('the server does not allow LOGIN on an unencrypted connection: use SSL/TLS or STARTTLS');
	    throw new ImapException('the server does not accept password sign-in (LOGIN)'
		. ($this->hasCapability('AUTH=XOAUTH2') ? ': it needs OAuth (XOAUTH2), use a token / an OAuth credential' : ''));
	}
	$r = $this->command([ 'LOGIN ', $this->astring($user), ' ', $this->astring($password) ], true);
	$this->afterAuth($r);
	return $this;
    }

    //SASL XOAUTH2 with an OAuth 2.0 access token (Gmail, Microsoft 365)
    public function authenticateXoauth2($user, $accessToken) {
	$payload = base64_encode("user={$user}\x01auth=Bearer {$accessToken}\x01\x01");
	$sasl = $this->hasCapability('SASL-IR');
	$tag = $this->send($sasl ? [ 'AUTHENTICATE XOAUTH2 ' . $payload ] : [ 'AUTHENTICATE XOAUTH2' ], true);

	$detail = '';
	$untagged = [];
	$sent = $sasl;
	while (true) {
	    $r = $this->readLogical();
	    $line = $r['line'];
	    if ($line !== '' && $line[0] === '+') {
		$challenge = trim(substr($line, 1));
		if (!$sent) {
		    //initial continuation: send the credentials
		    $sent = true;
		    $this->write($payload . "\r\n", true);
		    continue;
		}
		//error challenge (base64 JSON with status / scope): answer with an empty line, the server then fails the command
		$decoded = base64_decode($challenge, true);
		$detail = ($decoded !== false && $decoded !== '') ? $decoded : $challenge;
		$this->write("\r\n");
		continue;
	    }
	    if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) {
		$status = strtoupper(strtok(substr($line, strlen($tag) + 1), ' '));
		$text = trim(substr($line, strlen($tag) + 1 + strlen($status)));
		if ($status !== 'OK')
		    throw new ImapException('authentication failed: ' . $text . ($detail !== '' ? ' ' . $detail : ''));
		$this->afterAuth([ 'status' => $status, 'text' => $text, 'untagged' => $untagged ]);
		return $this;
	    }
	    $untagged[] = $r;
	}
    }

    //folders: list of [ name (UTF-8), raw (as the server knows it), delimiter, flags ]
    public function listFolders($pattern = '*') {
	$r = $this->command([ 'LIST "" ', $this->astring($pattern) ]);
	$folders = [];
	foreach($r['untagged'] as $u) {
	    if (!preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.*)$/is', $u['line'], $m))
		continue;
	    $flags = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
	    $delimiter = strtoupper($m[2]) === 'NIL' ? null : self::unquote($m[2]);
	    $nameToken = trim($m[3]);
	    if (preg_match('/^\{(\d+)\}$/', $nameToken))
		$raw = $u['literals'][0] ?? '';
	    elseif ($nameToken !== '' && $nameToken[0] === '"')
		$raw = self::unquote($nameToken);
	    else
		$raw = $nameToken;
	    $folders[] = [ 'name' => self::decodeFolder($raw), 'raw' => $raw, 'delimiter' => $delimiter, 'flags' => $flags ];
	}
	return $folders;
    }

    //select a folder (read-write): [ exists, uidvalidity, uidnext ]
    public function select($folder) {
	$r = $this->command([ 'SELECT ', $this->astring(self::encodeFolder($folder)) ]);
	$info = [ 'exists' => 0, 'uidvalidity' => null, 'uidnext' => null ];
	foreach($r['untagged'] as $u) {
	    $line = $u['line'];
	    if (preg_match('/^\* (\d+) EXISTS/i', $line, $m))
		$info['exists'] = (int)$m[1];
	    if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m))
		$info['uidvalidity'] = (int)$m[1];
	    if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m))
		$info['uidnext'] = (int)$m[1];
	}
	return $info;
    }

    //UIDs matching an IMAP search (e.g. 'UNSEEN', 'ALL', 'UID 120:*'), ascending
    public function uidSearch($criteria) {
	$r = $this->command('UID SEARCH ' . $criteria);
	$uids = [];
	foreach($r['untagged'] as $u) {
	    if (preg_match('/^\* SEARCH\b(.*)$/i', $u['line'], $m)) {
		foreach(preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $n) {
		    if (ctype_digit($n))
			$uids[] = (int)$n;
		}
	    }
	}
	$uids = array_values(array_unique($uids));
	sort($uids);
	return $uids;
    }

    //sizes in bytes: [ uid => size ]
    public function fetchSizes(array $uids) {
	if (empty($uids))
	    return [];
	$r = $this->command('UID FETCH ' . self::uidSet($uids) . ' (UID RFC822.SIZE)');
	$sizes = [];
	foreach($r['untagged'] as $u) {
	    if (preg_match('/^\* \d+ FETCH \(.*\bUID (\d+)/i', $u['line'], $m) && preg_match('/\bRFC822\.SIZE (\d+)/i', $u['line'], $s))
		$sizes[(int)$m[1]] = (int)$s[1];
	}
	return $sizes;
    }

    //the raw message (RFC 822) of $uid, without setting \Seen; null when the server has no such message
    public function fetchMessage($uid) {
	$uid = (int)$uid;
	$r = $this->command("UID FETCH {$uid} (UID BODY.PEEK[])");
	foreach($r['untagged'] as $u) {
	    $line = $u['line'];
	    if (!preg_match('/^\* \d+ FETCH \(/i', $line) || !preg_match('/\bUID ' . $uid . '\b/', $line))
		continue;
	    if (!preg_match('/BODY\[\] (\{\d+\}|"|NIL)/i', $line, $m, PREG_OFFSET_CAPTURE))
		continue;
	    $token = $m[1][0];
	    if ($token[0] === '{') {
		//index of this literal among the line's literals
		$index = preg_match_all('/\{\d+\}/', substr($line, 0, $m[1][1]));
		return $u['literals'][$index] ?? null;
	    }
	    if ($token === '"' && preg_match('/BODY\[\] ("(?:[^"\\\\]|\\\\.)*")/i', $line, $q))
		return self::unquote($q[1]);
	    return null;
	}
	return null;
    }

    //add flags (e.g. ['\Seen']) to messages
    public function addFlags(array $uids, array $flags) {
	if (empty($uids))
	    return;
	$this->command('UID STORE ' . self::uidSet($uids) . ' +FLAGS.SILENT (' . implode(' ', $flags) . ')');
    }

    //move messages to another folder (MOVE, else COPY + delete)
    public function move(array $uids, $folder) {
	if (empty($uids))
	    return;
	$target = $this->astring(self::encodeFolder($folder));
	if ($this->hasCapability('MOVE')) {
	    $this->command([ 'UID MOVE ' . self::uidSet($uids) . ' ', $target ]);
	    return;
	}
	$this->command([ 'UID COPY ' . self::uidSet($uids) . ' ', $target ]);
	$this->delete($uids);
    }

    //delete messages (\Deleted + expunge; UID EXPUNGE when available, so messages flagged by others stay)
    public function delete(array $uids) {
	if (empty($uids))
	    return;
	$this->addFlags($uids, [ '\\Deleted' ]);
	if ($this->hasCapability('UIDPLUS'))
	    $this->command('UID EXPUNGE ' . self::uidSet($uids));
	else
	    $this->command('EXPUNGE');
    }

    public function logout() {
	if ($this->isConnected()) {
	    try {
		$this->command('LOGOUT');
	    } catch (\Exception $e) {
	    }
	}
	$this->close();
    }

    public function close() {
	if (is_resource($this->stream))
	    @fclose($this->stream);
	$this->stream = null;
    }

    //utilities

    //1,2,3,5,7,8 -> 1:3,5,7:8
    public static function uidSet(array $uids) {
	$uids = array_values(array_unique(array_map('intval', $uids)));
	sort($uids);
	$parts = [];
	$start = $prev = null;
	foreach($uids as $u) {
	    if ($start === null) {
		$start = $prev = $u;
	    } elseif ($u === $prev + 1) {
		$prev = $u;
	    } else {
		$parts[] = $start === $prev ? (string)$start : "{$start}:{$prev}";
		$start = $prev = $u;
	    }
	}
	if ($start !== null)
	    $parts[] = $start === $prev ? (string)$start : "{$start}:{$prev}";
	return implode(',', $parts);
    }

    //folder names travel as modified UTF-7 (RFC 3501 5.1.3)
    public static function encodeFolder($name) {
	return mb_convert_encoding((string)$name, 'UTF7-IMAP', 'UTF-8');
    }

    public static function decodeFolder($name) {
	$decoded = @mb_convert_encoding((string)$name, 'UTF-8', 'UTF7-IMAP');
	return ($decoded === false || $decoded === '') ? (string)$name : $decoded;
    }

    public static function unquote($s) {
	$s = (string)$s;
	if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"')
	    return preg_replace('/\\\\(.)/s', '$1', substr($s, 1, -1));
	return $s;
    }

    //internals

    //a string argument: quoted when possible, else a literal (8-bit, CR/LF, very long)
    protected function astring($s) {
	$s = (string)$s;
	if (strlen($s) > 1000 || preg_match('/[\x00\r\n\x80-\xFF]/', $s))
	    return [ 'literal' => $s ];
	return '"' . addcslashes($s, '"\\') . '"';
    }

    //run a command; $parts: a string or a list of strings / [ 'literal' => data ]; NO / BAD answers throw
    protected function command($parts, $secret = false) {
	$tag = $this->send(is_array($parts) ? $parts : [ $parts ], $secret);
	return $this->readTagged($tag);
    }

    //send a tagged command (literals wait for the server's continuation); returns the tag
    protected function send(array $parts, $secret = false) {
	if (!is_resource($this->stream))
	    throw new ImapException('not connected');

	$tag = 'A' . str_pad((string)++$this->tagNo, 4, '0', STR_PAD_LEFT);
	$buf = $tag . ' ';
	foreach($parts as $p) {
	    if (is_array($p)) {
		$data = (string)$p['literal'];
		$this->write($buf . '{' . strlen($data) . "}\r\n", $secret);
		$r = $this->readLogical();
		if ($r['line'] === '' || $r['line'][0] !== '+')
		    throw new ImapException('the server refused the command: ' . substr($r['line'], 0, 200));
		$buf = $data;
	    } else {
		$buf .= $p;
	    }
	}
	$this->write($buf . "\r\n", $secret);
	return $tag;
    }

    //read until the tagged answer of $tag: [ status, text, untagged => list of [ line, literals ] ]
    protected function readTagged($tag) {
	$untagged = [];
	while (true) {
	    $r = $this->readLogical();
	    $line = $r['line'];
	    if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) {
		$rest = substr($line, strlen($tag) + 1);
		$status = strtoupper((string)strtok($rest, ' '));
		$text = trim(substr($rest, strlen($status)));
		if ($status !== 'OK')
		    throw new ImapException($text !== '' ? $text : "command failed ({$status})");
		return [ 'status' => $status, 'text' => $text, 'untagged' => $untagged ];
	    }
	    if (preg_match('/^\* BYE\b/i', $line)) {
		$this->close();
		throw new ImapException('the server closed the connection: ' . substr($line, 0, 200));
	    }
	    $untagged[] = $r;
	}
    }

    //one response: a line with its literals ({n} markers stay in the line, the data is in `literals`, in order)
    protected function readLogical() {
	$line = $part = $this->readLine();
	$literals = [];
	//only the part read last can announce another literal (the {n} markers stay in $line)
	while (preg_match('/\{(\d+)\}$/', $part, $m)) {
	    $n = (int)$m[1];
	    if ($n > self::MAX_LITERAL) {
		$this->close();
		throw new ImapException("server data too large ({$n} bytes)");
	    }
	    $literals[] = $this->readBytes($n);
	    $part = $this->readLine();
	    $line .= $part;
	}
	return [ 'line' => $line, 'literals' => $literals ];
    }

    protected function readLine() {
	$line = '';
	while (true) {
	    $chunk = fgets($this->stream, 65536);
	    if ($chunk === false) {
		$this->failRead();
	    }
	    $line .= $chunk;
	    if (substr($line, -1) === "\n")
		break;
	    if (strlen($line) > self::MAX_LINE) {
		$this->close();
		throw new ImapException('server line too long');
	    }
	}
	$line = rtrim($line, "\r\n");
	if ($this->debug)
	    call_user_func($this->debug, 'S', $line);
	return $line;
    }

    protected function readBytes($n) {
	$data = '';
	while (strlen($data) < $n) {
	    $chunk = fread($this->stream, min(65536, $n - strlen($data)));
	    if ($chunk === false || ($chunk === '' && (feof($this->stream) || stream_get_meta_data($this->stream)['timed_out'])))
		$this->failRead();
	    $data .= $chunk;
	}
	if ($this->debug)
	    call_user_func($this->debug, 'S', '(' . $n . ' bytes)');
	return $data;
    }

    protected function failRead() {
	$timedOut = is_resource($this->stream) && stream_get_meta_data($this->stream)['timed_out'];
	$this->close();
	throw new ImapException($timedOut ? 'the server did not answer in time' : 'connection lost');
    }

    protected function write($data, $secret = false) {
	if ($this->debug)
	    call_user_func($this->debug, 'C', $secret ? '(credentials)' : rtrim($data, "\r\n"));
	$len = strlen($data);
	$done = 0;
	while ($done < $len) {
	    $w = @fwrite($this->stream, substr($data, $done));
	    if ($w === false || $w === 0) {
		$this->close();
		throw new ImapException('connection lost while sending');
	    }
	    $done += $w;
	}
    }

    protected function afterAuth(array $r) {
	//capabilities may change after authentication
	$this->capabilities = [];
	$this->parseCapabilities($r['text'] ?? '');
	foreach($r['untagged'] ?? [] as $u)
	    $this->parseCapabilities($u['line']);
	if (empty($this->capabilities))
	    $this->capability();
    }

    protected function parseCapabilities($line) {
	if (preg_match('/\[CAPABILITY ([^\]]*)\]/i', $line, $m) || preg_match('/^\* CAPABILITY (.*)$/i', $line, $m))
	    $this->capabilities = array_values(array_unique(array_map('strtoupper', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY))));
    }
}
