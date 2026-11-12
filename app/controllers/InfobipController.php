<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: the public endpoint of the Infobip message triggers (/infobip/<token>, NodeTriggerInfobip): Infobip POSTs JSON { results: [ … ] } - messages
    received (SMS, WhatsApp, other channels) or delivery reports. Infobip does not sign them: the token is the credential. One run of the published version per result of a kind
    chosen (or one per post: Runs), at most NodeTriggerInfobip::MAX_RESULTS; the others are answered 200 and start nothing (Infobip retries other answers). Refusals (unknown token,
    not published, not JSON, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerInfobip;

class InfobipController extends \sys\APIController {

    const REFUSED = 'infobip.refused';

    //called by Infobip: no CSRF token, the URL's token is the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::infobip', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$data = json_decode($raw, true);
	if (!is_array($data))
	    $this->refuse(400, 'Not JSON');
	$this->requireRunnable($wf, $live);

	$results = isset($data['results']) && is_array($data['results']) ? array_values($data['results']) : (array_is_list($data) ? $data : [ $data ]);
	$kinds = NodeTriggerInfobip::chosenKinds($cfg);
	$items = [];
	foreach(array_slice($results, 0, NodeTriggerInfobip::MAX_RESULTS) as $r) {
	    if (!is_array($r))
		continue;
	    $item = NodeTriggerInfobip::itemOf($r);
	    $kind = $item['_kind'];
	    unset($item['_kind']);
	    if (in_array($kind, $kinds, true))
		$items[] = $item + [ 'data' => self::clean($r) ];
	}
	if (!$items) {
	    $resp->sendJSON([ 'state' => 'ignored', 'results' => count($results) ]);
	    return;
	}
	$received = new \MongoDB\BSON\UTCDateTime();
	$at = $received->toDateTime()->format(DATE_ATOM);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'message';
	$values = ($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'items' => array_map(fn($i) => $i + [ 'received_at' => $at ], $items), 'count' => count($items) ] ]
	    : array_map(fn($i) => $i + [ 'received_at' => $at ], $items);
	$ids = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($live, 'infobip', [ 'received_at' => $received, 'vars' => [ $name => $v ] ], $entry->node);
	    $exe->log($entry->node, isset($v['items']) ? 'Infobip: ' . \util\Format::plural($v['count'], 'message or report', 'messages or reports') . ' received'
		: 'Infobip ' . ($v['channel'] ?? '') . ' ' . $v['kind'] . ' received' . (isset($v['from']) ? " from {$v['from']}" : (isset($v['to']) ? " for {$v['to']}" : '')));
	    $exe->queue();
	    $ids[] = (string)$exe->_id;
	}
	$resp->sendJSON([ 'executions' => $ids, 'state' => 'accepted' ]);
    }
}
