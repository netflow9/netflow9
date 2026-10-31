<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new: the public endpoint of the GravityZone event triggers (/gravityzone/<token>, NodeTriggerGravityZone): GravityZone's Event Push Service
    POSTs batches of events (JSON-RPC 2.0: params.events; a list, or { events }, accepted too) with `Authorization: Bearer <the step's secret>`. The events of the types the step
    chose start runs of the published version: one per event, or one per batch (at most MAX_RUNS runs per request: the others are logged as left out). Answered 200 with a
    JSON-RPC result (GravityZone counts any other answer as an error); refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerGravityZone;

class GravityzoneController extends \sys\BaseController {

    const MAX_BODY = 10 * 1024 * 1024;
    const MAX_RUNS = 500;

    //called by GravityZone: no CSRF token, the URL's token and the secret are the credential
    public function validateRequest() {
    }

    private $wf = null;

    public function receive($req, $resp) {
	$token = (string)($req->query['id'] ?? '');
	if (!\models\TriggerToken::isToken($token, 'hex'))
	    $this->refuse(404, 'Not found');
	$entry = \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::gravityzone', 'token' => $token ]);
	$wf = $entry ? \V::app()->om->findOne('Workflow', [ '_id' => $entry->wf, 'aid' => $entry->aid ]) : null;
	$this->wf = $wf;
	$version = $wf ? $wf->publishedVersion() : null;
	$live = $version ? $wf->forVersion($version) : null;
	$node = null;
	foreach((is_array($live->nodes ?? null) ? $live->nodes : []) as $n) {
	    if (($n['wfid'] ?? null) == $entry->node && ($n['config']['token'] ?? null) === $token)
		$node = $n;
	}
	if (empty($node))
	    $this->refuse(404, 'Not found');
	\V::app()->var('rt.account', \V::app()->om->findById('Account', $entry->aid));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY)
	    $this->refuse(413, 'Request body too large');
	$secret = (string)($cfg['secret'] ?? '');
	$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
	if ($secret === '' || stripos($auth, 'Bearer ') !== 0 || !hash_equals($secret, trim(substr($auth, 7))))
	    $this->refuse(401, 'Unauthorized');

	$raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
	$data = json_decode($raw, true);
	if (!is_array($data))
	    $this->refuse(400, 'Invalid JSON body');
	$events = self::eventsOf($data);
	if ($events === null)
	    $this->refuse(400, 'No events (expected params.events)');

	if ($wf->status !== \models\Workflow::PUBLISHED)
	    $this->refuse(409, 'The workflow is not published');
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $this->refuse(409, 'The workflow has errors and cannot run');

	$types = NodeTriggerGravityZone::chosenTypes($cfg);
	$kept = array_values(array_filter($events, fn($e) => is_array($e) && (!$types || in_array((string)($e['module'] ?? ''), $types, true))));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'gz';
	$received = new \MongoDB\BSON\UTCDateTime();
	$at = $received->toDateTime()->format(DATE_ATOM);

	$runs = [];
	if ($kept && ($cfg['runs'] ?? 'each') === 'batch')
	    $runs[] = [ 'events' => array_map([ self::class, 'clean' ], $kept), 'count' => count($kept), 'received_at' => $at ];
	else
	    foreach($kept as $e)
		$runs[] = self::clean($e) + [ 'received_at' => $at ];
	$left = max(0, count($runs) - self::MAX_RUNS);
	$started = [];
	foreach(array_slice($runs, 0, self::MAX_RUNS) as $value) {
	    $exe = \models\WfExecution::startFor($live, 'gravityzone', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	    $exe->log($entry->node, 'GravityZone ' . (isset($value['events']) ? \util\Format::plural($value['count'], 'event') : 'event ' . ($value['module'] ?? '?')) . ' received');
	    $exe->queue();
	    $started[] = (string)$exe->_id;
	}
	if ($left)
	    \models\ExecutionLog::record('gravityzone.refused', $wf, "{$left} events left out: at most " . self::MAX_RUNS . ' runs per request');
	$resp->sendJSON([ 'jsonrpc' => '2.0', 'id' => $data['id'] ?? null, 'result' => [ 'received' => count($events), 'runs' => count($started) ] ]);
    }

    //the events of a push request: params.events (JSON-RPC), a list, or { events }; null when there are none to read
    public static function eventsOf(array $data) {
	$events = $data['params']['events'] ?? ($data['events'] ?? (array_is_list($data) ? $data : null));
	return is_array($events) && array_is_list($events) ? $events : null;
    }

    //an event as a variable value: keys a database field cannot have (`.`, leading `$`) left out, 32 levels at most
    public static function clean(array $data, $depth = 0) {
	$out = [];
	foreach($data as $key => $value) {
	    if (is_string($key) && ($key === '' || str_contains($key, '.') || $key[0] === '$'))
		continue;
	    $out[$key] = is_array($value) ? ($depth >= 31 ? null : self::clean($value, $depth + 1)) : $value;
	}
	return $out;
    }

    private function refuse($status, $error) {
	if ($this->wf)
	    \models\ExecutionLog::record('gravityzone.refused', $this->wf, $status . ': ' . $error . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' from ' . \V::app()->request->userIP . ')');
	\V::app()->response->sendJSON([ 'error' => $error ], true, $status);
    }
}
