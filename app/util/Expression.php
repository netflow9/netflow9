<?php

/*
    expressions in texts, written between {{ and }} (e.g. "Hello {{ name.toUpperCase() }}"),
	in the J language (sys\J: a sandboxed JavaScript subset).
    For the live help of expression fields (WorkflowController::expression):
	check($text, $vars)                  problems: unclosed {{, J syntax errors, unknown names (warnings) - positions in characters of $text
	complete($text, $before, $vars)      suggestions at the cursor ($before = the text before it): variables, J built-ins, members after `name.`
//$vars: the variables available there, [ name => [ type => string|number|boolean|array|object|any, info => where it comes from ] ]
//CLD: 2026-10-13T12:34:29+03:00: a variable's type description may go deeper: fields => [ name => description ] (an object's fields), items => description (a list's items), e.g.
//mail: { type: object, fields: { subject: { type: string }, attachments: { type: array, items: { type: object, fields: { filename: ... } } } } }; typeAt() follows a path
//through it (suggestions after `mail.attachments[0].`, warnings for a field an object does not have)
//CLD: 2026-10-13T12:17:47+03:00: one parser for all: sys\J\Parser in mode `template` (the {{ }} parts found and parsed by J, each with offsets in the whole text), with the field's syntax
//(SYNTAX): `text` - text with any {{ expression }} parts (e.g. "INVOICE-{{ mail.date }}"); `reference` - only {{ variable }} or {{ variable.field }}, nothing around it
//(e.g. a condition's variable). check / complete / render take the same syntax, so the live help and the run agree

*/
namespace util;

class Expression {
    //CLD: 2026-10-13T12:17:47+03:00: the syntaxes of an expression field (smartform `expression`: true = text, or the name) -> J parse options
    const SYNTAX = [
	'text' => [ 'mode' => 'template', 'inner' => 'expression' ],
	'reference' => [ 'mode' => 'template', 'inner' => 'reference', 'whole' => true ],
	//CLD: 2026-10-13T12:43:20+03:00: one {{ expression }} and nothing around it (e.g. the Loop's list: {{ found.rows.filter(r => r.amount > 100) }})
	'value' => [ 'mode' => 'template', 'inner' => 'expression', 'whole' => true ],
	//CLD: 2026-10-13T15:30:33+03:00: a J script (the Code step: statements, no {{ }}; names it assigns become workflow variables)
	'script' => [ 'mode' => 'script' ],
	//CLD: 2026-10-23T12:30:00+03:00: a JSON text with any {{ }} parts (the HTTP Request's body), its JSON syntax checked too (util\JsonCheck); jsonrpc: also a JSON-RPC 2.0 request
	'json' => [ 'mode' => 'template', 'inner' => 'expression' ],
	'jsonrpc' => [ 'mode' => 'template', 'inner' => 'expression' ],
    ];

    //suggestions returned at most
    const MAX_ITEMS = 50;

    //CLD: 2026-10-20T21:40:00+03:00: the methods offered after `x.` come from J (sys\J\Builtins::METHODS; were listed here: STRING_METHODS, ARRAY_METHODS, NUMBER_METHODS)

    const LITERALS = [ 'true', 'false', 'null', 'undefined' ];

    //CLD: 2026-10-23T01:00:00+03:00: the app's own functions in every expression (functions()), with their help: name => text. A variable of the same name hides one
    const FUNCTIONS = [
	'markdown' => 'markdown(text): Markdown as safe HTML; in an email, {{ markdown(...) }} alone in its {{ }} shows it formatted (an AI step\'s answer: also {{ ai.html }})',
    ];

    //J's global names (built-ins) and their members, computed once per process
    private static $globals = null;

    //CLD: 2026-10-25T21:00:00+02:00: the parse results kept in the process (parse()): syntax + "\0" + text => \sys\J\ParseResult (immutable: shared by its users); at most
    //PARSED_MAX, the oldest dropped first. A request checks each text once (validateSteps parsed a Code step's code 2-3 times), a step in a loop parses its texts once per run
    //(were parsed at each item: ~60% of render()'s time), a daemon worker keeps them between its jobs
    const PARSED_MAX = 500;
    private static $parsed = [];

    //CLD: 2026-10-26T22:34:16+02:00: the names each parse result uses (usedNames()): \WeakMap \sys\J\ParseResult => [ name => true ]; dropped with the parse result
    private static $used = null;

    //CLD: 2026-10-13T12:17:47+03:00: $text parsed with the J parse options of $syntax (a key of SYNTAX; else text)
    //CLD: 2026-10-25T21:00:00+02:00: kept in the process ($parsed)
    public static function parse($text, $syntax = 'text') {
	$syntax = isset(self::SYNTAX[$syntax]) ? $syntax : 'text';
	$key = $syntax . "\0" . (string)$text;
	if (isset(self::$parsed[$key]))
	    return self::$parsed[$key];

	if (count(self::$parsed) >= self::PARSED_MAX)
	    unset(self::$parsed[array_key_first(self::$parsed)]);
	return self::$parsed[$key] = \sys\J\Parser::parse((string)$text, self::SYNTAX[$syntax]);
    }

    //limits of one expression (J interpreter)
    //CLD: 2026-10-20T21:40:00+03:00: + dateFormat: the dates an expression gives (and those in {{ }} text) as ISO 8601 text in local time, as hostValue gave them before they became J Dates
    const LIMITS = [
	'maxSteps' => 100000,
	'timeLimit' => 1.0,
	'dateFormat' => DATE_ATOM
    ];

    //$text with its {{ }} parts evaluated against $vars (the execution's variables, the expressions' names): a text that is a single expression
    //(`{{ x }}`, spaces around allowed) gives its value as it is (number, list, object, ...); else the parts become text (lists and objects as JSON, null /
    //undefined as nothing) inside the text. A text without {{ is returned unchanged. \ErrorException for a broken or failing expression
    //CLD: 2026-10-13T12:17:47+03:00: parsed and run by J as a template (Parser mode `template`, Interpreter::evalTemplate; were the parts found here and run one by one), in the syntax of
    //the field ($syntax, SYNTAX): a reference field refuses anything but {{ variable(.field) }}
    public static function render($text, array $vars, $syntax = 'text') {
	if (!is_string($text) || ($syntax === 'text' && !str_contains($text, \sys\J\Template::OPEN)))
	    return $text;
	$label = '`' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 60) . '`';
	$parsed = self::parse($text, $syntax);
	if ($parsed->hasErrors()) {
	    $e = $parsed->errors()[0];
	    throw new \ErrorException("Expression {$label}: {$e['message']} (column {$e['column']})");
	}

	//CLD: 2026-10-23T01:00:00+03:00: the app's functions first (a variable of the same name wins)
	//CLD: 2026-10-26T22:34:16+02:00: only the variables the expression names (usedNames) are converted and given to J (was all of them, at each render: ~60 ms with 1 MB of
	//variables; a Data Table upsert of 10 values in a loop of 800 items spent ~8 min on it). J reaches a variable only by its name (no globalThis / eval)
	$context = self::functions();
	$used = self::usedNames($parsed);
	foreach($vars as $name => $value) {
	    if (isset($used[$name]) && \util\Validator::isIdentifier((string)$name))
		$context[(string)$name] = self::hostValue($value, true);
	}
	$vm = new \sys\J\Interpreter(self::LIMITS);
	try {
	    return $vm->run($parsed, $context);
	} catch (\sys\J\ScriptError | \sys\J\LimitExceeded | \InvalidArgumentException $e) {
	    throw new \ErrorException("Expression {$label}: " . $e->getMessage());
	}
    }

    //an execution variable as J may take it: database values as plain ones (dates as ISO 8601 text, ids as text, BSON documents / arrays as arrays)
    //CLD: 2026-10-20T21:40:00+03:00: $dates: dates stay dates (PHP DateTimeImmutable: a Date in J; for the J context); else ISO 8601 text (JSON output: MCP tools, data table rows)
    public static function hostValue($v, $dates = false) {
	if ($v instanceof \MongoDB\BSON\UTCDateTime) {
	    $d = \DateTimeImmutable::createFromInterface($v->toDateTime())->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	    return $dates ? $d : $d->format(DATE_ATOM);
	}
	if ($v instanceof \MongoDB\BSON\ObjectId)
	    return (string)$v;
	if ($v instanceof \ArrayObject)
	    $v = $v->getArrayCopy();
	if (is_array($v))
	    return array_map(fn($x) => self::hostValue($x, $dates), $v);
	if (is_object($v) && !($v instanceof \stdClass) && !($v instanceof \DateTimeInterface))
	    return (string)json_encode($v);
	return $v;
    }

    //a value as text (If / Switch compare texts): lists and objects as JSON, null as empty, booleans as true / false
    public static function toText($v) {
	if ($v === null)
	    return '';
	if (is_bool($v))
	    return $v ? 'true' : 'false';
	if (is_array($v) || is_object($v))
	    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	if (is_float($v))
	    return \sys\J\Runtime\Values::toString($v);
	return (string)$v;
    }

    // ------------------------------------------------------------------------------------
    //checking

    //the problems of $text: [ [ severity error|warning, message, start, end (characters), line, column (1-based) ] ]
    //CLD: 2026-10-13T12:17:47+03:00: J's diagnostics of $text in the field's syntax ($syntax: SYNTAX; the parser reports unclosed / empty {{ }}, statements, and for a reference anything but a
    //variable or its fields), the first at a place only; then the unknown names (warnings)
    public static function check($text, array $vars = [], $syntax = 'text') {
	$text = (string)$text;
	$parsed = self::parse($text, $syntax);
	$problems = [];
	$seen = [];
	foreach($parsed->diagnostics as $d) {
	    if (isset($seen[$d['start']]))
		continue;
	    $seen[$d['start']] = true;
	    $problems[] = self::problem($text, $d['severity'], (string)$d['message'], (int)$d['start'], max((int)$d['end'], (int)$d['start'] + 1));
	}
	if ($parsed->hasErrors())
	    return $problems;
	//CLD: 2026-10-23T12:30:00+03:00: json / jsonrpc: the JSON syntax (and the JSON-RPC request)
	if ($syntax === 'json' || $syntax === 'jsonrpc')
	    $problems = array_merge($problems, self::checkJson($text, $syntax === 'jsonrpc'));

	//names that are neither variables nor built-ins nor declared in the expressions
	//CLD: 2026-10-23T01:00:00+03:00: + the app's functions
	$known = array_merge(array_keys($vars), array_keys(self::globals()), array_keys(self::FUNCTIONS), self::LITERALS);
	//CLD: 2026-10-13T15:30:33+03:00: a script: the names it assigns (they become workflow variables) are known
	if ($syntax === 'script')
	    $known = array_merge($known, self::assignedNames($parsed->ast));
	foreach(self::unknownNames($parsed->ast, $known) as [ $name, $start, $end ])
	    $problems[] = self::problem($text, 'warning', "Unknown variable `{$name}`", $start, $end);
	//CLD: 2026-10-13T12:34:29+03:00: a field an object (of declared fields) does not have
	self::walk($parsed->ast, function($node) use (&$problems, $text, $vars) {
	    if (($node['type'] ?? '') !== 'MemberExpression' || !empty($node['computed']) || ($node['property']['name'] ?? '') === '')
		return;
	    $path = self::nodePath($node['object'] ?? []);
	    $t = $path !== null ? self::typeAt($vars, $path) : null;
	    if (!is_array($t) || ($t['type'] ?? '') !== 'object' || empty($t['fields']) || isset($t['fields'][$node['property']['name']]))
		return;
	    $of = substr($text, (int)$node['object']['start'], (int)$node['object']['end'] - (int)$node['object']['start']);
	    $problems[] = self::problem($text, 'warning', "`{$node['property']['name']}` is not a field of `{$of}`", (int)$node['property']['start'], (int)$node['property']['end']);
	});
	return $problems;
    }

    //CLD: 2026-10-23T12:30:00+03:00: the JSON problems of $text (util\JsonCheck; $rpc: a JSON-RPC 2.0 request), as check() gives them
    public static function checkJson($text, $rpc = false) {
	$text = (string)$text;
	return array_map(fn($p) => self::problem($text, $p['severity'], $p['message'], $p['start'], max($p['end'], $p['start'] + 1)), \util\JsonCheck::problems($text, $rpc));
    }

    private static function problem($text, $severity, $message, $byteStart, $byteEnd) {
	$byteEnd = min(max($byteEnd, $byteStart), strlen($text));
	$before = substr($text, 0, $byteStart);
	$lineStart = strrpos($before, "\n");
	$lineStart = $lineStart === false ? 0 : $lineStart + 1;
	return [
	    'severity' => $severity,
	    'message' => $message,
	    'start' => mb_strlen($before, 'UTF-8'),
	    'end' => mb_strlen(substr($text, 0, $byteEnd), 'UTF-8'),
	    'line' => substr_count($before, "\n") + 1,
	    'column' => mb_strlen(substr($before, $lineStart), 'UTF-8') + 1,
	];
    }

    //[ [ name, start, end ] ] of the names used in $ast that are not in $known and not declared in it (let / const / var, function names and parameters,
    //catch parameters); property names (a.b, { b: 1 }) are not names
    private static function unknownNames(array $ast, array $known) {
	$declared = [];
	self::walk($ast, function($node) use (&$declared) {
	    foreach([ 'id', 'param' ] as $k) {
		if (isset($node[$k]['type']) && $node[$k]['type'] === 'Identifier')
		    $declared[$node[$k]['name']] = true;
	    }
	    foreach(($node['params'] ?? []) as $param)
		self::collectPattern($param, $declared);
	});

	$unknown = [];
	$known = array_flip($known);
	self::walk($ast, function($node) use (&$unknown, $known, $declared) {
	    if (($node['type'] ?? '') !== 'Identifier' || !empty($node['__skip']))
		return;
	    $name = (string)($node['name'] ?? '');
	    if ($name === '' || isset($known[$name]) || isset($declared[$name]))
		return;
	    $unknown[] = [ $name, (int)$node['start'], (int)$node['end'] ];
	});
	return $unknown;
    }

    //CLD: 2026-10-13T15:30:33+03:00: the names a script assigns that it does not declare (`total = 1`, `count++`, `x += 2`): the workflow variables it sets (in the order first assigned)
    public static function assignedNames(array $ast) {
	$declared = [];
	self::walk($ast, function($node) use (&$declared) {
	    foreach([ 'id', 'param' ] as $k) {
		if (isset($node[$k]['type']) && $node[$k]['type'] === 'Identifier')
		    $declared[$node[$k]['name']] = true;
	    }
	    foreach(($node['params'] ?? []) as $param)
		self::collectPattern($param, $declared);
	});
	$names = [];
	self::walk($ast, function($node) use (&$names, $declared) {
	    $target = match($node['type'] ?? '') { 'AssignmentExpression' => $node['left'] ?? null, 'UpdateExpression' => $node['argument'] ?? null, default => null };
	    if (($target['type'] ?? '') === 'Identifier' && !isset($declared[$target['name']]))
		$names[$target['name']] = true;
	});
	return array_keys($names);
    }

    //CLD: 2026-10-26T22:34:16+02:00: [ name => true ] of the names $parsed refers to (property names are not; names it declares itself may be: a superset is enough for render()),
    //computed once per parse result
    private static function usedNames(\sys\J\ParseResult $parsed) {
	self::$used ??= new \WeakMap();
	if (!isset(self::$used[$parsed])) {
	    $names = [];
	    self::walk($parsed->ast, function($node) use (&$names) {
		if (($node['type'] ?? '') === 'Identifier' && empty($node['__skip']))
		    $names[(string)$node['name']] = true;
	    });
	    self::$used[$parsed] = $names;
	}
	return self::$used[$parsed];
    }

    private static function collectPattern($node, array &$declared) {
	if (!is_array($node))
	    return;
	switch($node['type'] ?? '') {
	    case 'Identifier': $declared[$node['name']] = true; break;
	    case 'AssignmentPattern': self::collectPattern($node['left'] ?? null, $declared); break;
	    case 'RestElement': self::collectPattern($node['argument'] ?? null, $declared); break;
	}
    }

    //calls $fn for every node of $node; marks the identifiers that are not references (property names, declared names) with __skip
    private static function walk(array $node, \Closure $fn) {
	if (isset($node['type'])) {
	    switch($node['type']) {
		case 'MemberExpression':
		    if (empty($node['computed']) && isset($node['property']))
			$node['property']['__skip'] = true;
		    break;
		case 'Property':
		    if (empty($node['computed']) && isset($node['key']) && empty($node['shorthand']))
			$node['key']['__skip'] = true;
		    elseif (!empty($node['shorthand']))
			$node['key']['__skip'] = true;    //its value is the same name, checked there
		    break;
		case 'VariableDeclarator':
		case 'FunctionDeclaration':
		case 'FunctionExpression':
		    if (isset($node['id']))
			$node['id']['__skip'] = true;
		    break;
		case 'CatchClause':
		    if (isset($node['param']))
			$node['param']['__skip'] = true;
		    break;
	    }
	    if (isset($node['params'])) {
		foreach($node['params'] as $i => $param) {
		    if (($param['type'] ?? '') === 'Identifier')
			$node['params'][$i]['__skip'] = true;
		}
	    }
	    $fn($node);
	}
	foreach($node as $key => $child) {
	    if (is_array($child) && $key !== 'loc')
		self::walk($child, $fn);
	}
    }

    // ------------------------------------------------------------------------------------
    //suggestions

    //the suggestions at the cursor of $text ($before: the text up to the cursor): [ replace => characters before the cursor the chosen item replaces,
    //items => [ [ label, kind variable|global|method|property|literal, detail ] ] ]; none outside {{ }} and inside strings or comments
    //CLD: 2026-10-13T12:17:47+03:00: $syntax (SYNTAX) as check(): a reference gets the variables and their fields only (no built-ins, literals or methods); the {{ }} parts found by J
    //(sys\J\Template; were found here)
    public static function complete($text, $before, array $vars = [], $syntax = 'text') {
	$reference = $syntax === 'reference';
	$none = [ 'replace' => 0, 'items' => [] ];
	$before = (string)$before;
	$cursor = strlen($before);

	//the expression the cursor is in
	//CLD: 2026-10-13T15:30:33+03:00: a script: all of it
	$part = null;
	foreach($syntax === 'script' ? [ [ 'start' => 0 ] ] : \sys\J\Template::parts($before . substr((string)$text, $cursor)) as $p) {
	    if ($p['start'] <= $cursor && (!isset($p['closed']) || !$p['closed'] || $p['end'] >= $cursor))
		$part = $p;
	}
	if ($part === null)
	    return $none;
	$inside = null;
	\sys\J\Template::scan($before, $part['start'], $cursor, $inside);
	if ($inside !== null)
	    return $none;
	$code = substr($before, $part['start']);

	//after `name.` (or `name?.`): its members
	//CLD: 2026-10-13T12:34:29+03:00: after a path (`mail.attachments[0].`): the members of the type found there (typeAt; was: of the last name's variable)
	if (preg_match('/(?<![\w$.\])])(' . self::PATH . ')\s*\??\.\s*([A-Za-z_$][\w$]*)?$/', $code, $m)) {
	    $prefix = $m[2] ?? '';
	    $path = self::path($m[1]);
	    $globals = self::globals();
	    if (count($path) == 1 && !isset($vars[$path[0]]) && isset($globals[$path[0]]))
		$items = array_map(fn($member) => [ 'label' => $member, 'kind' => 'property', 'detail' => $path[0] . '.' . $member ], $globals[$path[0]]);
	    else
		$items = self::members(self::typeAt($vars, $path));
	    if ($reference)
		$items = array_values(array_filter($items, fn($i) => $i['kind'] === 'property'));
	    return [ 'replace' => mb_strlen($prefix, 'UTF-8'), 'items' => self::filter($items, $prefix) ];
	}
	//after a number or `)` / `]` and a dot: nothing to suggest
	if (preg_match('/[\w)\]]\s*\.\s*\w*$/', $code))
	    return $none;

	//a name: variables, built-ins, literals
	preg_match('/([A-Za-z_$][\w$]*)$/', $code, $m);
	$prefix = $m[1] ?? '';
	if ($prefix === '' && preg_match('/\d$/', $code))
	    return $none;
	//a reference: a name only at its start
	if ($reference && trim(substr($code, 0, strlen($code) - strlen($prefix))) !== '')
	    return $none;
	$items = [];
	foreach($vars as $name => $v)
	    $items[] = [ 'label' => (string)$name, 'kind' => 'variable', 'detail' => trim(($v['type'] ?? 'any') . ' · ' . ($v['info'] ?? ''), ' ·') ];
	if ($reference)
	    return [ 'replace' => mb_strlen($prefix, 'UTF-8'), 'items' => self::filter($items, $prefix) ];
	foreach(self::globals() as $name => $members)
	    $items[] = [ 'label' => $name, 'kind' => 'global', 'detail' => $members ? 'built-in object' : 'built-in' ];
	//CLD: 2026-10-23T01:00:00+03:00: the app's functions
	foreach(self::FUNCTIONS as $name => $help)
	    if (!isset($vars[$name]))
		$items[] = [ 'label' => $name, 'kind' => 'global', 'detail' => $help ];
	foreach(self::LITERALS as $l)
	    $items[] = [ 'label' => $l, 'kind' => 'literal', 'detail' => '' ];
	return [ 'replace' => mb_strlen($prefix, 'UTF-8'), 'items' => self::filter($items, $prefix) ];
    }

    //CLD: 2026-10-11T23:02:07+03:00: a variable name field (not an expression): the variables starting with / containing the name typed before the cursor
    public static function completeName($before, array $vars) {
	preg_match('/([A-Za-z_][\w]*)$/', (string)$before, $m);
	$prefix = $m[1] ?? '';
	$items = [];
	foreach($vars as $name => $v)
	    $items[] = [ 'label' => (string)$name, 'kind' => 'variable', 'detail' => trim(($v['type'] ?? 'any') . ' · ' . ($v['info'] ?? ''), ' ·') ];
	return [ 'replace' => mb_strlen($prefix, 'UTF-8'), 'items' => self::filter($items, $prefix) ];
    }

    //CLD: 2026-10-11T23:02:07+03:00: the problems of a variable name field that reads a variable (If, Switch): a name no step before sets (a warning: it may come from the caller,
    //e.g. a variable set by a step it cannot see) //CLD: 2026-10-17T10:46:42+03:00: example updated (webhook query_<name> variables are gone)
    public static function checkName($name, array $vars) {
	$name = trim((string)$name);
	if ($name === '' || isset($vars[$name]) || !\util\Validator::isIdentifier($name))
	    return [];
	return [ self::problem($name, 'warning', "No step before this one sets `{$name}`", 0, strlen($name)) ];
    }

    //CLD: 2026-10-13T12:34:29+03:00: the members of a value of type $t (a description, see typeAt; null: unknown, none): an object's declared fields, a list's methods and length, a number's
    //methods, else (text, any) the text methods and length (the values set by steps are mostly texts); was by the variable's top-level type
    private static function members($t) {
	if (!is_array($t))
	    return [];
	$type = $t['type'] ?? 'any';
	$items = [];
	if ($type === 'object') {
	    foreach((array)($t['fields'] ?? []) as $key => $f)
		$items[] = [ 'label' => (string)$key, 'kind' => 'property', 'detail' => trim(self::typeLabel($f) . ' · ' . ($f['info'] ?? ''), ' ·') ];
	    return $items;
	}
	if ($type === 'boolean')
	    return [];
	//CLD: 2026-10-20T21:40:00+03:00: the methods J has for the type (sys\J\Builtins::METHODS; were listed here and checked against J); + date
	$kind = in_array($type, [ 'array', 'number', 'date' ], true) ? $type : 'string';
	if ($kind === 'array' || $kind === 'string')
	    $items[] = [ 'label' => 'length', 'kind' => 'property', 'detail' => $kind === 'array' ? 'number of items' : 'number of characters' ];
	foreach(\sys\J\Builtins::METHODS[$kind] as $m)
	    $items[] = [ 'label' => $m, 'kind' => 'method', 'detail' => ($type === 'any' ? 'text' : $type) . ' method' ];
	return $items;
    }

    //CLD: 2026-10-13T12:34:29+03:00: a type description as a short text: string, array of object, ...
    public static function typeLabel($t) {
	$type = is_array($t) ? (string)($t['type'] ?? 'any') : 'any';
	return $type === 'array' && is_array($t['items'] ?? null) ? 'array of ' . ($t['items']['type'] ?? 'any') : $type;
    }

    // ------------------------------------------------------------------------------------
    //CLD: 2026-10-13T12:34:29+03:00: types

    //a path in code: a name, then .field, ["field"] or [0]
    const PATH = '[A-Za-z_$][\w$]*(?:\s*\??\.\s*[A-Za-z_$][\w$]*|\s*\[\s*(?:\d+|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')\s*\])*';

    //the parts of a path in code (PATH): [ name, [ field, name ] | [ index, n ], ... ]
    private static function path($code) {
	preg_match_all('/^\s*([A-Za-z_$][\w$]*)|\??\.\s*([A-Za-z_$][\w$]*)|\[\s*(\d+)\s*\]|\[\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')\s*\]/', $code, $m, PREG_SET_ORDER);
	$path = [];
	foreach($m as $g) {
	    if (($g[1] ?? '') !== '')
		$path[] = $g[1];
	    elseif (($g[2] ?? '') !== '')
		$path[] = [ 'field', $g[2] ];
	    elseif (($g[3] ?? '') !== '')
		$path[] = [ 'index', (int)$g[3] ];
	    elseif (($g[4] ?? '') !== '')
		$path[] = [ 'field', $g[4][0] === '"' ? (string)json_decode($g[4]) : stripslashes(substr($g[4], 1, -1)) ];
	}
	return $path;
    }

    //the path of a J node that is a variable or a chain of fields / literal indexes on one ([ name, [ field, x ] | [ index, n ], ... ]); else null
    private static function nodePath(array $node) {
	switch($node['type'] ?? '') {
	    case 'Identifier':
		return [ (string)$node['name'] ];
	    case 'ChainExpression':
		return self::nodePath($node['expression'] ?? []);
	    case 'MemberExpression':
		$path = self::nodePath($node['object'] ?? []);
		$p = $node['property'] ?? [];
		if ($path === null)
		    return null;
		if (empty($node['computed']))
		    return ($p['name'] ?? '') === '' ? null : array_merge($path, [ [ 'field', (string)$p['name'] ] ]);
		if (($p['type'] ?? '') !== 'Literal')
		    return null;
		return array_merge($path, [ is_int($p['value']) || is_float($p['value']) ? [ 'index', (int)$p['value'] ] : [ 'field', (string)$p['value'] ] ]);
	}
	return null;
    }

    //the type description of the value at $path ([ name, segment, ... ], see path()) among $vars: null when unknown. A list's index gives its items' type, `length` a number,
    //an object's field its description; fields of a value of unknown type are unknown
    public static function typeAt(array $vars, array $path) {
	if (!$path)
	    return null;
	$t = $vars[array_shift($path)] ?? null;
	foreach($path as [ $kind, $key ]) {
	    if (!is_array($t))
		return null;
	    $type = $t['type'] ?? 'any';
	    if ($kind === 'index' && $type === 'array')
		$t = is_array($t['items'] ?? null) ? $t['items'] : [ 'type' => 'any' ];
	    elseif ($kind === 'index' && $type === 'string')
		$t = [ 'type' => 'string' ];
	    elseif ($key === 'length' && in_array($type, [ 'array', 'string' ], true))
		$t = [ 'type' => 'number', 'info' => $type === 'array' ? 'number of items' : 'number of characters' ];
	    elseif ($type === 'object' && isset($t['fields'][(string)$key]))
		$t = $t['fields'][(string)$key];
	    else
		return null;
	}
	return $t;
    }

    //the type description of the value of $text (a field of syntax text) among $vars: a single {{ path }}: the type there; a single literal: its type; other text: string;
    //else any (for the Set step's variable)
    public static function typeOf($text, array $vars) {
	$text = (string)$text;
	if (!str_contains($text, \sys\J\Template::OPEN))
	    return [ 'type' => 'string' ];
	$parsed = self::parse($text, 'text');
	if ($parsed->hasErrors())
	    return [ 'type' => 'any' ];
	$parts = $parsed->ast['parts'] ?? [];
	$expressions = array_values(array_filter($parts, fn($p) => $p['type'] === 'TemplateExpression'));
	$blanks = array_filter($parts, fn($p) => $p['type'] === 'TemplateText' && trim($p['value']) !== '') === [];
	if (count($expressions) != 1 || !$blanks)
	    return [ 'type' => 'string' ];
	return self::nodeType($expressions[0]['expression'], $vars) ?? [ 'type' => 'any' ];
    }

    //CLD: 2026-10-13T12:47:09+03:00: the list methods by what they give: the same list (filter, slice, ...), one item (find, at, ...), else a type
    const LIST_SAME = [ 'filter', 'slice', 'concat', 'reverse', 'sort', 'toSorted', 'toReversed', 'splice' ];
    const LIST_ITEM = [ 'find', 'findLast', 'at', 'pop', 'shift' ];
    const METHOD_TYPES = [ 'join' => 'string', 'toString' => 'string', 'includes' => 'boolean', 'some' => 'boolean', 'every' => 'boolean', 'indexOf' => 'number',
	'lastIndexOf' => 'number', 'findIndex' => 'number', 'findLastIndex' => 'number', 'push' => 'number', 'unshift' => 'number', 'map' => 'array', 'flatMap' => 'array',
	'flat' => 'array', 'keys' => 'array', 'values' => 'array', 'entries' => 'array', 'split' => 'array', 'toFixed' => 'string', 'toPrecision' => 'string',
	//CLD: 2026-10-20T22:10:00+03:00: the regular expression methods of texts
	'match' => 'array', 'matchAll' => 'array', 'search' => 'number' ];

    //CLD: 2026-10-13T12:47:09+03:00: the type of a J expression node among $vars (null: unknown): a path, a literal, or a method call on a list / text (was typeOf: paths and literals only)
    private static function nodeType(array $e, array $vars) {
	if (($path = self::nodePath($e)) !== null)
	    return self::typeAt($vars, $path);
	switch($e['type'] ?? '') {
	    case 'Literal':
		$v = $e['value'] ?? null;
		return [ 'type' => is_bool($v) ? 'boolean' : (is_int($v) || is_float($v) ? 'number' : (is_string($v) ? 'string' : 'any')) ];
	    case 'TemplateLiteral':
		return [ 'type' => 'string' ];
	    case 'ArrayExpression':
		return [ 'type' => 'array' ];
	    case 'ObjectExpression':
		return [ 'type' => 'object' ];
	    case 'ChainExpression':
		return self::nodeType($e['expression'] ?? [], $vars);
	    case 'CallExpression':
		$callee = $e['callee'] ?? [];
		if (($callee['type'] ?? '') !== 'MemberExpression' || !empty($callee['computed']))
		    return null;
		$method = (string)($callee['property']['name'] ?? '');
		$of = self::nodeType($callee['object'] ?? [], $vars);
		$type = $of['type'] ?? 'any';
		if ($type === 'array' && in_array($method, self::LIST_SAME, true))
		    return $of;
		if ($type === 'array' && in_array($method, self::LIST_ITEM, true))
		    return is_array($of['items'] ?? null) ? $of['items'] : null;
		if (in_array($type, [ 'string', 'any' ], true) && \sys\J\Builtins::stringMethod($method, '') !== null && !isset(self::METHOD_TYPES[$method]))
		    return [ 'type' => 'string' ];
		if ($type === 'string' && $method === 'split')
		    return [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
		return isset(self::METHOD_TYPES[$method]) ? [ 'type' => self::METHOD_TYPES[$method] ] : null;
	}
	return null;
    }

    //the items starting with $prefix (any case) first, then those containing it; variables before the rest
    private static function filter(array $items, $prefix) {
	$p = mb_strtolower($prefix, 'UTF-8');
	$rank = [ 'variable' => 0, 'property' => 1, 'method' => 1, 'global' => 2, 'literal' => 3 ];
	$scored = [];
	foreach($items as $i => $item) {
	    $l = mb_strtolower($item['label'], 'UTF-8');
	    if ($p !== '' && $l === $p)
		continue;
	    $pos = $p === '' ? 0 : mb_strpos($l, $p, 0, 'UTF-8');
	    if ($pos === false)
		continue;
	    $scored[] = [ $pos === 0 ? 0 : 1, $rank[$item['kind']] ?? 4, $item['label'], $item ];
	}
	usort($scored, fn($a, $b) => [ $a[0], $a[1], $a[2] ] <=> [ $b[0], $b[1], $b[2] ]);
	return array_slice(array_column($scored, 3), 0, self::MAX_ITEMS);
    }

    //CLD: 2026-10-23T01:00:00+03:00: the app's functions (FUNCTIONS) as J may call them: name => \Closure
    public static function functions() {
	return [
	    'markdown' => fn($text = '') => \util\Markdown::toHtml($text),
	];
    }

    //[ global name => [ member names ] ] of J's built-ins (from J itself, so they stay in sync)
    public static function globals() {
	if (self::$globals !== null)
	    return self::$globals;
	$scope = new \sys\J\Runtime\Scope();
	\sys\J\Builtins::install($scope, new \sys\J\Interpreter());
	$globals = [];
	foreach($scope->vars as $name => $value) {
	    $members = [];
	    if ($value instanceof \sys\J\Runtime\JsObject || $value instanceof \sys\J\Runtime\NativeFunction)
		$members = array_map('strval', array_keys($value->props));
	    sort($members);
	    $globals[$name] = $members;
	}
	ksort($globals);
	return self::$globals = $globals;
    }
}
