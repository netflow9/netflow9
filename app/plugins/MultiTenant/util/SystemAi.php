<?php

/*
    CLD: 2026-10-23T10:00:00+03:00: new: the System AI provider: the model server this installation offers to every account (config ai.system: app.env SYSTEM_AI_*), paid in
    credits (models\SystemAiWallet). Was the Assistant's own (plugins\Assistant\models\AssistantSettings::system, PRICES, pricesOf); now used by
	the Assistant                      its provider System (util\WorkflowAssistant, models\AssistantSettings: core)
	the AI steps of the same provider  Credential: System AI (models\node\NodeAppAi: hook ai:system-provider, Plugin; a run: util\StepSession)
*/

namespace plugins\MultiTenant\util;

class SystemAi {
    //the providers a System AI may be (config ai.system.provider) and their names
    const PROVIDERS = [
	'anthropic' => 'Claude (Anthropic)',
	'openai' => 'OpenAI (or another OpenAI-compatible server)',
	'gemini' => 'Google Gemini',
	'ollama' => 'Ollama',
	'lemonade' => 'Lemonade server',
    ];
    const DEFAULT_EFFORT = 'high';

    //CLD: 2026-10-31T22:00:00+02:00: the known prices (PRICES, CACHE_WRITE, LOCAL, pricesOf) are the core's: util\AiPrices (the Assistant's own key counts with them too)

    //the System AI (config ai.system): { provider, url, api_key, model, effort, price_input, price_output }; null when there is none (no provider or model)
    //(was plugins\Assistant\models\AssistantSettings::system)
    public static function config() {
	$s = (array)\V::app()->config('ai.system');
	$provider = (string)($s['provider'] ?? '');
	if (!isset(self::PROVIDERS[$provider]) || trim((string)($s['model'] ?? '')) === '')
	    return null;
	return (object)[
	    'provider' => $provider,
	    'url' => trim((string)($s['url'] ?? '')),
	    'api_key' => (string)($s['api_key'] ?? ''),
	    'model' => trim((string)$s['model']),
	    'effort' => array_key_exists((string)($s['effort'] ?? ''), \models\node\NodeAppClaude::EFFORT) ? (string)$s['effort'] : self::DEFAULT_EFFORT,
	    'price_input' => (float)($s['price_input'] ?? 0),
	    'price_output' => (float)($s['price_output'] ?? 0),
	];
    }

    public static function available() {
	return self::config() !== null;
    }

    //the credits each account gets per month (no rollover), the most one request (an Assistant request, an AI step's run) may reserve (0: no limit)
    public static function monthlyCredits() {
	return max(0, (int)(\V::app()->config('ai.system')['monthly_credits'] ?? 0));
    }

    public static function maxCreditsPerRequest() {
	return max(0, (int)(\V::app()->config('ai.system')['max_credits_per_request'] ?? 0));
    }

    //the System AI as a credential of an AI step of its provider (models\node\NodeAppClaude, ...: the fields and methods those steps use of models\credential\Credential<Provider>):
    //baseUrl (the step's API: Gemini's own, not its OpenAI-compatible one the Assistant uses; Ollama's without /v1), httpAuth(), apiBase() (Ollama), provides()
    public static function stepCredential($system) {
	$provider = $system->provider;
	$url = rtrim($system->url, '/');
	if ($provider === 'gemini')
	    $url = $url === '' ? 'https://generativelanguage.googleapis.com/v1beta' : preg_replace('~/openai$~', '', $url);
	elseif ($provider === 'ollama')
	    $url = preg_replace('~/v1$~', '', $url === '' ? 'http://localhost:11434' : $url);
	elseif ($url === '')
	    $url = [ 'anthropic' => 'https://api.anthropic.com', 'openai' => 'https://api.openai.com/v1', 'lemonade' => 'http://localhost:8000/api/v1' ][$provider];
	return new class($provider, $url, $system->api_key) {
	    public $name = 'System AI';
	    public function __construct(private $provider, public $baseUrl, private $apiKey) {}
	    public function httpAuth() {
		if ($this->provider === 'anthropic')
		    return [ 'headers' => [ 'x-api-key' => $this->apiKey, 'anthropic-version' => \models\credential\CredentialAnthropic::API_VERSION ], 'query' => [] ];
		if ($this->provider === 'gemini')
		    return [ 'headers' => [ 'x-goog-api-key' => $this->apiKey ], 'query' => [] ];
		return [ 'headers' => $this->apiKey !== '' ? [ 'Authorization' => 'Bearer ' . $this->apiKey ] : [], 'query' => [] ];
	    }
	    public function apiBase() {
		return preg_replace('~/(api/?)?$~', '', $this->baseUrl);
	    }
	    public function provides($capability) {
		return $capability === $this->provider;
	    }
	};
    }
}
