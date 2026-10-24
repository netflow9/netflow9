<?php

/*
    CLD: 2026-10-22T01:36:46+03:00: new step: Ollama - an AI step (NodeAppAi: prompt, tools, agent loop) on an Ollama server, through Ollama's own API (POST <URL>/api/chat,
    stream false), with an Ollama server credential (models\credential\CredentialOllama, provides `ollama`). The model is one the server has (Test on the credential lists
    them, e.g. qwen3:8b).
	- tools: offered as functions; the model's tool_calls (arguments as an object) are answered by `tool` messages named after the tool (tool_name)
	- images: base64 in the message's `images` (a vision model reads them); the images of tool results in a user message after them
	- JSON answers: Ollama's structured outputs (format: the schema, or "json"), with the system prompt instruction too
	- settings of the step: temperature, context length (options.num_ctx), thinking (think: the server's default, on, off; the reasoning is not part of the answer), how long
	  the model stays loaded (keep_alive); Max tokens is options.num_predict
*/

namespace models\node;

class NodeAppOllama extends NodeAppAi {
    public static $name = 'ollama';
    public static $label = 'Ollama';
    public static $icon = 'bi-robot';

    const CAPABILITY = 'ollama';
    const PROVIDER = 'Ollama';

    const THINK = [ '' => 'The model\'s default', 'on' => 'On', 'off' => 'Off' ];
    //the largest context length asked for (tokens)
    const MAX_CONTEXT = 1048576;

    protected static function providerSchema() {
	return [
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 2 wide (was 3), not on a new line; shorter help (was: 0 - 2; empty: the model's default)
		'key' => 'temperature',
		'type' => 'text',
		'label' => 'Temperature',
		'default' => '',
		//CLD: 2026-10-24T12:42:45+03:00: the four settings 3 wide on one line (were 6); shorter helps
		'help' => '0 - 2; empty: default',
		'validate' => [ [ 'regex', 'pattern' => '^(0(\.\d+)?|1(\.\d+)?|2(\.0+)?)$', 'skipEmpty' => true, 'message' => 'A number from 0 to 2' ] ],
		'ui' => [ 'width' => 2, 'placeholder' => 'default' ],
	    ],
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 2 wide (was 3); shorter help (was: Thinking models (qwen3, deepseek-r1))
		'key' => 'think',
		'type' => 'select',
		'label' => 'Thinking',
		'default' => '',
		'choices' => self::THINK,
		'help' => 'qwen3, deepseek-r1',
		'ui' => [ 'width' => 2 ],
	    ],
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 3 wide, on a new line
		'key' => 'contextLength',
		'type' => 'text',
		'label' => 'Context length (tokens)',
		'default' => '',
		'help' => 'num_ctx; empty: the server\'s default',
		'validate' => [ [ 'regex', 'pattern' => '^[1-9][0-9]{2,6}$', 'skipEmpty' => true, 'message' => 'A number of tokens, e.g. 8192' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => 'default' ],
	    ],
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 3 wide
		'key' => 'keepAlive',
		'type' => 'text',
		'label' => 'Keep the model loaded',
		'default' => '',
		'help' => 'e.g. 10m, 1h, 0; empty: 5 minutes',
		'validate' => [ [ 'regex', 'pattern' => '^(0|-1|[0-9]+(\.[0-9]+)?(ms|s|m|h))$', 'skipEmpty' => true, 'message' => 'A duration, e.g. 10m, 1h, or 0' ] ],
		'ui' => [ 'width' => 3, 'placeholder' => '5m' ],
	    ],
	];
    }

    //CLD: 2026-10-22T01:58:24+03:00: configSchema() removed: the Model field (a select of the credential's recorded models) is NodeAppAi's

    protected function startConversation($cred, $system, $prompt, $schema, array $images = []) {
	if ($schema !== null)
	    $system = trim($system . "\n\n" . self::jsonInstruction($schema));
	$messages = [];
	if ($system !== '')
	    $messages[] = [ 'role' => 'system', 'content' => $system ];
	$user = [ 'role' => 'user', 'content' => $prompt ];
	if ($images)
	    $user['images'] = array_map(fn($i) => $i[1], $images);
	$messages[] = $user;
	//the names of the tools asked for, by call id (Ollama's tool messages name the tool)
	return [ 'messages' => $messages, 'names' => [] ];
    }

    protected function callModel($cred, array &$state, array $tools, $schema) {
	$model = trim((string)$this->cfg('model', ''));
	$body = [
	    'model' => $model,
	    'messages' => $state['messages'],
	    'stream' => false,
	    'options' => [ 'num_predict' => max(1, min(self::MAX_TOKENS, (int)$this->cfg('maxTokens', 16000))) ],
	];
	if (trim((string)$this->cfg('temperature', '')) !== '')
	    $body['options']['temperature'] = (float)$this->cfg('temperature');
	if (trim((string)$this->cfg('contextLength', '')) !== '')
	    $body['options']['num_ctx'] = max(256, min(self::MAX_CONTEXT, (int)$this->cfg('contextLength')));
	$think = (string)$this->cfg('think', '');
	if ($think !== '')
	    $body['think'] = $think === 'on';
	if (($keep = trim((string)$this->cfg('keepAlive', ''))) !== '')
	    $body['keep_alive'] = preg_match('/^-?\d+$/', $keep) ? (int)$keep : $keep;
	if ($schema !== null)
	    $body['format'] = is_object($schema) ? $schema : 'json';
	if ($tools) {
	    $body['tools'] = [];
	    foreach($tools as $t)
		$body['tools'][] = [ 'type' => 'function', 'function' => [ 'name' => $t['name'], 'description' => mb_substr($t['description'], 0, 1000), 'parameters' => $t['schema'] ] ];
	}

	$r = $this->postJson($cred->apiBase() . '/api/chat', $cred->httpAuth()['headers'], $body);
	$msg = is_array($r['message'] ?? null) ? $r['message'] : null;
	if (!$msg)
	    throw new \ErrorException('the server answered without a message');
	$stop = (string)($r['done_reason'] ?? '');

	//the assistant turn back into the conversation (its tool calls with it; not the thinking)
	$turn = [ 'role' => 'assistant', 'content' => (string)($msg['content'] ?? '') ];
	$calls = [];
	if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
	    $turn['tool_calls'] = $msg['tool_calls'];
	    foreach($msg['tool_calls'] as $i => $tc) {
		$name = (string)($tc['function']['name'] ?? '');
		$args = $tc['function']['arguments'] ?? [];
		$args = is_array($args) ? $args : (trim((string)$args) === '' ? [] : json_decode((string)$args, true));
		$id = (string)($tc['id'] ?? ('call_' . count($state['messages']) . '_' . $i));
		$state['names'][$id] = $name;
		$calls[] = [ $id, $name, is_array($args) ? $args : null ];
	    }
	}
	$state['messages'][] = $turn;

	if ($stop === 'length' && !$calls)
	    throw new \ErrorException('the answer was cut off at Max tokens (' . $body['options']['num_predict'] . '): raise it');
	$text = trim(preg_replace('~<think>.*?</think>~s', '', (string)($msg['content'] ?? '')));
	return [ 'text' => $text, 'calls' => $calls, 'stop_reason' => $stop, 'model' => (string)($r['model'] ?? $model),
	    'usage' => [ (int)($r['prompt_eval_count'] ?? 0), (int)($r['eval_count'] ?? 0) ], 'continue' => false ];
    }

    //one `tool` message per call, named after the tool; the images of the results in one user message after them
    protected function addToolResults(array &$state, array $results) {
	$images = [];
	foreach($results as $r) {
	    [ $id, $text, $isError ] = $r;
	    $state['messages'][] = [ 'role' => 'tool', 'tool_name' => (string)($state['names'][$id] ?? ''), 'content' => ($isError ? 'ERROR: ' : '') . ($text === '' ? '(empty)' : $text)
		. (!empty($r[3]) ? ' [' . count($r[3]) . ' image(s): in the next message]' : '') ];
	    foreach((array)($r[3] ?? []) as $image)
		$images[] = $image[1];
	}
	if ($images)
	    $state['messages'][] = [ 'role' => 'user', 'content' => 'The images returned by the tools:', 'images' => $images ];
    }
}
