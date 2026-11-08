<?php

namespace data;

//constants shared by the app's parts
class Constants {
    //the sidebar's width limits (px; as app.sidebar's minWidth / maxWidth)
    const SIDEBAR_MIN_W = 250;
    const SIDEBAR_MAX_W = 400;

    //how long a "forgot password" link works (seconds)
    const RESET_VALID = 86400;
    //how long a project invitation's link works (seconds; models\user\ProjectInvite)
    const INVITE_VALID = 7 * 86400;
    //how long the link of a new user's welcome email works (seconds; UserController::create / send_link), as a project invitation's
    const WELCOME_VALID = self::INVITE_VALID;

    //the largest request body accepted by the public endpoints (bytes): webhooks (HookController), MCP (McpController), the FortiGate push (FortigateController)
    const MAX_REQUEST_BODY = 1048576;

    //the objects of a project that can be shared with other projects of the account and moved to another one (ShareController: the `object` parameter)
    const PROJECT_KINDS = [ 'DataTableDef', 'Workflow', 'Credential' ];

    //the model names that are not chat models (embeddings, speech, images, moderation, old completions): left out of the AI steps' choices (models\Credential)
    const NOT_CHAT = '/(embed|tts|whisper|transcribe|dall-e|gpt-image|moderation|davinci|babbage|rerank)/i';
}
