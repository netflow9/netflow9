<?php

/*
    CLD: 2026-10-22T01:36:46+03:00: new file: a fake local AI server for the check suite (CheckCommand::suiteLocalAi), run by PHP's built-in server: php -S 127.0.0.1:<port> AiTestServer.php
    It answers as a Lemonade server (OpenAI-compatible: GET /api/v1/models, POST /api/v1/chat/completions) and as an Ollama server (GET /api/version, /api/tags, POST /api/chat):
	- an API key: with the env AI_TEST_KEY set, a request without `Authorization: Bearer <key>` gets 401 (as the servers, with their error bodies)
	- a chat with tools and no tool result yet: the model asks for the first tool (arguments {}); with a tool result: the answer names the tool's result (its first 40 characters);
	  a JSON answer (Ollama `format`) when asked; a model named `slow-model` is refused (404, as an unknown model)
	- every request body is appended to <AI_TEST_LOG> (one JSON line: { path, body }) for the checks to read
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
$ollama = str_starts_with($path, '/api/') && !str_starts_with($path, '/api/v1/');
$key = (string)getenv('AI_TEST_KEY');
if ($key !== '' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== "Bearer {$key}")
    $send(401, $ollama ? [ 'error' => 'unauthorized' ] : [ 'error' => [ 'message' => 'Invalid API key', 'type' => 'invalid_request_error' ] ]);

//the result of the last tool message, and the tool asked for
$toolResult = null;
foreach((array)($body['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'tool')
	$toolResult = (string)($m['content'] ?? '');
}
$firstTool = (string)($body['tools'][0]['function']['name'] ?? '');
$model = (string)($body['model'] ?? '');
$answer = $toolResult !== null ? 'The tool said: ' . mb_substr($toolResult, 0, 40) : 'Hello from ' . $model;

switch($path) {
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
