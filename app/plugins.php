<?php

//CLD: 2026-10-31T11:22:13+02:00: the plugins enabled, in this order. An installation's own list: copy this file to plugins.local.php and change that one (read instead of this file;
//not in git, so an update never overwrites it or is blocked by it)

return [
    //CLD: 2026-10-31T21:00:00+02:00: an installation that hosts many accounts: the administration (super-admins: the accounts, their plans, credits and tool servers, the
    //services), and as options (plugins/MultiTenant/config.php) the sign-up and the System AI; was the Admin, Registration and SystemAI plugins. First: the others add their sections
    'MultiTenant',
    //CLD: 2026-10-19T21:10:00+03:00: inbound mail: the Email (inbound) trigger (was in the core); active only when Haraka is found
    'InboundMail',
    //CLD: 2026-10-22T22:00:00+03:00: the installation's tool servers for the AI steps (MCP servers run on this machine, e.g. a web browser), for the accounts the administrator enables
    'LocalMCP',
    //CLD: 2026-10-25T20:00:00+02:00: (the Database plugin is core now: the Database step and the database credentials, data\SysObjects)
    //CLD: 2026-10-26T23:39:49+02:00: (the GoogleIntegration and MicrosoftIntegration plugins are core again: their credentials and steps, data\SysObjects)
    //CLD: 2026-10-26T23:49:50+02:00: (the Assistant plugin is core again: util\WorkflowAssistant, controllers\AssistantController, views/assistant)
    //CLD: 2026-10-26T23:56:07+02:00: (the SysMCPTools plugin is core again: controllers\McpController, TokenController, util\McpServerTools, models\McpToken)
];
