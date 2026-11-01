<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new file: a fake Zabbix server (JSON-RPC API) for the check suite (CheckCommand::suiteZabbix), run by PHP's built-in server:
    ZBX_STATE=<a folder> ZBX_VERSION=7.0.5 php -S 127.0.0.1:<port> ZabbixTestServer.php
	POST /zabbix/api_jsonrpc.php; the token `tok3n`: from 6.4 as `Authorization: Bearer`, before as the `auth` member (each refused where the version does not take it);
	apiinfo.version must come without either. Each call appended to <state>/requests.jsonl (method, params, auth member, authorization header)
	<state>/problems.json: the current problems [ { eventid, objectid, name, severity, clock, acknowledged, suppressed, hostid, tags } ] (problem.get, event.get)
	<state>/config.json (made here): hosts, host groups, templates, items, maintenances, acknowledgements; host.create of an existing name: Zabbix's error
*/

$state = getenv('ZBX_STATE') ?: sys_get_temp_dir();
$version = getenv('ZBX_VERSION') ?: '7.0.5';
$token = 'tok3n';
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$req = json_decode((string)file_get_contents('php://input'), true) ?: [];
$method = (string)($req['method'] ?? '');
$p = (array)($req['params'] ?? []);
$id = $req['id'] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'params' => $p, 'auth' => $req['auth'] ?? null, 'authorization' => $headers['authorization'] ?? null,
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

header('Content-Type: application/json');
$result = function($r) use ($id) {
    echo json_encode([ 'jsonrpc' => '2.0', 'result' => $r, 'id' => $id ], JSON_UNESCAPED_SLASHES);
    exit;
};
$error = function($code, $message, $data) use ($id) {
    echo json_encode([ 'jsonrpc' => '2.0', 'error' => [ 'code' => $code, 'message' => $message, 'data' => $data ], 'id' => $id ], JSON_UNESCAPED_SLASHES);
    exit;
};
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/zabbix/api_jsonrpc.php') {
    http_response_code(404);
    header('Content-Type: text/html');
    echo '<html>Not found</html>';
    exit;
}
if (($req['jsonrpc'] ?? '') !== '2.0')
    $error(-32600, 'Invalid request.', 'Invalid parameter "/jsonrpc": a character string is expected.');

$bearer = $headers['authorization'] ?? null;
$auth = $req['auth'] ?? null;
if ($method === 'apiinfo.version') {
    if ($bearer !== null || $auth !== null)
	$error(-32602, 'Invalid params.', 'The "apiinfo.version" method must be called without the "auth" parameter.');
    $result($version);
}
$new = version_compare($version, '6.4', '>=');
if ($new && $auth !== null && version_compare($version, '7.2', '>='))
    $error(-32602, 'Invalid params.', 'Invalid parameter "/": unexpected parameter "auth".');
if ((string)($new ? $bearer : 'Bearer ' . $auth) !== 'Bearer ' . $token)
    $error(-32602, 'Invalid params.', 'Not authorized.');

$config = is_file("{$state}/config.json") ? json_decode(file_get_contents("{$state}/config.json"), true) : [
    'groups' => [ [ 'groupid' => '2', 'name' => 'Linux servers' ], [ 'groupid' => '4', 'name' => 'Databases' ] ],
    'templates' => [ [ 'templateid' => '10001', 'host' => 'Linux by Zabbix agent' ] ],
    'hosts' => [
	[ 'hostid' => '10084', 'host' => 'web01', 'name' => 'Web server 1', 'status' => '0', 'groups' => [ '2' ], 'interfaces' => [ [ 'ip' => '10.0.0.5', 'dns' => '', 'port' => '10050', 'type' => '1', 'main' => '1' ] ] ],
	[ 'hostid' => '10085', 'host' => 'db01', 'name' => 'Database 1', 'status' => '0', 'groups' => [ '4' ], 'interfaces' => [] ],
    ],
    'items' => [
	[ 'itemid' => '1', 'hostid' => '10084', 'name' => 'CPU utilization', 'key_' => 'system.cpu.util', 'lastvalue' => '12.5', 'lastclock' => '1793500000', 'units' => '%', 'state' => '0', 'status' => '0' ],
	[ 'itemid' => '2', 'hostid' => '10084', 'name' => 'Free disk space on /', 'key_' => 'vfs.fs.size[/,free]', 'lastvalue' => '1073741824', 'lastclock' => '1793500000', 'units' => 'B', 'state' => '0', 'status' => '0' ],
    ],
    'maintenances' => [], 'acks' => [], 'next' => 20000,
];
$save = function() use (&$config, $state) {
    file_put_contents("{$state}/config.json", json_encode($config));
};
$problems = is_file("{$state}/problems.json") ? json_decode(file_get_contents("{$state}/problems.json"), true) : [];
$hostById = fn($hid) => current(array_filter($config['hosts'], fn($h) => $h['hostid'] === (string)$hid)) ?: null;
$groupRows = fn(array $ids) => array_values(array_map(fn($g) => [ 'groupid' => $g['groupid'], 'name' => $g['name'] ], array_filter($config['groups'], fn($g) => in_array($g['groupid'], $ids, true))));
$limit = fn(array $rows) => isset($p['limit']) ? array_slice($rows, 0, (int)$p['limit']) : $rows;
$hostGroupsKey = version_compare($version, '6.2', '>=') ? 'selectHostGroups' : 'selectGroups';
$hostGroupsOut = version_compare($version, '6.2', '>=') ? 'hostgroups' : 'groups';

switch($method) {
    case 'host.get':
	$rows = array_values(array_filter($config['hosts'], function($h) use ($p) {
	    if (isset($p['filter']['host']) && !in_array($h['host'], (array)$p['filter']['host'], true))
		return false;
	    if (isset($p['filter']['status']) && (string)$p['filter']['status'] !== $h['status'])
		return false;
	    if (isset($p['search']['name']) && stripos($h['name'], $p['search']['name']) === false)
		return false;
	    if (isset($p['groupids']) && !array_intersect($h['groups'], (array)$p['groupids']))
		return false;
	    if (isset($p['hostids']) && !in_array($h['hostid'], (array)$p['hostids'], true))
		return false;
	    return true;
	}));
	if (!empty($p['countOutput']))
	    $result((string)count($rows));
	$result($limit(array_map(function($h) use ($p, $groupRows, $hostGroupsKey, $hostGroupsOut) {
	    $out = [ 'hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'status' => $h['status'], 'description' => '' ];
	    if (isset($p['selectInterfaces']))
		$out['interfaces'] = $h['interfaces'];
	    if (isset($p[$hostGroupsKey]))
		$out[$hostGroupsOut] = $groupRows($h['groups']);
	    if (isset($p['selectParentTemplates']))
		$out['parentTemplates'] = $h['templates'] ?? [];
	    if (isset($p['selectTags']))
		$out['tags'] = $h['tags'] ?? [];
	    if (isset($p['selectMacros']))
		$out['macros'] = [];
	    return $out;
	}, $rows)));
    case 'hostgroup.get':
	$rows = array_values(array_filter($config['groups'], fn($g) => (!isset($p['filter']['name']) || in_array($g['name'], (array)$p['filter']['name'], true))
	    && (!isset($p['search']['name']) || stripos($g['name'], $p['search']['name']) !== false)));
	$result($limit($rows));
    case 'template.get':
	$result(array_values(array_filter($config['templates'], fn($t) => !isset($p['filter']['host']) || in_array($t['host'], (array)$p['filter']['host'], true))));
    case 'host.create':
	foreach($config['hosts'] as $h)
	    if ($h['host'] === ($p['host'] ?? ''))
		$error(-32602, 'Invalid params.', "Host with the same name \"{$h['host']}\" already exists.");
	$hid = (string)$config['next']++;
	$config['hosts'][] = [ 'hostid' => $hid, 'host' => (string)$p['host'], 'name' => (string)($p['name'] ?? $p['host']), 'status' => '0',
	    'groups' => array_column((array)($p['groups'] ?? []), 'groupid'), 'interfaces' => (array)($p['interfaces'] ?? []), 'templates' => (array)($p['templates'] ?? []), 'tags' => (array)($p['tags'] ?? []) ];
	$save();
	$result([ 'hostids' => [ $hid ] ]);
    case 'host.update':
	foreach($config['hosts'] as &$h)
	    if ($h['hostid'] === (string)($p['hostid'] ?? ''))
		$h['status'] = (string)($p['status'] ?? $h['status']);
	unset($h);
	$save();
	$result([ 'hostids' => [ (string)($p['hostid'] ?? '') ] ]);
    case 'host.delete':
	$config['hosts'] = array_values(array_filter($config['hosts'], fn($h) => !in_array($h['hostid'], $p, true)));
	$save();
	$result([ 'hostids' => array_values($p) ]);
    case 'item.get':
	$rows = array_values(array_filter($config['items'], fn($i) => in_array($i['hostid'], (array)($p['hostids'] ?? []), true)
	    && (!isset($p['search']) || stripos($i['key_'], $p['search']['key_'] ?? '') !== false || stripos($i['name'], $p['search']['name'] ?? '') !== false)));
	$result($limit($rows));
    case 'problem.get':
	$rows = array_values(array_filter($problems, function($x) use ($p, $hostById) {
	    if (isset($p['severities']) && !in_array((int)$x['severity'], array_map('intval', (array)$p['severities']), true))
		return false;
	    if (isset($p['hostids']) && !in_array((string)$x['hostid'], (array)$p['hostids'], true))
		return false;
	    if (isset($p['groupids']) && !array_intersect($hostById($x['hostid'])['groups'] ?? [], (array)$p['groupids']))
		return false;
	    if (array_key_exists('acknowledged', $p) && $p['acknowledged'] === false && (string)$x['acknowledged'] === '1')
		return false;
	    if (array_key_exists('suppressed', $p) && $p['suppressed'] === false && (string)$x['suppressed'] === '1')
		return false;
	    foreach((array)($p['tags'] ?? []) as $t) {
		$has = array_filter((array)($x['tags'] ?? []), fn($xt) => $xt['tag'] === $t['tag'] && ((int)($t['operator'] ?? 0) === 4 || stripos($xt['value'], (string)$t['value']) !== false));
		if (!$has)
		    return false;
	    }
	    return true;
	}));
	if (!empty($p['countOutput']))
	    $result((string)count($rows));
	usort($rows, fn($a, $b) => (int)$b['eventid'] <=> (int)$a['eventid']);
	$result($limit(array_map(fn($x) => array_diff_key($x, [ 'hostid' => 1 ]) + [ 'source' => '0', 'object' => '0', 'opdata' => '' ], $rows)));
    case 'event.get':
	$rows = array_values(array_filter($problems, fn($x) => in_array((string)$x['eventid'], array_map('strval', (array)($p['eventids'] ?? [])), true)));
	$result(array_map(function($x) use ($p, $hostById, $config) {
	    $h = $hostById($x['hostid']);
	    $out = $p['output'] === 'extend' ? array_diff_key($x, [ 'hostid' => 1 ]) + [ 'value' => '1' ] : [ 'eventid' => $x['eventid'] ];
	    if (isset($p['selectHosts']))
		$out['hosts'] = $h ? [ [ 'hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'] ] ] : [];
	    if (isset($p['selectAcknowledges']))
		$out['acknowledges'] = array_values(array_filter($config['acks'], fn($a) => in_array((string)$x['eventid'], $a['eventids'], true)));
	    return $out;
	}, $rows));
    case 'event.acknowledge':
	$config['acks'][] = [ 'eventids' => array_map('strval', (array)$p['eventids']), 'action' => (int)$p['action'], 'message' => (string)($p['message'] ?? ''), 'clock' => '1793500100',
	    'username' => 'Admin', 'name' => 'Zabbix', 'surname' => 'Administrator' ];
	$save();
	$result([ 'eventids' => array_map('intval', (array)$p['eventids']) ]);
    case 'maintenance.create':
	$mid = (string)$config['next']++;
	$config['maintenances'][] = [ 'maintenanceid' => $mid ] + $p;
	$save();
	$result([ 'maintenanceids' => [ $mid ] ]);
    case 'maintenance.get':
	$result($limit(array_map(function($m) use ($hostById, $groupRows, $hostGroupsKey, $hostGroupsOut, $p) {
	    $ids = array_merge((array)($m['hostids'] ?? []), array_column((array)($m['hosts'] ?? []), 'hostid'));
	    $out = array_diff_key($m, [ 'hostids' => 1, 'hosts' => 1, 'groupids' => 1, 'groups' => 1 ]);
	    $out['hosts'] = array_map(fn($hid) => array_intersect_key($hostById($hid) ?? [], [ 'hostid' => 1, 'host' => 1, 'name' => 1 ]), $ids);
	    if (isset($p[$hostGroupsKey]))
		$out[$hostGroupsOut] = $groupRows(array_merge((array)($m['groupids'] ?? []), array_column((array)($m['groups'] ?? []), 'groupid')));
	    return $out;
	}, $config['maintenances'])));
    case 'maintenance.delete':
	$config['maintenances'] = array_values(array_filter($config['maintenances'], fn($m) => !in_array($m['maintenanceid'], $p, true)));
	$save();
	$result([ 'maintenanceids' => array_values($p) ]);
}
$error(-32601, 'Method not found.', "Incorrect API \"{$method}\".");
