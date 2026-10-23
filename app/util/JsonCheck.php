<?php

/*
    CLD: 2026-10-23T12:30:00+03:00: new: the syntax of a JSON text that may hold {{ }} expressions (the HTTP Request step's body), for util\Expression::check (syntaxes json / jsonrpc)
    and the step's save check:
	\util\JsonCheck::problems($text, $rpc)    [ [ severity error|warning, message, start, end ] ] - byte offsets in $text
    A {{ }} part outside a string stands for a value (as it runs: util\Expression::render gives objects and lists as JSON); inside a string it is part of the string.
    The first syntax error stops the check; warnings: a key repeated in an object. $rpc: the value must also be a JSON-RPC 2.0 request (an object, or a batch: a list of them):
	`jsonrpc`: "2.0", `method`: a text, `params` (optional): an object or a list, `id` (optional, none = a notification): a text, a number or null
    A text with an unclosed {{ is left to the expression check (nothing here). An empty text has no problems.
*/
namespace util;

class JsonCheck {
    private $text;
    private $len;
    private $at = 0;
    //{{ }} parts: start offset => offset after their }}
    private $parts = [];
    private $problems = [];

    private function __construct($text) {
	$this->text = $text;
	$this->len = strlen($text);
	foreach(\sys\J\Template::parts($text) as $p)
	    $this->parts[$p['open']] = $p['closed'] ? $p['end'] + strlen(\sys\J\Template::CLOSE) : null;
    }

    public static function problems($text, $rpc = false) {
	$text = (string)$text;
	if (trim($text) === '')
	    return [];
	$check = new self($text);
	if (in_array(null, $check->parts, true))
	    return [];
	try {
	    $value = $check->value();
	    $check->space();
	    if ($check->at < $check->len)
		$check->fail('Unexpected text after the JSON value', $check->at, $check->len);
	    if ($rpc)
		$check->rpc($value);
	} catch (JsonCheckError $e) {
	    $check->problems[] = [ 'severity' => 'error', 'message' => $e->getMessage(), 'start' => $e->start, 'end' => $e->end ];
	}
	return $check->problems;
    }

    // ------------------------------------------------------------------------------------
    //the JSON syntax: values as [ type object|array|string|number|literal|expression, start, end, members [ [ key, start, end, value ] ] | items | value (a string without {{ }}) ]

    private function fail($message, $start, $end = null) {
	throw new JsonCheckError($message, $start, $end ?? min($start + 1, $this->len));
    }

    private function warn($message, $start, $end) {
	$this->problems[] = [ 'severity' => 'warning', 'message' => $message, 'start' => $start, 'end' => $end ];
    }

    private function space() {
	$this->at += strspn($this->text, " \t\r\n", $this->at);
    }

    private function value() {
	$this->space();
	$start = $this->at;
	if ($start >= $this->len)
	    $this->fail('A value is missing here (the text ends)', max(0, $this->len - 1), $this->len);
	if (isset($this->parts[$start])) {
	    $this->at = $this->parts[$start];
	    return [ 'type' => 'expression', 'start' => $start, 'end' => $this->at ];
	}
	$c = $this->text[$start];
	if ($c === '{')
	    return $this->object();
	if ($c === '[')
	    return $this->items();
	if ($c === '"')
	    return $this->string();
	if ($c === '-' || ctype_digit($c)) {
	    if (!preg_match('/\G-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?/', $this->text, $m, 0, $start))
		$this->fail('Not a number', $start);
	    $this->at += strlen($m[0]);
	    if ($this->at < $this->len && (ctype_alnum($this->text[$this->at]) || $this->text[$this->at] === '.'))
		$this->fail('Not a number', $start, $this->at + 1);
	    return [ 'type' => 'number', 'start' => $start, 'end' => $this->at ];
	}
	if (preg_match('/\G[A-Za-z_]\w*/', $this->text, $m, 0, $start)) {
	    if (!in_array($m[0], [ 'true', 'false', 'null' ], true))
		$this->fail("`{$m[0]}` is not a JSON value (texts are between double quotes; true, false, null)", $start, $start + strlen($m[0]));
	    $this->at += strlen($m[0]);
	    return [ 'type' => 'literal', 'start' => $start, 'end' => $this->at, 'value' => $m[0] ];
	}
	if ($c === "'")
	    $this->fail('JSON texts are between double quotes (")', $start);
	$this->fail('A value is expected here', $start);
    }

    private function object() {
	$start = $this->at++;
	$members = [];
	$seen = [];
	$this->space();
	if (($this->text[$this->at] ?? '') === '}') {
	    $this->at++;
	    return [ 'type' => 'object', 'start' => $start, 'end' => $this->at, 'members' => [] ];
	}
	while(true) {
	    $this->space();
	    $kStart = $this->at;
	    if ($kStart >= $this->len)
		$this->fail('The object is not closed (`}` missing)', $start);
	    //a {{ }} key: its text, as it runs
	    if (isset($this->parts[$kStart])) {
		$this->at = $this->parts[$kStart];
		$key = null;
	    } elseif ($this->text[$kStart] === '"') {
		$key = $this->string()['value'];
	    } elseif ($this->text[$kStart] === '}') {
		$this->fail('A comma before `}` (trailing comma)', $kStart);
	    } else {
		$this->fail('A key between double quotes is expected here', $kStart);
	    }
	    $kEnd = $this->at;
	    if ($key !== null) {
		if (isset($seen[$key]))
		    $this->warn("The key `{$key}` is repeated (the last one wins)", $kStart, $kEnd);
		$seen[$key] = true;
	    }
	    $this->space();
	    if (($this->text[$this->at] ?? '') !== ':')
		$this->fail('`:` is expected after the key', $this->at < $this->len ? $this->at : $kStart);
	    $this->at++;
	    $members[] = [ 'key' => $key, 'start' => $kStart, 'end' => $kEnd, 'value' => $this->value() ];
	    $this->space();
	    $c = $this->text[$this->at] ?? '';
	    if ($c === ',') {
		$this->at++;
		continue;
	    }
	    if ($c === '}') {
		$this->at++;
		return [ 'type' => 'object', 'start' => $start, 'end' => $this->at, 'members' => $members ];
	    }
	    $this->fail($c === '' ? 'The object is not closed (`}` missing)' : '`,` or `}` is expected here', $c === '' ? $start : $this->at);
	}
    }

    private function items() {
	$start = $this->at++;
	$items = [];
	$this->space();
	if (($this->text[$this->at] ?? '') === ']') {
	    $this->at++;
	    return [ 'type' => 'array', 'start' => $start, 'end' => $this->at, 'items' => [] ];
	}
	while(true) {
	    $this->space();
	    if (($this->text[$this->at] ?? '') === ']')
		$this->fail('A comma before `]` (trailing comma)', $this->at);
	    if ($this->at >= $this->len)
		$this->fail('The list is not closed (`]` missing)', $start);
	    $items[] = $this->value();
	    $this->space();
	    $c = $this->text[$this->at] ?? '';
	    if ($c === ',') {
		$this->at++;
		continue;
	    }
	    if ($c === ']') {
		$this->at++;
		return [ 'type' => 'array', 'start' => $start, 'end' => $this->at, 'items' => $items ];
	    }
	    $this->fail($c === '' ? 'The list is not closed (`]` missing)' : '`,` or `]` is expected here', $c === '' ? $start : $this->at);
	}
    }

    //a string; its value (null when it holds {{ }} parts: known when it runs)
    private function string() {
	$start = $this->at++;
	$value = '';
	$expr = false;
	while(true) {
	    if ($this->at >= $this->len)
		$this->fail('The text is not closed (`"` missing)', $start);
	    if (isset($this->parts[$this->at])) {
		$this->at = $this->parts[$this->at];
		$expr = true;
		continue;
	    }
	    $c = $this->text[$this->at];
	    if ($c === '"') {
		$this->at++;
		break;
	    }
	    if ($c === "\n" || $c === "\r")
		$this->fail('A line break in a text (write it as \n)', $this->at);
	    if (ord($c) < 0x20)
		$this->fail('A control character in a text (write it escaped)', $this->at);
	    if ($c === '\\') {
		$n = $this->text[$this->at + 1] ?? '';
		if ($n === 'u') {
		    if (!preg_match('/\G[0-9a-fA-F]{4}/', $this->text, $m, 0, $this->at + 2))
			$this->fail('\u takes 4 hexadecimal digits', $this->at, min($this->at + 6, $this->len));
		    $value .= json_decode('"\u' . $m[0] . '"') ?? '';
		    $this->at += 6;
		    continue;
		}
		$escapes = [ '"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t" ];
		if (!isset($escapes[$n]))
		    $this->fail('Not a JSON escape (\" \\\\ \/ \b \f \n \r \t \uXXXX)', $this->at, min($this->at + 2, $this->len));
		$value .= $escapes[$n];
		$this->at += 2;
		continue;
	    }
	    $value .= $c;
	    $this->at++;
	}
	return [ 'type' => 'string', 'start' => $start, 'end' => $this->at, 'value' => $expr ? null : $value ];
    }

    // ------------------------------------------------------------------------------------
    //JSON-RPC 2.0

    private function rpc(array $v) {
	if ($v['type'] === 'expression')
	    return;
	if ($v['type'] === 'array') {
	    if (empty($v['items']))
		$this->fail('A JSON-RPC batch holds at least one request', $v['start'], $v['end']);
	    foreach($v['items'] as $item)
		$this->request($item);
	    return;
	}
	$this->request($v);
    }

    private function request(array $v) {
	if ($v['type'] === 'expression')
	    return;
	if ($v['type'] !== 'object')
	    $this->fail('A JSON-RPC request is an object { "jsonrpc": "2.0", "method": ..., "params": ..., "id": ... } (or a list of them: a batch)', $v['start'], $v['end']);
	$m = [];
	foreach($v['members'] as $member) {
	    if ($member['key'] !== null)
		$m[$member['key']] = $member;
	}
	$isExpr = fn($x) => $x['type'] === 'expression' || ($x['type'] === 'string' && $x['value'] === null);

	if (!isset($m['jsonrpc']))
	    $this->fail('"jsonrpc": "2.0" is missing', $v['start']);
	$x = $m['jsonrpc']['value'];
	if (!$isExpr($x) && ($x['type'] !== 'string' || $x['value'] !== '2.0'))
	    $this->fail('"jsonrpc" must be "2.0"', $x['start'], $x['end']);

	if (!isset($m['method']))
	    $this->fail('"method" (the name of the method called) is missing', $v['start']);
	$x = $m['method']['value'];
	if (!$isExpr($x)) {
	    if ($x['type'] !== 'string')
		$this->fail('"method" is a text: the name of the method called', $x['start'], $x['end']);
	    if (trim($x['value']) === '')
		$this->fail('"method" is empty', $x['start'], $x['end']);
	    if (str_starts_with($x['value'], 'rpc.'))
		$this->warn('Methods starting with `rpc.` are reserved by JSON-RPC', $x['start'], $x['end']);
	}

	if (isset($m['params'])) {
	    $x = $m['params']['value'];
	    if ($x['type'] !== 'expression' && !in_array($x['type'], [ 'object', 'array' ], true))
		$this->fail('"params" is an object (by name) or a list (by position)', $x['start'], $x['end']);
	}

	if (isset($m['id'])) {
	    $x = $m['id']['value'];
	    if ($x['type'] === 'literal' && $x['value'] === 'null')
		$this->warn('An `id` of null is discouraged (leave `id` out for a notification)', $x['start'], $x['end']);
	    elseif (!in_array($x['type'], [ 'expression', 'string', 'number' ], true))
		$this->fail('"id" is a text or a number', $x['start'], $x['end']);
	} else {
	    $this->warn('No "id": a notification, the server sends no answer', $v['start'], $v['start'] + 1);
	}

	foreach($m as $key => $member) {
	    if (!in_array($key, [ 'jsonrpc', 'method', 'params', 'id' ], true))
		$this->warn("`{$key}` is not a member of a JSON-RPC 2.0 request", $member['start'], $member['end']);
	}
    }
}

//the first syntax error: its message and place (byte offsets)
class JsonCheckError extends \Exception {
    public $start;
    public $end;

    public function __construct($message, $start, $end) {
	parent::__construct($message);
	$this->start = $start;
	$this->end = max($end, $start);
    }
}
