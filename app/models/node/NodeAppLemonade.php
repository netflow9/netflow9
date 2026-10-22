<?php

/*
    CLD: 2026-10-22T01:36:46+03:00: new step: Lemonade AI - an AI step (NodeAppAi: prompt, tools, agent loop) on a Lemonade server (local models), through its OpenAI-compatible API: the
    OpenAI step's requests (NodeAppOpenAI: POST <base URL>/chat/completions, tools as functions, images as image_url parts, JSON by a system prompt instruction, <think> reasoning
    left out of the answer), with a Lemonade server credential (models\credential\CredentialLemonade, provides `lemonade`). The model is one the server has (Test on the
    credential lists them, e.g. Qwen3-8B-GGUF)
*/

namespace models\node;

class NodeAppLemonade extends NodeAppOpenAI {
    public static $name = 'lemonade';
    public static $label = 'Lemonade AI';
    public static $icon = 'bi-cup-straw';

    const CAPABILITY = 'lemonade';
    const PROVIDER = 'Lemonade';

    //CLD: 2026-10-22T01:58:24+03:00: configSchema() removed: the Model field (a select of the credential's recorded models) is NodeAppAi's
}
