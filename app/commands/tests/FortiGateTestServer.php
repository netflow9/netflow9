<?php

/*
    a fake FortiGate (FortiOS 7.4 REST API) for the check suite (CheckCommand::suiteFortigate), run by PHP's built-in server:
    FGT_STATE=<a folder> php -S 127.0.0.1:<port> FortiGateTestServer.php
	token `fgtoken` (Authorization: Bearer); each request appended to <state>/requests.jsonl (method, path, query, body)
	answers in FortiOS's form: { http_method, results, vdom, status: success | error, http_status, error (a number), version, build, serial }
	GET  monitor/system/status                         FGT-HQ, FortiGate 100F
	cmdb/firewall/address[6]                           list (filter name=@..., count), get, POST (exists: -5), DELETE (missing: -3); <state>/objects.json
	cmdb/firewall/addrgrp[6]/<group>/member            POST { name } (already: -15), DELETE /<name> (missing: -3)
	monitor/user/banned                                GET, add_users, clear_users, clear_all
	cmdb/firewall/policy[/<id>]                        GET, PUT { status }
	monitor/system/config/backup                       POST: a configuration text
*/

$state = getenv('FGT_STATE') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $_GET, 'body' => $body ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$vdom = $_GET['vdom'] ?? 'root';
$answer = function($status, $results = null, $error = null) use ($method, $vdom) {
    http_response_code($status);
    header('Content-Type: application/json');
    $d = [ 'http_method' => $method, 'vdom' => $vdom, 'status' => $status < 400 ? 'success' : 'error', 'http_status' => $status, 'serial' => 'FG100FTK20000001', 'version' => 'v7.4.4', 'build' => 2662 ];
    if ($results !== null)
	$d['results'] = $results;
    if ($error !== null)
	$d['error'] = $error;
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
};
if (($headers['authorization'] ?? '') !== 'Bearer fgtoken') {
    http_response_code(401);
    exit;
}
$objects = is_file("{$state}/objects.json") ? json_decode(file_get_contents("{$state}/objects.json"), true)
    : [ 'address' => [ 'r22-block-203.0.113.9' => [ 'name' => 'r22-block-203.0.113.9', 'subnet' => '203.0.113.9 255.255.255.255' ] ], 'address6' => [],
	'addrgrp' => [ 'Blocked' => [] ], 'addrgrp6' => [ 'Blocked' => [] ], 'banned' => [], 'policy' => [ 7 => [ 'policyid' => 7, 'name' => 'Deny blocked', 'status' => 'enable' ] ] ];
$save = function() use (&$objects, $state) {
    file_put_contents("{$state}/objects.json", json_encode($objects));
};

if ($path === '/api/v2/monitor/system/status')
    $answer(200, [ 'hostname' => 'FGT-HQ', 'model_name' => 'FortiGate', 'model_number' => '100F' ]);
if (preg_match('~^/api/v2/cmdb/firewall/(address6?)(?:/([^/]+))?$~', $path, $m)) {
    $table = $m[1];
    $name = isset($m[2]) ? rawurldecode($m[2]) : null;
    if ($method === 'GET' && $name === null) {
	$list = array_values($objects[$table]);
	if (isset($_GET['filter']) && str_starts_with($_GET['filter'], 'name=@'))
	    $list = array_values(array_filter($list, fn($o) => stripos($o['name'], substr($_GET['filter'], 6)) !== false));
	$answer(200, array_slice($list, 0, (int)($_GET['count'] ?? 1000)));
    }
    if ($method === 'GET')
	isset($objects[$table][$name]) ? $answer(200, [ $objects[$table][$name] ]) : $answer(404, null, -3);
    if ($method === 'POST') {
	if (isset($objects[$table][$body['name'] ?? '']))
	    $answer(500, null, -5);
	$objects[$table][$body['name']] = $body;
	$save();
	$answer(200, null);
    }
    if ($method === 'DELETE') {
	if (!isset($objects[$table][$name]))
	    $answer(404, null, -3);
	unset($objects[$table][$name]);
	$save();
	$answer(200, null);
    }
}
if (preg_match('~^/api/v2/cmdb/firewall/(addrgrp6?)/([^/]+)/member(?:/([^/]+))?$~', $path, $m)) {
    [ , $table, $group ] = $m;
    $group = rawurldecode($group);
    if (!isset($objects[$table][$group]))
	$answer(404, null, -3);
    if ($method === 'POST') {
	if (in_array($body['name'] ?? '', $objects[$table][$group], true))
	    $answer(500, null, -15);
	$objects[$table][$group][] = $body['name'];
	$save();
	$answer(200, null);
    }
    if ($method === 'DELETE') {
	$member = rawurldecode($m[3] ?? '');
	if (!in_array($member, $objects[$table][$group], true))
	    $answer(404, null, -3);
	$objects[$table][$group] = array_values(array_diff($objects[$table][$group], [ $member ]));
	$save();
	$answer(200, null);
    }
}
if ($path === '/api/v2/monitor/user/banned' && $method === 'GET')
    $answer(200, array_map(fn($ip) => [ 'ipv6' => false, 'source' => 'quarantine', 'ip_address' => $ip ], array_keys($objects['banned'])));
if (preg_match('~^/api/v2/monitor/user/banned/(add_users|clear_users|clear_all)$~', $path, $m) && $method === 'POST') {
    foreach((array)($body['ip_addresses'] ?? []) as $ip) {
	if ($m[1] === 'add_users')
	    $objects['banned'][$ip] = (int)($body['expiry'] ?? 0);
	else
	    unset($objects['banned'][$ip]);
    }
    if ($m[1] === 'clear_all')
	$objects['banned'] = [];
    $save();
    $answer(200, (object)[]);
}
if ($path === '/api/v2/cmdb/firewall/policy' && $method === 'GET')
    $answer(200, array_values($objects['policy']));
if (preg_match('~^/api/v2/cmdb/firewall/policy/(\d+)$~', $path, $m)) {
    if (!isset($objects['policy'][$m[1]]))
	$answer(404, null, -3);
    if ($method === 'PUT') {
	$objects['policy'][$m[1]] = array_replace($objects['policy'][$m[1]], $body);
	$save();
	$answer(200, null);
    }
    $answer(200, [ $objects['policy'][$m[1]] ]);
}
if ($path === '/api/v2/monitor/system/config/backup' && $method === 'POST') {
    header('Content-Type: text/plain');
    echo "#config-version=FG100F-7.04.4-FW-build2662-240514:opmode=0:vdom=0\n#conf_file_ver=1\nconfig system global\n    set hostname \"FGT-HQ\"\nend\n";
    exit;
}
$answer(404, null);
