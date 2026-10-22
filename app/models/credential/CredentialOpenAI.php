<?php

//CLD: 2026-10-18T12:00:00+03:00: was CredentialLemonade; now any OpenAI-compatible API
//CLD: 2026-10-22T01:53:07+03:00: Lemonade no longer named here or in the form (a Lemonade server has its own credential and step: CredentialLemonade, NodeAppLemonade)
//an OpenAI-compatible API: the API's base URL (default https://api.openai.com/v1)
//and an API key (optional: servers that do not ask for one), sent as `Authorization: Bearer`.
//Provides `openai` (steps calling an OpenAI-compatible API: baseUrl + httpAuth) and `http` (the HTTP Request step). Test: GET <base URL>/models

namespace models\credential;

class CredentialOpenAI extends \models\Credential {
    public static $label = 'OpenAI API (or compatible server)';
    public static $icon = 'bi-openai';
    public static $group = 'AI';
    public static $provides = [ 'openai', 'http' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'API base URL',
		'required' => true,
		'default' => 'https://api.openai.com/v1',
		//CLD: 2026-10-22T01:53:07+03:00: without the Lemonade example (it has its own credential)
		'help' => 'The OpenAI API (https://api.openai.com/v1) or another OpenAI-compatible API, up to the version (requests go to <URL>/chat/completions, <URL>/models, ...)',
		'validate' => [
		    [ 'url', 'skipEmpty' => true, 'message' => 'An http:// or https:// URL, without ? or #' ]
		],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'encrypt' => true,
		//CLD: 2026-10-22T01:53:07+03:00: without the LEMONADE_API_KEY example
		'help' => 'Required by OpenAI; for another server only when it asks for one',
	    ],
	]);
    }

    public function httpAuth() {
	return [ 'headers' => (string)$this->apiKey !== '' ? [ 'Authorization' => 'Bearer ' . $this->apiKey ] : [], 'query' => [] ];
    }

    //the models the server offers
    public function test() {
	$data = static::getJson(rtrim((string)$this->baseUrl, '/') . '/models', $this->httpAuth()['headers']);
	$ids = array_values(array_filter(array_map(fn($m) => is_array($m) ? (string)($m['id'] ?? '') : '', (array)($data['data'] ?? []))));
	//CLD: 2026-10-22T01:58:24+03:00: recorded: the choices of the AI steps' Model
	$this->recordModels($ids);
	$shown = array_slice($ids, 0, 10);
	return count($ids) . ' model(s)' . ($shown ? ': ' . implode(', ', $shown) . (count($ids) > count($shown) ? ', ...' : '') : '');
    }
}
