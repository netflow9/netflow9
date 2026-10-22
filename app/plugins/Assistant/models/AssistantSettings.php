<?php

/*
    the Assistant's settings of an account, one document per account;
    read by WorkflowAssistant.
    //CLD: 2026-10-20T10:00:00+03:00: the Assistant uses only these settings (no longer the projects' credentials): provider, server URL, API key, then the model,
    //chosen from the ones the server lists (Connect on the settings page: AssistantController::connect). Were model / openai_model / openai_url / openai_key
    //CLD: 2026-10-22T14:00:00+03:00: bring your own key: the providers System (the installation's server, config assistant.system: no key, URL or model here), Claude, OpenAI, Gemini,
    //Ollama, Lemonade; each speaks one protocol (PROTOCOLS: the Anthropic Messages API or OpenAI chat completions). + a monthly budget (USD; the spending: AssistantUsage) and the prices
    //it is counted with (USD per 1M tokens; empty: PRICES, the known prices of Claude models)
    //CLD: 2026-10-22T15:00:00+03:00: System is paid in credits (AssistantWallet), not with the budget: + credit_cap, the most credits the account lets the Assistant use per month
*/

namespace plugins\Assistant\models;

class AssistantSettings extends \sys\BaseModel {
    protected static $collection = 'assistant_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    //CLD: 2026-10-22T14:00:00+03:00: + system, gemini, ollama, lemonade; openai is OpenAI itself (still any OpenAI-compatible server with its Server URL, as the settings saved before)
    const PROVIDERS = [
	'system' => 'System (provided by this installation)',
	'anthropic' => 'Claude (Anthropic)',
	'openai' => 'OpenAI (or another OpenAI-compatible server)',
	'gemini' => 'Google Gemini',
	'ollama' => 'Ollama',
	'lemonade' => 'Lemonade server',
    ];
    //CLD: 2026-10-22T14:00:00+03:00: the API each provider speaks (WorkflowAssistant: turnAnthropic / turnOpenAI); system: the one of the installation's provider
    const PROTOCOLS = [ 'anthropic' => 'anthropic', 'openai' => 'openai', 'gemini' => 'openai', 'ollama' => 'openai', 'lemonade' => 'openai' ];
    const DEFAULT_PROVIDER = 'anthropic';
    //CLD: 2026-10-22T14:00:00+03:00: + gemini (its OpenAI-compatible API), ollama, lemonade (their OpenAI-compatible APIs, on this machine)
    const DEFAULT_URLS = [
	'anthropic' => 'https://api.anthropic.com',
	'openai' => 'https://api.openai.com/v1',
	'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
	'ollama' => 'http://localhost:11434/v1',
	'lemonade' => 'http://localhost:8000/api/v1',
    ];
    //CLD: 2026-10-22T14:00:00+03:00: the providers that cannot work without a key (the others: only when their server asks for one); local ones cost nothing unless prices are set
    const KEY_REQUIRED = [ 'anthropic', 'openai', 'gemini' ];
    const LOCAL = [ 'ollama', 'lemonade' ];
    const DEFAULT_EFFORT = 'high';
    const DEFAULT_MODEL = 'claude-opus-5-5';

    //CLD: 2026-10-22T14:00:00+03:00: the prices of Claude models (Anthropic API, USD per 1M tokens: input, output, cache read), by model id prefix (the first that matches; longer ones first).
    //A cache write is 1.25 x input. Other providers' prices change too often to be kept here: they are set in the settings (price_input, price_output)
    const PRICES = [
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
    ];
    const CACHE_WRITE = 1.25;

    public function getSchema() {
	$fields = [
	    [
		'key' => 'provider',
		'type' => 'select',
		'label' => 'Provider',
		//CLD: 2026-10-22T14:00:00+03:00: + System, Gemini, Ollama, Lemonade
		'help' => 'Who runs the Assistant: the server this installation provides (System), or your own account with a provider (your key, your costs)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => self::DEFAULT_PROVIDER,
		'choices' => self::PROVIDERS,
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Server URL',
		'help' => 'Empty: the provider\'s own API. Otherwise a proxy, or a server up to the version, e.g. http://localhost:8000/api/v1 (Lemonade), http://localhost:11434/v1 (Ollama)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'url', 'skipEmpty' => true, 'message' => 'An http:// or https:// URL, without ? or #' ],
		],
	    ],
	    [
		'key' => 'api_key',
		'type' => 'password',
		'label' => 'API key',
		'help' => 'Claude: console.anthropic.com; OpenAI: platform.openai.com; Gemini: aistudio.google.com; Ollama, Lemonade: only when the server asks for one. Empty keeps the saved one',
		'perm:update' => 'everybody',
		'encrypt' => true,
		'default' => '',
	    ],
	    [
		'key' => 'model',
		'type' => 'text',
		'label' => 'Model',
		'help' => 'Connect to list the models of the server',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'regex', 'pattern' => '^[A-Za-z0-9._:@/+-]{1,200}$', 'skipEmpty' => true, 'message' => 'A model id: letters, digits and . _ : @ / + -' ],
		],
	    ],
	    [
		'key' => 'effort',
		'type' => 'select',
		'label' => 'Effort',
		'help' => 'How thoroughly Claude thinks: higher is better for large workflows, lower is faster and cheaper',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		//CLD: 2026-10-20T10:45:00+03:00: self:: (was WorkflowAssistant::)
		'default' => self::DEFAULT_EFFORT,
		'choices' => \models\node\NodeAppClaude::EFFORT,
	    ],
	    //CLD: 2026-10-22T14:00:00+03:00: the monthly budget and the prices it is counted with
	    [
		'key' => 'budget',
		'type' => 'number',
		'label' => 'Monthly budget (USD)',
		'help' => 'The most the Assistant may spend per calendar month (UTC); a request that reaches it stops. Empty or 0: no limit',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ],
		],
	    ],
	    //CLD: 2026-10-22T15:00:00+03:00: System: the account's own limit on its credits
	    [
		'key' => 'credit_cap',
		'type' => 'number',
		'label' => 'Monthly credit limit',
		'help' => 'The most credits the Assistant may use per calendar month (UTC), from the allowance and the top-ups. Empty or 0: no limit',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ],
		],
	    ],
	    [
		'key' => 'price_input',
		'type' => 'number',
		'label' => 'Input price',
		'help' => 'USD per 1M input tokens. Empty: the known price of Claude models (other models: set it, or the budget cannot be counted)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ],
		],
	    ],
	    [
		'key' => 'price_output',
		'type' => 'number',
		'label' => 'Output price',
		'help' => 'USD per 1M output tokens (as Input price)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ],
		],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-10-22T14:00:00+03:00: + the checks across fields: System only when the installation provides it; a key for the providers that need one; a budget needs prices
    public function validates($options = []) {
	$ok = parent::validates($options);
	$provider = (string)$this->provider;
	if ($provider === 'system') {
	    if (!self::systemAvailable()) {
		$this->errors('provider', [ 'This installation provides no Assistant server: choose a provider and set your key' ]);
		$ok = false;
	    }
	    return $ok;
	}
	//OpenAI with a Server URL: another OpenAI-compatible server, maybe without keys (as saved before)
	if (in_array($provider, self::KEY_REQUIRED, true) && !($provider === 'openai' && trim((string)$this->url) !== '') && trim((string)$this->api_key) === '') {
	    $this->errors('api_key', [ 'Required for ' . (self::PROVIDERS[$provider] ?? $provider) ]);
	    $ok = false;
	}
	if ((float)$this->budget > 0 && self::pricesOf($this) === null) {
	    $this->errors('price_input', [ 'The price of this model is not known: set Input price and Output price, or the budget cannot be counted' ]);
	    $ok = false;
	}
	return $ok;
    }

    //CLD: 2026-10-22T14:00:00+03:00: the server the installation provides (config assistant.system); null when there is none (no provider or model)
    public static function system() {
	$s = (array)\V::app()->config('assistant.system');
	$provider = (string)($s['provider'] ?? '');
	if (!isset(self::PROTOCOLS[$provider]) || trim((string)($s['model'] ?? '')) === '')
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

    public static function systemAvailable() {
	return self::system() !== null;
    }

    //CLD: 2026-10-22T14:00:00+03:00: the monthly limit (USD) for the account's settings $settings; null: no limit
    //CLD: 2026-10-22T15:00:00+03:00: none with System: its credits limit it (AssistantWallet; was the smaller of the budget and the installation's)
    public static function budgetOf($settings) {
	if ((string)($settings->provider ?? '') === 'system')
	    return null;
	$budget = (float)($settings->budget ?? 0);
	return $budget > 0 ? $budget : null;
    }

    //CLD: 2026-10-22T14:00:00+03:00: the prices for $settings (resolved: provider, model, price_input, price_output): [ input, output, cache read ] USD per 1M tokens; the settings' when
    //given, else the known ones of the model (PRICES), else nothing for a local server; null: not known
    public static function pricesOf($settings) {
	$in = (float)($settings->price_input ?? 0);
	$out = (float)($settings->price_output ?? 0);
	$model = (string)($settings->model ?? '');
	$known = null;
	foreach(self::PRICES as $prefix => $p) {
	    if (str_starts_with($model, $prefix)) {
		$known = $p;
		break;
	    }
	}
	if ($in > 0 || $out > 0)
	    return [ $in, $out, $known && $known[0] == $in ? $known[2] : $in * 0.1 ];
	if ($known)
	    return $known;
	if (in_array((string)($settings->provider ?? ''), self::LOCAL, true))
	    return [ 0, 0, 0 ];
	return null;
    }

}
