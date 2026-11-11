<?php

/*
    a fake Wazuh (Server API and indexer, on one port) for the check suite (CheckCommand::suiteWazuh), run by PHP's built-in server:
    WZ_STATE=<a folder> php -S 127.0.0.1:<port> WazuhTestServer.php
	Server API: POST /security/user/authenticate with Basic wazuh-wui / s3cret gives a JWT; the others want it as Bearer (<state>/expired: 401 once, for the token in use)
	Like the real one it refuses a parameter a request does not take (Wazuh's spec is strict), e.g. wait_for_complete on PUT /manager/restart
	Indexer: /wazuh-*: Basic admin / 1ndexer; <state>/alerts.json the alerts, as { _id, timestamp, ... }; term(s), range, ids, bool.filter / should, sort, size, a terms aggregation
	Each request appended to <state>/requests.jsonl (method, path, query, body, authorization)
*/

$state = getenv('WZ_STATE') ?: sys_get_temp_dir();
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$method = $_SERVER['REQUEST_METHOD'];
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $q, 'body' => $body, 'authorization' => $headers['authorization'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

$answer = function($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
};
$problem = fn($status, $title, $detail, $code = null) => $answer([ 'title' => $title, 'detail' => $detail ] + ($code !== null ? [ 'error' => $code ] : []), $status);
$basic = fn($user, $password) => ($headers['authorization'] ?? '') === 'Basic ' . base64_encode("{$user}:{$password}");

// ---------------------------------------------------------------------------- the indexer

if (str_starts_with($path, '/wazuh-')) {
    if (!$basic('admin', '1ndexer'))
	$answer([ 'error' => [ 'type' => 'security_exception', 'reason' => 'missing authentication credentials' ], 'status' => 401 ], 401);
    if (is_file("{$state}/indexer-error"))
	$answer([ 'error' => [ 'root_cause' => [ [ 'type' => 'query_shard_exception', 'reason' => 'Failed to parse query' ] ], 'type' => 'search_phase_execution_exception', 'reason' => 'all shards failed' ], 'status' => 400 ], 400);
    $file = str_starts_with($path, '/wazuh-states-vulnerabilities-') ? 'vulnerabilities.json' : 'alerts.json';
    $docs = is_file("{$state}/{$file}") ? json_decode(file_get_contents("{$state}/{$file}"), true) : [];
    $get = function($doc, $field) {
	foreach(explode('.', $field) as $k)
	    $doc = is_array($doc) ? ($doc[$k] ?? null) : null;
	return $doc;
    };
    $match = function($doc, $f) use (&$match, $get) {
	foreach($f as $kind => $spec) {
	    if ($kind === 'bool') {
		foreach((array)($spec['filter'] ?? []) as $x) {
		    if (!$match($doc, $x))
			return false;
		}
		if (!empty($spec['should']) && !array_filter($spec['should'], fn($x) => $match($doc, $x)))
		    return false;
	    } elseif ($kind === 'ids') {
		if (!in_array($doc['_id'], $spec['values'], true))
		    return false;
	    } elseif ($kind === 'terms' || $kind === 'term') {
		foreach($spec as $field => $values) {
		    if (!array_intersect((array)$get($doc, $field), (array)$values))
			return false;
		}
	    } elseif ($kind === 'range') {
		foreach($spec as $field => $r) {
		    $v = $get($doc, $field);
		    $time = fn($x) => is_string($x) ? (float)(new DateTimeImmutable($x))->format('U.v') : (float)$x;
		    foreach([ 'gte' => fn($a, $b) => $a >= $b, 'gt' => fn($a, $b) => $a > $b, 'lte' => fn($a, $b) => $a <= $b ] as $op => $cmp) {
			if (isset($r[$op]) && ($v === null || !$cmp($time($v), $time($r[$op]))))
			    return false;
		    }
		}
	    } elseif ($kind === 'query_string') {
		if (!str_contains(json_encode($doc), trim($spec['query'], '"')))
		    return false;
	    }
	}
	return true;
    };
    $rows = array_values(array_filter($docs, fn($d) => $match($d, (array)($body['query'] ?? []))));
    foreach((array)($body['sort'] ?? []) as $s) {
	foreach($s as $field => $o) {
	    $sign = ($o['order'] ?? 'asc') === 'desc' ? -1 : 1;
	    $key = fn($d) => $field === 'timestamp' ? (float)(new DateTimeImmutable((string)$d['timestamp']))->format('U.v') : ($get($d, $field) ?? 0);
	    usort($rows, fn($a, $b) => $sign * ($key($a) <=> $key($b)));
	}
    }
    $out = [ 'hits' => [ 'total' => [ 'value' => count($rows), 'relation' => 'eq' ], 'hits' => array_map(function($d) {
	$id = $d['_id'];
	unset($d['_id']);
	return [ '_index' => 'wazuh-alerts-4.x-2026.11.03', '_id' => $id, '_source' => $d ];
    }, array_slice($rows, 0, (int)($body['size'] ?? 10))) ], '_shards' => [ 'total' => 1, 'successful' => 1, 'failed' => 0 ] ];
    foreach((array)($body['aggs'] ?? []) as $name => $agg) {
	$counts = [];
	foreach($rows as $d) {
	    foreach((array)$get($d, $agg['terms']['field']) as $v)
		$counts[(string)$v] = ($counts[(string)$v] ?? 0) + 1;
	}
	arsort($counts);
	$out['aggregations'][$name]['buckets'] = array_map(fn($k, $c) => [ 'key' => (string)$k, 'doc_count' => $c ], array_keys($counts), array_values($counts));
    }
    $answer($out);
}

// ---------------------------------------------------------------------------- the Server API

if ($path === '/security/user/authenticate') {
    if (!$basic('wazuh-wui', 's3cret'))
	$problem(401, 'Unauthorized', 'Invalid credentials');
    $n = (int)@file_get_contents("{$state}/signins") + 1;
    file_put_contents("{$state}/signins", (string)$n);
    $b64 = fn($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
    $answer([ 'data' => [ 'token' => $b64([ 'alg' => 'none' ]) . '.' . $b64([ 'exp' => time() + 900, 'n' => $n ]) . '.sig' ], 'error' => 0 ]);
}
if (!str_starts_with($headers['authorization'] ?? '', 'Bearer '))
    $problem(401, 'Unauthorized', 'No authorization token provided');
if (is_file("{$state}/expired")) {
    unlink("{$state}/expired");
    $problem(401, 'Unauthorized', 'Invalid token');
}
$agents = is_file("{$state}/agents.json") ? json_decode(file_get_contents("{$state}/agents.json"), true) : [
    [ 'id' => '000', 'name' => 'manager', 'status' => 'active', 'group' => [] ],
    [ 'id' => '001', 'name' => 'web01', 'status' => 'active', 'group' => [ 'default', 'web' ], 'ip' => '10.0.0.5' ],
    [ 'id' => '002', 'name' => 'db01', 'status' => 'disconnected', 'group' => [ 'default' ], 'ip' => '10.0.0.6' ],
];
$items = fn($list, $total = null, $failed = [], $message = 'All selected items were returned') => $answer([ 'data' => [ 'affected_items' => array_values($list), 'total_affected_items' => $total ?? count($list),
    'total_failed_items' => count($failed), 'failed_items' => $failed ], 'message' => $message, 'error' => $failed ? ($list ? 2 : 1) : 0 ]);
$paged = function($list) use ($items, $q) {
    $n = count($list);
    $items(array_slice($list, (int)($q['offset'] ?? 0), (int)($q['limit'] ?? 500)), $n);
};
//each request and the parameters it takes, as Wazuh's spec says (others: 400)
$common = [ 'pretty', 'wait_for_complete' ];
$list = [ 'offset', 'limit', 'sort', 'search', 'select', 'q', 'distinct' ];
$allowed = [
    'GET /' => [ 'pretty' ],
    'GET /agents' => [ 'agents_list', 'status', 'group', 'older_than', 'name', 'ip' ],
    'POST /agents' => [],
    'DELETE /agents' => [ 'agents_list', 'status', 'older_than', 'purge', 'q' ],
    'GET /agents/summary/status' => [],
    'GET /agents/outdated' => [],
    'PUT /agents/restart' => [ 'agents_list' ],
    'PUT /agents/group' => [ 'agents_list', 'group_id', 'force_single_group' ],
    'DELETE /agents/group' => [ 'agents_list', 'group_id' ],
    'GET /groups' => [ 'groups_list', 'hash' ],
    'POST /groups' => [],
    'DELETE /groups' => [ 'groups_list' ],
    'PUT /active-response' => [ 'agents_list' ],
    'PUT /syscheck' => [ 'agents_list' ],
    'PUT /rootcheck' => [ 'agents_list' ],
    'GET /manager/status' => [],
    'GET /manager/info' => [],
    'GET /cluster/status' => [],
    'GET /manager/logs' => [ 'tag', 'level' ],
    'GET /rules' => [ 'rule_ids', 'status', 'group', 'level', 'filename' ],
    'GET /decoders' => [ 'decoder_names', 'status', 'filename' ],
];
$route = "{$method} {$path}";
$takes = $allowed[$route] ?? (preg_match('~^GET /(agents/\d+/key|syscheck/\d+/last_scan|syscollector/\d+/(os|hardware))$~', $route) ? [] :
    (preg_match('~^GET /(sca/\d+|sca/\d+/checks/[\w.-]+|syscheck/\d+|rootcheck/\d+|syscollector/\d+/\w+|groups/[\w.-]+/agents)$~', $route) ? [ 'result', 'file', 'hash', 'status' ] : null));
if ($route === 'PUT /manager/restart')
    $takes = [];
elseif ($takes !== null)
    $takes = array_merge($takes, $route === 'GET /' ? [] : $common, str_starts_with($route, 'GET') && $route !== 'GET /' ? $list : []);
if ($takes === null)
    $problem(404, 'Not Found', "The requested URL was not found: {$route}");
foreach(array_keys($q) as $k) {
    if (!in_array($k, $takes, true))
	$problem(400, 'Bad Request', "Extra query parameter(s) {$k} not in spec");
}

$ids = isset($q['agents_list']) ? explode(',', $q['agents_list']) : null;
$known = array_column($agents, 'id');
$of = fn($list) => [ array_values(array_intersect($list, $known)), array_map(fn($i) => [ 'error' => [ 'code' => 1701, 'message' => 'Agent does not exist', 'remediation' => 'Use GET /agents' ], 'id' => [ $i ] ], array_values(array_diff($list, $known))) ];

switch($route) {
    case 'GET /':
	$answer([ 'data' => [ 'title' => 'Wazuh API REST', 'api_version' => 'v4.14.0', 'revision' => 41400, 'hostname' => 'wazuh-manager', 'timestamp' => gmdate('c') ], 'error' => 0 ]);
    case 'GET /agents':
	$rows = $agents;
	if ($ids !== null)
	    $rows = array_filter($rows, fn($a) => in_array($a['id'], $ids, true));
	if (isset($q['status']))
	    $rows = array_filter($rows, fn($a) => in_array($a['status'], explode(',', $q['status']), true));
	if (isset($q['group']))
	    $rows = array_filter($rows, fn($a) => in_array($q['group'], $a['group'], true));
	if (isset($q['search']))
	    $rows = array_filter($rows, fn($a) => str_contains(json_encode($a), $q['search']));
	$paged(array_values($rows));
    case 'POST /agents':
	if (in_array($body['name'] ?? '', array_column($agents, 'name'), true))
	    $problem(400, 'Bad Request', 'An agent with the same name already exists', 1708);
	$answer([ 'data' => [ 'id' => '003', 'key' => 'c2VjcmV0a2V5' ], 'error' => 0 ]);
    case 'DELETE /agents':
	if (($q['status'] ?? '') !== 'all' || ($q['older_than'] ?? '') !== '0s')
	    $problem(400, 'Bad Request', "'status' is a required query parameter");
	[ $ok, $bad ] = $of($ids ?? []);
	$items($ok, null, $bad, $ok ? 'All selected agents were deleted' : 'No agents were deleted');
    case 'GET /agents/summary/status':
	$answer([ 'data' => [ 'connection' => [ 'active' => 2, 'disconnected' => 1, 'never_connected' => 0, 'pending' => 0, 'total' => 3 ], 'configuration' => [ 'synced' => 3, 'total' => 3, 'not_synced' => 0 ] ], 'error' => 0 ]);
    case 'GET /agents/outdated':
	$paged([ [ 'id' => '002', 'name' => 'db01', 'version' => 'Wazuh v4.7.0' ] ]);
    case 'PUT /agents/restart':
    case 'PUT /active-response':
    case 'PUT /syscheck':
    case 'PUT /rootcheck':
	if ($route === 'PUT /active-response' && empty($body['command']))
	    $problem(400, 'Bad Request', "'command' is a required property");
	[ $ok, $bad ] = $of($ids ?? array_values(array_diff($known, [ '000' ])));
	$items($ok, null, $bad, $ok ? 'Command sent' : 'Command was not sent to any agent');
    case 'PUT /agents/group':
    case 'DELETE /agents/group':
	[ $ok, $bad ] = $of($ids ?? array_values(array_diff($known, [ '000' ])));
	$items($ok, null, $bad);
    case 'GET /groups':
	$paged([ [ 'name' => 'default', 'count' => 2 ], [ 'name' => 'web', 'count' => 1 ] ]);
    case 'POST /groups':
	$answer([ 'message' => "Group '{$body['group_id']}' created.", 'error' => 0 ]);
    case 'DELETE /groups':
	$items([ $q['groups_list'] ], null, [], 'All selected groups were deleted');
    case 'GET /manager/status':
	$items([ [ 'wazuh-analysisd' => 'running', 'wazuh-remoted' => 'running', 'wazuh-agentlessd' => 'stopped', 'wazuh-db' => 'failed' ] ]);
    case 'GET /manager/info':
	$items([ [ 'version' => 'v4.14.0', 'type' => 'server', 'path' => '/var/ossec' ] ]);
    case 'GET /cluster/status':
	$answer([ 'data' => [ 'enabled' => 'no', 'running' => 'no' ], 'error' => 0 ]);
    case 'GET /manager/logs':
	$paged([ [ 'timestamp' => '2026-11-03T10:00:00Z', 'tag' => 'wazuh-analysisd', 'level' => 'error', 'description' => 'something' ] ]);
    case 'GET /rules':
	$paged([ [ 'id' => 5712, 'level' => 10, 'description' => 'sshd: brute force', 'groups' => [ 'authentication_failures' ] ], [ 'id' => 5501, 'level' => 3, 'description' => 'PAM: Login session opened', 'groups' => [ 'pam' ] ] ]);
    case 'GET /decoders':
	$paged([ [ 'name' => 'sshd', 'position' => 0 ] ]);
    case 'PUT /manager/restart':
	$items([ 'wazuh-manager' ], null, [], 'Restart request sent to all specified nodes');
}
if (preg_match('~^/agents/(\d+)/key$~', $path, $m))
    $items([ [ 'id' => $m[1], 'key' => 'c2VjcmV0a2V5' ] ]);
if (preg_match('~^/groups/([\w.-]+)/agents$~', $path, $m))
    $paged(array_values(array_filter($agents, fn($a) => in_array($m[1], $a['group'], true))));
if (preg_match('~^/syscollector/(\d+)/(\w+)$~', $path, $m)) {
    if (in_array($m[2], [ 'os', 'hardware' ], true))
	$items([ [ 'agent_id' => $m[1], 'kind' => $m[2] ] ]);
    //a long list, to read in pages
    $paged(array_map(fn($i) => [ 'agent_id' => $m[1], 'name' => "{$m[2]}{$i}" ], range(1, 1200)));
}
if (preg_match('~^/sca/(\d+)$~', $path))
    $paged([ [ 'policy_id' => 'cis_ubuntu24', 'name' => 'CIS Ubuntu', 'pass' => 90, 'fail' => 30, 'score' => 75 ] ]);
if (preg_match('~^/sca/(\d+)/checks/([\w.-]+)$~', $path, $m))
    $paged(array_values(array_filter([ [ 'id' => 1, 'title' => 'Check 1', 'result' => 'failed' ], [ 'id' => 2, 'title' => 'Check 2', 'result' => 'passed' ] ], fn($c) => !isset($q['result']) || $c['result'] === $q['result'])));
if (preg_match('~^/syscheck/(\d+)/last_scan$~', $path))
    $items([ [ 'start' => '2026-11-03T09:00:00Z', 'end' => '2026-11-03T09:05:00Z' ] ]);
if (preg_match('~^/syscheck/(\d+)$~', $path))
    $paged([ [ 'file' => '/etc/passwd', 'md5' => 'abc', 'type' => 'file' ] ]);
if (preg_match('~^/rootcheck/(\d+)$~', $path))
    $paged([ [ 'log' => 'Ending rootcheck scan.', 'status' => 'solved' ] ]);
$problem(404, 'Not Found', "The requested URL was not found: {$route}");
