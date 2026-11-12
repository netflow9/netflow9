<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: a fake GLPI for the check suite (CheckCommand::suiteGlpi), run by PHP's built-in server:
    GLPI_STATE=<a folder> php -S 127.0.0.1:<port> GlpiTestServer.php
	/apirest.php: initSession with `user_token tok-glpi` or Basic glpi / secret, and App-Token app-1 (else 401 / 400); then Session-Token (else 401 ERROR_SESSION_TOKEN_INVALID).
	Items in <state>/items.json { Itemtype: { id: fields } }, seeded with users, groups, categories (two "Printers": Hardware > Printers, Software > Printers), entities, locations,
	states, solution types. Lists by range (Content-Range, 206, ERROR_RANGE_EXCEED_TOTAL), searchText (a part, any case), sort / order, expand_dropdowns (the *_id fields by name);
	sub-items of tickets (ITILFollowup, TicketTask, Ticket_User, Group_Ticket) and users (UserEmail, Group_User, Profile_User); getMultipleItems; the search engine for Ticket
	(fields 1 name, 2 id, 12 status: all / notold / old / a number, 7 category, 19 date_mod; sort 2 / 19) and User (5 email). Adds set date / date_mod / date_creation from
	<state>/now.txt (Y-m-d H:i:s, else the clock); follow-ups, tasks and solutions change the ticket's date_mod (a solution: status 5). A document's multipart: uploadManifest
	+ filename[0]. <state>/expire.json: the next request's session refused once. Each request appended to <state>/requests.jsonl (method, path, query, body, headers)
*/

$state = getenv('GLPI_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (isset($_POST['uploadManifest']))
    $body = json_decode($_POST['uploadManifest'], true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body,
    'headers' => array_filter([ 'app' => $hdr('App-Token'), 'session' => $hdr('Session-Token'), 'auth' => $hdr('Authorization') ]),
    'files' => array_map(fn($f) => [ (array)$f['name'], (array)$f['size'] ], $_FILES) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$err = fn($status, $code, $message = '') => $out([ $code, $message !== '' ? "{$message}; view documentation in your browser at http://x/apirest.php/#{$code}" : '' ], $status);

if (!str_starts_with($path, '/apirest.php'))
    $out([ 'not the API' ], 404);
$p = substr($path, strlen('/apirest.php')) ?: '/';
if ($hdr('App-Token') !== 'app-1')
    $err(400, 'ERROR_WRONG_APP_TOKEN_PARAMETER', 'parameter app_token seems wrong');
$sessions = $load('sessions', []);
if ($p === '/initSession') {
    $auth = (string)$hdr('Authorization');
    if ($auth !== 'user_token tok-glpi' && $auth !== 'Basic ' . base64_encode('glpi:secret'))
	$err(401, 'ERROR_GLPI_LOGIN_USER_TOKEN', 'parameter user_token seems invalid');
    $token = 'sess-' . bin2hex(random_bytes(6));
    $sessions[] = $token;
    $save('sessions', $sessions);
    $out([ 'session_token' => $token ]);
}
$session = (string)$hdr('Session-Token');
if (!in_array($session, $sessions, true))
    $err(401, 'ERROR_SESSION_TOKEN_INVALID', 'session_token seems invalid');
if ((int)$load('expire', 0) > 0) {
    $save('expire', 0);
    $save('sessions', array_values(array_diff($sessions, [ $session ])));
    $err(401, 'ERROR_SESSION_TOKEN_INVALID', 'session_token seems invalid');
}
if ($p === '/killSession') {
    $save('sessions', array_values(array_diff($sessions, [ $session ])));
    $out(true);
}
if (in_array($p, [ '/changeActiveProfile', '/changeActiveEntities' ], true) && $method === 'POST')
    $out(true);
if ($p === '/getFullSession')
    $out([ 'session' => [ 'glpiname' => 'bot', 'glpifirstname' => 'Workflow', 'glpirealname' => 'Bot', 'glpiactiveprofile' => [ 'id' => 6, 'name' => 'Technician' ],
	'glpiactive_entity_name' => 'Root entity' ] ]);

$now = trim((string)@file_get_contents("{$state}/now.txt")) ?: date('Y-m-d H:i:s');
$db = $load('items', null) ?? [
    'User' => [ 2 => [ 'id' => 2, 'name' => 'glpi', 'realname' => 'Bot', 'firstname' => 'Workflow', 'is_active' => 1, 'entities_id' => 0, 'api_token' => 'secret-token' ],
	5 => [ 'id' => 5, 'name' => 'ana', 'realname' => 'Pop', 'firstname' => 'Ana', 'is_active' => 1, 'entities_id' => 0 ],
	6 => [ 'id' => 6, 'name' => 'dan', 'realname' => 'Ionescu', 'firstname' => 'Dan', 'is_active' => 1, 'entities_id' => 0 ] ],
    'UserEmail' => [ 1 => [ 'id' => 1, 'users_id' => 5, 'email' => 'ana@example.com', 'is_default' => 1 ], 2 => [ 'id' => 2, 'users_id' => 6, 'email' => 'dan@example.com', 'is_default' => 1 ] ],
    'Group_User' => [ 1 => [ 'id' => 1, 'users_id' => 5, 'groups_id' => 1 ] ],
    'Profile_User' => [ 1 => [ 'id' => 1, 'users_id' => 5, 'profiles_id' => 6, 'entities_id' => 0, 'is_recursive' => 1 ] ],
    'Profile' => [ 6 => [ 'id' => 6, 'name' => 'Technician' ] ],
    'Group' => [ 1 => [ 'id' => 1, 'name' => 'Helpdesk', 'completename' => 'Helpdesk', 'entities_id' => 0 ], 2 => [ 'id' => 2, 'name' => 'Network', 'completename' => 'Network', 'entities_id' => 0 ] ],
    'ITILCategory' => [ 1 => [ 'id' => 1, 'name' => 'Hardware', 'completename' => 'Hardware', 'entities_id' => 0 ],
	2 => [ 'id' => 2, 'name' => 'Printers', 'completename' => 'Hardware &#62; Printers', 'entities_id' => 0 ],
	3 => [ 'id' => 3, 'name' => 'Printers', 'completename' => 'Software &#62; Printers', 'entities_id' => 0 ] ],
    'Entity' => [ 0 => [ 'id' => 0, 'name' => 'Root entity', 'completename' => 'Root entity', 'entities_id' => -1 ], 1 => [ 'id' => 1, 'name' => 'Branch', 'completename' => 'Root entity &#62; Branch', 'entities_id' => 0 ] ],
    'Location' => [ 1 => [ 'id' => 1, 'name' => 'HQ', 'completename' => 'HQ', 'entities_id' => 0 ] ],
    'State' => [ 1 => [ 'id' => 1, 'name' => 'In use', 'completename' => 'In use' ] ],
    'SolutionType' => [ 1 => [ 'id' => 1, 'name' => 'Fixed' ] ],
    'RequestType' => [ 1 => [ 'id' => 1, 'name' => 'Helpdesk' ] ],
    'Ticket' => [], 'ITILFollowup' => [], 'TicketTask' => [], 'ITILSolution' => [], 'Ticket_User' => [], 'Group_Ticket' => [], 'Document' => [], 'Computer' => [],
];
$commit = function() use (&$db, $save) {
    $save('items', $db);
};
$refs = [ 'itilcategories_id' => 'ITILCategory', 'entities_id' => 'Entity', 'locations_id' => 'Location', 'users_id' => 'User', 'users_id_tech' => 'User', 'groups_id' => 'Group',
    'states_id' => 'State', 'requesttypes_id' => 'RequestType', 'profiles_id' => 'Profile', 'solutiontypes_id' => 'SolutionType' ];
$expand = function(array $i) use (&$db, $refs, $query) {
    if (($query['expand_dropdowns'] ?? '') !== 'true')
	return $i;
    foreach($refs as $k => $type)
	if (isset($i[$k]) && isset($db[$type][$i[$k]])) {
	    $d = $db[$type][$i[$k]];
	    $i[$k] = $type === 'User' ? trim("{$d['realname']} {$d['firstname']}") : ($d['completename'] ?? $d['name']);
	} elseif (isset($i[$k]) && (int)$i[$k] === 0 && $type !== 'Entity')
	    $i[$k] = '';
    return $i;
};
$list = function(array $all) use ($query, $out, $err, $expand) {
    $all = array_values($all);
    foreach((array)($query['searchText'] ?? []) as $k => $v)
	$all = array_values(array_filter($all, fn($i) => stripos(html_entity_decode((string)($i[$k] ?? '')), (string)$v) !== false));
    if (isset($query['sort'])) {
	usort($all, fn($a, $b) => strcmp((string)($a[$query['sort']] ?? ''), (string)($b[$query['sort']] ?? '')) ?: $a['id'] <=> $b['id']);
	if (strtoupper($query['order'] ?? 'ASC') === 'DESC')
	    $all = array_reverse($all);
    }
    [ $a, $b ] = array_map('intval', explode('-', (string)($query['range'] ?? '0-49')) + [ 0, 49 ]);
    $total = count($all);
    if ($total > 0 && $a >= $total)
	$err(400, 'ERROR_RANGE_EXCEED_TOTAL', 'Provided range exceed total count of data');
    $page = array_map($expand, array_slice($all, $a, $b - $a + 1));
    $out($page, $total > count($page) ? 206 : 200, $total ? [ 'Content-Range' => "{$a}-" . ($a + count($page) - 1) . "/{$total}", 'Accept-Range' => 'Ticket 1000' ] : []);
};
$parts = array_values(array_filter(explode('/', $p), 'strlen'));

if ($p === '/getMultipleItems') {
    $items = [];
    foreach((array)($query['items'] ?? []) as $it)
	if (isset($db[$it['itemtype']][$it['items_id']]))
	    $items[] = $expand($db[$it['itemtype']][$it['items_id']]);
    $out($items);
}
if (($parts[0] ?? '') === 'listSearchOptions')
    $out([ 'common' => [ 'name' => 'Characteristics' ], '1' => [ 'name' => 'Title', 'table' => 'glpi_tickets', 'field' => 'name', 'datatype' => 'itemlink', 'available_searchtypes' => [ 'contains' ] ],
	'2' => [ 'name' => 'ID', 'table' => 'glpi_tickets', 'field' => 'id', 'datatype' => 'number', 'available_searchtypes' => [ 'contains' ] ],
	'7' => [ 'name' => 'Category', 'table' => 'glpi_itilcategories', 'field' => 'completename', 'datatype' => 'dropdown', 'available_searchtypes' => [ 'contains', 'equals', 'notequals', 'under' ] ],
	'12' => [ 'name' => 'Status', 'table' => 'glpi_tickets', 'field' => 'status', 'datatype' => 'specific', 'available_searchtypes' => [ 'equals', 'notequals' ] ] ]);
if (($parts[0] ?? '') === 'search') {
    $type = $parts[1] ?? '';
    $all = array_values($db[$type] ?? []);
    foreach((array)($query['criteria'] ?? []) as $c) {
	$f = (int)$c['field'];
	$v = (string)$c['value'];
	if ($type === 'Ticket' && $f === 12)
	    $all = array_filter($all, fn($t) => $v === 'all' || ($v === 'notold' ? $t['status'] < 5 : ($v === 'old' ? $t['status'] >= 5 : (string)$t['status'] === $v)));
	elseif ($type === 'Ticket' && $f === 1)
	    $all = array_filter($all, fn($t) => stripos($t['name'], $v) !== false);
	elseif ($type === 'Ticket' && $f === 7)
	    $all = array_filter($all, fn($t) => (string)$t['itilcategories_id'] === $v);
	elseif ($type === 'User' && $f === 5)
	    $all = array_filter($all, fn($u) => (bool)array_filter($db['UserEmail'], fn($e) => $e['users_id'] === $u['id'] && stripos($e['email'], $v) !== false));
	else
	    $err(400, 'ERROR_BAD_ARRAY', "criterion {$f} not understood by the fake");
    }
    $col = [ 1 => 'name', 2 => 'id', 12 => 'status', 19 => 'date_mod', 15 => 'date' ];
    $sort = $col[(int)($query['sort'] ?? 1)] ?? 'id';
    $all = array_values($all);
    usort($all, fn($a, $b) => strcmp((string)$a[$sort], (string)$b[$sort]) ?: $a['id'] <=> $b['id']);
    if (strtoupper($query['order'] ?? 'ASC') === 'DESC')
	$all = array_reverse($all);
    [ $a, $b ] = array_map('intval', explode('-', (string)($query['range'] ?? '0-49')) + [ 0, 49 ]);
    $total = count($all);
    if ($total > 0 && $a >= $total)
	$err(400, 'ERROR_RANGE_EXCEED_TOTAL', 'Provided range exceed total count of data');
    $rows = [];
    foreach(array_slice($all, $a, $b - $a + 1) as $i) {
	$row = [ '1' => $i['name'] ?? null, '2' => $i['id'] ];
	foreach((array)($query['forcedisplay'] ?? []) as $fd)
	    $row[(string)$fd] = (int)$fd === 5 ? implode('$#$', array_column(array_filter($db['UserEmail'], fn($e) => $e['users_id'] === $i['id']), 'email')) : ($i[$col[(int)$fd] ?? ''] ?? null);
	$rows[] = $row;
    }
    $out([ 'totalcount' => $total, 'count' => count($rows), 'sort' => [ (int)($query['sort'] ?? 1) ], 'order' => [ $query['order'] ?? 'ASC' ], 'data' => $rows ], $total > count($rows) ? 206 : 200);
}

$type = $parts[0] ?? '';
$id = isset($parts[1]) ? (int)$parts[1] : null;
$sub = $parts[2] ?? null;
if (!isset($db[$type]))
    $err(400, 'ERROR_ITEMTYPE_NOT_FOUND_NOR_COMMONDBTM', 'You asked a wrong itemtype');
$touch = function($tid) use (&$db, $now) {
    if (isset($db['Ticket'][$tid]))
	$db['Ticket'][$tid]['date_mod'] = $now;
};

if ($method === 'GET') {
    if ($id === null)
	$list($db[$type]);
    if (!isset($db[$type][$id]))
	$err(404, 'ERROR_ITEM_NOT_FOUND', 'Item not found');
    if ($sub !== null) {
	$link = [ 'ITILFollowup' => fn($x) => $x['itemtype'] === $type && $x['items_id'] === $id, 'TicketTask' => fn($x) => $x['tickets_id'] === $id, 'Ticket_User' => fn($x) => $x['tickets_id'] === $id,
	    'Group_Ticket' => fn($x) => $x['tickets_id'] === $id, 'UserEmail' => fn($x) => $x['users_id'] === $id, 'Group_User' => fn($x) => $x['users_id'] === $id,
	    'Profile_User' => fn($x) => $x['users_id'] === $id ][$sub] ?? null;
	if (!$link)
	    $err(400, 'ERROR_ITEMTYPE_NOT_FOUND_NOR_COMMONDBTM', 'You asked a wrong itemtype');
	$list(array_filter($db[$sub], $link));
    }
    $item = $expand($db[$type][$id]);
    foreach($query as $k => $v)
	if (str_starts_with($k, 'with_') && $v === 'true')
	    $item["_{$k}"] = [];
    $out($item);
}
if ($method === 'POST' && $id === null) {
    $in = (array)($body['input'] ?? []);
    $new = $db[$type] ? max(array_keys($db[$type])) + 1 : 1;
    if ($type === 'Ticket') {
	if (trim((string)($in['name'] ?? '')) === '' || trim((string)($in['content'] ?? '')) === '')
	    $err(400, 'ERROR_GLPI_ADD', 'Mandatory fields are not filled. Please correct: Title, Description');
	if (isset($in['itilcategories_id']) && !isset($db['ITILCategory'][$in['itilcategories_id']]))
	    $err(400, 'ERROR_GLPI_ADD', 'Category not found');
	$t = [ 'id' => $new, 'entities_id' => (int)($in['entities_id'] ?? 0), 'name' => $in['name'], 'content' => htmlspecialchars($in['content'], ENT_NOQUOTES), 'status' => !empty($in['_users_id_assign']) || !empty($in['_groups_id_assign']) ? 2 : 1,
	    'type' => (int)($in['type'] ?? 1), 'urgency' => (int)($in['urgency'] ?? 3), 'impact' => (int)($in['impact'] ?? 3), 'priority' => (int)($in['priority'] ?? 3),
	    'itilcategories_id' => (int)($in['itilcategories_id'] ?? 0), 'requesttypes_id' => 1, 'locations_id' => (int)($in['locations_id'] ?? 0), 'date' => $now, 'date_mod' => $now,
	    'date_creation' => $now, 'solvedate' => null, 'closedate' => null, 'is_deleted' => 0 ];
	$db['Ticket'][$new] = $t;
	$actor = function($link, $key, $who, $role, $email = '') use (&$db, $new) {
	    $n = $db[$link] ? max(array_keys($db[$link])) + 1 : 1;
	    $db[$link][$n] = [ 'id' => $n, 'tickets_id' => $new, $key => $who, 'type' => $role ] + ($link === 'Ticket_User' ? [ 'alternative_email' => $email, 'use_notification' => 1 ] : []);
	};
	if (isset($in['_users_id_requester']))
	    $actor('Ticket_User', 'users_id', (int)$in['_users_id_requester'], 1, (string)($in['_users_id_requester_notif']['alternative_email'][0] ?? ''));
	else
	    $actor('Ticket_User', 'users_id', 2, 1);
	if (!empty($in['_users_id_assign']))
	    $actor('Ticket_User', 'users_id', (int)$in['_users_id_assign'], 2);
	if (!empty($in['_groups_id_assign']))
	    $actor('Group_Ticket', 'groups_id', (int)$in['_groups_id_assign'], 2);
    } elseif ($type === 'Document') {
	$f = $_FILES['filename'] ?? null;
	if (!$f)
	    $err(400, 'ERROR_UPLOAD_FILE_TOO_BIG_POST_MAX_SIZE', 'no file');
	$db['Document'][$new] = [ 'id' => $new, 'name' => $in['name'] ?? '', 'filename' => (array)$f['name'], 'size' => (array)$f['size'], 'itemtype' => $in['itemtype'] ?? null,
	    'items_id' => $in['items_id'] ?? null ];
    } else {
	if (in_array($type, [ 'ITILFollowup', 'ITILSolution' ], true) && !isset($db['Ticket'][$in['items_id'] ?? 0]))
	    $err(400, 'ERROR_GLPI_ADD', 'no ticket');
	$db[$type][$new] = [ 'id' => $new ] + $in + [ 'date' => $now, 'users_id' => 2 ];
	if (in_array($type, [ 'ITILFollowup', 'ITILSolution' ], true))
	    $touch((int)$in['items_id']);
	if (in_array($type, [ 'TicketTask', 'Ticket_User', 'Group_Ticket' ], true))
	    $touch((int)$in['tickets_id']);
	if ($type === 'ITILSolution') {
	    $db['Ticket'][(int)$in['items_id']]['status'] = 5;
	    $db['Ticket'][(int)$in['items_id']]['solvedate'] = $now;
	}
	if ($type === 'Computer')
	    $db[$type][$new] += [ 'date_mod' => $now, 'entities_id' => 0, 'is_deleted' => 0 ];
    }
    $commit();
    $out([ 'id' => $new, 'message' => "Item successfully added: {$type} {$new}" ], 201, [ 'Location' => "http://x/apirest.php/{$type}/{$new}" ]);
}
if ($method === 'PUT' && $id !== null) {
    if (!isset($db[$type][$id]))
	$out([ [ (string)$id => false, 'message' => 'Item not found' ] ], 207);
    $db[$type][$id] = array_replace($db[$type][$id], (array)($body['input'] ?? []), [ 'date_mod' => $now ]);
    if ($type === 'Ticket' && (int)($body['input']['status'] ?? 0) === 6)
	$db[$type][$id]['closedate'] = $now;
    $commit();
    $out([ [ (string)$id => true, 'message' => '' ] ]);
}
if ($method === 'DELETE' && $id !== null) {
    if (!isset($db[$type][$id]))
	$out([ [ (string)$id => false, 'message' => 'Item not found' ] ], 207);
    if (($query['force_purge'] ?? '') === 'true' || !array_key_exists('is_deleted', $db[$type][$id]))
	unset($db[$type][$id]);
    else
	$db[$type][$id]['is_deleted'] = 1;
    $commit();
    $out([ [ (string)$id => true, 'message' => '' ] ]);
}
$err(400, 'ERROR_METHOD_NOT_ALLOWED', 'not in the fake');
