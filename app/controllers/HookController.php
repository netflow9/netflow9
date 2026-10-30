<?php

//public endpoint of the webhook triggers (/hook/<token>)

namespace controllers;

use \models\node\NodeTriggerWebhook;

class HookController extends \sys\BaseController {

    //largest accepted request body (bytes)
    const MAX_BODY = 1048576;

    //CLD: 2026-10-17T10:46:42+03:00: limits of the request variable (were MAX_VARS, the number of query_ / body_ variables): headers kept, levels of query / body data
    const MAX_HEADERS = 100;
    const MAX_DEPTH = 32;

    //called by other systems: no CSRF token, the URL's secret token is the credential
    public function validateRequest() {
    }

    //the workflow of the token, once known: refusals are written to its account's execution log (refuse)
    private $wf = null;

    public function receive($req, $resp) {
	//webhook trigger of the token, still present in its workflow
	$token = (string)($req->query['id'] ?? '');
	//CLD: 2026-10-18T15:00:00+03:00: TriggerToken::isToken (was NodeTriggerWebhook::isToken)
	if (!\models\TriggerToken::isToken($token, 'hex'))
	    $this->refuse(404, 'Not found');


	$hook = \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::webhook', 'token' => $token ]);
	$wf = $hook ? \V::app()->om->findOne('Workflow', [ '_id' => $hook->wf, 'aid' => $hook->aid ]) : null;
	$this->wf = $wf;
	//CLD: 2026-10-11T13:51:42+03:00: the step of the published version (the index is built from it; the workflow's nodes are the draft): a copy of the
	//workflow holding that version's steps (Workflow::forVersion) is validated and started below
	$version = $wf ? $wf->publishedVersion() : null;
	$live = $version ? $wf->forVersion($version) : null;
	$node = null;
	foreach((is_array($live->nodes ?? null) ? $live->nodes : []) as $n) {
	    if (($n['wfid'] ?? null) == $hook->node && ($n['config']['token'] ?? null) === $token)
		$node = $n;
	}
	if (empty($node))
	    $this->refuse(404, 'Not found');

	\V::app()->var('rt.account', \V::app()->om->findById('Account', $hook->aid));
	$cfg = $node['config'];

	//CLD: 2026-10-07T11:36:11+03:00: size first (the HMAC check reads the body), then authentication, before anything else is revealed
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY)
	    $this->refuse(413, 'Request body too large');


	$this->authenticate($cfg);

	//method
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	$allowed = $cfg['method'] ?? 'POST';
	if ($allowed !== 'ANY' && $method !== $allowed) {
	    $resp->addHeader('Allow', $allowed);
	    $this->refuse(405, "Method not allowed, use {$allowed}");
	}

	//body by content type (not checked for GET)
	$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
	[ $bodyText, $bodyData ] = ($method === 'GET') ? [ '', null ] : $this->readBody($cfg['contentType'] ?? 'json', $contentType, $method);

	//CLD: 2026-10-11T13:29:36+03:00: drafts are not started by their triggers (checked after authentication: nothing is revealed before)
	if ($wf->status !== \models\Workflow::PUBLISHED)
	    $this->refuse(409, 'The workflow is not published');


	//the workflow must be able to run
	//CLD: 2026-10-11T13:51:42+03:00: the published version (was the workflow's nodes)
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $this->refuse(409, 'The workflow has errors and cannot run');

	//start: the request is kept as JSON text (any keys allowed)
	//CLD: 2026-10-17T10:46:42+03:00: the whole request goes into one variable, an object named by the step's `variable` setting (default `webhook`; were webhook_method,
	//query_<name>, body_<name>)
	$query = $_GET;
	$name = (string)($cfg['variable'] ?? 'webhook');
	if (!\util\Validator::isIdentifier($name))
	    $name = 'webhook';

	$received = new \MongoDB\BSON\UTCDateTime();
	$vars = [ $name => [
	    'method' => $method,
	    'path' => '/hook/' . $token,
	    //CLD: 2026-10-18T23:07:51+03:00: V::createAbsoluteUrl (was NodeTriggerWebhook::urlFor, removed)
	    'url' => \V::app()->createAbsoluteUrl('/hook/' . $token) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''),
	    'query' => $this->clean($query),
	    'headers' => $this->requestHeaders($cfg),
	    'content_type' => $contentType,
	    'body' => is_array($bodyData) ? $this->clean($bodyData) : $bodyText,
	    'raw' => $bodyText,
	    'ip' => (string)\V::app()->request->userIP,
	    'received_at' => $received->toDateTime()->format(DATE_ATOM),
	] ];

	$input = [
	    'method' => $method,
	    'contentType' => $contentType,
	    'query' => json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
	    'body' => $bodyText,
	    'received_at' => $received,
	    'vars' => $vars,
	];

	//CLD: 2026-10-10T19:08:46+03:00: started at this webhook step only (was every trigger step of the workflow: Manual, Schedule, IMAP, ... ran too)
	//CLD: 2026-10-11T13:51:42+03:00: the published version (records its number)
	//CLD: 2026-10-30T15:00:00+02:00: always run by the daemon (queued); answered with its result: waits for it (WfExecution::waitFor, at most WAIT_RESULT s; was run here, in a sync slot:
	//429 when the account's were all running)
	$respWhenFinished = ($cfg['respond'] ?? 'received') === 'finished';

	$exe = \models\WfExecution::startFor($live, 'webhook', $input, $hook->node);
	$exe->log($hook->node, "webhook {$method} received");
	$exe->queue();

	if ($respWhenFinished) {
	    //CLD: 2026-10-26T09:58:28+02:00: still running: the daemon finishes it, no result to answer with
	    if ($exe->waitFor(\models\WfExecution::WAIT_RESULT) === 'queued')
		$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'queued' ], true, 202);
	    $answer = [ 'execution' => (string)$exe->_id, 'state' => $exe->state ];
	    if ($exe->state == 'error')
		$answer['error'] = $exe->error;
	    $resp->sendJSON($answer);
	}

	//answer now
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ], true, 202);
    }

    //CLD: 2026-10-07T11:36:11+03:00: request authentication of the node (none / header / bearer / basic / hmac); replies 401 when it fails;
    //fails closed: a mode without its secret refuses every request
    private function authenticate(array $cfg) {
	$mode = $cfg['auth'] ?? 'none';
	if ($mode === 'none')
	    return;

	$secret = (string)($cfg['authSecret'] ?? '');
	$ok = false;
	switch($mode) {
	    case 'header':
		$name = (string)($cfg['authHeader'] ?? '');
		$ok = $secret !== '' && $name !== '' && hash_equals($secret, (string)$this->header($name));
		break;

	    case 'bearer':
		$auth = (string)$this->header('Authorization');
		$ok = $secret !== '' && stripos($auth, 'Bearer ') === 0 && hash_equals($secret, trim(substr($auth, 7)));
		if (!$ok)
		    \V::app()->response->addHeader('WWW-Authenticate', 'Bearer');
		break;

	    case 'basic':
		$user = (string)($cfg['authUser'] ?? '');
		$password = (string)($cfg['authPassword'] ?? '');
		$auth = (string)$this->header('Authorization');
		$given = (stripos($auth, 'Basic ') === 0) ? (string)base64_decode(trim(substr($auth, 6)), true) : '';
		$ok = $user !== '' && $password !== '' && hash_equals($user . ':' . $password, $given);
		if (!$ok)
		    \V::app()->response->addHeader('WWW-Authenticate', 'Basic realm="webhook"');
		break;

	    case 'hmac':
		$name = (string)($cfg['authHeader'] ?? '');
		$given = strtolower(trim((string)$this->header($name)));
		if (strpos($given, 'sha256=') === 0)
		    $given = substr($given, 7);
		$expected = hash_hmac('sha256', (string)file_get_contents('php://input'), $secret);
		$ok = $secret !== '' && $name !== '' && $given !== '' && hash_equals($expected, $given);
		break;
	}

	if (!$ok)
	    $this->refuse(401, 'Unauthorized');
    }

    //a request header (case-insensitive), or null
    private function header($name) {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return $_SERVER[$key] ?? null;
    }

    //[ raw text as stored, decoded array or null ]; replies 415 / 400 when the body does not match the configured type
    private function readBody($expected, $contentType, $method) {
	$isJson = ($contentType === 'application/json');
	$isForm = in_array($contentType, [ 'application/x-www-form-urlencoded', 'multipart/form-data' ], true);
	$isText = (strpos($contentType, 'text/') === 0);

	//CLD: 2026-10-18T18:34:20+03:00: refuse() (was reply(), removed with the output buffer handling)
	if (($expected === 'json' && !$isJson) || ($expected === 'form' && !$isForm) || ($expected === 'text' && !$isText))
	    $this->refuse(415, 'Unsupported content type, expected ' . (NodeTriggerWebhook::CONTENT_TYPES[$expected] ?? $expected));

	if ($isForm) {
	    //PHP parses form bodies of POST only
	    $data = $_POST;
	    if ($method !== 'POST' && $contentType === 'application/x-www-form-urlencoded')
		parse_str((string)file_get_contents('php://input'), $data);
	    return [ json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $data ];
	}

	$raw = (string)file_get_contents('php://input');
	if ($isJson) {
	    $data = ($raw === '') ? null : json_decode($raw, true);
	    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE)
		$this->refuse(400, 'Invalid JSON body');
	    return [ $raw, is_array($data) ? $data : null ];
	}
	return [ $raw, null ];
    }

    //CLD: 2026-10-17T10:46:42+03:00: query / body data as a variable value (replaces addVars, which made top-level simple values into $prefix<name> variables): keys a
    //database field cannot have (`.`, leading `$`) are left out, levels below MAX_DEPTH become null
    private function clean(array $data, $depth = 0) {
	$out = [];
	foreach($data as $key => $value) {
	    if (is_string($key) && ($key === '' || str_contains($key, '.') || $key[0] === '$'))
		continue;
	    if (is_array($value))
		$value = ($depth + 1 >= self::MAX_DEPTH) ? null : $this->clean($value, $depth + 1);
	    $out[$key] = $value;
	}
	return $out;
    }

    //CLD: 2026-10-17T10:46:42+03:00: the request headers as a variable value: lower-case name => value; without the ones carrying secrets (authorization, cookie, the
    //step's secret header)
    private function requestHeaders(array $cfg) {
	$skip = [ 'authorization', 'cookie' ];
	if (($cfg['auth'] ?? 'none') === 'header')
	    $skip[] = strtolower((string)($cfg['authHeader'] ?? ''));

	$headers = [];
	foreach($_SERVER as $key => $value) {
	    if (strpos($key, 'HTTP_') === 0)
		$name = substr($key, 5);
	    elseif (in_array($key, [ 'CONTENT_TYPE', 'CONTENT_LENGTH' ], true))
		$name = $key;
	    else
		continue;
	    $name = strtolower(str_replace('_', '-', $name));
	    if (in_array($name, $skip, true) || (string)$value === '')
		continue;
	    $headers[$name] = (string)$value;
	    if (count($headers) >= self::MAX_HEADERS)
		break;
	}
	ksort($headers);
	return $headers;
    }

    //CLD: 2026-10-18T18:34:20+03:00: a refused call: the JSON { error } answer, and, for a known workflow's webhook, an execution log entry (unknown tokens: no account to
    //write to); replaces reply() / send(), which handled the output buffers
    private function refuse($status, $error) {
	if ($this->wf)
	    \models\ExecutionLog::record('webhook.refused', $this->wf, $status . ': ' . $error . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' from ' . \V::app()->request->userIP . ')');
	\V::app()->response->sendJSON([ 'error' => $error ], true, $status);
    }
}
