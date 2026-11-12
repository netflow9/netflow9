<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: a fake Salesforce org for the check suite (CheckCommand::suiteSalesforce), run by PHP's built-in server:
    SF_STATE=<a folder> php -S 127.0.0.1:<port> SalesforceTestServer.php
	POST /services/oauth2/token: client_credentials, ck-check / cs-check: a token (instance_url: this server); <state>/expired.json: tokens answered 401 (INVALID_SESSION_ID).
	/services/data/v<n>/: query (SOQL understood: SELECT fields FROM object [WHERE a AND b, each field =, !=, >, >=, <, <= a 'text', a number or a UTC datetime] [ORDER BY f
	ASC|DESC, …] [LIMIT n]; 3 records a page, nextRecordsUrl), search (SOSL FIND {text} … RETURNING Object(fields)), sobjects (create, get, update, delete, upsert by a field),
	describe, limits; /services/oauth2/userinfo. Records in <state>/<Object>.json; made / changed at <state>/now.txt (ms, else the clock). Requests in <state>/requests.jsonl
*/

$state = getenv('SF_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'form' => $_POST, 'token' => substr($auth, 7) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json;charset=UTF-8');
    if ($v !== null)
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$err = fn($status, $message, $code, array $fields = []) => $out([ [ 'message' => $message, 'errorCode' => $code ] + ($fields ? [ 'fields' => $fields ] : []) ], $status);
$base = 'http://' . $_SERVER['HTTP_HOST'];

if ($path === '/services/oauth2/token') {
    if (($_POST['grant_type'] ?? '') !== 'client_credentials' || ($_POST['client_id'] ?? '') !== 'ck-check' || ($_POST['client_secret'] ?? '') !== 'cs-check')
	$out([ 'error' => 'invalid_client', 'error_description' => 'invalid client credentials' ], 400);
    $tokens = $load('tokens');
    $t = '00Dtok-' . (count($tokens) + 1);
    $tokens[] = $t;
    $save('tokens', $tokens);
    $out([ 'access_token' => $t, 'instance_url' => $base, 'id' => "{$base}/id/00D/005", 'token_type' => 'Bearer', 'issued_at' => (string)(time() * 1000), 'signature' => 'x' ]);
}
$token = substr($auth, 7);
if (!str_starts_with($auth, 'Bearer ') || !in_array($token, $load('tokens'), true) || in_array($token, $load('expired'), true))
    $err(401, 'Session expired or invalid', 'INVALID_SESSION_ID');
if ($path === '/services/oauth2/userinfo')
    $out([ 'name' => 'Integration User', 'preferred_username' => 'bot@acme.example', 'organization_id' => '00D000000000001' ]);
if (!preg_match('~^/services/data/v(\d+\.0)(/.*)?$~', $path, $m))
    $err(404, 'The requested resource does not exist', 'NOT_FOUND');
$p = $m[2] ?? '/';
$now = (int)(trim((string)@file_get_contents("{$state}/now.txt")) ?: floor(microtime(true) * 1000));
$stamp = gmdate('Y-m-d\TH:i:s', (int)floor($now / 1000)) . sprintf('.%03d', $now % 1000) . '+0000';
$prefixes = [ 'Account' => '001', 'Contact' => '003', 'Lead' => '00Q', 'Opportunity' => '006', 'Case' => '500', 'Task' => '00T' ];
$newId = fn($o, $n) => str_pad(($prefixes[$o] ?? 'a01') . str_pad((string)$n, 9, '0', STR_PAD_LEFT), 15, '0') . 'AAA';
$withAttrs = fn($o, $r) => [ 'attributes' => [ 'type' => $o, 'url' => "/services/data/v64.0/sobjects/{$o}/{$r['Id']}" ] ] + $r;
$ms = fn($v) => (int)(new DateTimeImmutable((string)$v))->format('Uv');
$required = [ 'Lead' => [ 'LastName', 'Company' ], 'Contact' => [ 'LastName' ], 'Account' => [ 'Name' ] ];

//SOQL, the part this server understands
$soql = function($q) use ($load, $ms, $withAttrs, $err) {
    if (!preg_match('/^SELECT\s+(.+?)\s+FROM\s+(\w+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER BY\s+(.+?))?(?:\s+LIMIT\s+(\d+))?$/is', trim($q), $m))
	$err(400, "unexpected token: '" . substr($q, 0, 30) . "'", 'MALFORMED_QUERY');
    $fields = array_map('trim', explode(',', $m[1]));
    $o = $m[2];
    if ($o === 'Organization')
	return [ $withAttrs('Organization', [ 'Id' => '00D000000000001AAA', 'Name' => 'Acme', 'IsSandbox' => false, 'OrganizationType' => 'Enterprise Edition' ]) ];
    $rows = $load($o);
    $where = trim($m[3] ?? '');
    if ($where !== '')
	foreach(preg_split('/\s+AND\s+/i', $where) as $c) {
	    $c = trim($c, " ()");
	    if (!preg_match("/^(\w+)\s*(=|!=|>=|<=|>|<)\s*('(?:[^'\\\\]|\\\\.)*'|[\d.]+|\d{4}-\d{2}-\d{2}T[\d:.]+Z)$/", $c, $x))
		$err(400, "unexpected token in WHERE: '{$c}'", 'MALFORMED_QUERY');
	    [ , $f, $op, $v ] = $x;
	    $str = $v[0] === "'";
	    $date = preg_match('/^\d{4}-/', $v);
	    $v = $str ? stripcslashes(substr($v, 1, -1)) : $v;
	    $rows = array_filter($rows, function($r) use ($f, $op, $v, $date, $ms) {
		$a = $r[$f] ?? null;
		if ($a === null)
		    return $op === '!=';
		[ $a, $b ] = $date ? [ $ms($a), $ms($v) ] : (is_numeric($a) && is_numeric($v) ? [ (float)$a, (float)$v ] : [ strtolower((string)$a), strtolower((string)$v) ]);
		return match($op) { '=' => $a == $b, '!=' => $a != $b, '>' => $a > $b, '>=' => $a >= $b, '<' => $a < $b, '<=' => $a <= $b };
	    });
	}
    $rows = array_values($rows);
    foreach(array_reverse(array_filter(array_map('trim', explode(',', $m[4] ?? '')))) as $ord) {
	[ $f, $dir ] = array_pad(preg_split('/\s+/', $ord), 2, 'ASC');
	usort($rows, fn($a, $b) => (strtoupper($dir) === 'DESC' ? -1 : 1) * strnatcmp((string)($a[$f] ?? ''), (string)($b[$f] ?? '')));
    }
    if (isset($m[5]) && $m[5] !== '')
	$rows = array_slice($rows, 0, (int)$m[5]);
    return array_map(fn($r) => $withAttrs($o, array_intersect_key($r, array_flip($fields)) + [ 'Id' => $r['Id'] ]), $rows);
};

if ($p === '/query' || preg_match('~^/query/(01g[0-9]+)-(\d+)$~', $p, $qm)) {
    if ($p === '/query') {
	$rows = $soql((string)($query['q'] ?? ''));
	$key = '01g' . count($load('cursors'));
	$c = $load('cursors');
	$c[$key] = $rows;
	$save('cursors', $c);
	$from = 0;
    } else {
	$key = $qm[1];
	$rows = $load('cursors')[$key] ?? [];
	$from = (int)$qm[2];
    }
    $page = array_slice($rows, $from, 3);
    $done = $from + 3 >= count($rows);
    $out([ 'totalSize' => count($rows), 'done' => $done, 'records' => $page ] + ($done ? [] : [ 'nextRecordsUrl' => "/services/data/v64.0/query/{$key}-" . ($from + 3) ]));
}
if ($p === '/search') {
    if (!preg_match('/^FIND \{(.*)\} IN ALL FIELDS RETURNING (\w+)\(([^)]*?)(?: LIMIT (\d+))?\)$/s', (string)($query['q'] ?? ''), $x))
	$err(400, 'malformed SOSL', 'MALFORMED_SEARCH');
    $text = preg_replace('/\\\\(.)/', '$1', $x[1]);
    $fields = array_map('trim', explode(',', $x[3]));
    $found = array_values(array_filter($load($x[2]), fn($r) => stripos(json_encode($r, JSON_UNESCAPED_UNICODE), $text) !== false));
    $out([ 'searchRecords' => array_map(fn($r) => $withAttrs($x[2], array_intersect_key($r, array_flip($fields)) + [ 'Id' => $r['Id'] ]), $found) ]);
}
if ($p === '/limits')
    $out([ 'DailyApiRequests' => [ 'Max' => 100000, 'Remaining' => 99876 ], 'DataStorageMB' => [ 'Max' => 5120, 'Remaining' => 5000 ] ]);
if ($p === '/sobjects')
    $out([ 'sobjects' => [ [ 'name' => 'Account', 'label' => 'Account', 'custom' => false, 'queryable' => true, 'createable' => true, 'keyPrefix' => '001' ],
	[ 'name' => 'Invoice__c', 'label' => 'Invoice', 'custom' => true, 'queryable' => true, 'createable' => true, 'keyPrefix' => 'a01' ] ] ]);
if ($p === '/sobjects/Lead/describe')
    $out([ 'name' => 'Lead', 'label' => 'Lead', 'fields' => [ [ 'name' => 'LastName', 'label' => 'Last Name', 'type' => 'string', 'nillable' => false, 'createable' => true, 'updateable' => true ],
	[ 'name' => 'Status', 'label' => 'Lead Status', 'type' => 'picklist', 'nillable' => false, 'defaultedOnCreate' => true, 'createable' => true, 'updateable' => true,
	    'picklistValues' => [ [ 'value' => 'Open - Not Contacted', 'label' => 'Open - Not Contacted', 'active' => true ], [ 'value' => 'Working - Contacted', 'label' => 'Working', 'active' => true ] ] ],
	[ 'name' => 'ERP_Id__c', 'label' => 'ERP Id', 'type' => 'string', 'nillable' => true, 'createable' => true, 'updateable' => true, 'externalId' => true ] ] ]);
if (preg_match('~^/sobjects/(\w+)(?:/(\w+))?(?:/([^/]+))?$~', $p, $s)) {
    $o = $s[1];
    $rows = $load($o);
    $make = function(array $f) use ($o, &$rows, $newId, $stamp, $required, $err) {
	$missing = array_values(array_diff($required[$o] ?? [], array_keys(array_filter($f, fn($v) => $v !== null && $v !== ''))));
	if ($missing)
	    $err(400, 'Required fields are missing: [' . implode(', ', $missing) . ']', 'REQUIRED_FIELD_MISSING', $missing);
	$r = [ 'Id' => $newId($o, count($rows) + 1) ] + $f + [ 'CreatedDate' => $stamp, 'LastModifiedDate' => $stamp, 'SystemModstamp' => $stamp ];
	if (isset($r['FirstName']) || isset($r['LastName']))
	    $r['Name'] = trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? ''));
	$rows[] = $r;
	return $r;
    };
    if (!isset($s[2]) && $method === 'POST') {
	$r = $make((array)$body);
	$save($o, $rows);
	$out([ 'id' => $r['Id'], 'success' => true, 'errors' => [] ], 201);
    }
    if (isset($s[3]) && $method === 'PATCH') {
	$f = $s[2];
	$v = $s[3];
	foreach($rows as $i => $r)
	    if ((string)($r[$f] ?? '') === $v) {
		$rows[$i] = array_replace($r, (array)$body, [ 'LastModifiedDate' => $stamp, 'SystemModstamp' => $stamp ]);
		$save($o, $rows);
		$out([ 'id' => $r['Id'], 'success' => true, 'errors' => [], 'created' => false ]);
	    }
	$r = $make([ $f => $v ] + (array)$body);
	$save($o, $rows);
	$out([ 'id' => $r['Id'], 'success' => true, 'errors' => [], 'created' => true ], 201);
    }
    $id = $s[2] ?? '';
    $i = null;
    foreach($rows as $n => $r)
	if ($r['Id'] === $id || substr($r['Id'], 0, 15) === $id)
	    $i = $n;
    if ($i === null)
	$err(404, 'The requested resource does not exist', 'NOT_FOUND');
    if ($method === 'GET') {
	$f = array_filter(explode(',', (string)($query['fields'] ?? '')));
	$out($withAttrs($o, $f ? array_intersect_key($rows[$i], array_flip($f)) + [ 'Id' => $rows[$i]['Id'] ] : $rows[$i]));
    }
    if ($method === 'PATCH') {
	$rows[$i] = array_replace($rows[$i], (array)$body, [ 'LastModifiedDate' => $stamp, 'SystemModstamp' => $stamp ]);
	$save($o, $rows);
	$out(null, 204);
    }
    if ($method === 'DELETE') {
	array_splice($rows, $i, 1);
	$save($o, $rows);
	$out(null, 204);
    }
}
$err(404, 'The requested resource does not exist', 'NOT_FOUND');
