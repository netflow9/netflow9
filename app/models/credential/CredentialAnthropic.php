<?php

/*
    the Anthropic API (Claude models): an API key (console.anthropic.com), sent as `x-api-key` with the
    `anthropic-version` header the API requires;
    the base URL can be changed (e.g. a proxy).
    Provides `anthropic` (steps calling Claude: baseUrl + httpAuth) and `http` (the HTTP Request step).

    Test: GET <base URL>/v1/models
*/

namespace models\credential;

class CredentialAnthropic extends \models\Credential {
    public static $label = 'Anthropic (Claude)';
    public static $icon = 'bi-stars';
    public static $group = 'AI';
    public static $provides = [ 'anthropic', 'http' ];
    public static $testable = true;

    //the API version sent with every request
    const API_VERSION = '2023-06-01';

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'From console.anthropic.com (Settings, API keys); starts with sk-ant-',
	    ],
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'API base URL',
		'required' => true,
		'default' => 'https://api.anthropic.com',
		'help' => 'Change it only for a proxy or gateway (requests go to <URL>/v1/messages, ...)',
		'validate' => [
		    [ 'url', 'skipEmpty' => true, 'message' => 'An http:// or https:// URL, without ? or #' ]
		],
	    ],
	]);
    }

    public function httpAuth() {
	return [ 'headers' => [ 'x-api-key' => (string)$this->apiKey, 'anthropic-version' => self::API_VERSION ], 'query' => [] ];
    }

    //the models the key can use
    public function test() {
	$data = static::getJson(rtrim((string)$this->baseUrl, '/') . '/v1/models?limit=100', $this->httpAuth()['headers']);
	$ids = array_values(array_filter(array_map(fn($m) => is_array($m) ? (string)($m['id'] ?? '') : '', (array)($data['data'] ?? []))));
	$this->recordModels($ids);
	$shown = array_slice($ids, 0, 10);
	return \util\Format::plural(count($ids), 'model') . ($shown ? ': ' . implode(', ', $shown) . (count($ids) > count($shown) ? ', ...' : '') : '');
    }
}
