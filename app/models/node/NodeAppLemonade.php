<?php

/*
    Lemonade AI - an AI step (NodeAppAi: prompt, tools, agent loop) on a Lemonade server (local models), through its OpenAI-compatible API: the
    OpenAI step's requests (NodeAppOpenAI: POST <base URL>/chat/completions, tools as functions, images as image_url parts, JSON by a system prompt instruction, <think> reasoning
    left out of the answer), with a Lemonade server credential (models\credential\CredentialLemonade, provides `lemonade`). The model is one the server has (Test on the
    credential lists them, e.g. Qwen3-8B-GGUF)
*/

namespace models\node;

class NodeAppLemonade extends NodeAppOpenAI {
    //the step picker: an advanced step (a self-hosted server)
    public static $audience = 'advanced';
    public static $name = 'lemonade';
    public static $label = 'Lemonade AI';
    public static $icon = 'bi-cup-straw';

    const CAPABILITY = 'lemonade';
    const PROVIDER = \data\AiProviders::DATA[self::CAPABILITY]['name'];
    //a local server: a long prompt on a local model can take minutes
    const DEFAULT_TIMEOUT = 900;
    const MAX_TIMEOUT = 900;

    //the Model field (a select of the credential's recorded models) is NodeAppAi's configSchema()
}
