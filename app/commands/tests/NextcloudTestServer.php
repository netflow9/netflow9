<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: a fake Nextcloud for the check suite (CheckCommand::suiteNextcloud), run by PHP's built-in server:
    NX_STATE=<a folder> php -S 127.0.0.1:<port> NextcloudTestServer.php
	Basic auth ncuser / app-pass-1 (else 401). The user's files are <state>/files/ncuser (a real folder):
	    WebDAV /remote.php/dav/files/ncuser/…: PROPFIND (Depth 0 / 1), GET, PUT (If-None-Match: * refused when it exists), MKCOL, MOVE / COPY (Destination, Overwrite), DELETE;
	    SEARCH /remote.php/dav/ (the literal of a displayname like, the scope's folder and below)
	OCS /ocs/v2.php/… (format=json): cloud/capabilities, cloud/user, cloud/users[/details|/<id>[/enable|/disable|/groups]], cloud/groups, apps/files_sharing/api/v1/shares[/<id>],
	    apps/spreed/api/v4/room, apps/spreed/api/v1/chat/<token>; their data in <state>/<name>.json (users, groups, shares, rooms, messages)
	Each request appended to <state>/requests.jsonl (method, path, query, form, headers)
*/

$state = getenv('NX_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = rawurldecode(parse_url($uri, PHP_URL_PATH));
parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$form = [];
if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded'))
    parse_str($raw, $form);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'form' => $form,
    'headers' => array_filter([ 'depth' => $hdr('Depth'), 'destination' => $hdr('Destination'), 'overwrite' => $hdr('Overwrite'), 'if-none-match' => $hdr('If-None-Match'),
	'ocs' => $hdr('OCS-APIRequest') ]), 'body' => in_array($method, [ 'PROPFIND', 'SEARCH' ], true) ? $raw : null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$ocs = function($data, $status = 200, $message = 'OK', $code = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([ 'ocs' => [ 'meta' => [ 'status' => $status < 300 ? 'ok' : 'failure', 'statuscode' => $code ?? $status, 'message' => $message ], 'data' => $data ] ], JSON_UNESCAPED_SLASHES);
    exit;
};
$sabre = function($status, $message) {
    http_response_code($status);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?><d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:exception>Sabre\DAV\Exception</s:exception><s:message>'
	. htmlspecialchars($message, ENT_XML1) . '</s:message></d:error>';
    exit;
};

if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'ncuser' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'app-pass-1') {
    if (str_starts_with($path, '/ocs/'))
	$ocs([], 401, 'Current user is not logged in');
    $sabre(401, 'No public access to this resource., Username or password was incorrect');
}

// ------------------------------------------------------------------------------------
//WebDAV
$root = "{$state}/files/ncuser";
@mkdir($root, 0700, true);
$prefix = '/remote.php/dav/files/ncuser';
$local = fn($rel) => $root . rtrim($rel, '/');
$relOf = function($href) use ($prefix) {
    $p = rawurldecode(parse_url((string)$href, PHP_URL_PATH));
    return str_starts_with($p, $prefix) ? (substr($p, strlen($prefix)) ?: '/') : null;
};
$entry = function($rel) use ($local, $prefix) {
    $f = $local($rel);
    $dir = is_dir($f);
    $href = $prefix . implode('/', array_map('rawurlencode', explode('/', $rel === '/' ? '' : $rel))) . ($dir ? '/' : '');
    $size = $dir ? 0 : filesize($f);
    $etag = md5(filemtime($f) . ':' . $size . ':' . $rel);
    $props = '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', filemtime($f)) . ' GMT</d:getlastmodified><d:getetag>"' . $etag . '"</d:getetag>'
	. ($dir ? '<d:resourcetype><d:collection/></d:resourcetype><oc:size>' . $size . '</oc:size>'
	    : '<d:resourcetype/><d:getcontentlength>' . $size . '</d:getcontentlength><d:getcontenttype>' . (str_ends_with($rel, '.pdf') ? 'application/pdf' : 'text/plain') . '</d:getcontenttype>')
	. '<oc:fileid>' . (crc32($rel) % 100000) . '</oc:fileid><oc:permissions>RGDNVW</oc:permissions><oc:favorite>0</oc:favorite>';
    return '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
	. '<d:propstat><d:prop><nc:has-preview/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>';
};
$multi = function(array $rels) use ($entry) {
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
	. implode('', array_map($entry, $rels)) . '</d:multistatus>';
    exit;
};
if ($path === '/remote.php/dav/' && $method === 'SEARCH') {
    preg_match('~<d:literal>(.*?)</d:literal>~s', $raw, $l);
    preg_match('~<d:href>/files/ncuser(.*?)</d:href>~s', $raw, $h);
    $needle = str_replace('%', '', html_entity_decode($l[1] ?? '', ENT_XML1));
    $scope = html_entity_decode($h[1] ?? '', ENT_XML1) ?: '/';
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($local($scope), FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $f)
	if (str_contains(mb_strtolower($f->getFilename()), $needle))
	    $found[] = substr($f->getPathname(), strlen($root));
    $multi($found);
}
if (str_starts_with($path, $prefix)) {
    $rel = substr($path, strlen($prefix)) ?: '/';
    $rel = '/' . trim($rel, '/');
    $f = $local($rel);
    $exists = file_exists($f);
    switch($method) {
	case 'PROPFIND':
	    if (!$exists)
		$sabre(404, "File with name {$rel} could not be located");
	    $rels = [ $rel ];
	    if (is_dir($f) && ($hdr('Depth') ?? '1') === '1')
		foreach(scandir($f) as $n)
		    if ($n !== '.' && $n !== '..')
			$rels[] = rtrim($rel, '/') . '/' . $n;
	    $multi($rels);
	case 'GET':
	    if (!$exists || is_dir($f))
		$sabre(404, "File with name {$rel} could not be located");
	    header('Content-Type: ' . (str_ends_with($rel, '.pdf') ? 'application/pdf' : 'text/plain'));
	    readfile($f);
	    exit;
	case 'PUT':
	    if ($exists && $hdr('If-None-Match') === '*')
		$sabre(412, 'An If-None-Match header was specified, but the ETag matched (or * was specified).');
	    if (!is_dir(dirname($f)))
		$sabre(409, 'Parent node does not exist');
	    file_put_contents($f, $raw);
	    http_response_code($exists ? 204 : 201);
	    exit;
	case 'MKCOL':
	    if ($exists)
		$sabre(405, 'The resource you tried to create already exists');
	    if (!is_dir(dirname($f)))
		$sabre(409, 'Parent node does not exist');
	    mkdir($f);
	    http_response_code(201);
	    exit;
	case 'MOVE':
	case 'COPY':
	    $to = $relOf($hdr('Destination'));
	    if (!$exists || $to === null)
		$sabre(404, "File with name {$rel} could not be located");
	    $t = $local('/' . trim($to, '/'));
	    if (file_exists($t) && $hdr('Overwrite') === 'F')
		$sabre(412, 'Destination already exists and overwrite is false');
	    if (!is_dir(dirname($t)))
		$sabre(409, 'The destination node is not found');
	    $method === 'MOVE' ? rename($f, $t) : copy($f, $t);
	    http_response_code(201);
	    exit;
	case 'DELETE':
	    if (!$exists)
		$sabre(404, "File with name {$rel} could not be located");
	    is_dir($f) ? rmdir($f) : unlink($f);
	    http_response_code(204);
	    exit;
    }
    $sabre(405, 'Method not allowed');
}

// ------------------------------------------------------------------------------------
//OCS
if (!str_starts_with($path, '/ocs/v2.php/') || ($query['format'] ?? '') !== 'json' || $hdr('OCS-APIRequest') !== 'true')
    $sabre(404, 'not found');
$p = substr($path, strlen('/ocs/v2.php'));
$users = $load('users', []);
$userOf = fn($id) => $users[$id] ?? null;

if ($p === '/cloud/capabilities')
    $ocs([ 'version' => [ 'major' => 30, 'string' => '30.0.4' ], 'capabilities' => [] ]);
if ($p === '/cloud/user')
    $ocs([ 'id' => 'ncuser', 'displayname' => 'NC User', 'quota' => [ 'used' => 1048576, 'total' => 10737418240 ] ]);
if ($p === '/cloud/users/details' && $method === 'GET') {
    $s = mb_strtolower((string)($query['search'] ?? ''));
    $all = array_filter($users, fn($u) => $s === '' || str_contains(mb_strtolower($u['id'] . ' ' . ($u['email'] ?? '') . ' ' . ($u['displayname'] ?? '')), $s));
    $ocs([ 'users' => array_slice($all, (int)($query['offset'] ?? 0), (int)($query['limit'] ?? 100), true) ]);
}
if ($p === '/cloud/users' && $method === 'POST') {
    $id = (string)($form['userid'] ?? '');
    if (isset($users[$id]))
	$ocs([], 400, 'User already exists', 102);
    if (($form['password'] ?? '') === '' && ($form['email'] ?? '') === '')
	$ocs([], 400, 'To send a password link to the user an email address is required.', 108);
    $users[$id] = [ 'id' => $id, 'enabled' => true, 'email' => $form['email'] ?? null, 'displayname' => $form['displayName'] ?? $id, 'groups' => array_values((array)($form['groups'] ?? [])),
	'quota' => [ 'quota' => $form['quota'] ?? 'default' ] ];
    $save('users', $users);
    $ocs([ 'id' => $id ]);
}
if (preg_match('~^/cloud/users/([^/]+)(?:/(enable|disable|groups))?$~', $p, $m)) {
    $id = rawurldecode($m[1]);
    $u = $userOf($id);
    if (!$u)
	$ocs([], 404, 'User does not exist');
    $sub = $m[2] ?? '';
    if ($sub === '' && $method === 'GET')
	$ocs($u);
    if ($sub === '' && $method === 'PUT') {
	$key = (string)($form['key'] ?? '');
	if (!in_array($key, [ 'email', 'displayname', 'quota' ], true))
	    $ocs([], 400, 'Invalid key', 103);
	$key === 'quota' ? $u['quota']['quota'] = $form['value'] : $u[$key] = $form['value'];
    } elseif ($sub === '' && $method === 'DELETE') {
	unset($users[$id]);
	$save('users', $users);
	$ocs([]);
    } elseif ($sub === 'enable' || $sub === 'disable')
	$u['enabled'] = $sub === 'enable';
    elseif ($sub === 'groups') {
	$g = (string)($form['groupid'] ?? $query['groupid'] ?? '');
	if (!in_array($g, $load('groups', []), true))
	    $ocs([], 400, 'Group does not exist', 102);
	$u['groups'] = $method === 'POST' ? array_values(array_unique(array_merge($u['groups'], [ $g ]))) : array_values(array_diff($u['groups'], [ $g ]));
    }
    $users[$id] = $u;
    $save('users', $users);
    $ocs([]);
}
if ($p === '/cloud/groups')
    $ocs([ 'groups' => array_values(array_filter($load('groups', []), fn($g) => ($query['search'] ?? '') === '' || str_contains($g, $query['search']))) ]);

if (preg_match('~^/apps/files_sharing/api/v1/shares(?:/(\d+))?$~', $p, $m)) {
    $shares = $load('shares', []);
    $sid = $m[1] ?? null;
    if ($sid === null && $method === 'GET')
	$ocs(array_values(array_filter($shares, fn($s) => isset($query['path']) ? $s['path'] === $query['path'] : (($query['shared_with_me'] ?? '') === 'true' ? $s['share_with'] === 'ncuser' : $s['uid_owner'] === 'ncuser'))));
    if ($sid === null && $method === 'POST') {
	if (!file_exists($local((string)$form['path'])))
	    $ocs([], 404, 'Wrong path, file/folder does not exist');
	$id = (string)(count($shares) + 11);
	$type = (int)$form['shareType'];
	$s = [ 'id' => $id, 'share_type' => $type, 'uid_owner' => 'ncuser', 'path' => $form['path'], 'item_type' => is_dir($local($form['path'])) ? 'folder' : 'file',
	    'permissions' => (int)($form['permissions'] ?? ($type === 3 ? 1 : 19)), 'share_with' => $form['shareWith'] ?? null, 'expiration' => isset($form['expireDate']) ? $form['expireDate'] . ' 00:00:00' : null,
	    'note' => $form['note'] ?? '', 'label' => $form['label'] ?? '', 'password_set' => isset($form['password']) ] + ($type === 3 ? [ 'token' => "tok{$id}", 'url' => "https://cloud.example.com/s/tok{$id}" ] : []);
	$shares[$id] = $s;
	$save('shares', $shares);
	$ocs($s);
    }
    if ($sid !== null && !isset($shares[$sid]))
	$ocs([], 404, 'Wrong share ID, share does not exist');
    if ($method === 'GET')
	$ocs([ $shares[$sid] ]);
    if ($method === 'PUT') {
	foreach([ 'permissions', 'note', 'label' ] as $k)
	    if (isset($form[$k]))
		$shares[$sid][$k] = $k === 'permissions' ? (int)$form[$k] : $form[$k];
	if (isset($form['expireDate']))
	    $shares[$sid]['expiration'] = $form['expireDate'] . ' 00:00:00';
	$save('shares', $shares);
	$ocs($shares[$sid]);
    }
    if ($method === 'DELETE') {
	unset($shares[$sid]);
	$save('shares', $shares);
	$ocs([]);
    }
}
if ($p === '/apps/spreed/api/v4/room')
    $ocs(array_values($load('rooms', [])));
if (preg_match('~^/apps/spreed/api/v1/chat/([a-z0-9]+)$~', $p, $m)) {
    $token = $m[1];
    if (!array_filter($load('rooms', []), fn($r) => $r['token'] === $token))
	$ocs([], 404, 'Conversation not found');
    $messages = $load('messages', []);
    if ($method === 'POST') {
	$msg = [ 'id' => count($messages) + 1, 'token' => $token, 'actorId' => 'ncuser', 'message' => (string)$form['message'], 'silent' => ($form['silent'] ?? '') === 'true', 'timestamp' => time() ];
	$messages[] = $msg;
	$save('messages', $messages);
	$ocs($msg, 201);
    }
    $ocs(array_slice(array_reverse(array_values(array_filter($messages, fn($x) => $x['token'] === $token))), 0, (int)($query['limit'] ?? 100)));
}
$ocs([], 404, 'Invalid query, please check the syntax. API specifications are here: http://www.freedesktop.org/wiki/Specifications/open-collaboration-services.', 998);
