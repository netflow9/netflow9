<?php

//CLD: 2026-10-12T21:51:51+03:00: new class: a client of an MCP server over Streamable HTTP (for models\credential\CredentialMcp and the AI steps): JSON-RPC requests by POST,
//answers as JSON or as an SSE stream (text/event-stream: the message with the request's id is taken), the server's Mcp-Session-Id kept and sent back,
//MCP-Protocol-Version sent after initialize. initialize() first, then listTools() / callTool(); close() ends the session. Errors: \Exception with a message for the user
//  $c = new \util\McpClient('https://host/mcp', [ 'Authorization' => 'Bearer ...' ]);
//  $info = $c->initialize(); $tools = $c->listTools(); $result = $c->callTool('name', [ ... ]); $c->close();

namespace util;

class McpClient {
    const PROTOCOL_VERSION = '2025-06-18';
    //largest answer read (bytes)
    const MAX_RESPONSE = 5242880;
    //most tool list pages read
    const MAX_PAGES = 20;

    public $url;
    public $headers;
    public $timeout;
    //from initialize: the server's { name, version, ... }, the protocol version agreed, its instructions
    public $serverInfo = [];
    public $protocolVersion = null;
    public $instructions = null;

    protected $sessionId = null;
    protected $nextId = 1;

    public function __construct($url, array $headers = [], $timeout = 30) {
	$this->url = (string)$url;
	$this->headers = $headers;
	$this->timeout = (int)$timeout;
    }

    //the handshake: initialize + notifications/initialized; returns the initialize result
    public function initialize() {
	$result = $this->request('initialize', [
	    'protocolVersion' => self::PROTOCOL_VERSION,
	    'capabilities' => (object)[],
	    'clientInfo' => [ 'name' => 'qsm', 'title' => 'QSM workflows', 'version' => '1.0' ],
	]);
	$this->protocolVersion = (string)($result['protocolVersion'] ?? self::PROTOCOL_VERSION);
	$this->serverInfo = is_array($result['serverInfo'] ?? null) ? $result['serverInfo'] : [];
	$this->instructions = isset($result['instructions']) ? (string)$result['instructions'] : null;
	$this->notify('notifications/initialized');
	return $result;
    }

    //all the server's tools (every page): [ { name, description, inputSchema, ... } ]
    //CLD: 2026-10-12T22:16:44+03:00: inputSchema is kept as decoded JSON objects (stdClass): sent on to a model as it came (an empty `properties` stays {}, not [])
    public function listTools() {
	$tools = [];
	$cursor = null;
	for($page = 0; $page < self::MAX_PAGES; $page++) {
	    $result = $this->request('tools/list', $cursor !== null ? [ 'cursor' => $cursor ] : (object)[], false);
	    foreach((array)($result->tools ?? []) as $t) {
		if (is_object($t) && isset($t->name) && is_string($t->name))
		    $tools[] = [ 'name' => $t->name, 'title' => isset($t->title) ? (string)$t->title : null, 'description' => isset($t->description) ? (string)$t->description : '',
			'inputSchema' => is_object($t->inputSchema ?? null) ? $t->inputSchema : (object)[ 'type' => 'object', 'properties' => (object)[] ] ];
	    }
	    $cursor = $result->nextCursor ?? null;
	    if (!is_string($cursor) || $cursor === '')
		break;
	}
	return $tools;
    }

    //calls tool $name: the result { content: [...], structuredContent?, isError }
    public function callTool($name, array $arguments = []) {
	return $this->request('tools/call', [ 'name' => (string)$name, 'arguments' => (object)$arguments ]);
    }

    //ends the server's session, when it gave one (DELETE; a refusal is fine)
    public function close() {
	if ($this->sessionId === null)
	    return;
	$this->send('DELETE', null);
	$this->sessionId = null;
    }

    //a request: its result, or \Exception (the server's JSON-RPC error)
    //CLD: 2026-10-12T22:16:44+03:00: $assoc = false: the result as decoded JSON objects (stdClass), as json_decode without assoc
    public function request($method, $params = null, $assoc = true) {
	$id = $this->nextId++;
	$msg = [ 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method ];
	if ($params !== null)
	    $msg['params'] = $params;
	[ $status, $type, $body ] = $this->send('POST', $msg);
	if ($status >= 400 && !str_contains($type, 'json') && !str_contains($type, 'event-stream'))
	    throw new \Exception("{$method}: HTTP status {$status}");

	$answer = null;
	$raw = $body;
	if (str_contains($type, 'text/event-stream')) {
	    //SSE: events separated by a blank line; the data lines of an event form one JSON-RPC message
	    foreach(preg_split('/\r?\n\r?\n/', $body) as $event) {
		$data = [];
		foreach(preg_split('/\r?\n/', $event) as $line) {
		    if (str_starts_with($line, 'data:'))
			$data[] = ltrim(substr($line, 5), ' ');
		}
		$m = $data ? json_decode(implode("\n", $data), true) : null;
		if (is_array($m) && ($m['id'] ?? null) === $id && (array_key_exists('result', $m) || array_key_exists('error', $m))) {
		    $answer = $m;
		    $raw = implode("\n", $data);
		    break;
		}
	    }
	} else {
	    $m = json_decode($body, true);
	    if (is_array($m) && array_is_list($m)) {
		foreach($m as $one) {
		    if (is_array($one) && ($one['id'] ?? null) === $id)
			$answer = $one;
		}
	    } else {
		$answer = $m;
	    }
	}
	if (!is_array($answer))
	    throw new \Exception("{$method}: " . ($status >= 400 ? "HTTP status {$status}" : 'no JSON-RPC answer (is the URL an MCP server?)'));
	if (isset($answer['error'])) {
	    $e = $answer['error'];
	    throw new \Exception("{$method}: " . (is_array($e) ? (string)($e['message'] ?? 'error') . (isset($e['code']) ? " ({$e['code']})" : '') : (string)$e));
	}
	if (!$assoc) {
	    $o = json_decode($raw);
	    if (is_array($o)) {
		foreach($o as $one) {
		    if (is_object($one) && ($one->id ?? null) === $id)
			$o = $one;
		}
	    }
	    return is_object($o->result ?? null) ? $o->result : (object)[];
	}
	return is_array($answer['result'] ?? null) ? $answer['result'] : [];
    }

    //a notification (no answer expected)
    public function notify($method, $params = null) {
	$msg = [ 'jsonrpc' => '2.0', 'method' => $method ];
	if ($params !== null)
	    $msg['params'] = $params;
	$this->send('POST', $msg);
    }

    //an HTTP exchange: [ status, content type, body ]; keeps the session id the server gives
    protected function send($method, $msg) {
	$lines = [ 'Accept: application/json, text/event-stream', 'User-Agent: QSM-Workflow' ];
	if ($msg !== null)
	    $lines[] = 'Content-Type: application/json';
	if ($this->protocolVersion !== null)
	    $lines[] = 'MCP-Protocol-Version: ' . $this->protocolVersion;
	if ($this->sessionId !== null)
	    $lines[] = 'Mcp-Session-Id: ' . $this->sessionId;
	foreach($this->headers as $name => $value)
	    $lines[] = $name . ': ' . str_replace([ "\r", "\n" ], ' ', (string)$value);

	$body = '';
	$tooLarge = false;
	$respHeaders = [];
	$ch = curl_init($this->url);
	curl_setopt_array($ch, [
	    CURLOPT_CUSTOMREQUEST => $method,
	    CURLOPT_HTTPHEADER => $lines,
	    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
	    CURLOPT_TIMEOUT => $this->timeout,
	    CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$respHeaders) {
		if (($p = strpos($line, ':')) !== false)
		    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
		return strlen($line);
	    },
	    CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$body, &$tooLarge) {
		if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE) {
		    $tooLarge = true;
		    return 0;
		}
		$body .= $chunk;
		return strlen($chunk);
	    },
	]);
	if ($msg !== null)
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    curl_setopt($ch, CURLOPT_POSTFIELDS, \util\JSON::encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	$ok = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = $ok === false && !$tooLarge ? curl_error($ch) : '';
	curl_close($ch);
	if ($tooLarge)
	    throw new \Exception('the MCP server answered more than ' . (self::MAX_RESPONSE / 1048576) . ' MB');
	if ($error !== '')
	    throw new \Exception('no answer from ' . preg_replace('/[?#].*$/', '', $this->url) . ": {$error}");
	//CLD: 2026-10-23T00:20:00+03:00: the credential named only when one was sent (an Authorization or another header given); the server's reason added (was always "refused the
	//credential", also for a server that refuses the Host header, e.g. Playwright MCP: "Access is only allowed at localhost:8931")
	if ($status === 401 || $status === 403) {
	    $reason = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$body)), 0, 200));
	    throw new \Exception('the MCP server refused ' . ($this->headers ? 'the credential' : 'the request') . " (HTTP status {$status})" . ($reason !== '' ? ": {$reason}" : ''));
	}
	if (isset($respHeaders['mcp-session-id']) && $respHeaders['mcp-session-id'] !== '')
	    $this->sessionId = $respHeaders['mcp-session-id'];
	return [ $status, strtolower((string)($respHeaders['content-type'] ?? '')), $body ];
    }
}
