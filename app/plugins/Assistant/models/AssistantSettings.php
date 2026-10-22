<?php

/*
    the Assistant's settings of an account, one document per account;
    read by WorkflowAssistant.
    //CLD: 2026-10-20T10:00:00+03:00: the Assistant uses only these settings (no longer the projects' credentials): provider, server URL, API key, then the model,
    //chosen from the ones the server lists (Connect on the settings page: AssistantController::connect). Were model / openai_model / openai_url / openai_key
*/

namespace plugins\Assistant\models;

class AssistantSettings extends \sys\BaseModel {
    protected static $collection = 'assistant_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const PROVIDERS = [ 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI-compatible (OpenAI, or a local server)' ];
    const DEFAULT_PROVIDER = 'anthropic';
    const DEFAULT_URLS = [ 'anthropic' => 'https://api.anthropic.com', 'openai' => 'https://api.openai.com/v1' ];
    const DEFAULT_EFFORT = 'high';
    const DEFAULT_MODEL = 'claude-opus-5-5';

    public function getSchema() {
	$fields = [
	    [
		'key' => 'provider',
		'type' => 'select',
		'label' => 'Provider',
		'help' => 'Who runs the Assistant: Claude (the Anthropic API), or an OpenAI-compatible model (OpenAI, or a local server)',
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
		'help' => 'Empty: the provider\'s own API (https://api.anthropic.com, https://api.openai.com/v1). Otherwise a proxy, or an OpenAI-compatible server up to the version, e.g. http://localhost:8000/api/v1 (Lemonade), http://localhost:11434/v1 (Ollama)',
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
		'help' => 'Anthropic: from console.anthropic.com; OpenAI-compatible: only when the server asks for one (sent as Authorization: Bearer). Empty keeps the saved one',
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
	];
	return array_merge($fields, $this->getSystemSchema());
    }

}
