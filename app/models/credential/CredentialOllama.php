<?php

/*
    an Ollama server (local models; its own API: POST <URL>/api/chat, GET <URL>/api/tags),
    for the Ollama step: its address (default http://localhost:11434) and an optional API key, sent as `Authorization: Bearer` (ollama.com's API, or a server behind a proxy that asks for one).
    Provides `ollama` (the Ollama step) and `http` (the HTTP Request step).
    Test: the server's version and models (GET /api/version, /api/tags)
*/

namespace models\credential;

class CredentialOllama extends \models\Credential {
    public static $label = 'Ollama server';
    public static $icon = 'bi-robot';
    public static $group = 'AI';
    public static $provides = [ 'ollama', 'http' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'Server URL',
		'required' => true,
		'default' => 'http://localhost:11434',
		'help' => 'The Ollama server, without /api (requests go to <URL>/api/chat, <URL>/api/tags); e.g. http://localhost:11434, https://ollama.com',
		'validate' => [
		    [ 'url', 'skipEmpty' => true, 'message' => 'An http:// or https:// URL, without ? or #' ]
		],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'encrypt' => true,
		'help' => 'Only when the server asks for one (ollama.com, a proxy); empty for a local server',
	    ],
	]);
    }

    //the API's base: the server URL without a trailing / or /api
    public function apiBase() {
	return preg_replace('~/(api/?)?$~', '', rtrim((string)$this->baseUrl, '/'));
    }

    public function httpAuth() {
	return [ 'headers' => (string)$this->apiKey !== '' ? [ 'Authorization' => 'Bearer ' . $this->apiKey ] : [], 'query' => [] ];
    }

    //the server's version and the models it has
    public function test() {
	$headers = $this->httpAuth()['headers'];
	$version = '';
	try {
	    $version = (string)(static::getJson($this->apiBase() . '/api/version', $headers)['version'] ?? '');
	} catch (\Exception $e) {
	    //ollama.com has no /api/version: the models answer
	}
	$data = static::getJson($this->apiBase() . '/api/tags', $headers);
	$names = array_values(array_filter(array_map(fn($m) => is_array($m) ? (string)($m['name'] ?? $m['model'] ?? '') : '', (array)($data['models'] ?? []))));
	//CLD: 2026-10-22T01:58:24+03:00: recorded: the choices of the AI steps' Model
	$this->recordModels($names);
	$shown = array_slice($names, 0, 10);
	//CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was (s))
	return ($version !== '' ? "Ollama {$version}: " : '') . \util\Format::plural(count($names), 'model') . ($shown ? ': ' . implode(', ', $shown) . (count($names) > count($shown) ? ', ...' : '') : '');
    }
}
