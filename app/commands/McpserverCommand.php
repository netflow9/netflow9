<?php

namespace commands;

use util\ToolServers;
use models\ToolServerAccess;
use models\ToolServerSlot;

//CLD: 2026-10-22T22:00:00+03:00: new: the installation's tool servers (util\ToolServers) and the accounts that may use them
//CLD: 2026-11-01T11:55:18+02:00: core: ./v mcpserver::<action>; the servers are in
//mcp.json (next to app.env): + add / remove (the installers use them, e.g. scripts/playwright-mcp.sh: the only writers of the file, with Settings > Tool servers)
//  ../v mcpserver::list                                  the servers: reachable?, the accounts enabled, the sessions open
//  ../v mcpserver::add --id=<id> [--file=<a JSON entry>] [--url=] [--label=] [--description=] [--tools=a,b,...] [--headers=<a JSON object>] [--max-sessions=]
//                      [--max-sessions-per-account=] [--service=<its unit's name>] [--managed-by=<the installer>]
//                                                        adds or replaces server <id> in mcp.json: the file's entry (as in mcp.json), the options over it
//  ../v mcpserver::remove --id=<id> [--managed-by=<the installer>]   removes it (one an installer wrote: only with its managedBy)
//  ../v mcpserver::enable --account=<id> --server=<id>    the account's AI steps may use the server
//  ../v mcpserver::disable --account=<id> --server=<id>
//  ../v mcpserver::test [--server=<id>]                   connects, lists the tools (those offered marked); a web browser: opens a public page, not a private address
class McpserverCommand extends \sys\BaseController {

    public function list($req = null, $resp = null) {
	if (($problem = ToolServers::fileError()) !== null)
	    echo "! {$problem}\n";
	$open = ToolServerSlot::open();
	foreach(ToolServers::all() as $id => $s) {
	    [ $ok, $text ] = ToolServers::ping($s);
	    echo "{$id}: {$s['label']} - {$s['url']} - " . ($ok ? "up ({$text})" : "DOWN: {$text}") . (!empty($s['managed_by']) ? " - written by {$s['managed_by']}" : '') . "\n";
	    echo '  tools: ' . ($s['tools'] === null ? 'all' : count($s['tools'])) . ', at most ' . (int)$s['max_sessions'] . ' session(s) at once, ' . (int)$s['max_sessions_per_account'] . " per account\n";
	    $aids = ToolServerAccess::accountsOf($id);
	    echo '  accounts: ' . ($aids ? '' : 'none') . "\n";
	    foreach($aids as $aid) {
		$acct = \V::app()->om->findById('Account', $aid);
		echo "    {$aid}" . ($acct && (string)$acct->name !== '' ? " ({$acct->name})" : '') . (isset($open[$id][$aid]) ? ", {$open[$id][$aid]} session(s) open" : '') . "\n";
	    }
	}
	if (!ToolServers::all())
	    echo 'no tool servers (' . ToolServers::file() . "; the web browser: sudo scripts/playwright-mcp.sh install)\n";
    }

    public function add($req = null, $resp = null) {
	$p = (array)($req->params ?? []);
	$id = (string)($p['id'] ?? '');
	$in = [];
	if (isset($p['file'])) {
	    $in = json_decode((string)@file_get_contents((string)$p['file']), true);
	    if (!is_array($in))
		$this->fail("--file: {$p['file']} is not a JSON object");
	} elseif (($old = ToolServers::all()[$id] ?? null) !== null) {
	    //options over the server as it is
	    $in = [ 'url' => $old['url'], 'headers' => $old['headers'], 'label' => $old['label'], 'description' => $old['description'], 'tools' => $old['tools'],
		'maxSessions' => $old['max_sessions'], 'maxSessionsPerAccount' => $old['max_sessions_per_account'], 'service' => $old['service'], 'managedBy' => $old['managed_by'] ];
	}
	foreach([ 'url' => 'url', 'label' => 'label', 'description' => 'description', 'tools' => 'tools', 'max-sessions' => 'maxSessions',
	    'max-sessions-per-account' => 'maxSessionsPerAccount', 'service' => 'service', 'managed-by' => 'managedBy' ] as $opt => $key)
	    if (isset($p[$opt]))
		$in[$key] = (string)$p[$opt];
	if (isset($p['headers'])) {
	    $in['headers'] = json_decode((string)$p['headers'], true);
	    if (!is_array($in['headers']))
		$this->fail('--headers: a JSON object, e.g. {"Authorization": "Bearer ..."}');
	}
	[ $entry, $problem ] = ToolServers::validate($id, $in);
	if ($problem !== null)
	    $this->fail("--id={$id}: {$problem}");
	try {
	    ToolServers::put($id, $entry);
	} catch (\ErrorException $e) {
	    $this->fail($e->getMessage());
	}
	echo "{$id}: {$entry['label']} - {$entry['url']} written to " . ToolServers::file() . "\n";
    }

    public function remove($req = null, $resp = null) {
	$id = (string)($req->params['id'] ?? '');
	$s = ToolServers::all()[$id] ?? null;
	if (!$s) {
	    echo "{$id}: not in " . ToolServers::file() . "\n";
	    return;
	}
	if (!empty($s['managed_by']) && $s['managed_by'] !== (string)($req->params['managed-by'] ?? ''))
	    $this->fail("{$id} was written by {$s['managed_by']}: remove it with its uninstall (or --managed-by={$s['managed_by']})");
	try {
	    ToolServers::remove($id);
	} catch (\ErrorException $e) {
	    $this->fail($e->getMessage());
	}
	$aids = ToolServerAccess::accountsOf($id);
	echo "{$id} removed" . ($aids ? '; ' . count($aids) . ' account(s) had it enabled: their AI steps that use it fail until it is removed from them (or added again)' : '') . "\n";
    }

    public function enable($req = null, $resp = null) {
	[ $aid, $id ] = $this->target($req);
	ToolServerAccess::set($aid, $id, true);
	echo "account {$aid} may use {$id}: " . implode(', ', ToolServerAccess::serversOf($aid)) . "\n";
    }

    public function disable($req = null, $resp = null) {
	[ $aid, $id ] = $this->target($req);
	ToolServerAccess::set($aid, $id, false);
	$left = ToolServerAccess::serversOf($aid);
	echo "account {$aid} may no longer use {$id}" . ($left ? ': ' . implode(', ', $left) . ' left' : '') . "; AI steps that use it fail until it is removed from them\n";
    }

    //connects to each server (or --server), lists its tools, then runs the server's own checks (its definition's `test`)
    public function test($req = null, $resp = null) {
	$only = (string)($req->params['server'] ?? '');
	$failed = 0;
	foreach(ToolServers::all() as $id => $s) {
	    if ($only !== '' && $id !== $only)
		continue;
	    [ $ok, $lines, $tools ] = ToolServers::test($s);
	    echo "{$id}: " . array_shift($lines) . "\n";
	    foreach($tools as $t)
		echo '  ' . ($s['tools'] === null || in_array($t, $s['tools'], true) ? '+' : '-') . " {$t}\n";
	    foreach($lines as $l)
		echo "  {$l}\n";
	    $failed += (int)!$ok;
	}
	exit($failed ? 1 : 0);
    }

    //--account (an account) and --server (a tool server)
    private function target($req) {
	$aid = (string)($req->params['account'] ?? '');
	$acct = preg_match('/^[a-f0-9]{24}$/', $aid) ? \V::app()->om->findById('Account', $aid) : null;
	if (!$acct)
	    $this->fail('--account=<id>: an account');
	$id = (string)($req->params['server'] ?? '');
	if (!ToolServers::get($id))
	    $this->fail('--server=<id>: one of ' . (implode(', ', array_keys(ToolServers::all())) ?: '(none: ' . ToolServers::file() . ')'));
	return [ (string)$acct->_id, $id ];
    }

    private function fail($message) {
	echo $message . "\n";
	exit(1);
    }
}
