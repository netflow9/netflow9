<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: a fake HubSpot API for the check suite (CheckCommand::suiteHubSpot), run by PHP's built-in server:
    HS_STATE=<a folder> php -S 127.0.0.1:<port> HubSpotTestServer.php
	Bearer pat-check (else 401). Records in <state>/<type>.json (contacts, companies, deals, tickets, notes, custom types): [ { id, properties, createdAt, updatedAt, archived } ];
	a record made or changed gets createdate / lastmodifieddate / hs_lastmodifieddate at <state>/now.txt (ms; else the clock). A second contact with an email that exists: 409.
	Search: the filters EQ, NEQ, GT(E), LT(E), CONTAINS_TOKEN, IN, HAS_PROPERTY, NOT_HAS_PROPERTY; query: a text in any property; sorts; limit / after.
	Associations (v4 default) in <state>/associations.json. <state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl
*/

$state = getenv('HS_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode(file_get_contents('php://input'), true);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json;charset=utf-8');
    if ($v !== null)
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$err = fn($status, $message, $category, array $errors = []) => $out([ 'status' => 'error', 'message' => $message, 'correlationId' => 'c0ffee', 'category' => $category ] + ($errors ? [ 'errors' => $errors ] : []), $status);

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer pat-check')
    $err(401, 'Authentication credentials not found. This API supports OAuth 2.0 authentication.', 'INVALID_AUTHENTICATION');
$throttle = (int)$load('throttle', 0);
if ($throttle > 0) {
    $save('throttle', $throttle - 1);
    header('Retry-After: 1');
    $err(429, 'You have reached your secondly limit.', 'RATE_LIMITS');
}
$now = (int)(trim((string)@file_get_contents("{$state}/now.txt")) ?: floor(microtime(true) * 1000));
$iso = fn($ms) => gmdate('Y-m-d\TH:i:s', (int)floor($ms / 1000)) . sprintf('.%03dZ', $ms % 1000);
$ms = fn($v) => is_numeric($v) ? (int)$v : (int)(new DateTimeImmutable((string)$v))->format('Uv');
$stamp = function(array $r, $new) use ($now, $iso) {
    $t = $iso($now);
    if ($new)
	$r['properties']['createdate'] = $r['createdAt'] = $t;
    $r['properties']['lastmodifieddate'] = $r['properties']['hs_lastmodifieddate'] = $r['updatedAt'] = $t;
    return $r;
};

if ($path === '/account-info/v3/details')
    $out([ 'portalId' => 4242, 'accountType' => 'STANDARD', 'timeZone' => 'Europe/Bucharest', 'companyCurrency' => 'RON', 'uiDomain' => 'app-eu1.hubspot.com', 'dataHostingLocation' => 'eu1' ]);
if (preg_match('~^/crm/v3/objects/([a-z0-9_-]+)/search$~', $path, $m) && $method === 'POST') {
    $all = $load($m[1]);
    $match = function($r, $f) use ($ms) {
	$v = $r['properties'][$f['propertyName']] ?? null;
	$num = fn($x) => is_numeric($x) ? (float)$x : (preg_match('/^\d{4}-/', (string)$x) ? (float)$ms($x) : null);
	switch($f['operator']) {
	    case 'EQ': return strcasecmp((string)$v, (string)$f['value']) === 0;
	    case 'NEQ': return strcasecmp((string)$v, (string)$f['value']) !== 0;
	    case 'GT': return $v !== null && $num($v) > $num($f['value']);
	    case 'GTE': return $v !== null && $num($v) >= $num($f['value']);
	    case 'LT': return $v !== null && $num($v) < $num($f['value']);
	    case 'LTE': return $v !== null && $num($v) <= $num($f['value']);
	    case 'CONTAINS_TOKEN': return $v !== null && stripos((string)$v, trim((string)$f['value'], '*')) !== false;
	    case 'IN': return in_array(strtolower((string)$v), array_map('strtolower', $f['values']), true);
	    case 'HAS_PROPERTY': return $v !== null && $v !== '';
	    case 'NOT_HAS_PROPERTY': return $v === null || $v === '';
	}
	return false;
    };
    $filters = $body['filterGroups'][0]['filters'] ?? [];
    $text = (string)($body['query'] ?? '');
    $all = array_values(array_filter($all, fn($r) => !array_filter($filters, fn($f) => !$match($r, $f)) && ($text === '' || stripos(json_encode($r['properties']), $text) !== false)));
    foreach(array_reverse((array)($body['sorts'] ?? [])) as $s)
	usort($all, function($a, $b) use ($s, $ms) {
	    $x = $a['properties'][$s['propertyName']] ?? '';
	    $y = $b['properties'][$s['propertyName']] ?? '';
	    $c = preg_match('/^\d{4}-/', (string)$x) ? $ms($x) <=> $ms($y) : strnatcasecmp((string)$x, (string)$y);
	    return $s['direction'] === 'DESCENDING' ? -$c : $c;
	});
    $from = (int)($body['after'] ?? 0);
    $page = array_slice($all, $from, (int)($body['limit'] ?? 10));
    $end = $from + count($page);
    $out([ 'total' => count($all), 'results' => $page ] + ($end < count($all) ? [ 'paging' => [ 'next' => [ 'after' => (string)$end ] ] ] : []));
}
if (preg_match('~^/crm/v3/objects/([a-z0-9_-]+)(?:/([^/]+))?$~', $path, $m)) {
    $type = $m[1];
    $all = $load($type);
    $key = $m[2] ?? null;
    if ($key === null && $method === 'POST') {
	$p = (array)($body['properties'] ?? []);
	if ($type === 'contacts' && isset($p['email']))
	    foreach($all as $r)
		if (strcasecmp((string)($r['properties']['email'] ?? ''), (string)$p['email']) === 0)
		    $err(409, "Contact already exists. Existing ID: {$r['id']}", 'CONFLICT');
	foreach($p as $k => $v)
	    if (!is_scalar($v))
		$err(400, 'Property values were not valid', 'VALIDATION_ERROR', [ [ 'message' => "Property \"{$k}\" is not a string" ] ]);
	$r = $stamp([ 'id' => (string)(1000 + count($all) + 1), 'properties' => array_map('strval', $p), 'archived' => false ], true);
	$r['properties']['hs_object_id'] = $r['id'];
	$all[] = $r;
	$save($type, $all);
	$out($r, 201);
    }
    $i = null;
    foreach($all as $n => $r)
	if (isset($query['idProperty']) ? strcasecmp((string)($r['properties'][$query['idProperty']] ?? ''), (string)$key) === 0 : $r['id'] === $key)
	    $i = $n;
    if ($i === null)
	$err(404, 'resource not found', 'OBJECT_NOT_FOUND');
    if ($method === 'GET')
	$out($all[$i]);
    if ($method === 'PATCH') {
	$all[$i]['properties'] = array_replace($all[$i]['properties'], array_map('strval', (array)($body['properties'] ?? [])));
	$all[$i] = $stamp($all[$i], false);
	$save($type, $all);
	$out($all[$i]);
    }
    if ($method === 'DELETE') {
	array_splice($all, $i, 1);
	$save($type, $all);
	$out(null, 204);
    }
}
if (preg_match('~^/crm/v4/objects/([a-z0-9_-]+)/(\d+)/associations/default/([a-z0-9_-]+)/(\d+)$~', $path, $m) && $method === 'PUT') {
    foreach([ [ $m[1], $m[2] ], [ $m[3], $m[4] ] ] as [ $t, $id ])
	if (!array_filter($load($t), fn($r) => $r['id'] === $id))
	    $err(404, "No {$t} with ID {$id} exist", 'OBJECT_NOT_FOUND');
    $a = $load('associations');
    $a[] = [ $m[1], $m[2], $m[3], $m[4] ];
    $save('associations', $a);
    $out([ 'status' => 'COMPLETE', 'results' => [ [ 'from' => [ 'id' => $m[2] ], 'to' => [ 'id' => $m[4] ], 'associationTypes' => [ [ 'category' => 'HUBSPOT_DEFINED', 'typeId' => 1, 'label' => null ] ] ] ] ]);
}
if (preg_match('~^/crm/v4/objects/([a-z0-9_-]+)/(\d+)/associations/([a-z0-9_-]+)$~', $path, $m)) {
    $res = [];
    foreach($load('associations') as $a) {
	if ($a[0] === $m[1] && $a[1] === $m[2] && $a[2] === $m[3])
	    $res[] = [ 'toObjectId' => (int)$a[3], 'associationTypes' => [ [ 'category' => 'HUBSPOT_DEFINED', 'typeId' => 1, 'label' => 'Primary' ] ] ];
	elseif ($a[2] === $m[1] && $a[3] === $m[2] && $a[0] === $m[3])
	    $res[] = [ 'toObjectId' => (int)$a[1], 'associationTypes' => [ [ 'category' => 'HUBSPOT_DEFINED', 'typeId' => 2, 'label' => null ] ] ];
    }
    $out([ 'results' => $res ]);
}
if ($path === '/crm/v3/properties/contacts')
    $out([ 'results' => [ [ 'name' => 'email', 'label' => 'Email', 'type' => 'string', 'fieldType' => 'text', 'groupName' => 'contactinformation', 'options' => [] ],
	[ 'name' => 'lifecyclestage', 'label' => 'Lifecycle Stage', 'type' => 'enumeration', 'fieldType' => 'radio', 'options' => [ [ 'value' => 'lead', 'label' => 'Lead' ], [ 'value' => 'customer', 'label' => 'Customer' ] ] ] ] ]);
if ($path === '/crm/v3/pipelines/deals')
    $out([ 'results' => [ [ 'id' => 'default', 'label' => 'Sales Pipeline', 'stages' => [ [ 'id' => 'appointmentscheduled', 'label' => 'Appointment Scheduled', 'displayOrder' => 0 ],
	[ 'id' => 'closedwon', 'label' => 'Closed Won', 'displayOrder' => 5, 'metadata' => [ 'isClosed' => 'true', 'probability' => '1.0' ] ] ] ] ] ]);
if ($path === '/crm/v3/owners') {
    $owners = [ [ 'id' => '77', 'email' => 'ana@example.com', 'firstName' => 'Ana', 'lastName' => 'Pop', 'userId' => 9, 'teams' => [ [ 'name' => 'Sales' ] ] ], [ 'id' => '78', 'email' => 'dan@example.com', 'firstName' => 'Dan' ] ];
    $out([ 'results' => array_values(array_filter($owners, fn($o) => empty($query['email']) || strcasecmp($o['email'], $query['email']) === 0)) ]);
}
$err(404, "Unable to route {$method} request to {$path}", 'NOT_FOUND');
