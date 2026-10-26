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
    //CLD: 2026-10-23T10:00:00+03:00: System is the System AI (plugins\SystemAI: util\SystemAi, models\SystemAiWallet), shared with the AI steps; the known prices are its own too
*/

//CLD: 2026-10-26T23:49:50+02:00: moved to the core (was the Assistant plugin's); its System provider only while the SystemAI plugin is enabled
namespace models;

use plugins\SystemAI\util\SystemAi;

class AssistantSettings extends \sys\BaseModel {
    protected static $collection = 'assistant_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    //CLD: 2026-10-22T14:00:00+03:00: + system, gemini, ollama, lemonade; openai is OpenAI itself (still any OpenAI-compatible server with its Server URL, as the settings saved before)
    const PROVIDERS = [
	'system' => 'System AI (provided by this installation)', //CLD: 2026-10-23T10:00:00+03:00: (was System)
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
    //CLD: 2026-10-23T10:00:00+03:00: LOCAL: SystemAi::LOCAL (was here)
    const DEFAULT_EFFORT = 'high';
    const DEFAULT_MODEL = 'claude-opus-5-5';

    //CLD: 2026-10-22T14:00:00+03:00: the prices of Claude models (Anthropic API, USD per 1M tokens: input, output, cache read), by model id prefix (the first that matches; longer ones first).
    //CLD: 2026-10-23T10:00:00+03:00: they are SystemAi::PRICES, CACHE_WRITE (were here)

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
		//CLD: 2026-10-23T10:00:00+03:00: the month's credits are the account's (the AI steps' too)
		'help' => 'The Assistant stops once the credits the account used this calendar month (UTC: the Assistant and the AI steps on the System AI) reach it. Empty or 0: no limit',
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
		$this->errors('provider', [ 'This installation provides no System AI: choose a provider and set your key' ]); //CLD: 2026-10-23T10:00:00+03:00: (was no Assistant server)
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
    //CLD: 2026-10-23T10:00:00+03:00: the System AI (SystemAi::config: config ai.system)
    //CLD: 2026-10-26T23:49:50+02:00: none without the SystemAI plugin (app/plugins.php): the Assistant is core, the System AI a plugin
    public static function system() {
	return self::systemEnabled() ? SystemAi::config() : null;
    }

    public static function systemAvailable() {
	return self::systemEnabled() && SystemAi::available();
    }

    //CLD: 2026-10-26T23:49:50+02:00: new: whether the SystemAI plugin is enabled (its credits and models registered)
    public static function systemEnabled() {
	return in_array('SystemAI', (array)\V::app()->config('modules'), true);
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
    //CLD: 2026-10-23T10:00:00+03:00: SystemAi::pricesOf (was here)
    public static function pricesOf($settings) {
	return SystemAi::pricesOf($settings);
    }

}
