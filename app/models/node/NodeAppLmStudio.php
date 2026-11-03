<?php

/*
    CLD: 2026-11-03T08:36:38+02:00: new step: LM Studio - an AI step (NodeAppAi: prompt, tools, agent loop) on an LM Studio server (local models), through its OpenAI-compatible API: the
    OpenAI step's requests (NodeAppOpenAI), with an LM Studio server credential (models\credential\CredentialLmStudio, provides `lmstudio`). The model is one the server has (Test on the
    credential lists them)
*/

namespace models\node;

class NodeAppLmStudio extends NodeAppOpenAI {
    public static $audience = 'advanced';
    public static $name = 'lmstudio';
    public static $label = 'LM Studio';
    public static $icon = 'bi-cpu';

    const CAPABILITY = 'lmstudio';
    const PROVIDER = \data\AiProviders::DATA[self::CAPABILITY]['name'];
    //a local server: a long prompt on a local model can take minutes
    const DEFAULT_TIMEOUT = 900;
    const MAX_TIMEOUT = 900;
}
