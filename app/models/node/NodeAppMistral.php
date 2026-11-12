<?php

/*
    CLD: 2026-11-12T23:55:00+02:00: Mistral - an AI step (NodeAppAi: prompt, tools, agent loop) on Mistral AI's API, through its OpenAI-compatible chat completions: the OpenAI step's requests
    (NodeAppOpenAI), with a Mistral AI credential (models\credential\CredentialMistral, provides `mistral`). The model is one Mistral lists (Test on the credential), e.g.
    mistral-large-latest, mistral-small-latest; images are read by its vision models; PDFs as Auto: their text and images (pdfAsDocuments: api.openai.com only)
*/

namespace models\node;

class NodeAppMistral extends NodeAppOpenAI {
    public static $name = 'mistral';
    public static $label = 'Mistral';
    public static $icon = 'bi-wind';

    const CAPABILITY = 'mistral';
    const PROVIDER = \data\AiProviders::DATA[self::CAPABILITY]['name'];
}
