<?php

/*
    the tool servers this installation runs (MCP servers on this machine, Streamable HTTP), offered to the AI steps of the accounts the administrator
    enables (./v LocalMCP::servers::enable). servers.local.php, when present, is read instead (an installation's own list, not overwritten by updates).
	id => [
	    label, description          shown in the AI step (Tool servers)
	    url, headers                the server's MCP endpoint (only on this machine: the server is started apart, e.g. scripts/playwright-mcp.sh install)
	    tools                       the tools the model may use (the server may list more; null: all of them)
	    max_sessions                most sessions at once on the whole installation (each run of an AI step opens one)
	    max_sessions_per_account    most at once per account
	]
*/

return [
    'playwright' => [
	'label' => 'Web browser',
	'description' => 'A headless Chromium (Playwright): open web pages, read them, click, type, fill in forms, take screenshots. Private network addresses are not reachable',
	//CLD: 2026-10-31T18:30:00+02:00: the installation's port (app.env PLAYWRIGHT_MCP_PORT, as scripts/playwright-mcp.sh), else 8931
	'url' => 'http://localhost:' . (defined('PLAYWRIGHT_MCP_PORT') && (int)PLAYWRIGHT_MCP_PORT > 0 ? (int)PLAYWRIGHT_MCP_PORT : 8931) . '/mcp',
	'headers' => [],
	//left out: browser_run_code_unsafe, browser_evaluate (run code), browser_file_upload, browser_drop (read the server's files), browser_network_request(s) (request headers and
	//bodies), browser_resize, browser_emulate_media, browser_console_messages, browser_handle_dialog (rarely needed: add them in servers.local.php)
	'tools' => [
	    'browser_navigate',
	    'browser_navigate_back',
	    'browser_snapshot',
	    'browser_find',
	    'browser_click',
	    'browser_hover',
	    'browser_type',
	    'browser_press_key',
	    'browser_fill_form',
	    'browser_select_option',
	    'browser_drag',
	    'browser_wait_for',
	    'browser_take_screenshot',
	    'browser_tabs',
	    'browser_close',
	],
	'max_sessions' => 4,
	'max_sessions_per_account' => 2,
    ],
];
