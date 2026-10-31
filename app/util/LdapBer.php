<?php

/*

    the BER encoding of LDAP (RFC 4511: the subset of X.690 it uses: definite lengths, primitive strings) and the search filters
    (RFC 4515), for the pure-PHP client (LdapPhpClient) and the check suite's test server (tests/LdapTestServer.php).

    encode:   LdapBer::seq(LdapBer::int(1), LdapBer::str('x'), ...); tlv($tag, $content) for any tag (0x60 = [APPLICATION 0] constructed, 0x80 = [0] primitive, ...)
    decode:   [ $tag, $content ] = LdapBer::read($data, $offset) (one element, $offset moved past it); children($content) = its elements; messageLength($buffer)
        	= the bytes of the first complete element (null: not complete yet)
    filters:  parseFilter('(&(uid=a*)(!(cn=x)))') = a tree [ 'and', [ ... ] ]; filter($text) = its BER; decodeFilter($tag, $content) = the tree of a BER filter
        	trees: [ 'and'|'or', [ nodes ] ], [ 'not', node ], [ 'eq'|'ge'|'le'|'approx', attr, value ], [ 'present', attr ],
        	[ 'sub', attr, initial|null, [ any ], final|null ], [ 'ext', rule|null, attr|null, value, dnAttributes ]
    Malformed input: \InvalidArgumentException (a filter: its message says where), a broken answer: LdapException (no connection code)
*/

namespace util;

class LdapBer {
    const BOOLEAN = 0x01;
    const INTEGER = 0x02;
    const OCTET_STRING = 0x04;
    const NULL = 0x05;
    const ENUMERATED = 0x0a;
    const SEQUENCE = 0x30;
    const SET = 0x31;

    //the filter choices (RFC 4511 4.5.1): constructed context tags, `present` primitive
    const F_AND = 0xa0;
    const F_OR = 0xa1;
    const F_NOT = 0xa2;
    const F_EQ = 0xa3;
    const F_SUB = 0xa4;
    const F_GE = 0xa5;
    const F_LE = 0xa6;
    const F_PRESENT = 0x87;
    const F_APPROX = 0xa8;
    const F_EXT = 0xa9;

    //the largest element read (an answer larger than this is refused)
    const MAX_LENGTH = 64 * 1024 * 1024;

    // ------------------------------------------------------------------------------------
    //encoding

    public static function length($n) {
	if ($n < 0x80)
	    return chr($n);
	$bytes = '';
	while ($n > 0) {
	    $bytes = chr($n & 0xff) . $bytes;
	    $n >>= 8;
	}
	return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function tlv($tag, $content) {
	return chr($tag) . self::length(strlen($content)) . $content;
    }

    //an integer, two's complement in the fewest bytes
    public static function int($value, $tag = self::INTEGER) {
	$value = (int)$value;
	$bytes = '';
	if ($value >= 0) {
	    do {
		$bytes = chr($value & 0xff) . $bytes;
		$value >>= 8;
	    } while ($value > 0);
	    if (ord($bytes[0]) & 0x80)
		$bytes = "\x00" . $bytes;
	} else {
	    do {
		$bytes = chr($value & 0xff) . $bytes;
		$value >>= 8;
	    } while ($value < -1);
	    if (!(ord($bytes[0]) & 0x80))
		$bytes = "\xff" . $bytes;
	}
	return self::tlv($tag, $bytes);
    }

    public static function enum($value) {
	return self::int($value, self::ENUMERATED);
    }

    public static function str($value, $tag = self::OCTET_STRING) {
	return self::tlv($tag, (string)$value);
    }

    public static function bool($value, $tag = self::BOOLEAN) {
	return self::tlv($tag, $value ? "\xff" : "\x00");
    }

    public static function seq(...$parts) {
	return self::tlv(self::SEQUENCE, implode('', $parts));
    }

    public static function set(...$parts) {
	return self::tlv(self::SET, implode('', $parts));
    }

    // ------------------------------------------------------------------------------------
    //decoding

    //the bytes of the first complete element of $buffer, null when it is not all there yet
    public static function messageLength($buffer) {
	$size = strlen($buffer);
	if ($size < 2)
	    return null;
	$first = ord($buffer[1]);
	if ($first < 0x80)
	    return 2 + $first <= $size ? 2 + $first : null;
	$n = $first & 0x7f;
	if ($n === 0 || $n > 4)
	    throw new LdapException(LdapException::NO_CONNECTION, 'a broken answer from the server (length)');
	if ($size < 2 + $n)
	    return null;
	$len = 0;
	for ($i = 0; $i < $n; $i++)
	    $len = ($len << 8) | ord($buffer[2 + $i]);
	if ($len > self::MAX_LENGTH)
	    throw new LdapException(LdapException::NO_CONNECTION, 'an answer larger than ' . (self::MAX_LENGTH >> 20) . ' MB');
	return 2 + $n + $len <= $size ? 2 + $n + $len : null;
    }

    //the element at $offset: [ tag, content ]; $offset moves past it
    public static function read($data, &$offset) {
	$size = strlen($data);
	if ($offset + 2 > $size)
	    throw new LdapException(LdapException::NO_CONNECTION, 'a broken answer from the server (truncated)');
	$tag = ord($data[$offset]);
	$first = ord($data[$offset + 1]);
	$offset += 2;
	if ($first < 0x80) {
	    $len = $first;
	} else {
	    $n = $first & 0x7f;
	    if ($n === 0 || $n > 4 || $offset + $n > $size)
		throw new LdapException(LdapException::NO_CONNECTION, 'a broken answer from the server (length)');
	    $len = 0;
	    for ($i = 0; $i < $n; $i++)
		$len = ($len << 8) | ord($data[$offset + $i]);
	    $offset += $n;
	}
	if ($offset + $len > $size)
	    throw new LdapException(LdapException::NO_CONNECTION, 'a broken answer from the server (truncated)');
	$content = (string)substr($data, $offset, $len);
	$offset += $len;
	return [ $tag, $content ];
    }

    //the elements inside a constructed element's content: [ [ tag, content ], ... ]
    public static function children($content) {
	$items = [];
	$offset = 0;
	$size = strlen($content);
	while ($offset < $size)
	    $items[] = self::read($content, $offset);
	return $items;
    }

    public static function toInt($content) {
	$len = strlen($content);
	if ($len === 0)
	    return 0;
	$value = ord($content[0]);
	if ($value & 0x80)
	    $value -= 0x100;
	for ($i = 1; $i < $len; $i++)
	    $value = ($value << 8) | ord($content[$i]);
	return $value;
    }

    // ------------------------------------------------------------------------------------
    //filters (RFC 4515)

    //the BER of filter text $text (the outer parentheses may be left out: uid=jdoe)
    public static function filter($text) {
	return self::encodeFilter(self::parseFilter($text));
    }

    public static function parseFilter($text) {
	$text = trim((string)$text);
	if ($text === '')
	    throw new \InvalidArgumentException('the filter is empty');
	if ($text[0] !== '(')
	    $text = '(' . $text . ')';
	$pos = 0;
	$tree = self::parseNode($text, $pos);
	if ($pos !== strlen($text))
	    throw new \InvalidArgumentException('the filter has text after its end, at character ' . ($pos + 1) . ' (unbalanced parentheses?)');
	return $tree;
    }

    protected static function parseNode($text, &$pos) {
	if (($text[$pos] ?? '') !== '(')
	    throw new \InvalidArgumentException('the filter needs ( at character ' . ($pos + 1));
	$pos++;
	$c = $text[$pos] ?? '';
	if ($c === '&' || $c === '|') {
	    $pos++;
	    $items = [];
	    while (($text[$pos] ?? '') === '(')
		$items[] = self::parseNode($text, $pos);
	    $node = [ $c === '&' ? 'and' : 'or', $items ];
	} elseif ($c === '!') {
	    $pos++;
	    $node = [ 'not', self::parseNode($text, $pos) ];
	} else {
	    $end = $pos;
	    $len = strlen($text);
	    //the item runs to the first `)` (one inside a value is escaped as \29)
	    while ($end < $len && $text[$end] !== ')' && $text[$end] !== '(')
		$end++;
	    if ($end >= $len || $text[$end] !== ')')
		throw new \InvalidArgumentException('the filter item at character ' . ($pos + 1) . ' is not closed with )');
	    $node = self::parseItem(substr($text, $pos, $end - $pos), $pos);
	    $pos = $end;
	}
	if (($text[$pos] ?? '') !== ')')
	    throw new \InvalidArgumentException('the filter needs ) at character ' . ($pos + 1));
	$pos++;
	return $node;
    }

    protected static function parseItem($item, $at) {
	$where = ' at character ' . ($at + 1);
	//extensible: attr[:dn][:rule]:=value, or :[dn:]rule:=value
	if (preg_match('/^([A-Za-z0-9][A-Za-z0-9.;-]*)?((?::dn)?)(?::([A-Za-z0-9][A-Za-z0-9.-]*))?:=(.*)$/s', $item, $m) && ($m[1] !== '' || ($m[3] ?? '') !== '')) {
	    return [ 'ext', ($m[3] ?? '') !== '' ? $m[3] : null, $m[1] !== '' ? $m[1] : null, self::unescape($m[4], $where), strtolower($m[2]) === ':dn' ];
	}
	if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9.;-]*)(~=|>=|<=|=)(.*)$/s', $item, $m))
	    throw new \InvalidArgumentException("not a filter item: `{$item}`{$where} (attribute=value)");
	[ , $attr, $op, $value ] = $m;
	if ($op === '=') {
	    if ($value === '*')
		return [ 'present', $attr ];
	    if (strpos($value, '*') !== false) {
		$parts = explode('*', $value);
		$initial = array_shift($parts);
		$final = array_pop($parts);
		return [ 'sub', $attr, $initial === '' ? null : self::unescape($initial, $where), array_map(fn($p) => self::unescape($p, $where), array_values(array_filter($parts, fn($p) => $p !== ''))),
		    $final === '' ? null : self::unescape($final, $where) ];
	    }
	    return [ 'eq', $attr, self::unescape($value, $where) ];
	}
	return [ $op === '>=' ? 'ge' : ($op === '<=' ? 'le' : 'approx'), $attr, self::unescape($value, $where) ];
    }

    //a filter value's text: \XX = byte XX
    protected static function unescape($value, $where = '') {
	return preg_replace_callback('/\\\\([0-9A-Fa-f]{2})|\\\\/', function($m) use ($where) {
	    if (!isset($m[1]))
		throw new \InvalidArgumentException('a \\ in the filter must be followed by 2 hex digits (\\2a for *, \\28 for (, \\5c for \\)' . $where);
	    return chr(hexdec($m[1]));
	}, $value);
    }

    public static function encodeFilter(array $node) {
	switch($node[0]) {
	    case 'and':
	    case 'or':
		return self::tlv($node[0] === 'and' ? self::F_AND : self::F_OR, implode('', array_map([ self::class, 'encodeFilter' ], $node[1])));
	    case 'not':
		return self::tlv(self::F_NOT, self::encodeFilter($node[1]));
	    case 'eq':
	    case 'ge':
	    case 'le':
	    case 'approx':
		$tag = [ 'eq' => self::F_EQ, 'ge' => self::F_GE, 'le' => self::F_LE, 'approx' => self::F_APPROX ][$node[0]];
		return self::tlv($tag, self::str($node[1]) . self::str($node[2]));
	    case 'present':
		return self::str($node[1], self::F_PRESENT);
	    case 'sub':
		$parts = '';
		if ($node[2] !== null)
		    $parts .= self::str($node[2], 0x80);
		foreach($node[3] as $any)
		    $parts .= self::str($any, 0x81);
		if ($node[4] !== null)
		    $parts .= self::str($node[4], 0x82);
		return self::tlv(self::F_SUB, self::str($node[1]) . self::seq($parts));
	    case 'ext':
		return self::tlv(self::F_EXT, ($node[1] !== null ? self::str($node[1], 0x81) : '') . ($node[2] !== null ? self::str($node[2], 0x82) : '')
		    . self::str($node[3], 0x83) . ($node[4] ? self::bool(true, 0x84) : ''));
	}
	throw new \InvalidArgumentException('unknown filter node ' . $node[0]);
    }

    //the tree of a BER filter (the test server reads the clients' filters)
    public static function decodeFilter($tag, $content) {
	switch($tag) {
	    case self::F_AND:
	    case self::F_OR:
		return [ $tag === self::F_AND ? 'and' : 'or', array_map(fn($c) => self::decodeFilter($c[0], $c[1]), self::children($content)) ];
	    case self::F_NOT:
		$c = self::children($content)[0] ?? null;
		if (!$c)
		    throw new \InvalidArgumentException('an empty not filter');
		return [ 'not', self::decodeFilter($c[0], $c[1]) ];
	    case self::F_EQ:
	    case self::F_GE:
	    case self::F_LE:
	    case self::F_APPROX:
		$c = self::children($content);
		return [ [ self::F_EQ => 'eq', self::F_GE => 'ge', self::F_LE => 'le', self::F_APPROX => 'approx' ][$tag], $c[0][1] ?? '', $c[1][1] ?? '' ];
	    case self::F_PRESENT:
		return [ 'present', $content ];
	    case self::F_SUB:
		$c = self::children($content);
		$initial = $final = null;
		$any = [];
		foreach(self::children($c[1][1] ?? '') as [ $t, $v ]) {
		    if ($t === 0x80)
			$initial = $v;
		    elseif ($t === 0x81)
			$any[] = $v;
		    elseif ($t === 0x82)
			$final = $v;
		}
		return [ 'sub', $c[0][1] ?? '', $initial, $any, $final ];
	    case self::F_EXT:
		$rule = $attr = null;
		$value = '';
		$dn = false;
		foreach(self::children($content) as [ $t, $v ]) {
		    if ($t === 0x81)
			$rule = $v;
		    elseif ($t === 0x82)
			$attr = $v;
		    elseif ($t === 0x83)
			$value = $v;
		    elseif ($t === 0x84)
			$dn = $v !== "\x00";
		}
		return [ 'ext', $rule, $attr, $value, $dn ];
	}
	throw new \InvalidArgumentException(sprintf('unknown filter tag 0x%02x', $tag));
    }
}
