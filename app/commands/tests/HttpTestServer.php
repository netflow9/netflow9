<?php

/*
    CLD: 2026-10-23T15:00:00+03:00: new file: a local HTTP server for the check suite (CheckCommand::suiteHttp: util\Http and the HTTP steps), run by PHP's built-in server:
    php -S 127.0.0.1:<port> HttpTestServer.php
	/echo                   JSON: { method, headers (lower-case names), body, query }
	/redirect?status=&to=   that status with Location: to (default 302, /echo)
	/loop                   302 to itself
	/bytes?n=               n bytes (x), text/plain
	/status?code=           that status, a JSON error { error: { message } }
	/file                   a small CSV with Content-Disposition: attachment; filename="report.csv"
	/page                   a small HTML page (CLD: 2026-10-30T15:00:00+02:00: the Read a web page step)
*/

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch($path) {
    case '/echo':
	$headers = [];
	foreach(getallheaders() as $n => $v)
	    $headers[strtolower($n)] = $v;
	header('Content-Type: application/json');
	header('X-Check: one');
	header('X-Check: two', false);
	//CLD: 2026-10-28T08:30:00+02:00: + raw_query (the query string as sent: $_GET keeps one value per name)
	echo json_encode([ 'method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers, 'body' => file_get_contents('php://input'), 'query' => $_GET,
	    'raw_query' => $_SERVER['QUERY_STRING'] ?? '' ], JSON_UNESCAPED_SLASHES);
	break;
    case '/redirect':
	http_response_code((int)($_GET['status'] ?? 302));
	header('Location: ' . ($_GET['to'] ?? '/echo'));
	echo 'moved';
	break;
    case '/loop':
	http_response_code(302);
	header('Location: /loop');
	break;
    case '/bytes':
	header('Content-Type: text/plain');
	echo str_repeat('x', (int)($_GET['n'] ?? 10));
	break;
    case '/status':
	http_response_code((int)($_GET['code'] ?? 500));
	header('Content-Type: application/json');
	echo json_encode([ 'error' => [ 'message' => 'check error' ] ]);
	break;
    case '/file':
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="report.csv"');
	echo "a,b\n1,2\n";
	break;
    //CLD: 2026-10-30T15:00:00+02:00: a small HTML page (the Read a web page step)
    case '/page':
	header('Content-Type: text/html; charset=utf-8');
	echo '<!doctype html><html><head><title>Check &amp; page</title><style>p { color: red }</style></head><body><h1>Hello</h1><p>First <b>line</b></p><script>x()</script></body></html>';
	break;
    default:
	http_response_code(404);
	echo 'not found';
}
