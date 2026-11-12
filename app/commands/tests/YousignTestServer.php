<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: a fake Yousign API v3 for the check suite (CheckCommand::suiteYousign), run by PHP's built-in server:
    YOUSIGN_STATE=<a folder> php -S 127.0.0.1:<port> YousignTestServer.php
	`Authorization: Bearer key-ys` (else 401). Signature requests (create - from a template too -, get, list with status[in] / source[eq] / external_id[eq] / q and cursor
	pages, activate, cancel, delete), documents (multipart: a file named *broken* refused, 3 pages), signers (an email refused@example.com refused with invalid_params),
	reminders, the documents' download (a PDF; a ZIP of PDFs when several: version=completed when done), audit trails, templates, workspaces, users, contacts, webhooks.
	The requests in <state>/requests.json (the check sets a status there); <state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to
	<state>/requests.jsonl (method, path, query, body, post, files, auth)
*/

$state = getenv('YOUSIGN_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = preg_replace('~^/v3~', '', rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$qs = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
parse_str($qs, $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'qs' => $qs, 'body' => $body, 'post' => $_POST,
    'files' => array_map(fn($f) => [ $f['name'], $f['size'], $f['type'] ], $_FILES), 'auth' => $hdr('Authorization') ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    header('Content-Type: application/json');
    echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$file = function($bytes, $mime, $name) {
    header("Content-Type: {$mime}");
    header("Content-Disposition: attachment; filename=\"{$name}\"");
    echo $bytes;
    exit;
};
$err = fn($status, $detail, array $params = []) => $out(array_filter([ 'type' => 'about:blank', 'detail' => $detail, 'invalid_params' => $params ?: null ]), $status);
$uuid = function() use ($load, $save) {
    $n = (int)$load('next', 1);
    $save('next', $n + 1);
    return sprintf('00000000-0000-4000-8000-%012d', $n);
};

if ($hdr('Authorization') !== 'Bearer key-ys')
    $err(401, 'Unauthorized');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'message' => 'Too Many Requests' ], 429, [ 'Retry-After' => '1' ]);
}
$reqs = $load('requests', []);
$page = function(array $items) use ($query, $out) {
    $limit = max(1, min(100, (int)($query['limit'] ?? 100)));
    $at = (int)($query['after'] ?? 0);
    $slice = array_slice(array_values($items), $at, $limit);
    $out([ 'meta' => [ 'next_cursor' => $at + $limit < count($items) ? (string)($at + $limit) : null ], 'data' => $slice ]);
};
$public = fn($r) => array_merge($r, [ 'signers' => array_map(fn($s) => [ 'id' => $s['id'], 'status' => $s['status'] ], $r['signers']),
    'documents' => array_map(fn($d) => array_diff_key($d, [ 'bytes' => true ]), $r['documents']) ]);

if (preg_match('~^/signature_requests/([0-9a-f-]{36})(/.*)?$~', $path, $m)) {
    $id = $m[1];
    $rest = $m[2] ?? '';
    if (!isset($reqs[$id]))
	$err(404, 'Signature request not found');
    $r = &$reqs[$id];
    switch(true) {
	case $rest === '' && $method === 'GET':
	    $out($public($r));
	case $rest === '' && $method === 'DELETE':
	    unset($reqs[$id]);
	    $save('requests', $reqs);
	    http_response_code(204);
	    exit;
	case $rest === '/documents' && $method === 'POST':
	    $f = $_FILES['file'] ?? null;
	    if (!$f || $f['error'])
		$err(400, 'file is required', [ [ 'name' => 'file', 'reason' => 'This value should not be blank.' ] ]);
	    if (str_contains($f['name'], 'broken'))
		$err(400, 'The file is not a valid PDF');
	    $d = [ 'id' => $uuid(), 'filename' => $f['name'], 'nature' => $_POST['nature'] ?? '', 'content_type' => 'application/pdf', 'total_pages' => 3, 'bytes' => base64_encode(file_get_contents($f['tmp_name'])) ];
	    $r['documents'][] = $d;
	    $save('requests', $reqs);
	    $out(array_diff_key($d, [ 'bytes' => true ]), 201);
	case $rest === '/documents' && $method === 'GET':
	    $out(array_map(fn($d) => array_diff_key($d, [ 'bytes' => true ]), $r['documents']));
	case $rest === '/signers' && $method === 'POST':
	    $email = (string)($body['info']['email'] ?? '');
	    if ($email === 'refused@example.com')
		$err(400, 'Validation failed', [ [ 'name' => 'info.email', 'reason' => 'This email is blocked.' ] ]);
	    if (empty($body['fields']))
		$err(400, 'A signer needs a signature field');
	    $s = [ 'id' => $uuid(), 'status' => 'initiated', 'info' => $body['info'], 'signature_level' => $body['signature_level'] ?? null,
		'signature_authentication_mode' => $body['signature_authentication_mode'] ?? null, 'fields' => $body['fields'], 'signature_link' => null, 'signed_at' => null ];
	    $r['signers'][] = $s;
	    $save('requests', $reqs);
	    $out($s, 201);
	case $rest === '/signers' && $method === 'GET':
	    $out($r['signers']);
	case $rest === '/activate' && $method === 'POST':
	    if ($r['status'] !== 'draft')
		$err(400, 'The signature request is not a draft');
	    if (!$r['signers'] || (!$r['documents'] && empty($r['template_id'])))
		$err(400, 'A document and a signer are needed');
	    $r['status'] = 'ongoing';
	    $r['activated_at'] = '2026-11-12T10:00:00+00:00';
	    foreach($r['signers'] as &$s) {
		$s['status'] = 'notified';
		if ($r['delivery_mode'] === 'none')
		    $s['signature_link'] = 'https://yousign.app/signatures/' . $s['id'];
	    }
	    unset($s);
	    $save('requests', $reqs);
	    $out($public($r), 201);
	case $rest === '/cancel' && $method === 'POST':
	    if (!in_array($body['reason'] ?? '', [ 'contractualization_aborted', 'errors_in_document', 'other' ], true))
		$err(400, 'reason is not valid');
	    $r['status'] = 'canceled';
	    $save('requests', $reqs);
	    $out($public($r), 201);
	case (bool)preg_match('~^/signers/([0-9a-f-]{36})/send_reminder$~', $rest, $sm) && $method === 'POST':
	    if (!in_array($sm[1], array_column($r['signers'], 'id'), true))
		$err(404, 'Signer not found');
	    http_response_code(201);
	    exit;
	case $rest === '/documents/download':
	    $docs = array_values(array_filter($r['documents'], fn($d) => $d['nature'] === 'signable_document'));
	    $signed = ($query['version'] ?? '') === 'completed';
	    if ($signed && $r['status'] !== 'done')
		$err(400, 'completed is only available when the request is done');
	    $pdf = fn($d) => "%PDF-1.4\n" . ($signed ? 'signed ' : '') . $d['filename'] . "\n%%EOF";
	    if (count($docs) === 1)
		$file($pdf($docs[0]), 'application/pdf', $docs[0]['filename']);
	    $tmp = tempnam(sys_get_temp_dir(), 'ysz');
	    $zip = new ZipArchive();
	    $zip->open($tmp, ZipArchive::OVERWRITE);
	    foreach($docs as $d)
		$zip->addFromString($d['filename'], $pdf($d));
	    $zip->close();
	    $bytes = file_get_contents($tmp);
	    unlink($tmp);
	    $file($bytes, 'application/zip', 'documents.zip');
	case $rest === '/audit_trails/download':
	    $file("%PDF-1.4\naudit trail {$r['name']}\n%%EOF", 'application/pdf', 'audit_trail.pdf');
	case (bool)preg_match('~^/signers/([0-9a-f-]{36})/audit_trails/download$~', $rest, $sm):
	    $file("%PDF-1.4\naudit trail of {$sm[1]}\n%%EOF", 'application/pdf', 'signer_audit_trail.pdf');
    }
    $err(404, "no route {$method} {$path}");
}

switch(true) {
    case $path === '/signature_requests' && $method === 'POST':
	if (isset($body['template_id'])) {
	    if ($body['template_id'] !== '00000000-0000-4000-8000-00000000aaaa')
		$err(404, 'Template not found');
	    $labels = array_column((array)($body['template_placeholders']['signers'] ?? []), 'label');
	    if ($labels !== [ 'Client' ])
		$err(400, 'Placeholders do not match the template', [ [ 'name' => 'template_placeholders.signers', 'reason' => 'expected: Client' ] ]);
	}
	if (!isset($body['template_id']) && trim((string)($body['name'] ?? '')) === '')
	    $err(400, 'Validation failed', [ [ 'name' => 'name', 'reason' => 'This value should not be blank.' ] ]);
	$id = $uuid();
	$signers = [];
	foreach((array)($body['template_placeholders']['signers'] ?? []) as $p)
	    $signers[] = [ 'id' => $uuid(), 'status' => 'initiated', 'info' => $p['info'], 'signature_link' => null, 'signed_at' => null ];
	$r = [ 'id' => $id, 'name' => $body['name'] ?? 'Template contract', 'status' => 'draft', 'delivery_mode' => $body['delivery_mode'] ?? 'email', 'created_at' => '2026-11-12T09:00:00+00:00',
	    'activated_at' => null, 'completed_at' => null, 'expiration_date' => $body['expiration_date'] ?? null, 'external_id' => $body['external_id'] ?? null,
	    'workspace_id' => $body['workspace_id'] ?? 'ws-default', 'ordered_signers' => (bool)($body['ordered_signers'] ?? false), 'template_id' => $body['template_id'] ?? null,
	    'source' => 'public_api', 'signers' => $signers, 'documents' => [] ];
	$reqs[$id] = $r;
	$save('requests', $reqs);
	$out($public($r), 201);
    case $path === '/signature_requests' && $method === 'GET':
	$list = array_values(array_map($public, $reqs));
	if (isset($query['status']['in']))
	    $list = array_filter($list, fn($r) => in_array($r['status'], explode(',', $query['status']['in']), true));
	if (isset($query['external_id']['eq']))
	    $list = array_filter($list, fn($r) => $r['external_id'] === $query['external_id']['eq']);
	if (($query['source']['eq'] ?? 'public_api') !== 'public_api')
	    $list = [];
	if (($query['q'] ?? '') !== '')
	    $list = array_filter($list, fn($r) => stripos($r['name'], $query['q']) !== false);
	$page($list);
    case $path === '/templates':
	$page([ [ 'id' => '00000000-0000-4000-8000-00000000aaaa', 'name' => 'NDA', 'description' => 'Our NDA', 'signers' => [ [ 'label' => 'Client' ] ], 'created_at' => '2026-01-01T00:00:00+00:00' ] ]);
    case $path === '/workspaces':
	$page([ [ 'id' => 'ws-default', 'name' => 'Main', 'is_default' => true ], [ 'id' => 'ws-2', 'name' => 'Sales', 'is_default' => false ] ]);
    case $path === '/users':
	$page([ [ 'id' => 'u1', 'first_name' => 'Ana', 'last_name' => 'Pop', 'email' => 'ana@acme.example', 'role' => 'admin', 'status' => 'activated' ] ]);
    case $path === '/contacts' && $method === 'GET':
	$all = array_merge([ [ 'id' => 'c1', 'first_name' => 'Ion', 'last_name' => 'Ionescu', 'email' => 'ion@example.com', 'phone_number' => null, 'company_name' => 'Client SRL' ] ], $load('contacts', []));
	$page(array_values(array_filter($all, fn($c) => ($query['q'] ?? '') === '' || stripos($c['first_name'] . ' ' . $c['last_name'] . ' ' . $c['email'], $query['q']) !== false)));
    case $path === '/contacts' && $method === 'POST':
	$c = [ 'id' => $uuid() ] + (array)$body;
	$all = $load('contacts', []);
	$all[] = $c;
	$save('contacts', $all);
	$out($c, 201);
    case $path === '/webhooks' && $method === 'GET':
	$out(array_values($load('webhooks', [])));
    case $path === '/webhooks' && $method === 'POST':
	if (strlen((string)($body['secret_key'] ?? '')) !== 32)
	    $err(400, 'Validation failed', [ [ 'name' => 'secret_key', 'reason' => 'This value should have exactly 32 characters.' ] ]);
	$w = [ 'id' => $uuid() ] + (array)$body;
	$all = $load('webhooks', []);
	$all[$w['id']] = $w;
	$save('webhooks', $all);
	$out($w, 201);
    case (bool)preg_match('~^/webhooks/([0-9a-f-]{36})$~', $path, $wm):
	$all = $load('webhooks', []);
	if (!isset($all[$wm[1]]))
	    $err(404, 'Webhook not found');
	if ($method === 'DELETE')
	    unset($all[$wm[1]]);
	elseif ($method === 'PATCH')
	    $all[$wm[1]] = array_merge($all[$wm[1]], (array)$body);
	$save('webhooks', $all);
	if ($method === 'DELETE') {
	    http_response_code(204);
	    exit;
	}
	$out($all[$wm[1]]);
}
$err(404, "no route {$method} {$path}");
