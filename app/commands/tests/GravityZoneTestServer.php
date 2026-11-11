<?php

/*
    a fake GravityZone console for the check suite (CheckCommand::suiteGravityzone), run by PHP's built-in server:
    GZ_STATE=<a folder> php -S 127.0.0.1:<port> GravityZoneTestServer.php
	POST /api/v<version>/jsonrpc/<area>     JSON-RPC 2.0; keys: personal `pkey` (Basic pkey:), service `skey` (X-API-Key); each request appended to <state>/requests.jsonl
	    general.getApiKeyDetails            { enabledApis }
	    network.getEndpointsList            250 endpoints, paged (page, perPage at most 100): { page, pagesCount, perPage, total, items }
	    network.limited                     429 Retry-After: 1 the first time (<state>/limited), then { ok: true }
	    network.failing                     a JSON-RPC error with details
	    reports.getDownloadLinks            { readyForDownload, lastInstanceUrl: /download/<reportId> }
	    push.setPushEventSettings           the params kept (<state>/push.json): true
	    push.getPushEventSettings           what was kept
	    any other                           { method, params, area, version } (echo)
	GET /download/<id>                      a ZIP: endpoints.csv in a folder, run.exe (the key required)
*/

$state = getenv('GZ_STATE') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$authorized = ($headers['authorization'] ?? '') === 'Basic ' . base64_encode('pkey:') || ($headers['x-api-key'] ?? '') === 'skey';

$answer = function($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
};

if (preg_match('~^/download/([A-Za-z0-9_-]+)$~', $path, $m)) {
    if (!$authorized) {
	http_response_code(401);
	exit;
    }
    //a ZIP with the report as CSV (and a file of a type Files do not take)
    $tmp = tempnam(sys_get_temp_dir(), 'gzz');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('Weekly report/endpoints.csv', "name,status\nPC-1,ok\n");
    $zip->addFromString('run.exe', 'MZ');
    $zip->close();
    header('Content-Type: application/zip');
    readfile($tmp);
    unlink($tmp);
    exit;
}

if (!preg_match('~^/api/v(\d+\.\d+)/jsonrpc/([A-Za-z]+(?:/[A-Za-z]+)?)$~', $path, $m))
    $answer(404, [ 'error' => 'not found' ]);
[ , $version, $area ] = $m;
$req = json_decode((string)file_get_contents('php://input'), true);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'area' => $area, 'version' => $version, 'request' => $req, 'auth' => $headers['authorization'] ?? null,
    'apiKey' => $headers['x-api-key'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$id = $req['id'] ?? null;
if (!$authorized)
    $answer(401, [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32001, 'message' => 'Unauthorized', 'data' => [ 'details' => 'The API key is not valid' ] ] ]);
$method = (string)($req['method'] ?? '');
$params = (array)($req['params'] ?? []);
$ok = fn($result) => $answer(200, [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ]);

switch("{$area}.{$method}") {
    case 'general.getApiKeyDetails':
	$ok([ 'enabledApis' => [ 'network', 'incidents', 'reports', 'push' ] ]);
    case 'network.getEndpointsList':
	$per = min(100, max(1, (int)($params['perPage'] ?? 30)));
	$page = max(1, (int)($params['page'] ?? 1));
	$items = [];
	for ($i = ($page - 1) * $per; $i < min(250, $page * $per); $i++)
	    $items[] = [ 'id' => sprintf('ep%04d', $i), 'name' => "PC-{$i}", 'isManaged' => true ];
	$ok([ 'page' => $page, 'pagesCount' => (int)ceil(250 / $per), 'perPage' => $per, 'total' => 250, 'items' => $items ]);
    case 'network.limited':
	if (!is_file("{$state}/limited")) {
	    touch("{$state}/limited");
	    header('Retry-After: 1');
	    $answer(429, [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 429, 'message' => 'Too many requests' ] ]);
	}
	$ok([ 'ok' => true ]);
    case 'network.failing':
	$answer(200, [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32602, 'message' => 'Invalid params', 'data' => [ 'details' => 'The endpointId is not valid' ] ] ]);
    case 'reports.getDownloadLinks':
	$ok([ 'readyForDownload' => true, 'lastInstanceUrl' => 'http://' . $_SERVER['HTTP_HOST'] . '/download/' . ($params['reportId'] ?? 'x') ]);
    case 'push.setPushEventSettings':
	file_put_contents("{$state}/push.json", json_encode($params, JSON_UNESCAPED_SLASHES));
	$ok(true);
    case 'push.getPushEventSettings':
	$ok(is_file("{$state}/push.json") ? json_decode(file_get_contents("{$state}/push.json"), true) : [ 'status' => 0 ]);
}
$ok([ 'method' => $method, 'params' => $params, 'area' => $area, 'version' => $version ]);
