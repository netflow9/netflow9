<?php

namespace util;

class JSON {

    // <, >, &, ', " are written as \u00XX escapes, so the output cannot close a <script> or an attribute/string;
    // invalid UTF-8 is dropped instead of failing the whole encode
    const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_IGNORE;

    // encode $value with FLAGS (plus any $flags given)
    public static function encode($value, $flags = 0) {
	return json_encode($value, self::FLAGS | $flags);
    }

    //$text (JSON written by people) as a PHP array (an object or a list); tolerates keys without quotes, strings in single
    //quotes, a comma after the last item, // and /* */ comments, True / False / None; anything else that is not JSON, or not an object / list, throws \ErrorException
    //CLD: 2026-10-20T22:45:00+03:00: $objects: objects as \stdClass (else arrays: `{}` and `[]` are the same); $any: any value at the top (a number, text, true / false / null;
    //else an object or a list only) - for J's JSON.parse
    public static function json_decode($text, $objects = false, $any = false) {
	if (!is_string($text) || trim($text) === '')
	    throw new \ErrorException('JSON: empty text');
	if (!mb_check_encoding($text, 'UTF-8'))
	    throw new \ErrorException('JSON: not UTF-8 text');

	$i = self::skip($text, 0);
	if (!$any && !in_array($text[$i] ?? '', [ '{', '[' ], true))
	    throw new \ErrorException('JSON: not an object or a list');
	$value = self::parseValue($text, $i, 0, $objects);
	$i = self::skip($text, $i);
	if ($i < strlen($text))
	    throw new \ErrorException('JSON: text after the end at ' . self::position($text, $i));
	return $value;
    }

    //the deepest nesting of objects / lists (2 calls a level: 512, as json_decode's, reached Xdebug's limit of 1024 frames)
    const MAX_DEPTH = 128;

    //the value at $i (white space and comments skipped first); $i is moved past it
    private static function parseValue($text, &$i, $depth, $objects = false) {
	$i = self::skip($text, $i);
	$c = $text[$i] ?? '';

	if ($c === '{' || $c === '[') {
	    if ($depth >= self::MAX_DEPTH)
		throw new \ErrorException('JSON: nested deeper than ' . self::MAX_DEPTH . ' at ' . self::position($text, $i));
	    return $c === '{' ? self::parseObject($text, $i, $depth + 1, $objects) : self::parseList($text, $i, $depth + 1, $objects);
	}

	if ($c === '"' || $c === "'")
	    return self::parseString($text, $i);

	if (preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $text, $m, 0, $i)) {
	    $i += strlen($m[0]);
	    $int = strpbrk($m[0], '.eE') === false ? filter_var($m[0], FILTER_VALIDATE_INT) : false;
	    return $int !== false ? $int : (float)$m[0];
	}

	if (preg_match('/\G[A-Za-z_$\x80-\xff][\w$\x80-\xff]*/', $text, $m, 0, $i)) {
	    $word = $m[0];
	    $literals = [ 'true' => true, 'false' => false, 'null' => null, 'none' => null ];
	    if (!array_key_exists(strtolower($word), $literals) || (strtolower($word) === 'none' && $word !== 'None'))
		throw new \ErrorException("JSON: `{$word}` is not a value (text in quotes?) at " . self::position($text, $i));
	    $i += strlen($word);
	    return $literals[strtolower($word)];
	}

	throw new \ErrorException(($c === '' ? 'JSON: the text ends where a value is expected' : 'JSON: a value is expected') . ' at ' . self::position($text, $i));
    }

    //{ key: value, ... } at $i: a key in quotes or a word; a comma after the last member
    private static function parseObject($text, &$i, $depth, $objects = false) {
	$object = [];
	$i++;
	while (true) {
	    $i = self::skip($text, $i);
	    $c = $text[$i] ?? '';
	    if ($c === '}') {
		$i++;
		return $objects ? (object)$object : $object;
	    }

	    if ($c === '"' || $c === "'") {
		$key = self::parseString($text, $i);
	    } elseif (preg_match('/\G[\w$\x80-\xff]+/', $text, $m, 0, $i)) {
		$key = $m[0];
		$i += strlen($key);
	    } else {
		throw new \ErrorException(($c === '' ? 'JSON: the text ends before `}`' : 'JSON: a key or `}` is expected') . ' at ' . self::position($text, $i));
	    }

	    $i = self::skip($text, $i);
	    if (($text[$i] ?? '') !== ':')
		throw new \ErrorException("JSON: `:` is expected after the key `{$key}` at " . self::position($text, $i));
	    $i++;
	    $object[$key] = self::parseValue($text, $i, $depth, $objects);

	    $i = self::skip($text, $i);
	    $c = $text[$i] ?? '';
	    if ($c === ',') {
		$i++;
	    } elseif ($c !== '}') {
		throw new \ErrorException(($c === '' ? 'JSON: the text ends before `}`' : 'JSON: `,` or `}` is expected') . ' at ' . self::position($text, $i));
	    }
	}
    }

    //[ value, ... ] at $i; a comma after the last item
    private static function parseList($text, &$i, $depth, $objects = false) {
	$list = [];
	$i++;
	while (true) {
	    $i = self::skip($text, $i);
	    if (($text[$i] ?? '') === ']') {
		$i++;
		return $list;
	    }

	    $list[] = self::parseValue($text, $i, $depth, $objects);

	    $i = self::skip($text, $i);
	    $c = $text[$i] ?? '';
	    if ($c === ',') {
		$i++;
	    } elseif ($c !== ']') {
		throw new \ErrorException(($c === '' ? 'JSON: the text ends before `]`' : 'JSON: `,` or `]` is expected') . ' at ' . self::position($text, $i));
	    }
	}
    }

    //the string in double or single quotes at $i, its escapes decoded (\uXXXX, surrogate pairs included); $i is moved past the closing quote
    private static function parseString($text, &$i) {
	$start = $i;
	$quote = $text[$i];
	$n = strlen($text);
	$escapes = [ '"' => '"', "'" => "'", '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t" ];
	$out = '';
	for ($i++; $i < $n; $i++) {
	    $c = $text[$i];
	    if ($c === $quote) {
		$i++;
		return $out;
	    }
	    if ($c !== '\\') {
		$out .= $c;
		continue;
	    }

	    $e = $text[$i + 1] ?? '';
	    if (isset($escapes[$e])) {
		$out .= $escapes[$e];
		$i++;
		continue;
	    }
	    if ($e !== 'u')
		throw new \ErrorException("JSON: `\\{$e}` is not an escape at " . self::position($text, $i));

	    $code = self::hex4($text, $i);
	    $i += 5;
	    //a surrogate pair: \uD800-\uDBFF followed by \uDC00-\uDFFF
	    if ($code >= 0xD800 && $code <= 0xDBFF) {
		$low = substr($text, $i + 1, 2) === '\\u' ? self::hex4($text, $i + 1) : null;
		if ($low === null || $low < 0xDC00 || $low > 0xDFFF)
		    throw new \ErrorException('JSON: a lone surrogate `\\u' . sprintf('%04X', $code) . '` at ' . self::position($text, $i - 5));
		$code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
		$i += 6;
	    } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
		throw new \ErrorException('JSON: a lone surrogate `\\u' . sprintf('%04X', $code) . '` at ' . self::position($text, $i - 5));
	    }
	    $out .= mb_chr($code, 'UTF-8');
	}
	throw new \ErrorException('JSON: a string not closed, opened at ' . self::position($text, $start));
    }

    //the code of the \uXXXX at $i (the backslash)
    private static function hex4($text, $i) {
	$hex = substr($text, $i + 2, 4);
	if (!preg_match('/^[0-9A-Fa-f]{4}$/', $hex))
	    throw new \ErrorException('JSON: `\\u` needs 4 hex digits at ' . self::position($text, $i));
	return hexdec($hex);
    }

    //from $i, the position of the next character that is not white space or a comment
    private static function skip($text, $i) {
	$n = strlen($text);
	while ($i < $n) {
	    if (ctype_space($text[$i])) {
		$i++;
	    } elseif ($text[$i] === '/' && ($text[$i + 1] ?? '') === '/') {
		$end = strpos($text, "\n", $i);
		$i = $end === false ? $n : $end + 1;
	    } elseif ($text[$i] === '/' && ($text[$i + 1] ?? '') === '*') {
		$end = strpos($text, '*/', $i + 2);
		if ($end === false)
		    throw new \ErrorException('JSON: a comment not closed, opened at ' . self::position($text, $i));
		$i = $end + 2;
	    } else {
		break;
	    }
	}
	return $i;
    }

    //"line L, column C" of the byte $i
    private static function position($text, $i) {
	$before = substr($text, 0, $i);
	$line = substr_count($before, "\n") + 1;
	$column = $i - (int)strrpos("\n" . $before, "\n") + 1;
	return "line {$line}, column {$column}";
    }
}
