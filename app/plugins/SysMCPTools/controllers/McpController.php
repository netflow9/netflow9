<?php

/*
    the project's MCP server (Model Context Protocol, Streamable HTTP transport, JSON answers - no SSE streams):
    POST /mcp (router rule + no session: the plugin's Plugin.php).
    Authorization: Bearer <token> (models\McpToken, made in the project): the call acts as the token's user on the token's project.
    JSON-RPC 2.0 (a message or a batch): initialize, ping, tools/list, tools/call (util\McpTools), notifications (answered 202, nothing to do).
    Stateless: no Mcp-Session-Id.
    A request from a browser page of another site (Origin header of another host) is refused (DNS rebinding)
*/
//CLD: 2026-10-20T23:40:00+03:00: moved to the SysMCPTools plugin (was controllers\McpController); POST /mcp is routed here by the plugin (Plugin: router rule, no session)
namespace plugins\SysMCPTools\controllers;

class McpController extends \sys\BaseController {

    //supported protocol versions, newest first (initialize answers the client's when supported, else the newest)
    const PROTOCOL_VERSIONS = [ '2025-06-18', '2025-03-26', '2024-11-05' ];

    //largest accepted request body (bytes)
    const MAX_BODY = 1048576;

    //last_used_at is written at most this often (seconds)
    const TOUCH_EVERY = 60;

    //called by MCP clients: no CSRF token, the bearer token is the credential
    public function validateRequest() {
    }

    //CLD: 2026-10-21T00:25:00+03:00: the answers are sent with $resp->sendJSON (JSON, no-store; ends the request), slashes and Unicode as they are (was reply())
    //CLD: 2026-10-21T00:35:00+03:00: the error answers made by fail() (were written here)
    public function serve($req, $resp) {
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	if ($method !== 'POST') {
	    //GET would open a server-to-client stream: this server has none
	    $resp->addHeader('Allow', 'POST');
	    $resp->sendJSON($this->fail(null, -32000, 'Method not allowed: use POST'), true, 405, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
	if ($origin !== '' && strcasecmp((string)parse_url($origin, PHP_URL_HOST), (string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) !== 0)
	    $resp->sendJSON($this->fail(null, -32000, 'Origin not allowed'), true, 403, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	//the token: its user signs in for this request only (no session), its project is the one the tools work on
	$token = preg_match('/^Bearer\s+(\S+)$/i', trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? '')), $m) ? \plugins\SysMCPTools\models\McpToken::findByToken($m[1]) : null;
	$project = $token ? \V::app()->om->findOne('Project', [ '_id' => $token->project, 'aid' => $token->aid ]) : null;
	$identity = new \sys\Identity();
	if (!$token || !$project || !$identity->authenticateById($token->user)) {
	    $resp->addHeader('WWW-Authenticate', 'Bearer realm="QSM MCP", error="invalid_token"');
	    $resp->sendJSON($this->fail(null, -32000, 'A valid token is required (Authorization: Bearer <token>)'), true, 401, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
	\V::app()->user->crtProject = $project->_id;
	\V::app()->user->login($identity);
	if ((string)\V::app()->var('rt.account')->_id !== (string)$token->aid)
	    $resp->sendJSON($this->fail(null, -32000, 'A valid token is required'), true, 401, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$used = $token->last_used_at instanceof \MongoDB\BSON\UTCDateTime ? $token->last_used_at->toDateTime()->getTimestamp() : 0;
	if (time() - $used >= self::TOUCH_EVERY)
	    \V::app()->om->updateMany('McpToken', [ '_id' => $token->_id ], [ '$set' => [ 'last_used_at' => new \MongoDB\BSON\UTCDateTime() ] ]);

	$version = (string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '');
	if ($version !== '' && !in_array($version, self::PROTOCOL_VERSIONS, true))
	    $resp->sendJSON($this->fail(null, -32600, "Unsupported MCP-Protocol-Version {$version}"), true, 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY)
	    $resp->sendJSON($this->fail(null, -32600, 'Request too large'), true, 413, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$body = json_decode((string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1), true);
	if (!is_array($body) || $body === [])
	    $resp->sendJSON($this->fail(null, -32700, 'Parse error: a JSON-RPC message (or a list of them) is expected'), true, 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	//a batch (list) or one message; notifications and responses get no answer
	$answers = [];
	foreach(array_is_list($body) ? $body : [ $body ] as $msg) {
	    $answer = is_array($msg) ? $this->handle($msg, $project) : $this->fail(null, -32600, 'Invalid request');
	    if ($answer !== null)
		$answers[] = $answer;
	}
	if (!$answers) {
	    $resp->status = 202;
	    \V::app()->end();
	}
	$resp->sendJSON(array_is_list($body) ? $answers : $answers[0], true, 200, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    //the answer to message $msg, null for notifications / responses
    private function handle(array $msg, $project) {
	$isRequest = array_key_exists('id', $msg) && isset($msg['method']);
	if (!$isRequest) {
	    if (isset($msg['method']) || array_key_exists('result', $msg) || array_key_exists('error', $msg))
		return null;
	    return $this->fail(null, -32600, 'Invalid request');
	}
	$id = $msg['id'];
	$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
	//CLD: 2026-10-21T00:35:00+03:00: the answers are made by ok() / fail() (were the closures $ok / $fail)

	switch((string)$msg['method']) {
	    case 'initialize':
		$asked = (string)($params['protocolVersion'] ?? '');
		return $this->ok($id, [
		    'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
		    'capabilities' => [ 'tools' => [ 'listChanged' => false ] ],
		    'serverInfo' => [ 'name' => 'qsm', 'title' => 'QSM workflows', 'version' => '1.0' ],
		    'instructions' => "Tools of the QSM project `{$project->name}`: its data tables (list, query, insert / update / delete rows), workflows (list, run, executions), files (list, read) and variables. Use list_tables / list_workflows first to learn the names.",
		]);

	    case 'ping':
		return $this->ok($id, (object)[]);

	    case 'tools/list':
		return $this->ok($id, [ 'tools' => \plugins\SysMCPTools\util\McpTools::definitions() ]);

	    case 'tools/call':
		$name = (string)($params['name'] ?? '');
		if (!in_array($name, array_column(\plugins\SysMCPTools\util\McpTools::definitions(), 'name'), true))
		    return $this->fail($id, -32602, "Unknown tool: {$name}");
		$args = $params['arguments'] ?? [];
		if (!is_array($args) || ($args && array_is_list($args)))
		    return $this->fail($id, -32602, 'arguments: an object is expected');
		try {
		    $result = \plugins\SysMCPTools\util\McpTools::call($name, $args, $project);
		    [ $result, $images ] = \plugins\SysMCPTools\util\McpTools::images($result);
		    $content = [ [ 'type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ] ];
		    foreach($images as [ $mime, $data ])
			$content[] = [ 'type' => 'image', 'data' => $data, 'mimeType' => $mime ];
		    return $this->ok($id, [
			'content' => $content,
			'structuredContent' => (object)$result,
			'isError' => false,
		    ]);
		} catch (\ErrorException $e) {
		    //a mistake the model can correct: told as the tool's result
		    return $this->ok($id, [ 'content' => [ [ 'type' => 'text', 'text' => $e->getMessage() ] ], 'isError' => true ]);
		} catch (\Throwable $e) {
		    \V::app()->log->error("MCP {$name}: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
		    return $this->ok($id, [ 'content' => [ [ 'type' => 'text', 'text' => 'The tool failed: ' . $e->getMessage() ] ], 'isError' => true ]);
		}
	}
	return $this->fail($id, -32601, 'Method not found: ' . (string)$msg['method']);
    }

    private function ok($id, $result) {
	return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
    }

    private function fail($id, $code, $message) {
	return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ];
    }
}
