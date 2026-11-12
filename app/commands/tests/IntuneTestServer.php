<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: a fake Microsoft sign-in and Graph for Intune, for the check suite (CheckCommand::suiteIntune), run by PHP's built-in server:
    INTUNE_STATE=<a folder> php -S 127.0.0.1:<port> IntuneTestServer.php
	POST /<tenant>/oauth2/v2.0/token: client credentials, client 11111111-2222-3333-4444-555555555555 / sec-ok (else 401 invalid_client); tokens tok-<n>, expires_in 3600.
	/v1.0 and /beta: Bearer of a token given (else 401). Data in <state>/db.json, seeded: 4 managed devices (two named LAPTOP-02), compliance policies and configuration
	profiles with device statuses, mobile apps with assignments, detected apps, Autopilot devices. $filter understood: <field> eq '<v>' (and / or of them in parentheses),
	contains(<field>,'<v>'), enrolledDateTime ge <ISO>, isAssigned eq true; $top with @odata.nextLink ($skiptoken); $select; $expand=detectedApps / assignments.
	Remote actions recorded in db.actions. Errors as Intune's (the message a JSON with Message). <state>/expire.json: the next Graph request 401 once; <state>/throttle.json:
	how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, auth)
*/

$state = getenv('INTUNE_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$query = [];
foreach(explode('&', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY)) as $kv)
    if ($kv !== '') {
	[ $k, $v ] = explode('=', $kv, 2) + [ 1 => '' ];
	$query[rawurldecode($k)] = rawurldecode($v);
    }
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body ?? ($_POST ?: null),
    'auth' => $hdr('Authorization') ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$err = fn($status, $code, $message, $intune = true) => $out([ 'error' => [ 'code' => $code, 'message' => $intune ? json_encode([ '_version' => 3,
    'Message' => "{$message} - Operation ID (for customer support): 00000000-0000-0000-0000-000000000000 - Activity ID: x - Url: y", 'CustomApiErrorPhrase' => '' ]) : $message ] ], $status);

if (preg_match('~^/([^/]+)/oauth2/v2\.0/token$~', $path) && $method === 'POST') {
    if (($_POST['grant_type'] ?? '') !== 'client_credentials' || ($_POST['scope'] ?? '') !== 'https://graph.microsoft.com/.default')
	$out([ 'error' => 'unsupported_grant_type', 'error_description' => 'AADSTS70003: wrong grant. Trace ID: x' ], 400);
    if (($_POST['client_id'] ?? '') !== '11111111-2222-3333-4444-555555555555' || ($_POST['client_secret'] ?? '') !== 'sec-ok')
	$out([ 'error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: 1\r\nCorrelation ID: 2" ], 401);
    $tokens = $load('tokens', []);
    $tokens[] = $t = 'tok-' . (count($tokens) + 1);
    $save('tokens', $tokens);
    $out([ 'token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $t ]);
}
if (!preg_match('~^/(v1\.0|beta)(/.*)$~', $path, $m))
    $out(null, 404);
$p = $m[2];
if (!in_array(substr((string)$hdr('Authorization'), 7), $load('tokens', []), true))
    $err(401, 'InvalidAuthenticationToken', 'Access token is empty.', false);
if ((int)$load('expire', 0) > 0) {
    $save('expire', 0);
    $err(401, 'InvalidAuthenticationToken', 'Lifetime validation failed, the token is expired.', false);
}
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'error' => [ 'code' => 'TooManyRequests', 'message' => 'Too many requests' ] ], 429, [ 'Retry-After' => '1' ]);
}

$g = fn($n) => sprintf('aaaaaaaa-0000-0000-0000-%012d', $n);
$db = $load('db', null) ?? [
    'devices' => [
	[ 'id' => $g(1), 'deviceName' => 'LAPTOP-01', 'operatingSystem' => 'Windows', 'osVersion' => '10.0.26100', 'complianceState' => 'compliant', 'managedDeviceOwnerType' => 'company',
	    'userPrincipalName' => 'ana@contoso.com', 'userDisplayName' => 'Ana Pop', 'emailAddress' => 'ana@contoso.com', 'serialNumber' => 'SN-A1', 'model' => 'Latitude', 'manufacturer' => 'Dell',
	    'enrolledDateTime' => '2026-11-01T08:00:00Z', 'lastSyncDateTime' => '2026-11-12T08:00:00Z', 'isEncrypted' => true, 'managementAgent' => 'mdm', 'azureADDeviceId' => $g(101) ],
	[ 'id' => $g(2), 'deviceName' => 'LAPTOP-02', 'operatingSystem' => 'Windows', 'osVersion' => '10.0.22631', 'complianceState' => 'noncompliant', 'managedDeviceOwnerType' => 'company',
	    'userPrincipalName' => 'dan@contoso.com', 'userDisplayName' => 'Dan Ionescu', 'emailAddress' => '', 'serialNumber' => 'SN-B2', 'model' => 'ThinkPad', 'manufacturer' => 'Lenovo',
	    'enrolledDateTime' => '2026-11-02T08:00:00Z', 'lastSyncDateTime' => '2026-11-12T07:00:00Z', 'isEncrypted' => false, 'managementAgent' => 'mdm', 'azureADDeviceId' => $g(102) ],
	[ 'id' => $g(3), 'deviceName' => 'IPHONE-ANA', 'operatingSystem' => 'iOS', 'osVersion' => '19.1', 'complianceState' => 'compliant', 'managedDeviceOwnerType' => 'personal',
	    'userPrincipalName' => 'ana@contoso.com', 'userDisplayName' => 'Ana Pop', 'emailAddress' => 'ana@contoso.com', 'serialNumber' => 'SN-C3', 'model' => 'iPhone', 'manufacturer' => 'Apple',
	    'enrolledDateTime' => '2026-11-03T08:00:00Z', 'lastSyncDateTime' => '2026-11-12T06:00:00Z', 'isEncrypted' => true, 'managementAgent' => 'mdm', 'azureADDeviceId' => $g(103) ],
	[ 'id' => $g(4), 'deviceName' => 'LAPTOP-02', 'operatingSystem' => 'macOS', 'osVersion' => '16.1', 'complianceState' => 'compliant', 'managedDeviceOwnerType' => 'company',
	    'userPrincipalName' => 'eva@contoso.com', 'userDisplayName' => 'Eva Marin', 'emailAddress' => 'eva@contoso.com', 'serialNumber' => 'SN-D4', 'model' => 'MacBook Air', 'manufacturer' => 'Apple',
	    'enrolledDateTime' => '2026-11-04T08:00:00Z', 'lastSyncDateTime' => '2026-11-12T05:00:00Z', 'isEncrypted' => true, 'managementAgent' => 'mdm', 'azureADDeviceId' => $g(104) ],
    ],
    'detectedApps' => [ [ 'id' => str_repeat('ab', 22), 'displayName' => 'Google Chrome', 'version' => '142.0', 'publisher' => 'Google', 'platform' => 'windows', 'deviceCount' => 2, 'devices' => [ $g(1), $g(2) ] ],
	[ 'id' => str_repeat('cd', 22), 'displayName' => '7-Zip', 'version' => '25.01', 'publisher' => 'Igor Pavlov', 'platform' => 'windows', 'deviceCount' => 1, 'devices' => [ $g(2) ] ] ],
    'compliance' => [ [ 'id' => $g(201), '@odata.type' => '#microsoft.graph.windows10CompliancePolicy', 'displayName' => 'Windows baseline', 'description' => 'BitLocker',
	'createdDateTime' => '2026-01-01T00:00:00Z', 'lastModifiedDateTime' => '2026-10-01T00:00:00Z', 'version' => 3, 'assignments' => [ [ 'id' => 'as1', 'target' => [ '@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => $g(301) ] ] ],
	'statuses' => [ [ 'id' => 's1', 'deviceDisplayName' => 'LAPTOP-01', 'userName' => 'Ana Pop', 'userPrincipalName' => 'ana@contoso.com', 'status' => 'compliant', 'lastReportedDateTime' => '2026-11-12T08:00:00Z' ],
	    [ 'id' => 's2', 'deviceDisplayName' => 'LAPTOP-02', 'userName' => 'Dan Ionescu', 'userPrincipalName' => 'dan@contoso.com', 'status' => 'nonCompliant', 'lastReportedDateTime' => '2026-11-12T07:00:00Z',
		'complianceGracePeriodExpirationDateTime' => '2026-11-15T07:00:00Z' ] ],
	'overview' => [ 'successCount' => 1, 'failedCount' => 1, 'errorCount' => 0, 'conflictCount' => 0, 'pendingCount' => 0, 'notApplicableCount' => 2, 'lastUpdateDateTime' => '2026-11-12T08:00:00Z' ] ] ],
    'configurations' => [ [ 'id' => $g(211), '@odata.type' => '#microsoft.graph.windows10GeneralConfiguration', 'displayName' => 'Windows restrictions', 'version' => 1, 'assignments' => [],
	'statuses' => [], 'overview' => [ 'successCount' => 2, 'failedCount' => 0, 'errorCount' => 1, 'conflictCount' => 0, 'pendingCount' => 1, 'notApplicableCount' => 0 ] ] ],
    'apps' => [ [ 'id' => $g(401), '@odata.type' => '#microsoft.graph.win32LobApp', 'displayName' => 'Company Portal', 'publisher' => 'Microsoft', 'isAssigned' => true,
	'assignments' => [ [ 'id' => 'aa1', 'intent' => 'required', 'target' => [ '@odata.type' => '#microsoft.graph.allDevicesAssignmentTarget' ] ] ] ],
	[ 'id' => $g(402), '@odata.type' => '#microsoft.graph.winGetApp', 'displayName' => 'Notepad++', 'publisher' => 'Notepad++ Team', 'isAssigned' => false, 'assignments' => [] ] ],
    'autopilot' => [ [ 'id' => $g(501), 'serialNumber' => 'AP-1001', 'model' => 'Latitude', 'manufacturer' => 'Dell', 'groupTag' => 'Sales', 'userPrincipalName' => '', 'enrollmentState' => 'notContacted',
	'managedDeviceId' => '00000000-0000-0000-0000-000000000000', 'azureActiveDirectoryDeviceId' => $g(601), 'purchaseOrderIdentifier' => '' ],
	[ 'id' => $g(502), 'serialNumber' => 'AP-1002', 'model' => 'Latitude', 'manufacturer' => 'Dell', 'groupTag' => 'IT', 'userPrincipalName' => '', 'enrollmentState' => 'enrolled',
	'managedDeviceId' => $g(1), 'azureActiveDirectoryDeviceId' => $g(602), 'purchaseOrderIdentifier' => 'PO-9' ] ],
    'imports' => [],
    'actions' => [],
    'next' => 700,
];
$commit = function() use (&$db, $save) {
    $save('db', $db);
};
$host = 'http://' . $_SERVER['HTTP_HOST'];

//a $filter of the fake: and / or of comparisons, each maybe in parentheses
$test = function($item, $filter) use (&$test) {
    $f = trim($filter);
    if ($f === '')
	return true;
    $depth = 0;
    $parts = [];
    $cur = '';
    $ops = [];
    for ($i = 0; $i < strlen($f); $i++) {
	$c = $f[$i];
	$depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
	foreach([ ' and ', ' or ' ] as $op)
	    if ($depth === 0 && substr($f, $i, strlen($op)) === $op) {
		$parts[] = $cur;
		$ops[] = trim($op);
		$cur = '';
		$i += strlen($op) - 1;
		continue 2;
	    }
	$cur .= $c;
    }
    $parts[] = $cur;
    if (count($parts) > 1) {
	$r = $test($item, $parts[0]);
	foreach($ops as $k => $op)
	    $r = $op === 'and' ? $r && $test($item, $parts[$k + 1]) : $r || $test($item, $parts[$k + 1]);
	return $r;
    }
    if ($f[0] === '(' && substr($f, -1) === ')')
	return $test($item, substr($f, 1, -1));
    if (preg_match("~^contains\((\w+),'((?:[^']|'')*)'\)$~", $f, $m))
	return stripos((string)($item[$m[1]] ?? ''), str_replace("''", "'", $m[2])) !== false;
    if (preg_match("~^(\w+) eq '((?:[^']|'')*)'$~", $f, $m))
	return strcasecmp((string)($item[$m[1]] ?? ''), str_replace("''", "'", $m[2])) === 0;
    if (preg_match('~^(\w+) eq (true|false)$~', $f, $m))
	return (bool)($item[$m[1]] ?? false) === ($m[2] === 'true');
    if (preg_match('~^(\w+) ge (\S+)$~', $f, $m))
	return strtotime((string)($item[$m[1]] ?? '')) >= strtotime($m[2]);
    $GLOBALS['badFilter'] = $f;
    return false;
};
$list = function(array $items, array $strip = []) use ($query, $test, $out, $err, $host, $path) {
    $items = array_values(array_filter($items, fn($i) => $test($i, $query['$filter'] ?? '')));
    if (isset($GLOBALS['badFilter']))
	$err(400, 'BadRequest', "the fake does not understand `{$GLOBALS['badFilter']}`");
    $top = (int)($query['$top'] ?? 2);
    $skip = (int)($query['$skiptoken'] ?? 0);
    $page = array_slice($items, $skip, $top);
    if (isset($query['$select']))
	$page = array_map(fn($i) => array_intersect_key($i, array_flip(explode(',', $query['$select']))), $page);
    $page = array_map(fn($i) => array_diff_key($i, array_flip($strip)), $page);
    $res = [ '@odata.context' => 'x', 'value' => $page ];
    if ($skip + $top < count($items))
	$res['@odata.nextLink'] = $host . $path . '?' . http_build_query(array_diff_key($query, [ '$skiptoken' => 1 ]) + [ '$skiptoken' => $skip + $top ], '', '&', PHP_QUERY_RFC3986);
    $out($res);
};
$find = function($key, $id) use (&$db, $err) {
    foreach($db[$key] as $i => $x)
	if (strcasecmp((string)$x['id'], (string)$id) === 0)
	    return $i;
    $err(404, 'ResourceNotFound', "{$key} {$id} not found");
};

if ($p === '/deviceManagement/managedDeviceOverview')
    $out([ 'enrolledDeviceCount' => count($db['devices']), 'deviceOperatingSystemSummary' => [ 'windowsCount' => 2, 'macOSCount' => 1, 'iosCount' => 1, 'androidCount' => 0 ] ]);
if (preg_match('~^/deviceManagement/managedDevices(?:/([^/]+))?(?:/(\w+))?$~', $p, $m)) {
    if (empty($m[1]))
	$list($db['devices']);
    $i = $find('devices', $m[1]);
    $sub = $m[2] ?? '';
    if ($sub === '' && $method === 'GET') {
	$d = $db['devices'][$i];
	if (($query['$expand'] ?? '') === 'detectedApps')
	    $d['detectedApps'] = array_values(array_map(fn($a) => array_diff_key($a, [ 'devices' => 1, 'deviceCount' => 1 ]), array_filter($db['detectedApps'], fn($a) => in_array($d['id'], $a['devices'], true))));
	$out($d);
    }
    if ($sub === '' && $method === 'DELETE') {
	array_splice($db['devices'], $i, 1);
	$commit();
	$out(null, 204);
    }
    if ($sub === 'deviceCompliancePolicyStates')
	$list([ [ 'id' => 'st1', 'displayName' => 'Windows baseline', 'state' => $db['devices'][$i]['complianceState'], 'platformType' => 'windows10AndLater', 'settingStates' => [ [ 'setting' => 'BitLocker',
	    'settingName' => 'Require BitLocker', 'state' => $db['devices'][$i]['isEncrypted'] ? 'compliant' : 'nonCompliant', 'currentValue' => $db['devices'][$i]['isEncrypted'] ? 'true' : 'false' ] ] ] ]);
    if ($method === 'POST' && in_array($sub, [ 'syncDevice', 'rebootNow', 'remoteLock', 'shutDown', 'locateDevice', 'resetPasscode', 'windowsDefenderScan', 'windowsDefenderUpdateSignatures', 'retire', 'wipe' ], true)) {
	if ($sub === 'windowsDefenderScan' && $db['devices'][$i]['operatingSystem'] !== 'Windows')
	    $err(400, 'BadRequest', 'Defender scan is only supported on Windows devices');
	$db['actions'][] = [ $db['devices'][$i]['id'], $sub, $body ];
	$commit();
	$out(null, 204);
    }
    $err(400, 'BadRequest', "not in the fake: {$method} {$p}");
}
foreach([ 'deviceCompliancePolicies' => 'compliance', 'deviceConfigurations' => 'configurations' ] as $seg => $key) {
    if (!preg_match("~^/deviceManagement/{$seg}(?:/([^/]+))?(?:/(\w+))?$~", $p, $m))
	continue;
    if (empty($m[1]))
	$list($db[$key], [ 'assignments', 'statuses', 'overview' ]);
    $x = $db[$key][$find($key, $m[1])];
    $sub = $m[2] ?? '';
    if ($sub === 'deviceStatuses')
	$list($x['statuses']);
    if ($sub === 'deviceStatusOverview')
	$out($x['overview']);
    $r = array_diff_key($x, [ 'statuses' => 1, 'overview' => 1 ] + (($query['$expand'] ?? '') === 'assignments' ? [] : [ 'assignments' => 1 ]));
    $out($r);
}
if (preg_match('~^/deviceAppManagement/mobileApps(?:/([^/]+))?(?:/assignments(?:/([^/]+))?)?$~', $p, $m)) {
    if (empty($m[1]))
	$list($db['apps'], [ 'assignments' ]);
    $i = $find('apps', $m[1]);
    if (!str_contains($p, '/assignments'))
	$out(array_diff_key($db['apps'][$i], [ 'assignments' => 1 ]));
    if ($method === 'GET')
	$list($db['apps'][$i]['assignments']);
    if ($method === 'POST') {
	if (($body['target']['@odata.type'] ?? '') === '#microsoft.graph.groupAssignmentTarget' && empty($body['target']['groupId']))
	    $err(400, 'BadRequest', 'groupId required');
	$a = [ 'id' => 'aa' . ($db['next']++), 'intent' => $body['intent'], 'target' => $body['target'] ];
	$db['apps'][$i]['assignments'][] = $a;
	$db['apps'][$i]['isAssigned'] = true;
	$commit();
	$out($a, 201);
    }
    if ($method === 'DELETE') {
	$db['apps'][$i]['assignments'] = array_values(array_filter($db['apps'][$i]['assignments'], fn($a) => $a['id'] !== $m[2]));
	$commit();
	$out(null, 204);
    }
}
if (preg_match('~^/deviceManagement/detectedApps(?:/([^/]+)/managedDevices)?$~', $p, $m)) {
    if (empty($m[1]))
	$list($db['detectedApps'], [ 'devices' ]);
    $a = $db['detectedApps'][$find('detectedApps', $m[1])];
    $list(array_values(array_map(fn($d) => [ 'id' => $d['id'], 'deviceName' => $d['deviceName'] ], array_filter($db['devices'], fn($d) => in_array($d['id'], $a['devices'], true)))));
}
if (preg_match('~^/deviceManagement/windowsAutopilotDeviceIdentities(?:/([^/]+))?(?:/(\w+))?$~', $p, $m)) {
    if (empty($m[1]))
	$list($db['autopilot']);
    $i = $find('autopilot', $m[1]);
    if (($m[2] ?? '') === 'updateDeviceProperties' && $method === 'POST') {
	foreach([ 'groupTag', 'displayName', 'userPrincipalName' ] as $k)
	    if (isset($body[$k]))
		$db['autopilot'][$i][$k] = $body[$k];
	$commit();
	$out(null, 204);
    }
    if ($method === 'DELETE') {
	array_splice($db['autopilot'], $i, 1);
	$commit();
	$out(null, 204);
    }
    $out($db['autopilot'][$i]);
}
if (preg_match('~^/deviceManagement/importedWindowsAutopilotDeviceIdentities(?:/([^/]+))?$~', $p, $m)) {
    if ($method === 'POST') {
	if (base64_decode((string)($body['hardwareIdentifier'] ?? ''), true) === false || empty($body['serialNumber']))
	    $err(400, 'BadRequest', 'hardwareIdentifier is not valid');
	$x = [ 'id' => $g($db['next']++), 'serialNumber' => $body['serialNumber'], 'groupTag' => $body['groupTag'] ?? '', 'assignedUserPrincipalName' => $body['assignedUserPrincipalName'] ?? '',
	    'state' => [ 'deviceImportStatus' => 'pending', 'deviceRegistrationId' => '', 'deviceErrorCode' => 0, 'deviceErrorName' => '' ] ];
	$db['imports'][] = $x;
	$commit();
	$out($x, 201);
    }
    $i = $find('imports', $m[1]);
    $db['imports'][$i]['state'] = [ 'deviceImportStatus' => 'complete', 'deviceRegistrationId' => $g(777), 'deviceErrorCode' => 0, 'deviceErrorName' => '' ];
    $commit();
    $out($db['imports'][$i]);
}
$err(400, 'BadRequest', "not in the fake: {$method} {$p}");
