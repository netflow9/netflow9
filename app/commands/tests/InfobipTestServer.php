<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: a fake Infobip for the check suite (CheckCommand::suiteInfobip), run by PHP's built-in server:
    INFOBIP_STATE=<a folder> php -S 127.0.0.1:<port> InfobipTestServer.php
	`Authorization: App key-ib` (else 401). SMS (sms/2/text/advanced, preview, logs; reports and inbox/reports given once from <state>/reports.json, inbox.json), WhatsApp (text,
	template, image / document / video / audio from the sender 447860099299 only; templates), Email (email/3/send, multipart: $_POST keeps the last of a repeated name),
	2FA (pin, pin/voice, verify - PIN 1234 -, resend, applications, messages), account/1/balance, number/1/query, numbers/1/numbers. Errors as Infobip's (requestError with
	validationErrors). <state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, post, files, auth)
*/

$state = getenv('INFOBIP_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'post' => $_POST,
    'files' => array_map(fn($f) => [ $f['name'], $f['size'] ], $_FILES), 'auth' => $hdr('Authorization') ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
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
$err = fn($status, $id, $text, array $validation = []) => $out([ 'requestError' => [ 'serviceException' => array_filter([ 'messageId' => $id, 'text' => $text,
    'validationErrors' => $validation ?: null ]) ] ], $status);

if ($hdr('Authorization') !== 'App key-ib')
    $err(401, 'UNAUTHORIZED', 'Invalid login details');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $err(429, 'TOO_MANY_REQUESTS', 'Too many requests', []) ;
}
$n = (int)$load('next', 1);
$save('next', $n + 10);
$pending = fn($to, $id) => [ 'to' => $to, 'status' => [ 'groupId' => 1, 'groupName' => 'PENDING', 'id' => 26, 'name' => 'PENDING_ACCEPTED', 'description' => 'Message sent to next instance' ], 'messageId' => $id ];
$WA = '447860099299';

switch(true) {
    case $path === '/account/1/balance':
	$out([ 'balance' => 47.5, 'currency' => 'EUR' ]);
    case $path === '/sms/2/text/advanced' && $method === 'POST':
	$m = (array)($body['messages'][0] ?? []);
	if (trim((string)($m['text'] ?? '')) === '')
	    $err(400, 'BAD_REQUEST', 'Bad request', [ 'messages[0].text' => [ 'must not be blank' ] ]);
	if (($m['from'] ?? '') === 'TOOLONGSENDER')
	    $err(400, 'BAD_REQUEST', 'Bad request', [ 'messages[0].from' => [ 'size must be between 0 and 11' ] ]);
	$sent = $load('sent', []);
	$res = [];
	foreach((array)($m['destinations'] ?? []) as $k => $d) {
	    $id = "sms-" . ($n + $k);
	    $res[] = $pending($d['to'], $id);
	    $sent[] = [ 'bulkId' => "bulk-{$n}", 'messageId' => $id, 'to' => $d['to'], 'from' => $m['from'] ?? 'InfoSMS', 'text' => $m['text'], 'sentAt' => '2026-11-12T10:00:00.000+0000',
		'status' => [ 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET', 'description' => 'Message delivered to handset' ], 'error' => [ 'groupName' => 'OK' ] ];
	}
	$save('sent', $sent);
	$out([ 'bulkId' => "bulk-{$n}", 'messages' => $res ]);
    case $path === '/sms/1/preview' && $method === 'POST':
	$t = (string)($body['text'] ?? '');
	$gsm = !preg_match('/[^\x20-\x7E\n\r]/u', $t);
	$len = mb_strlen($t);
	[ $one, $part ] = $gsm ? [ 160, 153 ] : [ 70, 67 ];
	$count = $len <= $one ? 1 : (int)ceil($len / $part);
	$out([ 'originalText' => $t, 'previews' => [ [ 'textPreview' => $t, 'messageCount' => $count, 'charactersRemaining' => ($count === 1 ? $one : $count * $part) - $len, 'configuration' => (object)[] ] ] ]);
    case $path === '/sms/1/logs':
	$list = array_values(array_filter($load('sent', []), fn($s) => (!isset($query['to']) || $s['to'] === $query['to']) && (!isset($query['bulkId']) || $s['bulkId'] === $query['bulkId'])));
	$out([ 'results' => array_slice($list, 0, (int)($query['limit'] ?? 50)) ]);
    case $path === '/sms/1/reports' || $path === '/sms/1/inbox/reports' || $path === '/email/1/reports':
	$key = $path === '/sms/1/inbox/reports' ? 'inbox' : 'reports';
	$list = $load($key, []);
	$take = array_slice($list, 0, (int)($query['limit'] ?? 50));
	$save($key, array_slice($list, count($take)));
	$out([ 'results' => $take ]);
    case preg_match('~^/whatsapp/1/message/(text|template|image|document|video|audio)$~', $path, $m) === 1 && $method === 'POST':
	$check = function($msg) use ($WA, $err) {
	    if (($msg['from'] ?? '') !== $WA)
		$err(400, 'BAD_REQUEST', 'Bad request', [ 'from' => [ 'sender is not registered for WhatsApp' ] ]);
	};
	if ($m[1] === 'template') {
	    $res = [];
	    foreach((array)($body['messages'] ?? []) as $k => $msg) {
		$check($msg);
		if (($msg['content']['templateName'] ?? '') !== 'order_shipped')
		    $err(400, 'BAD_REQUEST', 'Bad request', [ "messages[{$k}].content.templateName" => [ 'template not found' ] ]);
		$res[] = $pending($msg['to'], "wa-" . ($n + $k)) + [ 'messageCount' => 1 ];
	    }
	    $out([ 'messages' => $res, 'bulkId' => "bulk-{$n}" ]);
	}
	$check($body);
	$out($pending($body['to'] ?? '', "wa-{$n}") + [ 'messageCount' => 1 ]);
    case preg_match('~^/whatsapp/2/senders/(\d+)/templates$~', $path, $m) === 1:
	$out([ 'templates' => $m[1] === $WA ? [ [ 'id' => '111', 'businessAccountId' => 222, 'name' => 'order_shipped', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
	    'structure' => [ 'body' => [ 'text' => 'Hi {{1}}, your order {{2}} is on its way.' ], 'type' => 'TEXT' ] ] ] : [] ]);
    case $path === '/email/3/send' && $method === 'POST':
	if (empty($_POST['from']) || empty($_POST['to']) || empty($_POST['subject']))
	    $err(400, 'BAD_REQUEST', 'Bad request', [ 'to' => [ 'must not be empty' ] ]);
	$out([ 'bulkId' => "bulk-{$n}", 'messages' => [ $pending($_POST['to'], "em-{$n}") ] ]);
    case $path === '/email/1/logs':
	$out([ 'results' => [ [ 'messageId' => 'em-1', 'to' => 'ana@example.com', 'from' => 'noreply@acme.com', 'text' => 'Your order', 'sentAt' => '2026-11-12T10:00:00.000+0000',
	    'status' => [ 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET' ] ] ] ]);
    case ($path === '/2fa/2/pin' || $path === '/2fa/2/pin/voice') && $method === 'POST':
	if (($body['applicationId'] ?? '') !== 'APP1' || ($body['messageId'] ?? '') !== 'MSG1')
	    $err(404, 'NOT_FOUND', 'Application or message not found');
	$pins = $load('pins', []);
	$id = 'PIN' . str_pad((string)$n, 9, '0', STR_PAD_LEFT);
	$pins[$id] = [ 'to' => $body['to'], 'pin' => '1234', 'attempts' => 3 ];
	$save('pins', $pins);
	$out([ 'pinId' => $id, 'to' => $body['to'], 'ncStatus' => 'NC_DESTINATION_REACHABLE', $path === '/2fa/2/pin' ? 'smsStatus' : 'callStatus' => 'MESSAGE_SENT' ]);
    case preg_match('~^/2fa/2/pin/([A-Za-z0-9]+)/(verify|resend)$~', $path, $m) === 1 && $method === 'POST':
	$pins = $load('pins', []);
	if (!isset($pins[$m[1]]))
	    $err(404, 'NOT_FOUND', 'Pin not found');
	if ($m[2] === 'resend')
	    $out([ 'pinId' => $m[1], 'to' => $pins[$m[1]]['to'], 'smsStatus' => 'MESSAGE_SENT' ]);
	$ok = (string)($body['pin'] ?? '') === $pins[$m[1]]['pin'];
	$pins[$m[1]]['attempts'] = $ok ? 0 : $pins[$m[1]]['attempts'] - 1;
	$save('pins', $pins);
	$out(array_filter([ 'pinId' => $m[1], 'msisdn' => $pins[$m[1]]['to'], 'verified' => $ok, 'attemptsRemaining' => $pins[$m[1]]['attempts'], 'pinError' => $ok ? null : 'WRONG_PIN' ],
	    fn($v) => $v !== null));
    case $path === '/2fa/2/applications':
	$out([ [ 'applicationId' => 'APP1', 'name' => 'Login', 'enabled' => true, 'configuration' => [ 'pinAttempts' => 3, 'pinTimeToLive' => '15m' ] ] ]);
    case $path === '/2fa/2/applications/APP1/messages':
	$out([ [ 'messageId' => 'MSG1', 'applicationId' => 'APP1', 'messageText' => 'Your code is {{pin}}', 'pinType' => 'NUMERIC', 'pinLength' => 4, 'senderId' => 'Acme', 'language' => 'en' ] ]);
    case $path === '/number/1/query' && $method === 'POST':
	$out([ 'results' => array_map(fn($to) => str_starts_with($to, '40') ? [ 'to' => $to, 'mccMnc' => '22610', 'originalNetwork' => [ 'networkName' => 'Orange', 'countryName' => 'Romania', 'countryPrefix' => '40' ],
	    'ported' => $to === '40733000000', 'portedNetwork' => $to === '40733000000' ? [ 'networkName' => 'Vodafone' ] : null, 'roaming' => false,
	    'status' => [ 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET' ], 'error' => [ 'groupName' => 'OK' ] ]
	    : [ 'to' => $to, 'status' => [ 'groupName' => 'UNDELIVERABLE', 'name' => 'UNDELIVERABLE_NOT_DELIVERED' ], 'error' => [ 'groupName' => 'HANDSET_ERRORS', 'description' => 'Absent subscriber' ] ],
	    (array)($body['to'] ?? [])) ]);
    case $path === '/numbers/1/numbers':
	$out([ 'numbers' => [ [ 'numberKey' => 'NK1', 'number' => $WA, 'country' => 'GB', 'type' => 'VIRTUAL_LONG_NUMBER', 'capabilities' => [ 'SMS', 'WHATSAPP' ] ] ], 'numberCount' => 1 ]);
}
$err(404, 'NOT_FOUND', "not in the fake: {$method} {$path}");
