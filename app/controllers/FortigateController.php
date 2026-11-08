<?php

/*
    the public endpoint of the FortiGate event triggers (/fortigate/<token>, NodeTriggerFortiGate): a FortiGate automation stitch's webhook action
    POSTs the log (%%log%%, JSON) with `Authorization: Bearer <the step's secret>`. One run of the published version per request, with the body in the step's variable (JSON as
    sent, else { raw }) and received_at. Refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow; answered 200 when started
*/

namespace controllers;

class FortigateController extends \sys\APIController {

    const REFUSED = 'fortigate.refused';

    //called by FortiGate: no CSRF token, the URL's token and the secret are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::fortigate', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$this->requireBearer($cfg['secret'] ?? '');

	$raw = (string)file_get_contents('php://input', false, null, 0, \data\Constants::MAX_REQUEST_BODY + 1);
	$this->requireRunnable($wf, $live);

	$data = json_decode($raw, true);
	$received = new \MongoDB\BSON\UTCDateTime();
	$value = (is_array($data) && !array_is_list($data) ? self::clean($data) : [ 'raw' => mb_substr($raw, 0, 100000) ])
	    + [ 'received_at' => $received->toDateTime()->format(DATE_ATOM) ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'fgt';
	$exe = \models\wf\WfExecution::startFor($live, 'fortigate', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	$exe->log($entry->node, 'FortiGate event received' . (isset($value['logid']) ? " (log {$value['logid']})" : ''));
	$exe->queue();
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ]);
    }
}
