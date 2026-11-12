<?php

/*
    CLD: 2026-11-12T23:55:00+02:00: Mistral AI's API (La Plateforme: chat completions, models, embeddings; OpenAI-compatible), for the Mistral step, the Assistant and the knowledge bases'
    embeddings. As an OpenAI API credential (CredentialOpenAI: base URL + API key, sent as `Authorization: Bearer`; Test lists the models), with Mistral's address as the
    default and the key required. Provides `mistral` (the Mistral step) and `http` (the HTTP Request step)
*/

namespace models\credential;

class CredentialMistral extends \models\credential\CredentialOpenAI {
    public static $label = 'Mistral AI';
    public static $icon = 'bi-wind';
    public static $group = 'AI';
    public static $provides = [ 'mistral', 'http' ];

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) === 'baseUrl') {
		$f['default'] = \data\AiProviders::DATA['mistral']['url'];
		$f['help'] = 'Mistral\'s API (https://api.mistral.ai/v1), or a proxy of it, up to the version (requests go to <URL>/chat/completions, <URL>/models)';
	    }
	    if (($f['key'] ?? null) === 'apiKey') {
		$f['required'] = true;
		$f['help'] = 'Made in Mistral\'s console (console.mistral.ai > API keys)';
	    }
	}
	unset($f);
	return $fields;
    }
}
