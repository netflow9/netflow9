<?php

/*
    the AI providers. A provider's id is also its step's CAPABILITY.
	label       its name in the settings' lists
	name        its short name, in messages ("Ollama answered ...") and the price table
	protocol    the API the Assistant speaks with it: the Anthropic Messages API or OpenAI chat completions
	cloud       someone else's servers: it cannot work without a key, personal data is masked for it, it costs money;
		    the others are local: a key only when the server asks for one, nothing to pay
	embeddings  whether it makes embeddings (the knowledge bases)
	url         its own API: the AI steps' credentials, the embeddings
	chat_url    its OpenAI-compatible API, for the Assistant, when that is another address than url
	api_version          (anthropic) the API version sent with every request (anthropic-version): its credential, the Assistant, the System AI
	fallback_models      (anthropic) the models server-side fallback is offered for (beta server-side-fallback-2026-07-01, fallbacks: "default"):
			     the Claude step, the Assistant
    Not here: the System AI (it is an installation's server, see models\assistant\AssistantSettings::providers)
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
	    'api_version' => '2023-06-01',
	    'fallback_models' => '/^claude-(opus-5|sonnet-5-5|fable-5)/',
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
	'lmstudio' => [
	    'label' => 'LM Studio',
	    'name' => 'LM Studio',
	    'protocol' => 'openai',
	    'cloud' => false,
	    'embeddings' => true,
	    'url' => 'http://localhost:1234/v1',
	],
    ];

    //the list prices of the cloud models, USD per 1M tokens: [ input, output, cached input ], by model id or the start of one (util\AiPrices::of: the longest that matches, followed by
    //the end of the id or a `-`). Standard tier, text, short prompts: the higher rates of very long prompts (above 200k or 272k tokens) are not counted. A model with no cached rate: its
    //input price. Read from the providers' pricing pages on 2026-11-02; they change: check them when a model is added.
    const PRICES = [
	'anthropic' => [
	    'claude-fable-5' => [ 10.00, 50.00, 0.25 ],
	    'claude-mythos-5' => [ 10.00, 50.00, 0.25 ],
	    'claude-opus-5-5' => [ 4.00, 20.00, 0.20 ],
	    'claude-opus-5' => [ 5.00, 25.00, 0.50 ],
	    'claude-opus-4' => [ 5.00, 25.00, 0.50 ],
	    'claude-sonnet-5-5' => [ 2.00, 10.00, 0.20 ],
	    'claude-sonnet-5' => [ 2.00, 10.00, 0.20 ],
	    'claude-sonnet-4' => [ 3.00, 15.00, 0.30 ],
	    'claude-haiku-5-5' => [ 0.10, 0.50, 0.01 ],
	    'claude-haiku-4-5' => [ 1.00, 5.00, 0.10 ],
	],
	//developers.openai.com/api/docs/pricing
	'openai' => [
	    'gpt-6-astra' => [ 10.00, 50.00, 1.00 ],
	    'gpt-6.1-sol' => [ 2.00, 10.00, 0.10 ],
	    'gpt-6.1-terra' => [ 1.00, 5.00, 0.05 ],
	    'gpt-6-sol' => [ 2.00, 10.00, 0.20 ],
	    'gpt-6-luna' => [ 0.10, 0.50, 0.01 ],
	    'gpt-5.6-sol' => [ 4.00, 20.00, 0.40 ],
	    'gpt-5.6-terra' => [ 2.00, 12.00, 0.20 ],
	    'gpt-5.6-luna' => [ 0.20, 1.20, 0.02 ],
	    'gpt-5.5-pro' => [ 30.00, 180.00, 30.00 ],
	    'gpt-5.5' => [ 5.00, 30.00, 0.50 ],
	    'gpt-5.4-pro' => [ 30.00, 180.00, 30.00 ],
	    'gpt-5.4-mini' => [ 0.75, 4.50, 0.075 ],
	    'gpt-5.4-nano' => [ 0.20, 1.25, 0.02 ],
	    'gpt-5.4' => [ 2.50, 15.00, 0.25 ],
	    'gpt-5.3-codex' => [ 1.75, 14.00, 0.175 ],
	    'gpt-5.2-pro' => [ 21.00, 168.00, 21.00 ],
	    'gpt-5.2' => [ 1.75, 14.00, 0.175 ],
	    'gpt-5.1' => [ 1.25, 10.00, 0.125 ],
	    'gpt-5-pro' => [ 15.00, 120.00, 15.00 ],
	    'gpt-5-mini' => [ 0.25, 2.00, 0.025 ],
	    'gpt-5-nano' => [ 0.05, 0.40, 0.005 ],
	    'gpt-5' => [ 1.25, 10.00, 0.125 ],
	    'gpt-4.1-mini' => [ 0.40, 1.60, 0.10 ],
	    'gpt-4.1-nano' => [ 0.10, 0.40, 0.025 ],
	    'gpt-4.1' => [ 2.00, 8.00, 0.50 ],
	    'gpt-4o-2024-05-13' => [ 5.00, 15.00, 5.00 ],
	    'gpt-4o-mini' => [ 0.15, 0.60, 0.075 ],
	    'gpt-4o' => [ 2.50, 10.00, 1.25 ],
	    'gpt-3.5-turbo' => [ 0.50, 1.50, 0.50 ],
	    'o4-mini' => [ 1.10, 4.40, 0.275 ],
	    'o3-pro' => [ 20.00, 80.00, 20.00 ],
	    'o3-mini' => [ 1.10, 4.40, 0.55 ],
	    'o3' => [ 2.00, 8.00, 0.50 ],
	    'o1-pro' => [ 150.00, 600.00, 150.00 ],
	    'o1' => [ 15.00, 60.00, 7.50 ],
	],
	//aistudio.google.com/docs/pricing, paid tier. Introductory prices that end: gemini-4-argon on 2026-12-01 (then 4.00 / 20.00 / 0.20), gemini-3.8-flash and gemini-3.6-flash on
	//2027-01-01 (then 1.50 / 7.50 / 0.15)
	'gemini' => [
	    'gemini-4-argon' => [ 2.00, 10.00, 0.10 ],
	    'gemini-3.8-flash' => [ 0.75, 3.75, 0.075 ],
	    'gemini-3.6-flash' => [ 0.75, 3.75, 0.075 ],
	    'gemini-3.5-flash-lite' => [ 0.30, 2.50, 0.03 ],
	    'gemini-3.1-pro-preview' => [ 2.00, 12.00, 0.20 ],
	    'gemini-3.1-flash-lite' => [ 0.25, 1.50, 0.025 ],
	    'gemini-3-flash-preview' => [ 0.50, 3.00, 0.05 ],
	    'gemini-2.5-pro' => [ 1.25, 10.00, 0.125 ],
	    'gemini-2.5-flash-lite' => [ 0.10, 0.40, 0.01 ],
	    'gemini-2.5-flash' => [ 0.30, 2.50, 0.03 ],
	],
    ];

    //Claude's effort levels: the AI step's, the Assistant's, the System AI's
    const EFFORT = [
	'low' => 'Low',
	'medium' => 'Medium',
	'high' => 'High',
	'xhigh' => 'Extra high',
	'max' => 'Max'
    ];
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
