<?php

/*
    the installation's tool servers for the AI steps: MCP servers run on this machine (the first: a web browser, Playwright MCP), offered to the
    AI steps (Tool servers) of the accounts the administrator enables.
    Enabled in app/plugins.php.
      servers.php                        the servers (servers.local.php: the installation's own)
      models/LocalMcpAccess.php          the servers an account may use (./v LocalMCP::servers::enable / disable)
      models/LocalMcpSlot.php            the sessions open at once (each server's limits, per account and in all)
      util/SessionClient.php             an AI step's session: an MCP client that holds its slots until it is closed
      commands/ServersCommand.php        ./v LocalMCP::servers::<list | enable | disable | test>
    The servers themselves are started apart: scripts/playwright-mcp.sh install (a system service, its own user, no access to private addresses)
*/

namespace plugins\LocalMCP;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'LocalMcpAccess' => [
		'class' => __NAMESPACE__ . '\\models\\LocalMcpAccess',
	    ],
	    'LocalMcpSlot' => [
		'class' => __NAMESPACE__ . '\\models\\LocalMcpSlot',
	    ],
	]);

	//the servers of the account, for the AI steps (models\node\NodeAppAi::localServers): also in the console (the daemon runs the executions)
	\V::app()->hooks->addHook('ai:mcp-servers', function(\ArrayObject $servers, $aid) {
	    $enabled = models\LocalMcpAccess::serversOf($aid);
	    foreach(self::servers() as $id => $s) {
		if (!in_array($id, $enabled, true))
		    continue;
		$servers[$id] = [
		    'id' => $id,
		    'label' => (string)$s['label'],
		    'description' => (string)($s['description'] ?? ''),
		    'tools' => isset($s['tools']) ? array_values((array)$s['tools']) : null,
		    'open' => fn($timeout) => util\SessionClient::open($id, $s, $aid, (int)$timeout),
		];
	    }
	});

	//CLD: 2026-10-22T23:00:00+03:00: the administration (the Admin plugin): the browser service, the servers per account (a switch each), the sessions open
	\V::app()->hooks->addHook('admin:services', function(\ArrayObject $services) {
	    $pw = self::servers()['playwright'] ?? null;
	    if (!$pw)
		return;
	    $services['playwright-mcp'] = [
		'label' => 'Web browser (Playwright MCP)',
		'description' => 'The browser the AI steps use (Tool servers)',
		//CLD: 2026-10-29T13:00:00+02:00: nfn-playwright (was playwright-mcp), as the app's other units
		'unit' => 'nfn-playwright.service',
		'pidfile' => null,
		'health' => function() use ($pw) {
		    $c = new \util\McpClient((string)$pw['url'], (array)($pw['headers'] ?? []), 5);
		    try {
			$info = $c->initialize();
			$c->close();
			return [ true, 'MCP answers: ' . ($info['serverInfo']['name'] ?? '?') . ' ' . ($info['serverInfo']['version'] ?? '') ];
		    } catch (\Exception $e) {
			return [ false, 'MCP does not answer' ];
		    }
		},
	    ];
	});
	\V::app()->hooks->addHook('admin:account-summary', function($account, \ArrayObject $items) {
	    $on = models\LocalMcpAccess::serversOf($account->_id);
	    if ($on)
		$items[] = 'tools: ' . implode(', ', array_map(fn($id) => self::servers()[$id]['label'] ?? $id, $on));
	});
	//CLD: 2026-10-24T10:06:05+03:00: with the account's features (admin:account-features; was admin:account-panels, after the other plugins' sections)
	\V::app()->hooks->addHook('admin:account-features', function($account) {
	    echo \V::app()->view->renderFile('/plugins/LocalMCP/views/admin/account_panel', [ 'account' => $account, 'servers' => self::servers(),
		'enabled' => models\LocalMcpAccess::serversOf($account->_id), 'open' => models\LocalMcpSlot::open() ]);
	});
	\V::app()->hooks->addHook('admin:installation-panels', function() {
	    echo \V::app()->view->renderFile('/plugins/LocalMCP/views/admin/installation_panel', [ 'servers' => self::servers(), 'open' => models\LocalMcpSlot::open() ]);
	});
    }

    //the servers of the installation (servers.php, or servers.local.php when present): [ id => definition ]
    public static function servers() {
	static $servers = null;
	if ($servers === null) {
	    $f = __DIR__ . '/servers.local.php';
	    $servers = require(is_file($f) ? $f : __DIR__ . '/servers.php');
	    $servers = is_array($servers) ? array_filter($servers, fn($s, $id) => preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string)$id) && !empty($s['url']), ARRAY_FILTER_USE_BOTH) : [];
	}
	return $servers;
    }
}
