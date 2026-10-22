<?php

//CLD: 2026-10-22T01:36:46+03:00: new credential variant: a Lemonade server (lemonade-server: local models on the CPU / GPU / NPU, an OpenAI-compatible API), for the Lemonade AI step. As an OpenAI API
//credential (CredentialOpenAI: base URL + optional API key, sent as `Authorization: Bearer`; Test lists the models), with Lemonade's address as the default.
//Provides `lemonade` (the Lemonade AI step) and `http` (the HTTP Request step)

namespace models\credential;

class CredentialLemonade extends \models\credential\CredentialOpenAI {
    public static $label = 'Lemonade server';
    public static $icon = 'bi-cup-straw';
    public static $group = 'AI';
    public static $provides = [ 'lemonade', 'http' ];

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) === 'baseUrl') {
		$f['default'] = 'http://localhost:8000/api/v1';
		$f['help'] = 'The server\'s OpenAI-compatible API, up to the version (requests go to <URL>/chat/completions, <URL>/models); a Lemonade server: http://<host>:8000/api/v1';
	    }
	    if (($f['key'] ?? null) === 'apiKey')
		$f['help'] = 'Only when the server requires one (started with LEMONADE_API_KEY)';
	}
	unset($f);
	return $fields;
    }
}
