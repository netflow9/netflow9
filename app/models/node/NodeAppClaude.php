<?php

//CLD: 2026-10-12T22:16:44+03:00: new step: Claude (Anthropic) - an AI step (NodeAppAi: prompt, tools, agent loop) on the Anthropic Messages API (POST <base URL>/v1/messages, raw
//HTTP: the project uses no Composer, so no SDK), with an Anthropic credential (models\credential\CredentialAnthropic). Model default claude-opus-5-5; thinking is left to the
//model (adaptive by default on the current models: no `thinking` parameter), its depth set by Effort (output_config.effort). The assistant turns go back unchanged (thinking
//blocks included). JSON answers use structured outputs when a schema is given (output_config.format json_schema), else a system prompt instruction. A declined request
//(stop_reason refusal) is an error; Fallback on refusal (default on) lets the API retry it on the model Anthropic recommends (fallbacks: "default",
//beta server-side-fallback-2026-07-01; for the models that support it)
//CLD: 2026-10-27T23:11:38+02:00: files: images as image blocks, PDFs as document blocks (PDFs as Auto: documents), text files as text blocks

//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\app)
namespace models\node;

class NodeAppClaude extends NodeAppAi {
    public static $name = 'claude';
    public static $label = 'Claude (Anthropic)';
    public static $icon = 'bi-stars';

    const CAPABILITY = 'anthropic';
    const PROVIDER = 'Anthropic';

    const EFFORT = [ 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high', 'max' => 'Max' ];
    //the models server-side fallback is offered for (beta server-side-fallback-2026-07-01, fallbacks: "default")
    const FALLBACK_MODELS = '/^claude-(opus-5|sonnet-5-5|fable-5)/';

    protected static function defaultModel() {
	return 'claude-opus-5-5';
    }

    protected static function providerSchema() {
	return [
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 2 wide (was 3), not on a new line; shorter help (was: Higher: hard tasks; lower: faster, cheaper)
		'key' => 'effort',
		'type' => 'select',
		'label' => 'Effort',
		'required' => true,
		'default' => 'high',
		'choices' => self::EFFORT,
		//CLD: 2026-10-24T12:42:45+03:00: 3 wide on a new line (was 6); shorter help (was: How thoroughly the model thinks: higher is better for hard tasks, lower is faster and cheaper)
		'help' => 'Higher: harder tasks',
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 2 ],
	    ],
	    [
		//CLD: 2026-10-24T13:05:14+03:00: layout: 2 wide (was 3); no help text (was: On a refusal, retry on the model Anthropic recommends)
		'key' => 'fallback',
		'type' => 'switch',
		'label' => 'Fallback on refusal',
		'default' => true,
		//CLD: 2026-10-24T12:42:45+03:00: 3 wide (was 6); shorter help (was: When the model declines, the API retries on the model Anthropic recommends (Claude Opus 5 / 5.5, Sonnet 5.5, Fable 5))
		'help' => false,
		'ui' => [ 'width' => 2 ],
	    ],
	];
    }

    //CLD: 2026-10-17T21:00:54+03:00: + $images: image blocks before the prompt's text
    //CLD: 2026-10-27T23:11:38+02:00: $files (was $images): their blocks before the prompt's text (fileBlock)
    protected function startConversation($cred, $system, $prompt, $schema, array $files = []) {
	if ($schema === true)
	    $system = trim($system . "\n\n" . self::jsonInstruction(true));
	$content = $prompt;
	if ($files) {
	    $content = array_map([ self::class, 'fileBlock' ], $files);
	    $content[] = [ 'type' => 'text', 'text' => $prompt ];
	}
	return [ 'system' => $system, 'messages' => [ [ 'role' => 'user', 'content' => $content ] ] ];
    }

    //CLD: 2026-10-27T23:11:38+02:00: new: Claude reads PDFs (document blocks)
    protected function pdfAsDocuments($cred) {
	return true;
    }

    //CLD: 2026-10-27T23:11:38+02:00: new: a file part as a content block: an image, a PDF (a document block, its name as the title), text
    protected static function fileBlock(array $part) {
	return match($part['type']) {
	    'image' => self::imageBlock([ $part['mime'], $part['data'] ]),
	    'pdf' => [ 'type' => 'document', 'source' => [ 'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $part['data'] ], 'title' => mb_substr((string)$part['name'], 0, 500) ],
	    default => [ 'type' => 'text', 'text' => self::fileAsText($part) ],
	};
    }

    //CLD: 2026-10-17T21:00:54+03:00: an image as a content block
    protected static function imageBlock(array $image) {
	return [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $image[0], 'data' => $image[1] ] ];
    }

    protected function callModel($cred, array &$state, array $tools, $schema) {
	$model = trim((string)$this->cfg('model', self::defaultModel()));
	$body = [
	    'model' => $model,
	    'max_tokens' => max(1, min(self::MAX_TOKENS, (int)$this->cfg('maxTokens', 16000))),
	    'messages' => $state['messages'],
	    'output_config' => [ 'effort' => (string)$this->cfg('effort', 'high') ],
	];
	if ($state['system'] !== '')
	    $body['system'] = $state['system'];
	if (is_object($schema))
	    $body['output_config']['format'] = [ 'type' => 'json_schema', 'schema' => $schema ];
	if ($tools) {
	    $body['tools'] = [];
	    foreach($tools as $t)
		$body['tools'][] = [ 'name' => $t['name'], 'description' => mb_substr($t['description'], 0, 1000), 'input_schema' => $t['schema'] ];
	}
	$headers = $cred->httpAuth()['headers'];
	if ($this->cfg('fallback', true) && preg_match(self::FALLBACK_MODELS, $model)) {
	    $body['fallbacks'] = 'default';
	    $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
	}

	//as objects: the turn is sent back exactly as it came (an empty tool input stays {})
	$r = $this->postJson(rtrim((string)$cred->baseUrl, '/') . '/v1/messages', $headers, $body, false);
	$stop = (string)($r->stop_reason ?? '');
	if ($stop === 'refusal') {
	    $d = $r->stop_details ?? null;
	    throw new \ErrorException('the model declined the request' . (!empty($d->category) ? " ({$d->category})" : '') . (!empty($d->explanation) ? ': ' . $d->explanation : ''));
	}
	$content = is_array($r->content ?? null) ? $r->content : [];
	//the whole turn goes back as it came (thinking blocks included)
	$state['messages'][] = [ 'role' => 'assistant', 'content' => $content ];

	$text = [];
	$calls = [];
	foreach($content as $block) {
	    if (($block->type ?? '') === 'text')
		$text[] = (string)$block->text;
	    elseif (($block->type ?? '') === 'tool_use')
		//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
		$calls[] = [ (string)$block->id, (string)$block->name, is_object($block->input ?? null) ? json_decode(\util\JSON::encode($block->input), true) ?: [] : null ];
	}
	if ($stop === 'max_tokens')
	    throw new \ErrorException('the answer was cut off at Max tokens (' . $body['max_tokens'] . '): raise it');
	return [ 'text' => implode("\n", $text), 'calls' => $calls, 'stop_reason' => $stop, 'model' => (string)($r->model ?? $model),
	    'usage' => [ (int)($r->usage->input_tokens ?? 0), (int)($r->usage->output_tokens ?? 0) ], 'continue' => $stop === 'pause_turn' ];
    }

    //all the results in one user turn
    //CLD: 2026-10-17T21:00:54+03:00: a result with images: its text and image blocks
    protected function addToolResults(array &$state, array $results) {
	$content = [];
	foreach($results as $r) {
	    [ $id, $text, $isError ] = $r;
	    $images = (array)($r[3] ?? []);
	    $body = $text === '' ? '(empty)' : $text;
	    if ($images) {
		$body = $text === '' ? [] : [ [ 'type' => 'text', 'text' => $text ] ];
		foreach($images as $image)
		    $body[] = self::imageBlock($image);
	    }
	    $content[] = [ 'type' => 'tool_result', 'tool_use_id' => $id, 'content' => $body, 'is_error' => (bool)$isError ];
	}
	$state['messages'][] = [ 'role' => 'user', 'content' => $content ];
    }
}
