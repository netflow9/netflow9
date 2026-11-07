<?php

/*
    the Assistant's settings of an account, one document per account; read by WorkflowAssistant.

    the Assistant uses only these settings:
	provider, server URL, API key, then the model, chosen from the ones the server lists (Connect on the settings page: AssistantController::connect).

    bring your own key:
	the providers System (the installation's server, config assistant.system: no key, URL or model here),
	Claude, OpenAI, Gemini, Ollama, Lemonade, LM Studio;
	each speaks one protocol (data\AiProviders: the Anthropic Messages API or OpenAI chat completions).
	+ a monthly budget (USD; the spending: AssistantUsage), counted at the list prices (data\AiProviders)

    System is paid in credits (AssistantWallet), not with the budget: + credit_cap, the most credits the account lets the Assistant use per month
*/

namespace models\assistant;


class AssistantSettings extends \sys\BaseModel {

    const DATA_ACCESS = [
	'structure' => 'Structure only',
	'data' => 'Structure and data',
    ];

    //whether the Assistant of account $aid may read the projects' data (its saved settings' data_access), else their structure only (also without settings)
    public static function readsData($aid) {
	$s = $aid ? \V::app()->om->findOne('AssistantSettings', [ 'aid' => \V::app()->om->to_oid((string)$aid) ]) : null;
	return (string)($s->data_access ?? 'structure') === 'data';
    }

    protected static $collection = 'assistant_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const DEFAULT_PROVIDER = 'anthropic';

    //the Assistant's providers: System, then the others
    public static function providers() {
	return [ 'system' => 'System AI (provided by this installation)' ] + \data\AiProviders::labels();
    }

    public function getSchema() {
	$fields = [
	    [
		'key' => 'provider',
		'type' => 'select',
		'label' => 'Provider',
		'help' => 'Who runs the Assistant: the server this installation provides (System), or your own account with a provider (your key, your costs)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => self::DEFAULT_PROVIDER,
		'choices' => self::providers(),
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Server URL',
		'help' => 'Empty: the provider\'s own API. Otherwise a proxy, or a server up to the version, e.g. http://localhost:8000/api/v1 (Lemonade), http://localhost:11434/v1 (Ollama), http://localhost:1234/v1 (LM Studio)',
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
		'help' => 'Claude: console.anthropic.com; OpenAI: platform.openai.com; Gemini: aistudio.google.com; Ollama, Lemonade, LM Studio: only when the server asks for one. Empty keeps the saved one',
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
		'default' => \data\AiProviders::DEFAULT_EFFORT,
		'choices' => \data\AiProviders::EFFORT,
	    ],
	    //CLD: 2026-11-01T03:00:00+02:00: what the Assistant may read (util\WorkflowAssistant): structure (tables' columns, workflows, step types, file names, variable names) or structure and data
	    //(rows, file contents, images, executions, variable values)
	    [
		'key' => 'data_access',
		'type' => 'select',
		'label' => 'May read',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => 'structure',
		'choices' => self::DATA_ACCESS,
	    ],
	    //CLD: 2026-10-22T14:00:00+03:00: the monthly budget
	    [
		'key' => 'budget',
		'type' => 'number',
		'label' => 'Monthly budget (USD)',
		'help' => 'The most the Assistant may spend per calendar month (UTC), at the provider\'s list prices; a request that reaches it stops. A model whose price is not known is not counted. Empty or 0: no limit',
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
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-10-22T14:00:00+03:00: + the checks across fields: System only when the installation provides it; a key for the providers that need one; a budget needs prices
    public function validates($options = []) {
	$ok = parent::validates($options);
	$provider = (string)$this->provider;
	if ($provider === 'system') {
	    if (!self::systemAvailable()) {
		$this->errors('provider', [ 'This installation provides no System AI: choose a provider and set your key' ]);
		$ok = false;
	    }
	    return $ok;
	}
	//OpenAI with a Server URL: another OpenAI-compatible server, maybe without keys (as saved before)
	if (\data\AiProviders::isCloud($provider) && !($provider === 'openai' && trim((string)$this->url) !== '') && trim((string)$this->api_key) === '') {
	    $this->errors('api_key', [ 'Required for ' . \data\AiProviders::label($provider) ]);
	    $ok = false;
	}
	return $ok;
    }

    //CLD: 2026-10-22T14:00:00+03:00: the server the installation provides (config assistant.system); null when there is none (no provider or model)
    //CLD: 2026-10-31T22:00:00+02:00: the provider a plugin offers (util\AssistantSystem, hook assistant:system-provider)
    public static function system() {
	$p = \util\AssistantSystem::provider();
	return $p ? $p->config() : null;
    }

    public static function systemAvailable() {
	return self::system() !== null;
    }

    //CLD: 2026-10-26T23:49:50+02:00: new: whether a plugin offers the System provider
    public static function systemEnabled() {
	return \util\AssistantSystem::provider() !== null;
    }

    //CLD: 2026-10-22T14:00:00+03:00: the monthly limit (USD) for the account's settings $settings; null: no limit
    //CLD: 2026-10-22T15:00:00+03:00: none with System: its credits limit it (AssistantWallet)
    public static function budgetOf($settings) {
	if ((string)($settings->provider ?? '') === 'system')
	    return null;
	$budget = (float)($settings->budget ?? 0);
	return $budget > 0 ? $budget : null;
    }

    //the prices for $settings (resolved: provider, model; the System AI's: its own price_input, price_output): [ input, output, cache read ] USD per 1M tokens; null: not known.
    //Settings of this model have no prices of their own: a price saved earlier is not used
    public static function pricesOf($settings) {
	return \util\AiPrices::of($settings instanceof self ? (object)[ 'provider' => $settings->provider, 'model' => $settings->model ] : $settings);
    }

}
