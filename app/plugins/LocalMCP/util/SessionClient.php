<?php

/*
    CLD: 2026-10-22T22:00:00+03:00: new: an AI step's session on a tool server (Plugin::servers): an MCP client (util\McpClient) holding a slot of the account and one of the installation
    (models\LocalMcpSlot) from open() until close(); the slots are renewed while tools are called, and freed also when the process ends without close()
*/

namespace plugins\LocalMCP\util;

use plugins\LocalMCP\models\LocalMcpSlot;

class SessionClient extends \util\McpClient {
    //how often the slots are renewed while the session is used (s)
    const RENEW = 60;

    protected $slots = [];
    protected $renewed = 0;

    //a session of server $id ($s: its definition) for account $aid, calls timing out after $timeout s; \ErrorException when its limits are reached
    public static function open($id, array $s, $aid, $timeout) {
	$perAccount = max(1, (int)($s['max_sessions_per_account'] ?? 1));
	$all = max(1, (int)($s['max_sessions'] ?? $perAccount));
	$mine = LocalMcpSlot::take("{$id}:{$aid}", $perAccount, $id, $aid);
	if ($mine === null)
	    throw new \ErrorException("this account already has {$perAccount} session(s) open, the most at once: try again when one finishes");
	$shared = LocalMcpSlot::take($id, $all, $id, '');
	if ($shared === null) {
	    LocalMcpSlot::free([ $mine ]);
	    throw new \ErrorException("the server is busy ({$all} session(s) open): try again later");
	}
	$c = new static((string)$s['url'], (array)($s['headers'] ?? []), $timeout);
	$c->slots = [ $mine, $shared ];
	$c->renewed = time();
	//freed also when the process ends without close() (an error, exit)
	register_shutdown_function(fn() => $c->release());
	return $c;
    }

    public function callTool($name, array $arguments = []) {
	if ($this->slots && time() - $this->renewed >= self::RENEW) {
	    $this->renewed = time();
	    LocalMcpSlot::renew($this->slots);
	}
	return parent::callTool($name, $arguments);
    }

    //ends the server's session, frees the slots
    public function close() {
	try {
	    parent::close();
	} finally {
	    $this->release();
	}
    }

    public function release() {
	if (!$this->slots)
	    return;
	$slots = $this->slots;
	$this->slots = [];
	LocalMcpSlot::free($slots);
    }
}
