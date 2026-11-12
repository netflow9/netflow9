<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: a fake Jira for the check suite (CheckCommand::suiteJira), run by PHP's built-in server:
    JIRA_STATE=<a folder> php -S 127.0.0.1:<port> JiraTestServer.php
	Cloud: /rest/api/3, Basic bot@example.com / tok-cloud; Data Center: /rest/api/2, Bearer pat-dc (else 401). Issues in <state>/issues.json { KEY: { id, key, fields } };
	the workflow: To Do -(Start)-> In Progress -(Resolve)-> Done, To Do -(Close)-> Done. Search: POST /rest/api/3/search/jql (nextPageToken) and /rest/api/2/search (startAt), JQL
	understood: project = X, created / updated >= -<n>m (now: <state>/now.txt, ms, else the clock), ORDER BY created / updated ASC / DESC.
	<state>/throttle.json: how many 429 answers next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, headers)
*/

$state = getenv('JIRA_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body,
    'headers' => array_filter([ 'atlassian' => $hdr('X-Atlassian-Token'), 'auth' => $hdr('Authorization') ]), 'files' => array_map(fn($f) => [ $f['name'], $f['size'] ], $_FILES) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json;charset=UTF-8');
    if ($v !== null)
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$err = fn($status, array $messages = [], array $errors = []) => $out([ 'errorMessages' => $messages, 'errors' => (object)$errors ], $status);

$cloud = str_starts_with($path, '/rest/api/3');
$auth = (string)$hdr('Authorization');
$ok = $cloud ? $auth === 'Basic ' . base64_encode('bot@example.com:tok-cloud') : $auth === 'Bearer pat-dc';
if (!$ok)
    $err(401, [ 'You are not authenticated. Authentication required to perform this operation.' ]);
$throttle = (int)$load('throttle', 0);
if ($throttle > 0) {
    $save('throttle', $throttle - 1);
    header('Retry-After: 1');
    $err(429, [ 'Rate limit exceeded.' ]);
}
$p = preg_replace('~^/rest/api/[23]~', '', $path);
$issues = $load('issues', []);
$now = (int)(trim((string)@file_get_contents("{$state}/now.txt")) ?: floor(microtime(true) * 1000));
$user = fn($id) => $cloud ? [ 'accountId' => $id, 'displayName' => ucfirst(explode('-', $id)[0]), 'emailAddress' => explode('-', $id)[0] . '@example.com', 'active' => true, 'accountType' => 'atlassian' ]
    : [ 'name' => $id, 'displayName' => ucfirst($id), 'emailAddress' => "{$id}@example.com", 'active' => true ];
$users = $cloud ? [ $user('ana-5b10a2844c20165700ede21g'), $user('dan-5b10a2844c20165700ede22h') ] : [ $user('ana'), $user('dan') ];
$flow = [ 'To Do' => [ [ 'id' => '11', 'name' => 'Start', 'to' => [ 'name' => 'In Progress' ] ], [ 'id' => '31', 'name' => 'Close', 'to' => [ 'name' => 'Done' ] ] ],
    'In Progress' => [ [ 'id' => '21', 'name' => 'Resolve', 'to' => [ 'name' => 'Done' ] ] ], 'Done' => [] ];
$iso = fn($ms) => gmdate('Y-m-d\TH:i:s', (int)floor($ms / 1000)) . sprintf('.%03d', $ms % 1000) . '+0000';
$text = fn($v) => is_array($v) ? $v : (string)$v;

if ($p === '/myself')
    $out(array_replace($cloud ? $user('bot-5b10a2844c20165700ede20f') : $user('bot'), [ 'displayName' => 'Workflow Bot', 'emailAddress' => 'bot@example.com' ]));
if ($p === '/serverInfo')
    $out([ 'serverTitle' => 'Check Jira', 'version' => $cloud ? '1001.0.0-SNAPSHOT' : '9.12.4', 'deploymentType' => $cloud ? 'Cloud' : 'Server' ]);
if (in_array($p, [ '/search/jql', '/search' ], true) && $method === 'POST') {
    $jql = (string)($body['jql'] ?? '');
    $list = array_values($issues);
    if (preg_match('/project\s*=\s*"?(\w+)"?/i', $jql, $m))
	$list = array_filter($list, fn($i) => strcasecmp($i['fields']['project']['key'], $m[1]) === 0);
    if (preg_match('/(created|updated)\s*>=\s*-(\d+)m/', $jql, $m))
	$list = array_filter($list, fn($i) => (new DateTimeImmutable($i['fields'][$m[1]]))->format('Uv') >= $now - (int)$m[2] * 60000);
    if (preg_match('/ORDER BY (created|updated) (ASC|DESC)/i', $jql, $m)) {
	usort($list, fn($a, $b) => strcmp($a['fields'][$m[1]], $b['fields'][$m[1]]));
	if (strtoupper($m[2]) === 'DESC')
	    $list = array_reverse($list);
    }
    $list = array_values($list);
    $from = $p === '/search/jql' ? (int)($body['nextPageToken'] ?? 0) : (int)($body['startAt'] ?? 0);
    $page = array_slice($list, $from, (int)($body['maxResults'] ?? 50));
    $end = $from + count($page);
    $out($p === '/search/jql' ? [ 'issues' => $page, 'isLast' => $end >= count($list) ] + ($end < count($list) ? [ 'nextPageToken' => (string)$end ] : [])
	: [ 'issues' => $page, 'startAt' => $from, 'maxResults' => count($page), 'total' => count($list) ]);
}
if ($p === '/issue' && $method === 'POST') {
    $f = (array)($body['fields'] ?? []);
    $errors = [];
    if (empty($f['summary']))
	$errors['summary'] = 'You must specify a summary of the issue.';
    if (($f['issuetype']['name'] ?? '') === 'Epic')
	$errors['issuetype'] = 'The issue type selected is invalid.';
    if ($errors)
	$err(400, [], $errors);
    $n = count($issues) + 1;
    $key = strtoupper($f['project']['key']) . "-{$n}";
    $issues[$key] = [ 'id' => (string)(10000 + $n), 'key' => $key, 'fields' => [ 'summary' => $f['summary'], 'status' => [ 'name' => 'To Do', 'statusCategory' => [ 'key' => 'new' ] ], 'issuetype' => $f['issuetype'],
	'priority' => $f['priority'] ?? [ 'name' => 'Medium' ], 'project' => [ 'key' => strtoupper($f['project']['key']) ], 'assignee' => isset($f['assignee']) ? array_filter($user($f['assignee']['accountId'] ?? $f['assignee']['name'])) : null,
	'reporter' => $user('bot'), 'labels' => $f['labels'] ?? [], 'components' => $f['components'] ?? [], 'created' => $iso($now), 'updated' => $iso($now), 'duedate' => $f['duedate'] ?? null,
	'description' => $f['description'] ?? null, 'parent' => $f['parent'] ?? null ] + array_filter($f, fn($k) => str_starts_with($k, 'customfield_'), ARRAY_FILTER_USE_KEY) ];
    $save('issues', $issues);
    $out([ 'id' => $issues[$key]['id'], 'key' => $key, 'self' => "http://jira/rest/api/3/issue/{$key}" ], 201);
}
if (preg_match('~^/issue/([A-Z0-9_]+-\d+)(?:/(transitions|comment|assignee|attachments))?$~', $p, $m)) {
    $key = $m[1];
    $sub = $m[2] ?? '';
    if (!isset($issues[$key]))
	$err(404, [ 'Issue does not exist or you do not have permission to see it.' ]);
    $i = &$issues[$key];
    $status = $i['fields']['status']['name'];
    $touch = function() use (&$i, $iso, $now) { $i['fields']['updated'] = $iso($now); };
    if ($sub === '' && $method === 'GET')
	$out($i);
    if ($sub === '' && $method === 'PUT') {
	foreach((array)($body['fields'] ?? []) as $k => $v)
	    $i['fields'][$k] = $v;
	foreach((array)($body['update']['labels'] ?? []) as $op)
	    $i['fields']['labels'] = isset($op['add']) ? array_values(array_unique(array_merge($i['fields']['labels'], [ $op['add'] ]))) : array_values(array_diff($i['fields']['labels'], [ $op['remove'] ]));
	$touch();
	$save('issues', $issues);
	$out(null, 204);
    }
    if ($sub === '' && $method === 'DELETE') {
	unset($issues[$key]);
	$save('issues', $issues);
	$out(null, 204);
    }
    if ($sub === 'assignee') {
	$id = $body['accountId'] ?? $body['name'] ?? null;
	$i['fields']['assignee'] = $id === null ? null : $user($id);
	$touch();
	$save('issues', $issues);
	$out(null, 204);
    }
    if ($sub === 'transitions' && $method === 'GET')
	$out([ 'transitions' => $flow[$status] ?? [] ]);
    if ($sub === 'transitions') {
	$t = array_values(array_filter($flow[$status] ?? [], fn($t) => $t['id'] === ($body['transition']['id'] ?? '')))[0] ?? null;
	if (!$t)
	    $err(400, [ "Transition id '" . ($body['transition']['id'] ?? '') . "' is not valid for this issue." ]);
	$i['fields']['status'] = [ 'name' => $t['to']['name'], 'statusCategory' => [ 'key' => $t['to']['name'] === 'Done' ? 'done' : 'indeterminate' ] ];
	if (isset($body['fields']['resolution']))
	    $i['fields']['resolution'] = $body['fields']['resolution'];
	foreach((array)($body['update']['comment'] ?? []) as $c)
	    $i['comments'][] = [ 'id' => (string)(count($i['comments'] ?? []) + 1), 'body' => $c['add']['body'], 'author' => $user('bot'), 'created' => $iso($now) ];
	$touch();
	$save('issues', $issues);
	$out(null, 204);
    }
    if ($sub === 'comment' && $method === 'POST') {
	$c = [ 'id' => (string)(100 + count($i['comments'] ?? [])), 'body' => $text($body['body'] ?? ''), 'author' => $user('bot'), 'created' => $iso($now) ];
	$i['comments'][] = $c;
	$save('issues', $issues);
	$out($c, 201);
    }
    if ($sub === 'comment')
	$out([ 'comments' => array_reverse($i['comments'] ?? []), 'total' => count($i['comments'] ?? []) ]);
    if ($sub === 'attachments') {
	if ($hdr('X-Atlassian-Token') !== 'no-check')
	    $err(403, [ 'XSRF check failed' ]);
	$f = $_FILES['file'] ?? null;
	if (!$f)
	    $err(400, [ 'no file' ]);
	$out([ [ 'id' => '9001', 'filename' => $f['name'], 'size' => $f['size'], 'mimeType' => 'application/octet-stream', 'content' => "http://jira/secure/attachment/9001/{$f['name']}" ] ]);
    }
}
if ($p === '/issueLink' && $method === 'POST') {
    foreach([ 'inwardIssue', 'outwardIssue' ] as $k)
	if (!isset($issues[$body[$k]['key'] ?? '']))
	    $err(404, [ 'Issue does not exist or you do not have permission to see it.' ]);
    $out(null, 201);
}
if ($p === '/user/search')
    $out(array_values(array_filter($users, fn($u) => stripos(json_encode($u), (string)($query['query'] ?? $query['username'] ?? '')) !== false)));
if ($p === '/project/search')
    $out([ 'values' => [ [ 'id' => '10000', 'key' => 'OPS', 'name' => 'Operations', 'projectTypeKey' => 'software', 'lead' => $users[0] ] ], 'isLast' => true ]);
if ($p === '/project')
    $out([ [ 'id' => '10000', 'key' => 'OPS', 'name' => 'Operations', 'projectTypeKey' => 'software' ], [ 'id' => '10001', 'key' => 'HR', 'name' => 'People' ] ]);
if ($p === '/project/OPS')
    $out([ 'id' => '10000', 'key' => 'OPS', 'name' => 'Operations', 'issueTypes' => [ [ 'id' => '1', 'name' => 'Task', 'subtask' => false ], [ 'id' => '5', 'name' => 'Sub-task', 'subtask' => true ] ],
	'components' => [ [ 'id' => '7', 'name' => 'Backend' ] ] ]);
if ($p === '/field')
    $out([ [ 'id' => 'summary', 'name' => 'Summary', 'custom' => false, 'schema' => [ 'type' => 'string' ] ], [ 'id' => 'customfield_10016', 'name' => 'Story point estimate', 'custom' => true, 'schema' => [ 'type' => 'number' ] ] ]);
if ($p === '/priority')
    $out([ [ 'id' => '1', 'name' => 'Highest' ], [ 'id' => '3', 'name' => 'Medium' ] ]);
if ($p === '/issueLinkType')
    $out([ 'issueLinkTypes' => [ [ 'id' => '1', 'name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks' ] ] ]);
$err(404, [ 'Not found' ]);
