<?php

/*
    the AI providers. A provider's id is also its step's CAPABILITY.
	label       its name in the settings' lists
	name        its short name, in messages ("Ollama answered ...") and the price table
	protocol    the API the Assistant speaks with it: the Anthropic Messages API or OpenAI chat completions
	cloud       someone else's servers: it cannot work without a key, personal data is masked for it, it costs money;
		    the others are local: a key only when the server asks for one, nothing to pay unless prices are set
	embeddings  whether it makes embeddings (the knowledge bases)
	url         its own API: the AI steps' credentials, the embeddings
	chat_url    its OpenAI-compatible API, for the Assistant, when that is another address than url
    Not here: the System AI (it is an installation's server, see models\AssistantSettings::providers), the prices (util\AiPrices)
*/

namespace data;

class AiProviders {
    const DATA = [
	'anthropic' => [
	    'label' => 'Claude (Anthropic)',
	    'name' => 'Anthropic',
	    'protocol' => 'anthropic',
	    'cloud' => true,
	    'embeddings' => false,
	    'url' => 'https://api.anthropic.com',
	],
	'openai' => [
	    'label' => 'OpenAI (or another OpenAI-compatible server)',
	    'name' => 'OpenAI',
	    'protocol' => 'openai',
	    'cloud' => true,
	    'embeddings' => true,
	    'url' => 'https://api.openai.com/v1',
	],
	'gemini' => [
	    'label' => 'Google Gemini',
	    'name' => 'Google Gemini',
	    'protocol' => 'openai',
	    'cloud' => true,
	    'embeddings' => true,
	    'url' => 'https://generativelanguage.googleapis.com/v1beta',
	    'chat_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
	],
	'ollama' => [
	    'label' => 'Ollama',
	    'name' => 'Ollama',
	    'protocol' => 'openai',
	    'cloud' => false,
	    'embeddings' => true,
	    'url' => 'http://localhost:11434',
	    'chat_url' => 'http://localhost:11434/v1',
	],
	'lemonade' => [
	    'label' => 'Lemonade server',
	    'name' => 'Lemonade',
	    'protocol' => 'openai',
	    'cloud' => false,
	    'embeddings' => true,
	    'url' => 'http://localhost:8000/api/v1',
	],
    ];

    //Claude's effort levels: the AI step's, the Assistant's, the System AI's
    const EFFORT = [ 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high', 'max' => 'Max' ];
    const DEFAULT_EFFORT = 'high';
    //Claude's model when none is chosen
    const DEFAULT_MODEL = 'claude-opus-5-5';

    //[ id => label ]
    public static function labels() {
	return array_map(fn($p) => $p['label'], self::DATA);
    }

    //[ id => short name ]
    public static function names() {
	return array_map(fn($p) => $p['name'], self::DATA);
    }

    //[ id => label ] of the providers that make embeddings
    public static function forEmbeddings() {
	return array_map(fn($p) => $p['label'], array_filter(self::DATA, fn($p) => $p['embeddings']));
    }

    //[ id => the address the Assistant speaks to ]
    public static function chatUrls() {
	return array_map(fn($p) => $p['chat_url'] ?? $p['url'], self::DATA);
    }

    //[ id => its own API ] of the providers that make embeddings
    public static function embeddingUrls() {
	return array_map(fn($p) => $p['url'], array_filter(self::DATA, fn($p) => $p['embeddings']));
    }

    //the label of $provider; an unknown one as it is
    public static function label($provider) {
	return self::DATA[$provider]['label'] ?? (string)$provider;
    }

    //the short name of $provider; an unknown one: $fallback, else as it is
    public static function name($provider, $fallback = null) {
	return self::DATA[$provider]['name'] ?? $fallback ?? (string)$provider;
    }

    public static function isCloud($provider) {
	return (self::DATA[$provider]['cloud'] ?? false) === true;
    }

    public static function isLocal($provider) {
	return (self::DATA[$provider]['cloud'] ?? true) === false;
    }
}
