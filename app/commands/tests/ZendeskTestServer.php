<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: a fake Zendesk Support for the check suite (CheckCommand::suiteZendesk), run by PHP's built-in server:
    ZENDESK_STATE=<a folder> php -S 127.0.0.1:<port> ZendeskTestServer.php
	/api/v2/*.json: Basic bot@acme.com/token : tok-zd, or Bearer oauth-zd (else 401). Data in <state>/db.json, seeded: agents (the bot, Ana, Dan), an end user of an
	organization, groups, a ticket field, a view, a macro. Tickets: created / updated at <state>/now.txt (ms, else the clock); a ticket without a comment, or an assignee who is
	not an agent, refused as Zendesk does (422 RecordInvalid with details). Cursor pages (page[size], meta.has_more, links.next); the search (search.json: type:ticket,
	status:<s>, status<solved, priority:, tags:, requester:<email>, created> / updated> <ISO>, other words in the subject; sort_by, sort_order; per_page, next_page).
	<state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, auth, type)
*/

$state = getenv('ZENDESK_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
$type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'auth' => $hdr('Authorization'), 'type' => $type,
    'size' => strlen($raw) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$invalid = fn(array $details) => $out([ 'error' => 'RecordInvalid', 'description' => 'Record validation errors', 'details' => $details ], 422);

$auth = (string)$hdr('Authorization');
if ($auth !== 'Basic ' . base64_encode('bot@acme.com/token:tok-zd') && $auth !== 'Bearer oauth-zd')
    $out([ 'error' => 'Couldn\'t authenticate you' ], 401);
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'error' => 'APIRateLimitExceeded', 'description' => 'Number of allowed API requests per minute exceeded' ], 429, [ 'Retry-After' => '1' ]);
}
if (!preg_match('~^/api/v2(/.*)\.json$~', $path, $m))
    $out([ 'error' => 'InvalidEndpoint', 'description' => 'Not found' ], 404);
$p = $m[1];
$host = 'http://' . $_SERVER['HTTP_HOST'];
$nowMs = (int)(trim((string)@file_get_contents("{$state}/now.txt")) ?: floor(microtime(true) * 1000));
$now = gmdate('Y-m-d\TH:i:s\Z', intdiv($nowMs, 1000));

$db = $load('db', null) ?? [
    'users' => [
	100 => [ 'id' => 100, 'name' => 'Workflow Bot', 'email' => 'bot@acme.com', 'role' => 'admin', 'active' => true, 'organization_id' => null, 'tags' => [] ],
	101 => [ 'id' => 101, 'name' => 'Ana Pop', 'email' => 'ana@acme.com', 'role' => 'agent', 'active' => true, 'organization_id' => null, 'tags' => [] ],
	102 => [ 'id' => 102, 'name' => 'Dan Ionescu', 'email' => 'dan@acme.com', 'role' => 'agent', 'active' => true, 'organization_id' => null, 'tags' => [] ],
	201 => [ 'id' => 201, 'name' => 'Eva Marin', 'email' => 'eva@customer.com', 'role' => 'end-user', 'active' => true, 'organization_id' => 301, 'tags' => [ 'vip' ], 'user_fields' => [ 'plan' => 'gold' ] ],
    ],
    'groups' => [ 401 => [ 'id' => 401, 'name' => 'Support', 'default' => true ], 402 => [ 'id' => 402, 'name' => 'Tier 2', 'default' => false ] ],
    'organizations' => [ 301 => [ 'id' => 301, 'name' => 'Customer Co', 'domain_names' => [ 'customer.com' ], 'tags' => [], 'organization_fields' => [] ] ],
    'ticket_fields' => [ [ 'id' => 501, 'title' => 'Plan', 'type' => 'tagger', 'active' => true, 'required' => false, 'custom_field_options' => [ [ 'name' => 'Gold', 'value' => 'gold' ], [ 'name' => 'Free', 'value' => 'free' ] ] ],
	[ 'id' => 1, 'title' => 'Subject', 'type' => 'subject', 'active' => true, 'required' => true ] ],
    'views' => [ [ 'id' => 601, 'title' => 'Unsolved tickets', 'position' => 1, 'active' => true ] ],
    'macros' => [ [ 'id' => 701, 'title' => 'Close and thank', 'description' => 'Solves the ticket', 'active' => true ] ],
    'tickets' => [], 'comments' => [], 'uploads' => [], 'next' => 1000,
];
$commit = function() use (&$db, $save) {
    $save('db', $db);
};
//a cursor page of $items
$page = function(array $items, $key) use ($query, $out, $host, $p) {
    $items = array_values($items);
    $size = (int)($query['page']['size'] ?? 100);
    $from = (int)($query['page']['after'] ?? 0);
    $chunk = array_slice($items, $from, $size);
    $more = $from + $size < count($items);
    $q = $query;
    $q['page'] = [ 'size' => $size, 'after' => $from + $size ];
    $out([ $key => $chunk, 'meta' => [ 'has_more' => $more, 'after_cursor' => $more ? (string)($from + $size) : null ],
	'links' => [ 'next' => $more ? $host . "/api/v2{$p}.json?" . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : null ] ]);
};
$ticketOut = fn($t) => array_diff_key($t, [ '_x' => 1 ]);
$ms = fn($iso) => (int)(new DateTimeImmutable($iso))->format('Uv');
$userIdOf = function($in) use (&$db) {
    if (isset($in['requester']['email'])) {
	foreach($db['users'] as $u)
	    if (strcasecmp($u['email'], $in['requester']['email']) === 0)
		return $u['id'];
	$id = $db['next']++;
	$db['users'][$id] = [ 'id' => $id, 'name' => $in['requester']['name'] ?? explode('@', $in['requester']['email'])[0], 'email' => $in['requester']['email'], 'role' => 'end-user', 'active' => true,
	    'organization_id' => null, 'tags' => [] ];
	return $id;
    }
    return $in['requester_id'] ?? 100;
};
//a ticket's fields set from the input, its comment added
$apply = function(array &$t, array $in) use (&$db, $invalid, $now) {
    if (isset($in['assignee_id']) && !in_array($db['users'][$in['assignee_id']]['role'] ?? '', [ 'agent', 'admin' ], true))
	$invalid([ 'assignee' => [ [ 'description' => 'Assignee is not an agent', 'error' => 'InvalidValue' ] ] ]);
    foreach([ 'subject', 'priority', 'type', 'status', 'assignee_id', 'group_id', 'tags', 'due_at', 'external_id' ] as $k)
	if (array_key_exists($k, $in))
	    $t[$k] = $in[$k];
    foreach((array)($in['custom_fields'] ?? []) as $f) {
	$t['custom_fields'] = array_values(array_filter($t['custom_fields'] ?? [], fn($x) => $x['id'] !== $f['id']));
	$t['custom_fields'][] = $f;
    }
    if (!empty($in['comment'])) {
	$c = $in['comment'];
	$id = $db['next']++;
	$text = $c['body'] ?? strip_tags($c['html_body'] ?? '');
	$db['comments'][$id] = [ 'id' => $id, 'ticket_id' => $t['id'], 'author_id' => 100, 'public' => $c['public'] ?? true, 'body' => $text, 'plain_body' => $text,
	    'html_body' => $c['html_body'] ?? '<p>' . htmlspecialchars($text) . '</p>', 'created_at' => $now,
	    'attachments' => array_values(array_map(fn($tok) => $db['uploads'][$tok]['attachment'], array_filter((array)($c['uploads'] ?? []), fn($tok) => isset($db['uploads'][$tok])))) ];
	if (!isset($t['description']))
	    $t['description'] = $text;
	if (empty($c['public']) && isset($c['public']) && $t['status'] === 'new')
	    $t['status'] = 'open';
    }
    $t['updated_at'] = $now;
};

if ($p === '/users/me')
    $out([ 'user' => $auth === 'Bearer oauth-zd' ? $db['users'][101] : $db['users'][100] ]);
if ($p === '/search') {
    $q = (string)($query['query'] ?? '');
    $list = array_values($db['tickets']);
    $words = [];
    foreach(preg_split('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($q)) as $term) {
	if ($term === '' || $term === 'type:ticket')
	    continue;
	if (preg_match('/^status<solved$/', $term))
	    $list = array_filter($list, fn($t) => in_array($t['status'], [ 'new', 'open', 'pending', 'hold' ], true));
	elseif (preg_match('/^(status|priority|type):(\w+)$/', $term, $mm))
	    $list = array_filter($list, fn($t) => ($t[$mm[1]] ?? null) === $mm[2]);
	elseif (preg_match('/^tags:(\S+)$/', $term, $mm))
	    $list = array_filter($list, fn($t) => in_array($mm[1], $t['tags'] ?? [], true));
	elseif (preg_match('/^requester:(\S+)$/', $term, $mm))
	    $list = array_filter($list, fn($t) => strcasecmp($db['users'][$t['requester_id']]['email'] ?? '', $mm[1]) === 0);
	elseif (preg_match('/^(created|updated)>(\S+)$/', $term, $mm))
	    $list = array_filter($list, fn($t) => $ms($t["{$mm[1]}_at"]) > $ms($mm[2]));
	elseif (str_contains($term, ':') || str_contains($term, '<') || str_contains($term, '>'))
	    $out([ 'error' => 'unavailable', 'description' => "the fake does not know `{$term}`" ], 400);
	else
	    $words[] = trim($term, '"');
    }
    foreach($words as $w)
	$list = array_filter($list, fn($t) => stripos($t['subject'], $w) !== false);
    $sort = ($query['sort_by'] ?? 'updated_at') === 'created_at' ? 'created_at' : 'updated_at';
    $list = array_values($list);
    usort($list, fn($a, $b) => strcmp($a[$sort], $b[$sort]) ?: $a['id'] <=> $b['id']);
    if (($query['sort_order'] ?? 'desc') === 'desc')
	$list = array_reverse($list);
    $per = (int)($query['per_page'] ?? 100);
    $pg = max(1, (int)($query['page'] ?? 1));
    $chunk = array_map(fn($t) => $ticketOut($t) + [ 'result_type' => 'ticket' ], array_slice($list, ($pg - 1) * $per, $per));
    $next = $pg * $per < count($list) ? $host . '/api/v2/search.json?' . http_build_query([ 'page' => $pg + 1 ] + $query, '', '&', PHP_QUERY_RFC3986) : null;
    $out([ 'results' => $chunk, 'count' => count($list), 'next_page' => $next, 'previous_page' => null ]);
}
if ($p === '/tickets' && $method === 'POST') {
    $in = (array)($body['ticket'] ?? []);
    if (empty($in['comment']))
	$invalid([ 'base' => [ [ 'description' => 'Description: cannot be blank', 'error' => 'BlankValue' ] ] ]);
    $id = count($db['tickets']) + 1;
    $t = [ 'id' => $id, 'subject' => $in['subject'] ?? '', 'status' => $in['status'] ?? (!empty($in['assignee_id']) ? 'open' : 'new'), 'priority' => $in['priority'] ?? null, 'type' => $in['type'] ?? null,
	'requester_id' => $userIdOf($in), 'submitter_id' => 100, 'assignee_id' => null, 'group_id' => null, 'organization_id' => null, 'tags' => [], 'custom_fields' => [],
	'via' => [ 'channel' => 'api' ], 'created_at' => $now, 'updated_at' => $now, 'due_at' => null, 'external_id' => null ];
    $t['organization_id'] = $db['users'][$t['requester_id']]['organization_id'] ?? null;
    $apply($t, $in);
    $db['tickets'][$id] = $t;
    $commit();
    $out([ 'ticket' => $ticketOut($t) ], 201);
}
if (preg_match('~^/tickets/(\d+)(?:/(comments|tags|merge))?$~', $p, $mm)) {
    $id = (int)$mm[1];
    if (!isset($db['tickets'][$id]))
	$out([ 'error' => 'RecordNotFound', 'description' => 'Not found' ], 404);
    $sub = $mm[2] ?? '';
    if ($sub === 'comments') {
	$list = array_values(array_filter($db['comments'], fn($c) => $c['ticket_id'] === $id));
	usort($list, fn($a, $b) => ($query['sort'] ?? '') === '-created_at' ? (strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']) : $a['id'] <=> $b['id']);
	$page($list, 'comments');
    }
    if ($sub === 'tags') {
	$tags = (array)($body['tags'] ?? []);
	$db['tickets'][$id]['tags'] = $method === 'DELETE' ? array_values(array_diff($db['tickets'][$id]['tags'], $tags)) : array_values(array_unique(array_merge($db['tickets'][$id]['tags'], $tags)));
	$db['tickets'][$id]['updated_at'] = $now;
	$commit();
	$out([ 'tags' => $db['tickets'][$id]['tags'] ]);
    }
    if ($sub === 'merge') {
	foreach((array)($body['ids'] ?? []) as $s)
	    if (isset($db['tickets'][$s]))
		$db['tickets'][$s]['status'] = 'closed';
	$commit();
	$out([ 'job_status' => [ 'id' => 'job-1', 'status' => 'queued' ] ]);
    }
    if ($method === 'GET') {
	$t = $ticketOut($db['tickets'][$id]);
	$res = [ 'ticket' => $t ];
	if (str_contains((string)($query['include'] ?? ''), 'users'))
	    $res['users'] = array_values(array_filter(array_map(fn($u) => $db['users'][$u] ?? null, array_unique(array_filter([ $t['requester_id'], $t['assignee_id'], $t['submitter_id'] ])))));
	if (str_contains((string)($query['include'] ?? ''), 'groups'))
	    $res['groups'] = $t['group_id'] ? [ $db['groups'][$t['group_id']] ] : [];
	if (str_contains((string)($query['include'] ?? ''), 'organizations'))
	    $res['organizations'] = $t['organization_id'] ? [ $db['organizations'][$t['organization_id']] ] : [];
	$out($res);
    }
    if ($method === 'PUT') {
	$apply($db['tickets'][$id], (array)($body['ticket'] ?? []));
	$commit();
	$out([ 'ticket' => $ticketOut($db['tickets'][$id]) ]);
    }
    if ($method === 'DELETE') {
	unset($db['tickets'][$id]);
	$commit();
	$out(null, 204);
    }
}
if ($p === '/uploads' && $method === 'POST') {
    $tok = 'up-' . ($db['next']++);
    $db['uploads'][$tok] = [ 'token' => $tok, 'attachment' => [ 'id' => $db['next']++, 'file_name' => (string)($query['filename'] ?? ''), 'size' => strlen($raw), 'content_url' => "{$host}/attachments/{$tok}" ] ];
    $commit();
    $out([ 'upload' => $db['uploads'][$tok] ], 201);
}
if ($p === '/users/search') {
    $q = (string)($query['query'] ?? '');
    $found = array_values(array_filter($db['users'], fn($u) => str_starts_with($q, 'email:') ? strcasecmp($u['email'], substr($q, 6)) === 0
	: (str_starts_with($q, 'role:') ? $u['role'] === substr($q, 5) : stripos($u['name'] . ' ' . $u['email'], $q) !== false)));
    $out([ 'users' => $found, 'count' => count($found), 'next_page' => null ]);
}
if ($p === '/users/create_or_update' && $method === 'POST') {
    $in = (array)($body['user'] ?? []);
    $id = null;
    foreach($db['users'] as $u)
	if ((isset($in['email']) && strcasecmp($u['email'], $in['email']) === 0) || (isset($in['external_id']) && ($u['external_id'] ?? null) === $in['external_id']))
	    $id = $u['id'];
    $created = $id === null;
    if ($created) {
	if (empty($in['name']))
	    $invalid([ 'name' => [ [ 'description' => 'Name: is too short (minimum one character)', 'error' => 'InvalidValue' ] ] ]);
	$id = $db['next']++;
	$db['users'][$id] = [ 'id' => $id, 'role' => 'end-user', 'active' => true, 'organization_id' => null, 'tags' => [], 'email' => null ];
    }
    $db['users'][$id] = array_replace($db['users'][$id], $in);
    $commit();
    $out([ 'user' => $db['users'][$id] ], $created ? 201 : 200);
}
if (preg_match('~^/users/(\d+)(/tickets/requested)?$~', $p, $mm)) {
    if (!isset($db['users'][(int)$mm[1]]))
	$out([ 'error' => 'RecordNotFound', 'description' => 'Not found' ], 404);
    if (!empty($mm[2]))
	$page(array_reverse(array_values(array_filter($db['tickets'], fn($t) => $t['requester_id'] === (int)$mm[1]))), 'tickets');
    $out([ 'user' => $db['users'][(int)$mm[1]] ]);
}
if ($p === '/organizations')
    $page($db['organizations'], 'organizations');
if ($p === '/organizations/autocomplete' || $p === '/organizations/search') {
    $n = (string)($query['name'] ?? '');
    $out([ 'organizations' => array_values(array_filter($db['organizations'], fn($o) => $p === '/organizations/search' ? strcasecmp($o['name'], $n) === 0 : stripos($o['name'], $n) === 0)) ]);
}
if ($p === '/organizations/create_or_update' && $method === 'POST') {
    $in = (array)($body['organization'] ?? []);
    $id = null;
    foreach($db['organizations'] as $o)
	if (strcasecmp($o['name'], (string)($in['name'] ?? '')) === 0)
	    $id = $o['id'];
    if ($id === null) {
	$id = $db['next']++;
	$db['organizations'][$id] = [ 'id' => $id, 'domain_names' => [], 'tags' => [], 'organization_fields' => [] ];
    }
    $db['organizations'][$id] = array_replace($db['organizations'][$id], $in);
    $commit();
    $out([ 'organization' => $db['organizations'][$id] ]);
}
if (preg_match('~^/organizations/(\d+)(/tickets)?$~', $p, $mm)) {
    if (!empty($mm[2]))
	$page(array_values(array_filter($db['tickets'], fn($t) => $t['organization_id'] === (int)$mm[1])), 'tickets');
    $out([ 'organization' => $db['organizations'][(int)$mm[1]] ?? null ]);
}
if ($p === '/groups')
    $page($db['groups'], 'groups');
if ($p === '/ticket_fields')
    $page($db['ticket_fields'], 'ticket_fields');
if ($p === '/views/active')
    $page($db['views'], 'views');
if ($p === '/macros/active')
    $page($db['macros'], 'macros');
if (preg_match('~^/views/(\d+)/tickets$~', $p))
    $page(array_values(array_filter($db['tickets'], fn($t) => in_array($t['status'], [ 'new', 'open', 'pending', 'hold' ], true))), 'tickets');
$out([ 'error' => 'InvalidEndpoint', 'description' => "not in the fake: {$method} {$p}" ], 404);
