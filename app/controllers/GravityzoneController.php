<?php

/*
    the public endpoint of the GravityZone event triggers (/gravityzone/<token>, NodeTriggerGravityZone): GravityZone's Event Push Service
    POSTs batches of events (JSON-RPC 2.0: params.events; a list, or { events }, accepted too) with `Authorization: Bearer <the step's secret>`. The events of the types the step
    chose start runs of the published version: one per event, or one per batch (at most MAX_RUNS runs per request: the others are logged as left out). Answered 200 with a
    JSON-RPC result (GravityZone counts any other answer as an error); refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerGravityZone;

class GravityzoneController extends \sys\APIController {

    const MAX_BODY = 10 * 1024 * 1024;
    const MAX_RUNS = 500;
    const REFUSED = 'gravityzone.refused';

    //called by GravityZone: no CSRF token, the URL's token and the secret are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::gravityzone', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY)
	    $this->refuse(413, 'Request body too large');
	$this->requireBearer($cfg['secret'] ?? '');

	//the body as sent (sys\http\Request), decoded whatever its content type; too large without a Content-Length (chunked): 413
	if (strlen($req->body()) > self::MAX_BODY)
	    $this->refuse(413, 'Request body too large');
	$data = json_decode($req->body(), true);
	if (!is_array($data))
	    $this->refuse(400, 'Invalid JSON body');
	$events = self::eventsOf($data);
	if ($events === null)
	    $this->refuse(400, 'No events (expected params.events)');

	$this->requireRunnable($wf, $live);

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
	    $exe = \models\wf\WfExecution::startFor($live, 'gravityzone', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
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

}
