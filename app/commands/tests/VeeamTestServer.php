<?php

/*
    a fake Veeam Backup & Replication REST API (v13) for the check suite (CheckCommand::suiteVeeam), run by PHP's built-in server:
    VEEAM_STATE=<a folder> php -S 127.0.0.1:<port> VeeamTestServer.php
	every request: an x-api-version of the release served (<state>/release.json; default VBR 13: 1.3-rev1) required (else 400); appended to <state>/requests.jsonl (method, path, query as sent, body, auth)
	POST /api/oauth2/token          form: grant_type password (backup / secret) or refresh_token: access a<n> / refresh r<n> (<state>/tokens.json), expires_in 900
	(a request whose token is listed in <state>/revoked answers 401)
	GET  /api/v1/serverInfo         VBR01, 13.0.1.1071
	GET  /api/v1/jobs, /jobs/states three jobs (two named "Copy"), nameFilter (exact, or * at the ends)
	POST /api/v1/jobs/{id}/start ...  a session started; stop / retry / enable / disable
	GET  /api/v1/sessions           <state>/sessions.json: endedAfterFilter, stateFilter, resultFilter (repeated), jobIdFilter, orderColumn EndTime / CreationTime, orderAsc, skip, limit
	GET  /api/v1/sessions/{id}      Working twice, then Stopped (Success)
	GET  /api/v1/restorePoints      web01: one 2 hours old; others: none
	GET  /api/v1/backupInfrastructure/repositories/states   two repositories (5% and 60% free)
	POST /api/v1/restore/instantRecovery/vSphere/vm          a mount; .../{id}/unmount
*/

$state = getenv('VEEAM_STATE') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
//the query, repeated names kept as lists
$query = [];
foreach(explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
    if ($pair === '')
	continue;
    [ $k, $v ] = array_map('rawurldecode', explode('=', $pair, 2) + [ 1 => '' ]);
    $query[$k][] = $v;
}
$one = fn($k, $d = null) => $query[$k][0] ?? $d;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $raw, 'auth' => $headers['authorization'] ?? null,
    'version' => $headers['x-api-version'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = function($status, $data = null) {
    http_response_code($status);
    if ($data !== null) {
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$page = function(array $items) use ($one, $answer) {
    $skip = (int)$one('skip', 0);
    $limit = (int)$one('limit', 200);
    $answer(200, [ 'data' => array_slice($items, $skip, $limit), 'pagination' => [ 'total' => count($items), 'count' => count(array_slice($items, $skip, $limit)), 'skip' => $skip, 'limit' => $limit ] ]);
};
$match = function($name, $pattern) {
    if ($pattern === null || $pattern === '')
	return true;
    $re = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
    return preg_match($re, $name) === 1;
};

//the release served: <state>/release.json { versions: [ x-api-version taken ], build } (default VBR 13: 1.3-rev1, 13.0.1.1071)
$release = is_file("{$state}/release.json") ? json_decode(file_get_contents("{$state}/release.json"), true) : [ 'versions' => [ '1.3-rev1' ], 'build' => '13.0.1.1071' ];
if (!in_array($headers['x-api-version'] ?? '', $release['versions'], true))
    $answer(400, [ 'errorCode' => 'BadRequest', 'message' => 'The x-api-version header is missing or not supported' ]);

$tokens = is_file("{$state}/tokens.json") ? json_decode(file_get_contents("{$state}/tokens.json"), true) : [ 'n' => 0, 'access' => [], 'refresh' => [] ];
if ($path === '/api/oauth2/token') {
    parse_str($raw, $form);
    $ok = ($form['grant_type'] ?? '') === 'password' ? (($form['username'] ?? '') === 'backup' && ($form['password'] ?? '') === 'secret')
	: (($form['grant_type'] ?? '') === 'refresh_token' && in_array($form['refresh_token'] ?? '', $tokens['refresh'], true));
    if (!$ok)
	$answer(400, [ 'error' => 'invalid_grant', 'error_description' => 'Invalid username or password' ]);
    $n = ++$tokens['n'];
    $tokens['access'][] = "a{$n}";
    $tokens['refresh'][] = "r{$n}";
    file_put_contents("{$state}/tokens.json", json_encode($tokens));
    $answer(200, [ 'access_token' => "a{$n}", 'token_type' => 'bearer', 'refresh_token' => "r{$n}", 'expires_in' => 900 ]);
}
$bearer = preg_match('/^Bearer (\S+)$/', (string)($headers['authorization'] ?? ''), $m) ? $m[1] : '';
$revoked = is_file("{$state}/revoked") ? array_filter(explode("\n", file_get_contents("{$state}/revoked"))) : [];
if (!in_array($bearer, $tokens['access'], true) || in_array($bearer, $revoked, true))
    $answer(401, [ 'errorCode' => 'Unauthorized', 'message' => 'The access token is not valid' ]);

$uuid = '[0-9a-f-]{36}';
$jobs = [
    [ 'id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Daily VMs', 'type' => 'VSphereBackup' ],
    [ 'id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Copy', 'type' => 'BackupCopy' ],
    [ 'id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Copy', 'type' => 'BackupCopy' ],
];
switch(true) {
    case $path === '/api/v1/serverInfo':
	$answer(200, [ 'vbrId' => '99999999-9999-9999-9999-999999999999', 'name' => 'VBR01', 'buildVersion' => $release['build'] ]);
    case $path === '/api/v1/jobs' || $path === '/api/v1/jobs/states':
	$page(array_values(array_filter($jobs, fn($j) => $match($j['name'], $one('nameFilter')))));
    case (bool)preg_match("~^/api/v1/jobs/({$uuid})/(start|stop|retry|enable|disable)$~", $path, $m) && $method === 'POST':
	if (in_array($m[2], [ 'start', 'retry' ], true))
	    $answer(201, [ 'id' => 'aaaaaaaa-0000-0000-0000-' . substr(str_replace('-', '', $m[1]), 0, 12), 'name' => 'Daily VMs', 'jobId' => $m[1], 'state' => 'Starting' ]);
	$answer(200, (object)[]);
    case (bool)preg_match("~^/api/v1/jobs/({$uuid})$~", $path, $m):
	foreach($jobs as $j)
	    if ($j['id'] === $m[1])
		$answer(200, $j);
	$answer(404, [ 'errorCode' => 'NotFound', 'message' => 'The job was not found' ]);
    case $path === '/api/v1/sessions':
	$list = is_file("{$state}/sessions.json") ? json_decode(file_get_contents("{$state}/sessions.json"), true) : [];
	$list = array_values(array_filter($list, function($s) use ($query, $one) {
	    if ($one('endedAfterFilter') !== null && strtotime($s['endTime'] ?? '1970-01-01') <= strtotime($one('endedAfterFilter')))
		return false;
	    if ($one('stateFilter') !== null && $s['state'] !== $one('stateFilter'))
		return false;
	    if (isset($query['resultFilter']) && !in_array($s['result']['result'], $query['resultFilter'], true))
		return false;
	    return $one('jobIdFilter') === null || $s['jobId'] === $one('jobIdFilter');
	}));
	$col = $one('orderColumn') === 'EndTime' ? 'endTime' : 'creationTime';
	usort($list, fn($a, $b) => strtotime($a[$col] ?? '') <=> strtotime($b[$col] ?? ''));
	if ($one('orderAsc') === 'false')
	    $list = array_reverse($list);
	$page($list);
    case (bool)preg_match("~^/api/v1/sessions/({$uuid})$~", $path, $m):
	$f = "{$state}/reads-{$m[1]}";
	$n = (int)@file_get_contents($f) + 1;
	file_put_contents($f, $n);
	$done = $n > 2;
	$answer(200, [ 'id' => $m[1], 'name' => 'Daily VMs', 'state' => $done ? 'Stopped' : 'Working', 'progressPercent' => $done ? 100 : 40 * $n,
	    'result' => [ 'result' => $done ? 'Success' : 'None', 'message' => '', 'isCanceled' => false ] ]);
    case $path === '/api/v1/restorePoints':
	$items = $match('web01', $one('nameFilter')) && $one('nameFilter') !== null
	    ? [ [ 'id' => 'bbbbbbbb-0000-0000-0000-000000000001', 'name' => 'web01', 'creationTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200) ] ] : [];
	$page($items);
    case $path === '/api/v1/backupInfrastructure/repositories/states':
	$page([ [ 'id' => 'cccccccc-0000-0000-0000-000000000001', 'name' => 'Repo A', 'capacityGB' => 1000, 'freeGB' => 50, 'usedSpaceGB' => 950, 'isOnline' => true ],
	    [ 'id' => 'cccccccc-0000-0000-0000-000000000002', 'name' => 'Repo B', 'capacityGB' => 500, 'freeGB' => 300, 'usedSpaceGB' => 200, 'isOnline' => true ] ]);
    case $path === '/api/v1/restore/instantRecovery/vSphere/vm' && $method === 'POST':
	$answer(201, [ 'id' => 'dddddddd-0000-0000-0000-000000000001', 'state' => 'Mounting', 'spec' => json_decode($raw, true) ]);
    case (bool)preg_match("~^/api/v1/restore/instantRecovery/vSphere/vm/({$uuid})/unmount$~", $path) && $method === 'POST':
	$answer(200, (object)[]);
}
$answer(404, [ 'errorCode' => 'NotFound', 'message' => "No such resource: {$path}" ]);
