<?php

//CLD: 2026-10-22T12:00:00+03:00: new credential variant: the Google Gemini API (Google AI Studio): an API key (aistudio.google.com), sent as `x-goog-api-key`;
//the base URL, up to the version (default https://generativelanguage.googleapis.com/v1beta), can be changed (e.g. a proxy).
//Provides `gemini` (the Gemini step: baseUrl + httpAuth) and `http` (the HTTP Request step). Test: GET <base URL>/models (those that generate content)

namespace models\credential;

class CredentialGemini extends \models\Credential {
    public static $label = 'Google Gemini';
    public static $icon = 'bi-google';
    public static $group = 'AI';
    public static $provides = [ 'gemini', 'http' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'From aistudio.google.com (Get API key)',
	    ],
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'API base URL',
		'required' => true,
		'default' => 'https://generativelanguage.googleapis.com/v1beta',
		'help' => 'Change it only for a proxy or gateway, up to the version (requests go to <URL>/models/<model>:generateContent, <URL>/models, ...)',
		'validate' => [
		    [ 'url', 'skipEmpty' => true, 'message' => 'An http:// or https:// URL, without ? or #' ]
		],
	    ],
	]);
    }

    public function httpAuth() {
	return [ 'headers' => [ 'x-goog-api-key' => (string)$this->apiKey ], 'query' => [] ];
    }

    //the models the key can use to generate content (their names without `models/`)
    public function test() {
	$data = static::getJson(rtrim((string)$this->baseUrl, '/') . '/models?pageSize=1000', $this->httpAuth()['headers']);
	$ids = [];
	foreach((array)($data['models'] ?? []) as $m) {
	    if (!is_array($m) || !in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true))
		continue;
	    $ids[] = preg_replace('~^models/~', '', (string)($m['name'] ?? ''));
	}
	$ids = array_values(array_filter($ids));
	//recorded: the choices of the AI steps' Model
	$this->recordModels($ids);
	$shown = array_slice($ids, 0, 10);
	return count($ids) . ' model(s)' . ($shown ? ': ' . implode(', ', $shown) . (count($ids) > count($shown) ? ', ...' : '') : '');
    }
}
