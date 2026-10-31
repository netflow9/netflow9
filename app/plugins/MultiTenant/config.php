<?php

/*
    CLD: 2026-10-31T21:00:00+02:00: new: the MultiTenant plugin's options (Plugin::options). Copy it to config.local.php to change them: that file is read instead, and updates
    don't overwrite it (not in git).
*/

return [
    //the sign-up of new accounts: a link on the sign-in page, /MultiTenant/register (was the Registration plugin)
    'registration' => false,
    //the System AI, paid in credits, for the Assistant and the AI steps (was the SystemAI plugin); it also needs SYSTEM_AI_PROVIDER and SYSTEM_AI_MODEL in app.env
    'system_ai' => true,
];
