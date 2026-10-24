<?php

/*
OpenAI - an AI step (NodeAppAi: prompt, tools, agent loop) on the OpenAI API or a compatible server (POST <base URL>/chat/completions)
    //CLD: 2026-10-18T12:30:00+03:00: was NodeAppLemonade (step app::lemonade, now app::openai); with a CredentialOpenAI (was CredentialLemonade)
    with an OpenAI API credential (models\credential\CredentialOpenAI):
    the model is one the server has (credential Test lists them); optional temperature.
    Tools are offered as functions (tool_calls, results as `tool` messages).
    JSON answers: a system prompt instruction (with the schema when given).
    Reasoning written by local models between <think> tags is left out of the answer
    //CLD: 2026-10-17T21:00:54+03:00: images (prompt, tool results) as image_url parts with data: URLs (the model must read images, e.g. a vision model); `tool` messages are text only, so
    the images of tool results follow them in a user message
*/

namespace models\node;

//CLD: 2026-10-18T12:30:00+03:00: was NodeAppLemonade / lemonade / 'Lemonade (OpenAI API)' / bi-cpu
class NodeAppOpenAI extends NodeAppAi {
    public static $name = 'openai';
    public static $label = 'OpenAI';
    public static $icon = 'bi-openai';

    const CAPABILITY = 'openai';
    const PROVIDER = 'OpenAI'; //CLD: 2026-10-18T12:30:00+03:00: was Lemonade

    protected static function providerSchema() {
	return [
	    [
		'key' => 'temperature',
		'type' => 'text',
		'label' => 'Temperature',
		'default' => '',
		//CLD: 2026-10-24T12:42:45+03:00: 3 wide on a new line (was 6); shorter help (was: Optional, 0 - 2: lower is more predictable; empty: the server default)
		'help' => '0 - 2; empty: the default',
		'validate' => [ [ 'regex', 'pattern' => '^(0(\.\d+)?|1(\.\d+)?|2(\.0+)?)$', 'skipEmpty' => true, 'message' => 'A number from 0 to 2' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => 'default' ],
	    ],
	];
    }

    //CLD: 2026-10-22T01:58:24+03:00: configSchema() removed: the Model field (a select of the credential's recorded models) is NodeAppAi's

    //CLD: 2026-10-17T21:00:54+03:00: + $images: image parts before the prompt's text
    protected function startConversation($cred, $system, $prompt, $schema, array $images = []) {
	if ($schema !== null)
	    $system = trim($system . "\n\n" . self::jsonInstruction($schema));
	$messages = [];
	if ($system !== '')
	    $messages[] = [ 'role' => 'system', 'content' => $system ];
	$content = $prompt;
	if ($images) {
	    $content = array_map([ self::class, 'imagePart' ], $images);
	    $content[] = [ 'type' => 'text', 'text' => $prompt ];
	}
	$messages[] = [ 'role' => 'user', 'content' => $content ];
	return [ 'messages' => $messages ];
    }

    //CLD: 2026-10-17T21:00:54+03:00: an image as a content part (data: URL)
    protected static function imagePart(array $image) {
	return [ 'type' => 'image_url', 'image_url' => [ 'url' => 'data:' . $image[0] . ';base64,' . $image[1] ] ];
    }

    protected function callModel($cred, array &$state, array $tools, $schema) {
	$model = trim((string)$this->cfg('model', ''));
	$body = [
	    'model' => $model,
	    'messages' => $state['messages'],
	    'max_tokens' => max(1, min(self::MAX_TOKENS, (int)$this->cfg('maxTokens', 16000))),
	];
	if (trim((string)$this->cfg('temperature', '')) !== '')
	    $body['temperature'] = (float)$this->cfg('temperature');
	if ($tools) {
	    $body['tools'] = [];
	    foreach($tools as $t)
		$body['tools'][] = [ 'type' => 'function', 'function' => [ 'name' => $t['name'], 'description' => mb_substr($t['description'], 0, 1000), 'parameters' => $t['schema'] ] ];
	}

	$r = $this->postJson(rtrim((string)$cred->baseUrl, '/') . '/chat/completions', $cred->httpAuth()['headers'], $body);
	$choice = is_array($r['choices'][0] ?? null) ? $r['choices'][0] : null;
	if (!$choice || !is_array($choice['message'] ?? null))
	    throw new \ErrorException('the server answered without a message');
	$msg = $choice['message'];
	$stop = (string)($choice['finish_reason'] ?? '');

	//the assistant turn back into the conversation (its tool calls with it)
	$turn = [ 'role' => 'assistant', 'content' => isset($msg['content']) ? (string)$msg['content'] : null ];
	$calls = [];
	if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
	    $turn['tool_calls'] = $msg['tool_calls'];
	    foreach($msg['tool_calls'] as $i => $tc) {
		$args = $tc['function']['arguments'] ?? '';
		$args = is_array($args) ? $args : (trim((string)$args) === '' ? [] : json_decode((string)$args, true));
		$calls[] = [ (string)($tc['id'] ?? 'call_' . $i), (string)($tc['function']['name'] ?? ''), is_array($args) ? $args : null ];
	    }
	}
	$state['messages'][] = $turn;

	if ($stop === 'length' && !$calls)
	    throw new \ErrorException('the answer was cut off at Max tokens (' . $body['max_tokens'] . '): raise it');
	$text = trim(preg_replace('~<think>.*?</think>~s', '', (string)($msg['content'] ?? '')));
	return [ 'text' => $text, 'calls' => $calls, 'stop_reason' => $stop, 'model' => (string)($r['model'] ?? $model),
	    'usage' => [ (int)($r['usage']['prompt_tokens'] ?? 0), (int)($r['usage']['completion_tokens'] ?? 0) ], 'continue' => false ];
    }

    //one `tool` message per call
    //CLD: 2026-10-17T21:00:54+03:00: the images of the results: in one user message after them
    protected function addToolResults(array &$state, array $results) {
	$images = [];
	foreach($results as $r) {
	    [ $id, $text, $isError ] = $r;
	    $state['messages'][] = [ 'role' => 'tool', 'tool_call_id' => $id, 'content' => ($isError ? 'ERROR: ' : '') . ($text === '' ? '(empty)' : $text)
		. (!empty($r[3]) ? ' [' . count($r[3]) . ' image(s): in the next message]' : '') ];
	    foreach((array)($r[3] ?? []) as $image)
		$images[] = $image;
	}
	if ($images) {
	    $content = [ [ 'type' => 'text', 'text' => 'The images returned by the tools:' ] ];
	    foreach($images as $image)
		$content[] = self::imagePart($image);
	    $state['messages'][] = [ 'role' => 'user', 'content' => $content ];
	}
    }
}
