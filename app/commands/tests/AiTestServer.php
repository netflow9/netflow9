<?php

/*
    CLD: 2026-10-22T01:36:46+03:00: new file: a fake local AI server for the check suite (CheckCommand::suiteLocalAi), run by PHP's built-in server: php -S 127.0.0.1:<port> AiTestServer.php
    It answers as a Lemonade server (OpenAI-compatible: GET /api/v1/models, POST /api/v1/chat/completions) and as an Ollama server (GET /api/version, /api/tags, POST /api/chat):
	- an API key: with the env AI_TEST_KEY set, a request without `Authorization: Bearer <key>` gets 401 (as the servers, with their error bodies)
	- a chat with tools and no tool result yet: the model asks for the first tool (arguments {}); with a tool result: the answer names the tool's result (its first 40 characters);
	  a JSON answer (Ollama `format`) when asked; a model named `slow-model` is refused (404, as an unknown model)
	- every request body is appended to <AI_TEST_LOG> (one JSON line: { path, body }) for the checks to read
    CLD: 2026-10-30T23:30:00+02:00: + embeddings (CheckCommand::suiteKb): POST /api/v1/embeddings (OpenAI-compatible) and /api/embed (Ollama): each text's words hashed into 64 numbers
    (texts sharing words are similar); a model named `small-embed` answers 8 numbers (another length), `slow-model` is refused; GET /kb/page: an HTML page (Add a web page)
    CLD: 2026-11-01T21:50:00+02:00: + a scripted chat (CheckCommand::suiteAgenttools; the OpenAI requests): a message with a line `SCRIPT: <JSON>` - a list of rounds, each a list of tool calls
    [ name, arguments ] - asks, in round N (the assistant turns with tool calls so far), for the calls of round N; after the last round it answers `RESULTS: ` and the JSON list of
    every tool result sent ({ tool_call_id, content }, in order)
    CLD: 2026-11-01T23:30:00+02:00: the scripted chat in each API's form (CheckCommand::suiteAgentapproval: every AI step resumes a saved conversation): OpenAI (POST .../chat/completions),
    Claude (POST /v1/messages, key in x-api-key), Gemini (POST .../models/<model>:generateContent, key in x-goog-api-key), Ollama (POST /api/chat). A round may start with a text
    (what the model says with its calls). The results: each tool result sent, an error as `ERROR: <text>` (Claude is_error, Gemini `error`), with the call's id
*/

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (getenv('AI_TEST_LOG'))
    file_put_contents(getenv('AI_TEST_LOG'), json_encode([ 'path' => $path, 'body' => $body ]) . "\n", FILE_APPEND);

$send = function($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
//a web page (no API key)
if ($path === '/kb/page') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><title>Refund policy</title><style>p{}</style></head><body><h1>Refunds</h1><p>Refunds are paid within 14 days of the return.</p></body></html>';
    exit;
}
$ollama = str_starts_with($path, '/api/') && !str_starts_with($path, '/api/v1/');
//CLD: 2026-11-01T23:30:00+02:00: the key as each API sends it (Bearer; Claude x-api-key; Gemini x-goog-api-key)
$key = (string)getenv('AI_TEST_KEY');
$given = [ $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer ' . ($_SERVER['HTTP_X_API_KEY'] ?? ''), 'Bearer ' . ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') ];
if ($key !== '' && !in_array("Bearer {$key}", $given, true))
    $send(401, $ollama ? [ 'error' => 'unauthorized' ] : [ 'error' => [ 'message' => 'Invalid API key', 'type' => 'invalid_request_error' ] ]);

//the result of the last tool message, and the tool asked for
$toolResult = null;
foreach((array)($body['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'tool')
	$toolResult = (string)($m['content'] ?? '');
}
$firstTool = (string)($body['tools'][0]['function']['name'] ?? '');
//the API of the request
$api = str_ends_with($path, '/chat/completions') ? 'openai' : ($path === '/v1/messages' ? 'claude' : (str_ends_with($path, ':generateContent') ? 'gemini' : ($path === '/api/chat' ? 'ollama' : '')));
//the script of the chat (a message's line SCRIPT: [ [ [ name, arguments ], ... ], ... ]), the rounds done (the model's turns with tool calls), the tool results
$script = null;
$round = 0;
$results = [];
$texts = function($v) use (&$texts) {
    if (is_string($v))
	return [ $v ];
    $out = [];
    foreach(is_array($v) ? $v : [] as $x)
	$out = array_merge($out, $texts($x));
    return $out;
};
foreach($texts($body['messages'] ?? $body['contents'] ?? []) as $t) {
    if ($script === null && preg_match('/^SCRIPT: (.+)$/m', $t, $sm))
	$script = json_decode($sm[1], true);
}
foreach((array)($body['messages'] ?? $body['contents'] ?? []) as $m) {
    $role = $m['role'] ?? '';
    if ($api === 'claude') {
	foreach(is_array($m['content'] ?? null) ? $m['content'] : [] as $b) {
	    if (($b['type'] ?? '') === 'tool_result') {
		$c = $b['content'] ?? '';
		$results[] = [ 'tool_call_id' => (string)($b['tool_use_id'] ?? ''), 'content' => (!empty($b['is_error']) ? 'ERROR: ' : '') . (is_string($c) ? $c : implode('', array_map(fn($x) => (string)($x['text'] ?? ''), (array)$c))) ];
	    }
	}
	if ($role === 'assistant' && array_filter((array)($m['content'] ?? []), fn($b) => is_array($b) && ($b['type'] ?? '') === 'tool_use'))
	    $round++;
    } elseif ($api === 'gemini') {
	foreach((array)($m['parts'] ?? []) as $part) {
	    if (isset($part['functionResponse'])) {
		$r = (array)($part['functionResponse']['response'] ?? []);
		$results[] = [ 'tool_call_id' => (string)($part['functionResponse']['id'] ?? $part['functionResponse']['name'] ?? ''), 'content' => isset($r['error']) ? 'ERROR: ' . $r['error'] : (string)($r['result'] ?? '') ];
	    }
	}
	if ($role === 'model' && array_filter((array)($m['parts'] ?? []), fn($x) => isset($x['functionCall'])))
	    $round++;
    } else {
	if ($role === 'assistant' && !empty($m['tool_calls']))
	    $round++;
	if ($role === 'tool')
	    $results[] = [ 'tool_call_id' => (string)($m['tool_call_id'] ?? $m['tool_name'] ?? ''), 'content' => (string)($m['content'] ?? '') ];
    }
}
//a scripted chat: the calls of this round (a text first: said with them), else the results
if (is_array($script) && $api !== '') {
    $mdl = (string)($body['model'] ?? 'scripted');
    $calls = $round < count($script) ? $script[$round] : null;
    $said = is_array($calls) && is_string($calls[0] ?? null) ? array_shift($calls) : '';
    $final = 'RESULTS: ' . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $argsOf = fn($c) => ($c[1] ?? []) === [] ? (object)[] : $c[1];
    switch($api) {
	case 'openai':
	    if ($calls !== null)
		$send(200, [ 'model' => $mdl, 'choices' => [ [ 'index' => 0, 'finish_reason' => 'tool_calls', 'message' => [ 'role' => 'assistant', 'content' => $said !== '' ? $said : null,
		    'tool_calls' => array_map(fn($c, $i) => [ 'id' => "call_{$round}_{$i}", 'type' => 'function', 'function' => [ 'name' => (string)$c[0],
			'arguments' => json_encode($argsOf($c), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ] ], $calls, array_keys($calls)) ] ] ], 'usage' => [ 'prompt_tokens' => 11, 'completion_tokens' => 7 ] ]);
	    $send(200, [ 'model' => $mdl, 'choices' => [ [ 'index' => 0, 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $final ] ] ],
		'usage' => [ 'prompt_tokens' => 11, 'completion_tokens' => 7 ] ]);
	case 'claude':
	    $usage = [ 'input_tokens' => 13, 'output_tokens' => 5 ];
	    if ($calls !== null)
		$send(200, [ 'id' => 'msg_' . $round, 'type' => 'message', 'role' => 'assistant', 'model' => $mdl, 'stop_reason' => 'tool_use', 'usage' => $usage,
		    'content' => array_merge($said !== '' ? [ [ 'type' => 'text', 'text' => $said ] ] : [], array_map(fn($c, $i) => [ 'type' => 'tool_use', 'id' => "toolu_{$round}_{$i}",
			'name' => (string)$c[0], 'input' => $argsOf($c) ], $calls, array_keys($calls))) ]);
	    $send(200, [ 'id' => 'msg_end', 'type' => 'message', 'role' => 'assistant', 'model' => $mdl, 'stop_reason' => 'end_turn', 'usage' => $usage,
		'content' => [ [ 'type' => 'text', 'text' => $final ] ] ]);
	case 'gemini':
	    $mdl = preg_replace('~^.*/models/([^:]+):.*$~', '$1', $path);
	    $usage = [ 'promptTokenCount' => 17, 'candidatesTokenCount' => 3 ];
	    if ($calls !== null)
		$send(200, [ 'modelVersion' => $mdl, 'usageMetadata' => $usage, 'candidates' => [ [ 'finishReason' => 'STOP', 'content' => [ 'role' => 'model',
		    'parts' => array_merge($said !== '' ? [ [ 'text' => $said ] ] : [], array_map(fn($c, $i) => [ 'functionCall' => [ 'id' => "fc_{$round}_{$i}", 'name' => (string)$c[0],
			'args' => $argsOf($c) ], 'thoughtSignature' => "SIG{$round}" ], $calls, array_keys($calls))) ] ] ] ]);
	    $send(200, [ 'modelVersion' => $mdl, 'usageMetadata' => $usage, 'candidates' => [ [ 'finishReason' => 'STOP', 'content' => [ 'role' => 'model', 'parts' => [ [ 'text' => $final ] ] ] ] ] ]);
	case 'ollama':
	    $counts = [ 'prompt_eval_count' => 13, 'eval_count' => 5 ];
	    if ($calls !== null)
		$send(200, [ 'model' => $mdl, 'done' => true, 'done_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $said,
		    'tool_calls' => array_map(fn($c, $i) => [ 'id' => "call_{$round}_{$i}", 'function' => [ 'name' => (string)$c[0], 'arguments' => $argsOf($c) ] ], $calls, array_keys($calls)) ] ] + $counts);
	    $send(200, [ 'model' => $mdl, 'done' => true, 'done_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $final ] ] + $counts);
    }
}
$model = (string)($body['model'] ?? '');
$answer = $toolResult !== null ? 'The tool said: ' . mb_substr($toolResult, 0, 40) : 'Hello from ' . $model;

//a text's fake embedding: its words (letters and digits, lower case) counted into $dims numbers
$embed = function($text, $dims) {
    $v = array_fill(0, $dims, 0.0);
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower((string)$text), $m);
    foreach($m[0] as $w)
	$v[crc32($w) % $dims] += 1.0;
    if (!array_filter($v))
	$v[0] = 1.0;
    return $v;
};

switch($path) {
    case '/api/v1/embeddings':
	if ($model === 'slow-model')
	    $send(404, [ 'error' => [ 'message' => "model {$model} not found" ] ]);
	$data = [];
	foreach(array_values((array)($body['input'] ?? [])) as $i => $t)
	    $data[] = [ 'object' => 'embedding', 'index' => $i, 'embedding' => $embed($t, $model === 'small-embed' ? 8 : 64) ];
	$send(200, [ 'object' => 'list', 'model' => $model, 'data' => array_reverse($data), 'usage' => [ 'prompt_tokens' => 5, 'total_tokens' => 5 ] ]);

    case '/api/embed':
	if ($model === 'slow-model')
	    $send(404, [ 'error' => "model '{$model}' not found" ]);
	$send(200, [ 'model' => $model, 'embeddings' => array_map(fn($t) => $embed($t, $model === 'small-embed' ? 8 : 64), array_values((array)($body['input'] ?? []))) ]);

    case '/api/v1/models':
	$send(200, [ 'object' => 'list', 'data' => [ [ 'id' => 'Qwen3-8B-GGUF', 'object' => 'model' ], [ 'id' => 'Gemma-3-4b-it-GGUF', 'object' => 'model' ] ] ]);

    case '/api/v1/chat/completions':
	if ($model === 'slow-model')
	    $send(404, [ 'error' => [ 'message' => "model {$model} not found" ] ]);
	$usage = [ 'prompt_tokens' => 11, 'completion_tokens' => 7 ];
	if ($firstTool !== '' && $toolResult === null)
	    $send(200, [ 'model' => $model, 'choices' => [ [ 'index' => 0, 'finish_reason' => 'tool_calls', 'message' => [ 'role' => 'assistant', 'content' => null,
		'tool_calls' => [ [ 'id' => 'call_1', 'type' => 'function', 'function' => [ 'name' => $firstTool, 'arguments' => '{}' ] ] ] ] ] ], 'usage' => $usage ]);
	$send(200, [ 'model' => $model, 'choices' => [ [ 'index' => 0, 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => "<think>reasoning</think>{$answer}" ] ] ],
	    'usage' => $usage ]);

    case '/api/version':
	$send(200, [ 'version' => '0.0-check' ]);

    case '/api/tags':
	$send(200, [ 'models' => [ [ 'name' => 'qwen3:8b', 'model' => 'qwen3:8b' ], [ 'name' => 'gemma3:4b', 'model' => 'gemma3:4b' ] ] ]);

    case '/api/chat':
	if ($model === 'slow-model')
	    $send(404, [ 'error' => "model '{$model}' not found" ]);
	$counts = [ 'prompt_eval_count' => 13, 'eval_count' => 5 ];
	if ($firstTool !== '' && $toolResult === null)
	    $send(200, [ 'model' => $model, 'done' => true, 'done_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => '', 'thinking' => 'I need the tables',
		'tool_calls' => [ [ 'function' => [ 'name' => $firstTool, 'arguments' => (object)[] ] ] ] ] ] + $counts);
	$content = isset($body['format']) ? json_encode([ 'answer' => $answer, 'format' => is_array($body['format']) ? 'schema' : (string)$body['format'] ]) : $answer;
	$send(200, [ 'model' => $model, 'done' => true, 'done_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $content ] ] + $counts);
}
$send(404, $ollama ? [ 'error' => 'not found' ] : [ 'error' => [ 'message' => 'not found' ] ]);
