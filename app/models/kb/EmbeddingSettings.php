<?php

/*
    the embedding model of an account, one document per account: what turns the knowledge bases' texts into vectors (util\Embeddings), for every
    project of the account. Set on the Settings menu's Knowledge bases page (KnowledgeController::settings; Connect lists the server's models: KnowledgeController::connect).
	provider   ollama | lemonade | lmstudio | openai (or another OpenAI-compatible server) | gemini | mistral
	url        the server (empty: the provider's own: data\AiProviders)
	api_key    encrypted; empty keeps the saved one (only for the same provider and server)
	model      the embedding model; a knowledge base remembers the model it was indexed with (models\kb\KnowledgeBase::$model): another model here means re-indexing it
*/

namespace models\kb;

class EmbeddingSettings extends \sys\BaseModel {
    protected static $collection = 'embedding_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const DEFAULT_PROVIDER = 'ollama';

    public function getSchema() {
	$fields = [
	    [
		'key' => 'provider',
		'type' => 'select',
		'label' => 'Provider',
		'help' => 'Who makes the embeddings: a server of your own (Ollama, Lemonade, LM Studio) or a provider\'s API (your key, your costs)',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => self::DEFAULT_PROVIDER,
		'choices' => \data\AiProviders::forEmbeddings(),
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Server URL',
		'help' => 'Empty: the provider\'s own address. Reached from this application\'s server: localhost means that machine',
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
		'help' => 'OpenAI: platform.openai.com; Gemini: aistudio.google.com; Mistral: console.mistral.ai; Ollama, Lemonade, LM Studio: only when the server asks for one. Empty keeps the saved one',
		'perm:update' => 'everybody',
		'encrypt' => true,
		'default' => '',
	    ],
	    [
		'key' => 'model',
		'type' => 'text',
		'label' => 'Embedding model',
		'help' => 'Connect to list the models of the server',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [
		    [ 'regex', 'pattern' => '^[A-Za-z0-9._:@/+-]{1,200}$', 'skipEmpty' => true, 'message' => 'A model id: letters, digits and . _ : @ / + -' ],
		],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //a key for the providers that need one
    public function validates($options = []) {
	$ok = parent::validates($options);
	$provider = (string)$this->provider;
	if (\data\AiProviders::isCloud($provider) && !($provider === 'openai' && trim((string)$this->url) !== '') && trim((string)$this->api_key) === '') {
	    $this->errors('api_key', [ 'Required for ' . \data\AiProviders::label($provider) ]);
	    $ok = false;
	}
	return $ok;
    }

    //the settings of account $aid; new (not saved) when it has none yet
    public static function of($aid) {
	return \V::app()->om->findOne('EmbeddingSettings', [ 'aid' => $aid ]) ?: \V::app()->om->create('EmbeddingSettings', [ 'aid' => $aid ]);
    }

    //whether a model is chosen (the knowledge bases can be used)
    public function ready() {
	return trim((string)$this->model) !== '' && array_key_exists((string)$this->provider, \data\AiProviders::forEmbeddings());
    }

    //the server's base: the Server URL, else the provider's own (no trailing /)
    public function baseUrl() {
	$url = rtrim(trim((string)$this->url), '/');
	return $url !== '' ? $url : \data\AiProviders::DATA[(string)$this->provider]['url'] ?? '';
    }

    //a short description: "<model> (<provider>)"
    public function label() {
	return trim((string)$this->model) . ' (' . \data\AiProviders::label((string)$this->provider) . ')';
    }
}
