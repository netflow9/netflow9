<?php

namespace plugins\LocalMCP\commands;

use plugins\LocalMCP\Plugin;
use plugins\LocalMCP\models\LocalMcpAccess;
use plugins\LocalMCP\models\LocalMcpSlot;

//CLD: 2026-10-22T22:00:00+03:00: new: the installation's tool servers (Plugin::servers) and the accounts that may use them
//  ../v LocalMCP::servers::list                                  the servers: reachable?, the accounts enabled, the sessions open
//  ../v LocalMCP::servers::enable --account=<id> --server=<id>    the account's AI steps may use the server
//  ../v LocalMCP::servers::disable --account=<id> --server=<id>
//  ../v LocalMCP::servers::test [--server=<id>]                   connects, lists the tools (those offered marked), opens a page when it is a browser
class ServersCommand extends \sys\BaseController {

    public function list($req = null, $resp = null) {
	$open = LocalMcpSlot::open();
	foreach(Plugin::servers() as $id => $s) {
	    echo "{$id}: {$s['label']} - {$s['url']} - " . $this->ping($s) . "\n";
	    echo '  at most ' . (int)($s['max_sessions'] ?? 1) . ' session(s) at once, ' . (int)($s['max_sessions_per_account'] ?? 1) . " per account\n";
	    $aids = LocalMcpAccess::accountsOf($id);
	    echo '  accounts: ' . ($aids ? '' : 'none') . "\n";
	    foreach($aids as $aid) {
		$acct = \V::app()->om->findById('Account', $aid);
		echo "    {$aid}" . ($acct && (string)$acct->name !== '' ? " ({$acct->name})" : '') . (isset($open[$id][$aid]) ? ", {$open[$id][$aid]} session(s) open" : '') . "\n";
	    }
	}
	if (!Plugin::servers())
	    echo "no servers (plugins/LocalMCP/servers.php)\n";
    }

    public function enable($req = null, $resp = null) {
	[ $aid, $id ] = $this->target($req);
	LocalMcpAccess::set($aid, $id, true);
	echo "account {$aid} may use {$id}: " . implode(', ', LocalMcpAccess::serversOf($aid)) . "\n";
    }

    public function disable($req = null, $resp = null) {
	[ $aid, $id ] = $this->target($req);
	LocalMcpAccess::set($aid, $id, false);
	$left = LocalMcpAccess::serversOf($aid);
	echo "account {$aid} may no longer use {$id}" . ($left ? ': ' . implode(', ', $left) . ' left' : '') . "; AI steps that use it fail until it is removed from them\n";
    }

    //connects to each server (or --server), lists its tools; a browser (a browser_navigate tool) opens https://example.com, then a private address, which must fail
    public function test($req = null, $resp = null) {
	$only = (string)($req->params['server'] ?? '');
	$failed = 0;
	foreach(Plugin::servers() as $id => $s) {
	    if ($only !== '' && $id !== $only)
		continue;
	    echo "{$id}: ";
	    $c = new \util\McpClient((string)$s['url'], (array)($s['headers'] ?? []), 90);
	    try {
		$info = $c->initialize();
		$tools = array_column($c->listTools(), 'name');
		$offered = isset($s['tools']) ? (array)$s['tools'] : $tools;
		echo ($info['serverInfo']['name'] ?? '?') . ' ' . ($info['serverInfo']['version'] ?? '') . ', ' . count($tools) . " tools\n";
		foreach($tools as $t)
		    echo '  ' . (in_array($t, $offered, true) ? '+' : '-') . " {$t}\n";
		foreach(array_diff($offered, $tools) as $t)
		    echo "  ! {$t}: offered, but the server does not have it\n";
		if (in_array('browser_navigate', $tools, true)) {
		    $r = $c->callTool('browser_navigate', [ 'url' => 'https://example.com' ]);
		    $ok = empty($r['isError']) && str_contains(json_encode($r), 'Example Domain');
		    echo '  open https://example.com: ' . ($ok ? 'OK' : 'FAILED ' . mb_substr(json_encode($r), 0, 300)) . "\n";
		    $r = $c->callTool('browser_navigate', [ 'url' => 'http://169.254.169.254/' ]);
		    $blocked = !empty($r['isError']) || !str_contains(json_encode($r), 'HTTP status: 200');
		    echo '  open http://169.254.169.254 (private, must fail): ' . ($blocked ? 'blocked' : 'NOT BLOCKED: is the firewall loaded (scripts/playwright-mcp.sh status)?') . "\n";
		    $failed += (int)!$ok + (int)!$blocked;
		}
	    } catch (\Exception $e) {
		echo 'FAILED: ' . $e->getMessage() . "\n";
		$failed++;
	    } finally {
		try {
		    $c->close();
		} catch (\Exception $e) {
		}
	    }
	}
	exit($failed ? 1 : 0);
    }

    //whether server $s answers (initialize)
    private function ping(array $s) {
	$c = new \util\McpClient((string)$s['url'], (array)($s['headers'] ?? []), 10);
	try {
	    $info = $c->initialize();
	    $c->close();
	    return 'up (' . ($info['serverInfo']['name'] ?? '?') . ' ' . ($info['serverInfo']['version'] ?? '') . ')';
	} catch (\Exception $e) {
	    return 'DOWN: ' . $e->getMessage();
	}
    }

    //--account (an account) and --server (a server of servers.php)
    private function target($req) {
	$aid = (string)($req->params['account'] ?? '');
	$acct = preg_match('/^[a-f0-9]{24}$/', $aid) ? \V::app()->om->findById('Account', $aid) : null;
	if (!$acct)
	    $this->fail('--account=<id>: an account');
	$id = (string)($req->params['server'] ?? '');
	if (!isset(Plugin::servers()[$id]))
	    $this->fail('--server=<id>: one of ' . (implode(', ', array_keys(Plugin::servers())) ?: '(none: plugins/LocalMCP/servers.php)'));
	return [ (string)$acct->_id, $id ];
    }

    private function fail($message) {
	echo $message . "\n";
	exit(1);
    }
}
