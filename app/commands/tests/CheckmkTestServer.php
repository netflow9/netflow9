<?php

/*
    a fake Checkmk site `mysite` (REST API 1.0, Checkmk 2.3) for the check suite (CheckCommand::suiteCheckmk), run by PHP's built-in server:
    CMK_STATE=<a folder> php -S 127.0.0.1:<port> CheckmkTestServer.php
	Authorization: Bearer automation s3cret; each request appended to <state>/requests.jsonl (method, path, query as sent, body, if-match)
	GET  /version
	GET  /domain-types/{host,service}/collections/all   <state>/monitoring.json { hosts, services }, filtered by `query` (Livestatus ops = > >= and)
	POST acknowledge/collections/{host,service}, downtime/collections/{host,service}, downtime/actions/delete/invoke: 204
	GET  downtime/collections/all
	host_config: GET collection / object, POST (exists: 400 with fields), DELETE; service_discovery_run start; each change adds a pending change
	activate-changes: If-Match required (428); nothing pending: 422 "No pending changes"; GET activation_run/collections/pending_changes
*/

$state = getenv('CMK_STATE') ?: sys_get_temp_dir();
$prefix = '/mysite/check_mk/api/1.0';
$full = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$query = [];
foreach(explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
    if ($pair === '')
	continue;
    [ $k, $v ] = array_map('rawurldecode', explode('=', $pair, 2) + [ 1 => '' ]);
    $query[$k][] = $v;
}
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $full, 'query' => $query, 'body' => $body, 'ifMatch' => $headers['if-match'] ?? null,
    'auth' => $headers['authorization'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = function($status, $data = null, $type = 'application/json') {
    http_response_code($status);
    if ($data !== null) {
	header('Content-Type: ' . $type);
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$problem = fn($status, $title, $detail = '', $fields = null) => $answer($status, array_filter([ 'title' => $title, 'status' => $status, 'detail' => $detail, 'fields' => $fields ]), 'application/problem+json');
if (!str_starts_with($full, $prefix . '/') && $full !== $prefix)
    $answer(404, null);
if (($headers['authorization'] ?? '') !== 'Bearer automation s3cret')
    $problem(401, 'Unauthorized', 'You need to be authenticated to use the REST API.');
$path = substr($full, strlen($prefix));

$data = is_file("{$state}/monitoring.json") ? json_decode(file_get_contents("{$state}/monitoring.json"), true) : [ 'hosts' => [], 'services' => [] ];
$config = is_file("{$state}/config.json") ? json_decode(file_get_contents("{$state}/config.json"), true) : [ 'hosts' => [ 'web01' => [ 'folder' => '/', 'attributes' => [ 'ipaddress' => '10.0.0.5' ] ] ], 'pending' => [] ];
$saveConfig = function() use (&$config, $state) {
    file_put_contents("{$state}/config.json", json_encode($config));
};
$match = function($row, $f) use (&$match) {
    if (!$f)
	return true;
    if (($f['op'] ?? '') === 'and')
	return !array_filter($f['expr'] ?? [], fn($e) => !$match($row, $e));
    $l = $row[$f['left']] ?? null;
    $r = $f['right'];
    return match($f['op']) { '=' => $l == $r, '>' => $l > $r, '>=' => $l >= $r, '<' => $l < $r, default => false };
};
$collection = fn(array $items) => [ 'value' => array_map(fn($x) => [ 'domainType' => 'dict', 'id' => $x['id'] ?? null, 'title' => $x['title'] ?? null, 'extensions' => $x ], $items) ];

if ($path === '/version')
    $answer(200, [ 'site' => 'mysite', 'group' => '', 'rest_api' => [ 'revision' => '0' ], 'versions' => [ 'checkmk' => '2.3.0p20' ], 'edition' => 'cre' ]);
if (preg_match('~^/domain-types/(host|service)/collections/all$~', $path, $m)) {
    $filter = isset($query['query'][0]) ? json_decode($query['query'][0], true) : null;
    $rows = array_values(array_filter($data[$m[1] === 'host' ? 'hosts' : 'services'], fn($r) => $match($r, $filter)));
    $answer(200, $collection($rows));
}
if (preg_match('~^/domain-types/(acknowledge|downtime)/collections/(host|service)$~', $path) && $method === 'POST')
    $answer(204);
if ($path === '/domain-types/downtime/collections/all')
    $answer(200, $collection([ [ 'id' => '54', 'title' => 'Maintenance', 'host_name' => 'web01', 'is_service' => false ] ]));
if ($path === '/domain-types/downtime/actions/delete/invoke' && $method === 'POST')
    $answer(204);
if ($path === '/domain-types/host_config/collections/all' && $method === 'GET')
    $answer(200, $collection(array_map(fn($h, $c) => [ 'id' => $h, 'title' => $h ] + $c, array_keys($config['hosts']), $config['hosts'])));
if ($path === '/domain-types/host_config/collections/all' && $method === 'POST') {
    if (isset($config['hosts'][$body['host_name'] ?? '']))
	$problem(400, 'Bad Request', 'These fields have problems: host_name', [ 'host_name' => [ "Host \"{$body['host_name']}\" already exists." ] ]);
    $config['hosts'][$body['host_name']] = [ 'folder' => $body['folder'], 'attributes' => $body['attributes'] ];
    $config['pending'][] = "Created new host {$body['host_name']}.";
    $saveConfig();
    $answer(200, [ 'id' => $body['host_name'], 'extensions' => $config['hosts'][$body['host_name']] ]);
}
if (preg_match('~^/objects/host_config/([^/]+)$~', $path, $m)) {
    $h = rawurldecode($m[1]);
    if (!isset($config['hosts'][$h]))
	$problem(404, 'Not Found', "Host \"{$h}\" is not known.");
    if ($method === 'DELETE') {
	unset($config['hosts'][$h]);
	$config['pending'][] = "Deleted host {$h}.";
	$saveConfig();
	$answer(204);
    }
    $answer(200, [ 'id' => $h, 'extensions' => $config['hosts'][$h] ]);
}
if ($path === '/domain-types/service_discovery_run/actions/start/invoke' && $method === 'POST') {
    $config['pending'][] = "Discovered services of {$body['host_name']}.";
    $saveConfig();
    $answer(200, [ 'id' => 'service_discovery_run', 'extensions' => [ 'active' => false, 'state' => 'finished' ] ]);
}
if ($path === '/domain-types/activation_run/actions/activate-changes/invoke' && $method === 'POST') {
    if (!isset($headers['if-match']))
	$problem(428, 'Precondition required', 'If-Match header required for this operation. See documentation.');
    if (!$config['pending'])
	$problem(422, 'No pending changes', 'Currently there are no changes to activate.');
    $config['pending'] = [];
    $saveConfig();
    $answer(200, [ 'id' => 'act-1', 'title' => 'Activation status: In progress.' ]);
}
if ($path === '/domain-types/activation_run/collections/pending_changes')
    $answer(200, $collection(array_map(fn($t, $i) => [ 'id' => "c{$i}", 'text' => $t ], $config['pending'], array_keys($config['pending']))));
$problem(404, 'Not Found', "No such endpoint: {$path}");
