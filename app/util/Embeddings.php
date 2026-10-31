<?php

/*
    CLD: 2026-10-30T23:30:00+02:00: new: embeddings (text -> vector) for the knowledge bases (models\KnowledgeBase)
    CLD: 2026-10-31T01:00:00+02:00: through the account's embedding settings (models\EmbeddingSettings: provider, server, key, model; was an AI credential of the project):
	ollama        POST <URL>/api/embed { model, input: [ ... ], truncate } -> embeddings; models: GET <URL>/api/tags
	lemonade,     POST <URL>/embeddings { model, input: [ ... ] } -> data[].embedding (by index); models: GET <URL>/models
	openai
	gemini        POST <URL>/models/<model>:batchEmbedContents { requests: [ { model, content, taskType } ] } -> embeddings[].values; models: GET <URL>/models (embedContent)
    The texts go in batches of BATCH. A document and a query are embedded differently where the model asks for it: Gemini's taskType (RETRIEVAL_DOCUMENT / RETRIEVAL_QUERY), the
    prefixes of the models trained with them (PREFIXES: nomic-embed, e5).
    Embeddings::embed($settings, $texts, $kind, $timeout)   [ [ float, ... ], ... ] in the order of $texts; \ErrorException when it fails
    Embeddings::models($settings)                            the server's models, the embedding ones first: [ [ id, label ] ]; \ErrorException when it cannot be reached
*/

namespace util;

class Embeddings {
    //texts per request
    const BATCH = 32;
    //the largest answer read (bytes): 32 vectors of 3072 floats as JSON are ~2 MB
    const MAX_RESPONSE = 16 * 1024 * 1024;
    //models trained with a prefix on each text: [ pattern => [ document prefix, query prefix ] ]
    const PREFIXES = [
	'/nomic-embed/i' => [ 'search_document: ', 'search_query: ' ],
	'/(^|[\/:_-])(multilingual-)?e5([:_-]|$)/i' => [ 'passage: ', 'query: ' ],
    ];
    //the names of embedding models (text-embedding-3-small, nomic-embed-text, bge-m3, multilingual-e5-large, all-minilm, ...): listed first by models()
    const EMBED_NAMES = '/(embed|(^|[\/:_-])(bge|gte|e5|multilingual-e5|minilm|all-minilm|mxbai)([:_-]|$))/i';

    //$kind: document | query
    public static function embed($settings, array $texts, $kind = 'document', $timeout = 120) {
	if (!$settings || !$settings->ready())
	    throw new \ErrorException('no embedding model is set for the account (Settings: Knowledge bases)');
	$model = trim((string)$settings->model);
	$texts = array_values(array_map('strval', $texts));
	foreach(self::PREFIXES as $pattern => [ $doc, $query ]) {
	    if (preg_match($pattern, $model)) {
		$prefix = $kind === 'query' ? $query : $doc;
		$texts = array_map(fn($t) => $prefix . $t, $texts);
		break;
	    }
	}
	$vectors = [];
	foreach(array_chunk($texts, self::BATCH) as $batch) {
	    $got = match((string)$settings->provider) {
		'ollama' => self::ollama($settings, $model, $batch, $timeout),
		'gemini' => self::gemini($settings, $model, $batch, $kind, $timeout),
		default => self::openai($settings, $model, $batch, $timeout),
	    };
	    if (count($got) !== count($batch))
		throw new \ErrorException('the server answered ' . count($got) . ' embedding(s) for ' . count($batch) . ' text(s)');
	    foreach($got as $v) {
		if (!is_array($v) || !$v || !array_is_list($v))
		    throw new \ErrorException('the server answered an embedding that is not a list of numbers');
		$vectors[] = array_map('floatval', $v);
	    }
	}
	return $vectors;
    }

    //the models the server lists: [ [ id, label ] ], the embedding ones first (by name; Gemini: embedContent)
    public static function models($settings) {
	$provider = (string)$settings->provider;
	$base = $settings->baseUrl();
	$headers = self::headers($settings);
	$ids = [];
	$embed = [];
	switch($provider) {
	    case 'ollama':
		$data = self::request('GET', self::ollamaBase($base) . '/api/tags', $headers, null, 15, 'Ollama');
		foreach((array)($data['models'] ?? []) as $m)
		    $ids[] = (string)($m['name'] ?? $m['model'] ?? '');
		break;
	    case 'gemini':
		$data = self::request('GET', $base . '/models?pageSize=1000', $headers, null, 15, 'Google Gemini');
		foreach((array)($data['models'] ?? []) as $m) {
		    $id = preg_replace('~^models/~', '', (string)($m['name'] ?? ''));
		    $ids[] = $id;
		    if (in_array('embedContent', (array)($m['supportedGenerationMethods'] ?? []), true))
			$embed[] = $id;
		}
		break;
	    default:
		$data = self::request('GET', $base . '/models', $headers, null, 15, self::name($provider));
		foreach((array)($data['data'] ?? []) as $m)
		    $ids[] = (string)($m['id'] ?? '');
	}
	$ids = array_values(array_unique(array_filter($ids)));
	natcasesort($ids);
	$isEmbed = fn($id) => $provider === 'gemini' ? in_array($id, $embed, true) : (bool)preg_match(self::EMBED_NAMES, $id);
	$first = array_filter($ids, $isEmbed);
	$rest = array_diff($ids, $first);
	return array_merge(array_map(fn($id) => [ 'id' => $id, 'label' => $id ], array_values($first)),
	    array_map(fn($id) => [ 'id' => $id, 'label' => "{$id} (not named as an embedding model)" ], array_values($rest)));
    }

    private static function ollama($settings, $model, array $texts, $timeout) {
	$data = self::request('POST', self::ollamaBase($settings->baseUrl()) . '/api/embed', self::headers($settings), [ 'model' => $model, 'input' => $texts, 'truncate' => true ], $timeout, 'Ollama');
	return (array)($data['embeddings'] ?? []);
    }

    private static function openai($settings, $model, array $texts, $timeout) {
	$data = self::request('POST', $settings->baseUrl() . '/embeddings', self::headers($settings), [ 'model' => $model, 'input' => $texts ], $timeout, self::name((string)$settings->provider));
	$items = (array)($data['data'] ?? []);
	usort($items, fn($a, $b) => (int)($a['index'] ?? 0) <=> (int)($b['index'] ?? 0));
	return array_map(fn($i) => $i['embedding'] ?? null, $items);
    }

    private static function gemini($settings, $model, array $texts, $kind, $timeout) {
	$name = 'models/' . preg_replace('~^models/~', '', $model);
	$requests = array_map(fn($t) => [ 'model' => $name, 'content' => [ 'parts' => [ [ 'text' => $t ] ] ], 'taskType' => $kind === 'query' ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT' ], $texts);
	$data = self::request('POST', $settings->baseUrl() . '/' . $name . ':batchEmbedContents', self::headers($settings), [ 'requests' => $requests ], $timeout, 'Google Gemini');
	return array_map(fn($e) => $e['values'] ?? null, (array)($data['embeddings'] ?? []));
    }

    //Ollama's address without /api or /v1 (its own API is under /api)
    private static function ollamaBase($url) {
	return preg_replace('~/(api|v1)/?$~', '', rtrim((string)$url, '/'));
    }

    private static function headers($settings) {
	$key = (string)$settings->api_key;
	if ($key === '')
	    return [];
	return (string)$settings->provider === 'gemini' ? [ 'x-goog-api-key' => $key ] : [ 'Authorization' => 'Bearer ' . $key ];
    }

    private static function name($provider) {
	return [ 'ollama' => 'Ollama', 'lemonade' => 'Lemonade', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini' ][$provider] ?? (string)$provider;
    }

    //$method $url ($body as JSON): the decoded answer; \ErrorException when there is no answer, an HTTP error (with the server's message) or no JSON
    private static function request($method, $url, array $headers, ?array $body, $timeout, $provider) {
	$o = [ 'headers' => $headers, 'timeout' => max(5, (int)$timeout), 'maxBytes' => self::MAX_RESPONSE ];
	if ($body !== null)
	    $o['json'] = $body;
	$r = \util\Http::json($method, $url, $o);
	if ($r['tooLarge'])
	    throw new \ErrorException('the answer is larger than ' . (self::MAX_RESPONSE / 1048576) . ' MB');
	if ($r['error'] !== null)
	    throw new \ErrorException('no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	$data = $r['data'];
	if ($r['status'] >= 400) {
	    $m = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null)) : null;
	    throw new \ErrorException("{$provider} answered HTTP status {$r['status']}" . (is_string($m) && $m !== '' ? ': ' . mb_substr($m, 0, 500) : ''));
	}
	if (!is_array($data))
	    throw new \ErrorException("{$provider} did not answer JSON (is the URL right?)");
	return $data;
    }
}
