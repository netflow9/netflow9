<?php

/*
    parses a raw email (RFC 5322 / MIME) into headers, addresses, bodies and attachments;
    shared by the mail trigger nodes (Haraka inbound, IMAP check);
    no dependencies besides mbstring / iconv
*/
namespace util;

/*
 * Usage:
 *   $mail = new \util\MailParser($raw);          // raw message, CRLF or LF line endings
 *   $mail->subject();                            // decoded, UTF-8
 *   $mail->from();                               // [ 'name' => ..., 'email' => ... ] or null
 *   $mail->to(); $mail->cc(); $mail->bcc(); $mail->replyTo();   // lists of [ name, email ]
 *   $mail->date();                               // DateTimeImmutable or null
 *   $mail->messageId(); $mail->inReplyTo(); $mail->references();
 *   $mail->text(); $mail->html();                // bodies, UTF-8 ('' when absent)
 *   $mail->attachments();                        // [ [ filename, contentType, size, disposition, contentId, content ], ... ]
 *   $mail->header('X-Mailer'); $mail->headers(); // decoded header values (all occurrences in headers())
 *   $mail->toArray();                            // everything above, attachments without their content
 */
class MailParser {

    //protection against hostile messages
    const MAX_DEPTH = 20;       //nested multiparts
    const MAX_PARTS = 500;      //MIME parts in total

    protected $headers = [];        //lower-case name => [ decoded values ]
    protected $rawHeaders = [];     //lower-case name => [ raw (unfolded) values ]
    protected $text = '';
    protected $html = '';
    protected $attachments = [];
    protected $nParts = 0;

    public function __construct($raw) {
	//one line ending to work with
	$raw = str_replace("\r\n", "\n", (string)$raw);

	[ $headerBlock, $body ] = self::splitHeaderBody($raw);
	$this->rawHeaders = self::parseHeaderBlock($headerBlock);
	foreach($this->rawHeaders as $name => $values)
	    $this->headers[$name] = array_map([ self::class, 'decodeHeader' ], $values);

	$this->parsePart($this->rawHeaders, $body, 0);
    }

    /* ---------- public API ---------- */

    //first value of a header (decoded), or null
    public function header($name) {
	return $this->headers[strtolower($name)][0] ?? null;
    }

    //all headers: lower-case name => [ decoded values ]
    public function headers() {
	return $this->headers;
    }

    public function subject() {
	return (string)$this->header('subject');
    }

    public function from() {
	return self::parseAddresses($this->rawHeaders['from'][0] ?? '')[0] ?? null;
    }

    public function to() { return $this->addressList('to'); }
    public function cc() { return $this->addressList('cc'); }
    public function bcc() { return $this->addressList('bcc'); }
    public function replyTo() { return $this->addressList('reply-to'); }

    public function date() {
	$value = $this->header('date');
	if (empty($value))
	    return null;
	//drop comments such as "(UTC)"
	$value = trim(preg_replace('/\([^)]*\)/', '', $value));
	//CLD: 2026-10-08T08:44:33+03:00: drop the day name - PHP moves the date to the next matching weekday when it is wrong (seen in real mail)
	$value = preg_replace('/^[A-Za-z]{3,},?\s*/', '', $value);
	if ($value === '')
	    return null;
	try {
	    return new \DateTimeImmutable($value);
	} catch (\Exception $e) {
	    return null;
	}
    }

    public function messageId() { return self::firstMsgId($this->header('message-id')); }
    public function inReplyTo() { return self::firstMsgId($this->header('in-reply-to')); }

    public function references() {
	preg_match_all('/<([^<>\s]+)>/', (string)$this->header('references'), $m);
	return $m[1];
    }

    public function text() { return $this->text; }
    public function html() { return $this->html; }

    public function attachments() { return $this->attachments; }

    //summary (attachments without their content)
    public function toArray() {
	$date = $this->date();
	return [
	    'from' => $this->from(),
	    'to' => $this->to(),
	    'cc' => $this->cc(),
	    'bcc' => $this->bcc(),
	    'reply_to' => $this->replyTo(),
	    'subject' => $this->subject(),
	    'date' => $date ? $date->format(DATE_ATOM) : null,
	    'message_id' => $this->messageId(),
	    'in_reply_to' => $this->inReplyTo(),
	    'references' => $this->references(),
	    'text' => $this->text,
	    'html' => $this->html,
	    'attachments' => array_map(function($a) {
		unset($a['content']);
		return $a;
	    }, $this->attachments),
	];
    }

    /* ---------- MIME structure ---------- */

    protected function parsePart(array $headers, $body, $depth) {
	if (++$this->nParts > self::MAX_PARTS || $depth > self::MAX_DEPTH)
	    return;

	[ $type, $params ] = self::parseHeaderValue($headers['content-type'][0] ?? 'text/plain');
	$type = strtolower($type ?: 'text/plain');
	[ $disposition, $dparams ] = self::parseHeaderValue($headers['content-disposition'][0] ?? '');
	$disposition = strtolower($disposition);

	//multipart: recurse into the sub-parts
	if (strpos($type, 'multipart/') === 0 && !empty($params['boundary'])) {
	    foreach(self::splitMultipart($body, $params['boundary']) as $partRaw) {
		[ $h, $b ] = self::splitHeaderBody($partRaw);
		$this->parsePart(self::parseHeaderBlock($h), $b, $depth + 1);
	    }
	    return;
	}

	$content = self::decodeTransfer($body, $headers['content-transfer-encoding'][0] ?? '');
	$filename = $dparams['filename'] ?? $params['name'] ?? null;

	//the first text/plain and text/html that are not attachments are the bodies
	$isBody = ($disposition !== 'attachment') && $filename === null;
	if ($isBody && $type === 'text/plain' && $this->text === '') {
	    $this->text = self::toUtf8($content, $params['charset'] ?? 'us-ascii');
	    return;
	}
	if ($isBody && $type === 'text/html' && $this->html === '') {
	    $this->html = self::toUtf8($content, $params['charset'] ?? 'us-ascii');
	    return;
	}

	//everything else is an attachment (forwarded messages included)
	if ($filename === null)
	    $filename = ($type === 'message/rfc822') ? 'message.eml' : 'part-' . $this->nParts . self::extensionFor($type);

	$this->attachments[] = [
	    'filename' => self::safeFilename($filename),
	    'contentType' => $type,
	    'size' => strlen($content),
	    'disposition' => $disposition === 'inline' ? 'inline' : 'attachment',
	    'contentId' => isset($headers['content-id'][0]) ? trim($headers['content-id'][0], " <>") : null,
	    'content' => $content,
	];
    }

    //[ header block, body ]: split at the first empty line
    protected static function splitHeaderBody($raw) {
	if (strncmp($raw, "\n", 1) === 0)
	    return [ '', substr($raw, 1) ];
	$pos = strpos($raw, "\n\n");
	if ($pos === false)
	    return [ $raw, '' ];
	return [ substr($raw, 0, $pos), substr($raw, $pos + 2) ];
    }

    //lower-case name => [ unfolded raw values ]
    protected static function parseHeaderBlock($block) {
	$headers = [];
	//unfold: a line starting with space / tab continues the previous one
	$block = preg_replace("/\n[ \t]+/", ' ', $block);
	foreach(explode("\n", $block) as $line) {
	    $pos = strpos($line, ':');
	    if ($pos === false || $pos === 0)
		continue;
	    $name = strtolower(trim(substr($line, 0, $pos)));
	    $headers[$name][] = trim(substr($line, $pos + 1));
	}
	return $headers;
    }

    //sub-parts of a multipart body (preamble and epilogue dropped; a missing closing boundary is tolerated)
    protected static function splitMultipart($body, $boundary) {
	$parts = [];
	$current = null;
	$delimiter = '--' . $boundary;
	foreach(explode("\n", $body) as $line) {
	    $trimmed = rtrim($line, " \t");
	    if ($trimmed === $delimiter || $trimmed === $delimiter . '--') {
		if ($current !== null)
		    $parts[] = implode("\n", $current);
		if ($trimmed === $delimiter . '--')
		    return $parts;
		$current = [];
		continue;
	    }
	    if ($current !== null)
		$current[] = $line;
	}
	if ($current !== null && $current !== [])
	    $parts[] = implode("\n", $current);
	return $parts;
    }

    /* ---------- header values ---------- */

    //"type/subtype; a=1; b=\"x y\"" -> [ 'type/subtype', [ a => 1, b => 'x y' ] ]; RFC 2231 (name*=, name*0*=) decoded
    protected static function parseHeaderValue($value) {
	$parts = self::splitOutsideQuotes((string)$value, ';');
	$main = trim(array_shift($parts) ?? '');

	$params = [];
	$extended = [];     //RFC 2231 pieces: name => [ index => [ value, encoded ] ]
	foreach($parts as $p) {
	    $pos = strpos($p, '=');
	    if ($pos === false)
		continue;
	    $name = strtolower(trim(substr($p, 0, $pos)));
	    $val = trim(substr($p, $pos + 1));
	    if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"')
		$val = preg_replace('/\\\\(.)/s', '$1', substr($val, 1, -1));   //quoted-pair: \" and \\ only

	    if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $name, $m)) {
		$extended[$m[1]][(int)($m[2] ?? 0)] = [ $val, isset($m[3]) || !isset($m[2]) ];
		continue;
	    }
	    $params[$name] = self::decodeHeader($val);
	}

	foreach($extended as $name => $pieces) {
	    ksort($pieces);
	    $charset = 'utf-8';
	    $out = '';
	    $first = true;
	    foreach($pieces as [ $val, $encoded ]) {
		if ($encoded && $first && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $m)) {
		    $charset = $m[1] ?: 'utf-8';
		    $val = $m[2];
		}
		$out .= $encoded ? rawurldecode($val) : $val;
		$first = false;
	    }
	    $params[$name] = self::toUtf8($out, $charset);
	}
	return [ $main, $params ];
    }

    //split on $sep outside double quotes
    protected static function splitOutsideQuotes($s, $sep) {
	$out = [];
	$cur = '';
	$inQuote = false;
	$len = strlen($s);
	for ($i = 0; $i < $len; $i++) {
	    $c = $s[$i];
	    if ($c === '\\' && $inQuote && $i + 1 < $len) {
		$cur .= $c . $s[++$i];
		continue;
	    }
	    if ($c === '"')
		$inQuote = !$inQuote;
	    if ($c === $sep && !$inQuote) {
		$out[] = $cur;
		$cur = '';
		continue;
	    }
	    $cur .= $c;
	}
	$out[] = $cur;
	return $out;
    }

    //RFC 2047 encoded words (=?charset?B|Q?...?=) -> UTF-8; raw 8-bit headers kept if UTF-8, else read as Latin-1
    public static function decodeHeader($value) {
	$value = (string)$value;
	//whitespace between two encoded words is not displayed
	$value = preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/', '$1', $value);

	$decoded = preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', function($m) {
	    $charset = preg_replace('/\*.*$/', '', $m[1]);     //RFC 2231 language suffix
	    $data = (strtoupper($m[2]) === 'B')
		? (string)base64_decode($m[3])
		: quoted_printable_decode(str_replace('_', ' ', $m[3]));
	    return self::toUtf8($data, $charset);
	}, $value);

	return mb_check_encoding($decoded, 'UTF-8') ? $decoded : self::toUtf8($decoded, 'iso-8859-1');
    }

    protected static function firstMsgId($value) {
	return preg_match('/<([^<>\s]+)>/', (string)$value, $m) ? $m[1] : null;
    }

    protected function addressList($name) {
	$list = [];
	foreach($this->rawHeaders[$name] ?? [] as $value)
	    $list = array_merge($list, self::parseAddresses($value));
	return $list;
    }

    //"A <a@x>, \"B, Jr.\" <b@x>, c@x (comment), Group: d@x;" -> [ [ name, email ], ... ]
    public static function parseAddresses($value) {
	$value = (string)$value;
	$addresses = [];
	$len = strlen($value);
	$token = '';
	$angle = null;      //address inside <...>
	$name = '';
	$inQuote = false;
	$depth = 0;         //comment nesting

	$flush = function() use (&$addresses, &$token, &$angle, &$name) {
	    $email = trim($angle ?? $token);
	    $display = trim($angle !== null ? $name . $token : $name);
	    if ($email !== '' && strpos($email, '@') !== false)
		$addresses[] = [ 'name' => self::decodeHeader(trim($display, " \t\"")), 'email' => $email ];
	    $token = '';
	    $angle = null;
	    $name = '';
	};

	for ($i = 0; $i < $len; $i++) {
	    $c = $value[$i];
	    if ($depth > 0) {
		if ($c === '(')
		    $depth++;
		elseif ($c === ')')
		    $depth--;
		elseif ($c === '\\')
		    $i++;
		continue;
	    }
	    if ($inQuote) {
		if ($c === '\\' && $i + 1 < $len) {
		    $token .= $value[++$i];
		} elseif ($c === '"') {
		    $inQuote = false;
		} else {
		    $token .= $c;
		}
		continue;
	    }
	    switch($c) {
		case '"':
		    $inQuote = true;
		    break;
		case '(':
		    $depth = 1;
		    break;
		case '<':
		    $name .= $token;
		    $token = '';
		    $end = strpos($value, '>', $i);
		    $angle = ($end === false) ? substr($value, $i + 1) : substr($value, $i + 1, $end - $i - 1);
		    $i = ($end === false) ? $len : $end;
		    break;
		case ':':
		    //start of a group: its name is not an address
		    $token = '';
		    $name = '';
		    break;
		case ',':
		case ';':
		    $flush();
		    break;
		default:
		    $token .= $c;
	    }
	}
	$flush();
	return $addresses;
    }

    /* ---------- content ---------- */

    protected static function decodeTransfer($body, $encoding) {
	switch(strtolower(trim((string)$encoding))) {
	    case 'base64':
		return (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
	    case 'quoted-printable':
		return quoted_printable_decode($body);
	    default:    //7bit, 8bit, binary
		return $body;
	}
    }

    //common aliases that mbstring / iconv do not know
    const CHARSET_ALIASES = [
	'utf8' => 'UTF-8',
	'ks_c_5601-1987' => 'CP949',
	'iso-8859-8-i' => 'ISO-8859-8',
	'x-sjis' => 'SJIS',
	'x-gbk' => 'GBK',
	'gb2312' => 'GBK',
	'ascii' => 'US-ASCII',
	'unknown-8bit' => 'ISO-8859-1',
	'x-unknown' => 'ISO-8859-1',
    ];

    //convert to UTF-8 from $charset; unknown charsets: kept if valid UTF-8, else read as Windows-1252; never invalid UTF-8
    public static function toUtf8($s, $charset) {
	$s = (string)$s;
	$charset = strtolower(trim((string)$charset, " \t\"'"));
	$charset = self::CHARSET_ALIASES[$charset] ?? $charset;

	if ($charset === '' || in_array(strtoupper($charset), [ 'UTF-8', 'US-ASCII' ], true))
	    return mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub($s, 'UTF-8');

	if (in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
	    $out = @mb_convert_encoding($s, 'UTF-8', $charset);
	    if ($out !== false)
		return mb_scrub($out, 'UTF-8');
	}

	$out = @iconv($charset, 'UTF-8//IGNORE', $s);
	if ($out !== false)
	    return mb_scrub($out, 'UTF-8');

	return mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub(mb_convert_encoding($s, 'UTF-8', 'Windows-1252'), 'UTF-8');
    }

    //a file name that is safe to store / show: no path, no control characters
    protected static function safeFilename($name) {
	$name = str_replace([ '/', '\\' ], '_', (string)$name);
	$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
	$name = trim($name, " .");
	return $name === '' ? 'attachment' : $name;
    }

    protected static function extensionFor($type) {
	$map = [
	    'text/plain' => '.txt', 'text/html' => '.html', 'text/calendar' => '.ics',
	    'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif',
	    'application/pdf' => '.pdf', 'message/rfc822' => '.eml',
	];
	return $map[$type] ?? '.bin';
    }
}
