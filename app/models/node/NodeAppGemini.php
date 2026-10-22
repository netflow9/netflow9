<?php

//CLD: 2026-10-22T12:00:00+03:00: new step: Gemini (Google) - an AI step (NodeAppAi: prompt, tools, agent loop) on the Gemini API (POST <base URL>/models/<model>:generateContent, raw
//HTTP: no SDK), with a Google Gemini credential (models\credential\CredentialGemini). Model default gemini-flash-latest (Google's alias of its latest Flash model); optional
//thinking level (Gemini 3 and later: generationConfig.thinkingConfig.thinkingLevel) and temperature. The model's turns go back unchanged (their thought signatures included:
//Gemini 3 requires them with function calls). Tools are function declarations (parametersJsonSchema); the results go back as functionResponse parts in one user turn, the images
//of tool results after them as inlineData parts. JSON answers: structured output (responseMimeType, responseJsonSchema with a schema) when no tools are offered, else a system
//prompt instruction. A blocked prompt (promptFeedback.blockReason) or answer (finishReason SAFETY, PROHIBITED_CONTENT, ...) is an error

namespace models\node;

class NodeAppGemini extends NodeAppAi {
    public static $name = 'gemini';
    public static $label = 'Gemini (Google)';
    public static $icon = 'bi-google';

    const CAPABILITY = 'gemini';
    const PROVIDER = 'Google Gemini';

    const THINKING = [ '' => 'Model default', 'minimal' => 'Minimal', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High' ];
    //the finish reasons of an answer Gemini declined or blocked
    const BLOCKED = [ 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' ];

    protected static function defaultModel() {
	return 'gemini-flash-latest';
    }

    protected static function providerSchema() {
	return [
	    [
		'key' => 'thinking',
		'type' => 'select',
		'label' => 'Thinking',
		'default' => '',
		'choices' => self::THINKING,
		'help' => 'How much the model thinks (Gemini 3 and later): higher is better for hard tasks, lower is faster and cheaper; Minimal / Medium: Flash models only',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'temperature',
		'type' => 'text',
		'label' => 'Temperature',
		'default' => '',
		'help' => 'Optional, 0 - 2; empty: the model default (recommended for Gemini 3)',
		'validate' => [ [ 'regex', 'pattern' => '^(0(\.\d+)?|1(\.\d+)?|2(\.0+)?)$', 'skipEmpty' => true, 'message' => 'A number from 0 to 2' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'default' ],
	    ],
	];
    }

    //the system prompt apart (systemInstruction); the images before the prompt's text
    protected function startConversation($cred, $system, $prompt, $schema, array $images = []) {
	$parts = array_map([ self::class, 'imagePart' ], $images);
	$parts[] = [ 'text' => $prompt ];
	return [ 'system' => $system, 'contents' => [ [ 'role' => 'user', 'parts' => $parts ] ], 'calls' => [] ];
    }

    //an image as an inlineData part
    protected static function imagePart(array $image) {
	return [ 'inlineData' => [ 'mimeType' => $image[0], 'data' => $image[1] ] ];
    }

    protected function callModel($cred, array &$state, array $tools, $schema) {
	$model = preg_replace('~^models/~', '', trim((string)$this->cfg('model', self::defaultModel())));
	$config = [ 'maxOutputTokens' => max(1, min(self::MAX_TOKENS, (int)$this->cfg('maxTokens', 16000))) ];
	if (trim((string)$this->cfg('temperature', '')) !== '')
	    $config['temperature'] = (float)$this->cfg('temperature');
	if ((string)$this->cfg('thinking', '') !== '')
	    $config['thinkingConfig'] = [ 'thinkingLevel' => (string)$this->cfg('thinking') ];
	$system = $state['system'];
	//structured output: not with function calling (the models before Gemini 3 refuse both), then an instruction
	if ($schema !== null && !$tools) {
	    $config['responseMimeType'] = 'application/json';
	    if (is_object($schema))
		$config['responseJsonSchema'] = $schema;
	} elseif ($schema !== null) {
	    $system = trim($system . "\n\n" . self::jsonInstruction($schema));
	}
	$body = [ 'contents' => $state['contents'], 'generationConfig' => $config ];
	if ($system !== '')
	    $body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
	if ($tools) {
	    $decl = [];
	    foreach($tools as $t)
		$decl[] = [ 'name' => $t['name'], 'description' => mb_substr($t['description'], 0, 1000), 'parametersJsonSchema' => $t['schema'] ];
	    $body['tools'] = [ [ 'functionDeclarations' => $decl ] ];
	}

	//as objects: the turn is sent back exactly as it came (thought signatures, an empty args stays {})
	$r = $this->postJson(rtrim((string)$cred->baseUrl, '/') . '/models/' . rawurlencode($model) . ':generateContent', $cred->httpAuth()['headers'], $body, false);
	if (!empty($r->promptFeedback->blockReason))
	    throw new \ErrorException('Gemini blocked the prompt (' . $r->promptFeedback->blockReason . ')');
	$cand = $r->candidates[0] ?? null;
	if (!is_object($cand))
	    throw new \ErrorException('Gemini answered without a candidate');
	$stop = (string)($cand->finishReason ?? '');
	if (in_array($stop, self::BLOCKED, true))
	    throw new \ErrorException('Gemini declined the answer (' . $stop . ')');
	$parts = is_array($cand->content->parts ?? null) ? $cand->content->parts : [];
	if ($parts)
	    $state['contents'][] = $cand->content;

	$text = [];
	$calls = [];
	$state['calls'] = [];
	foreach($parts as $i => $p) {
	    if (isset($p->functionCall)) {
		$fc = $p->functionCall;
		//the id Gemini gave, else ours; addToolResults needs the name of each
		$id = (string)($fc->id ?? '') !== '' ? (string)$fc->id : 'call_' . $i;
		$state['calls'][$id] = [ (string)($fc->name ?? ''), isset($fc->id) ];
		$calls[] = [ $id, (string)($fc->name ?? ''), !isset($fc->args) ? [] : (is_object($fc->args) ? json_decode(\util\JSON::encode($fc->args), true) ?: [] : null) ];
	    } elseif (isset($p->text) && empty($p->thought)) {
		$text[] = (string)$p->text;
	    }
	}
	if ($stop === 'MAX_TOKENS' && !$calls)
	    throw new \ErrorException('the answer was cut off at Max tokens (' . $config['maxOutputTokens'] . '): raise it');
	if ($stop === 'MALFORMED_FUNCTION_CALL')
	    throw new \ErrorException('Gemini made a malformed tool call');
	$u = $r->usageMetadata ?? null;
	return [ 'text' => implode('', $text), 'calls' => $calls, 'stop_reason' => $stop, 'model' => (string)($r->modelVersion ?? $model),
	    'usage' => [ (int)($u->promptTokenCount ?? 0), (int)($u->candidatesTokenCount ?? 0) + (int)($u->thoughtsTokenCount ?? 0) ], 'continue' => false ];
    }

    //all the results in one user turn (functionResponse parts), the images of the results after them
    protected function addToolResults(array &$state, array $results) {
	$parts = [];
	$images = [];
	foreach($results as $r) {
	    [ $id, $text, $isError ] = $r;
	    [ $name, $hasId ] = $state['calls'][$id] ?? [ '', false ];
	    $response = [ 'name' => $name, 'response' => [ $isError ? 'error' : 'result' => $text === '' ? '(empty)' : $text
		. (!empty($r[3]) ? ' [' . count($r[3]) . ' image(s): after the results]' : '') ] ];
	    if ($hasId)
		$response['id'] = $id;
	    $parts[] = [ 'functionResponse' => $response ];
	    foreach((array)($r[3] ?? []) as $image)
		$images[] = $image;
	}
	if ($images) {
	    $parts[] = [ 'text' => 'The images returned by the tools:' ];
	    foreach($images as $image)
		$parts[] = self::imagePart($image);
	}
	$state['contents'][] = [ 'role' => 'user', 'parts' => $parts ];
    }
}
