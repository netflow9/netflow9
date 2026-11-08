<?php

/*
    a LM Studio server (local models, an OpenAI-compatible API), for the LM Studio step.
    As an OpenAI API credential (CredentialOpenAI: base URL + optional API key, sent as `Authorization: Bearer`;
    Test lists the models), with LM Studio's address as the default.
    Provides `lmstudio` (the LM Studio step) and `http` (the HTTP Request step)
*/

namespace models\credential;

class CredentialLmStudio extends \models\credential\CredentialOpenAI {
    public static $label = 'LM Studio server';
    public static $icon = 'bi-cpu';
    public static $group = 'AI';
    public static $provides = [ 'lmstudio', 'http' ];

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) === 'baseUrl') {
		$f['default'] = \data\AiProviders::DATA['lmstudio']['url'];
		$f['help'] = 'The server\'s OpenAI-compatible API, up to the version (requests go to <URL>/chat/completions, <URL>/models); an LM Studio server: http://<host>:1234/v1';
	    }
	    if (($f['key'] ?? null) === 'apiKey')
		$f['help'] = 'Only when the server requires one (Require Authentication, in its Server Settings)';
	}
	unset($f);
	return $fields;
    }
}
