<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: the public endpoint of the Yousign event triggers (/yousign/<token>, NodeTriggerYousign): Yousign POSTs a webhook (JSON), signed in
    X-Yousign-Signature-256 (sha256=<HMAC-SHA256 of the body>) with the subscription's secret key, the step's Secret key. With a credential, a sandbox event must come to a
    sandbox credential's step and a production one to a production credential's. One run of the published version per event chosen; other events are answered 200 and start
    nothing (Yousign retries any other answer). Refusals (unknown token, signature, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerYousign;

class YousignController extends \sys\APIController {

    const REFUSED = 'yousign.refused';

    //called by Yousign: no CSRF token, the URL's token and the signature are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::yousign', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	//the body as sent (sys\http\Request): the signature is of these bytes
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	if (!\util\Yousign::verify($raw, (string)($_SERVER['HTTP_X_YOUSIGN_SIGNATURE_256'] ?? ''), trim((string)($cfg['secretKey'] ?? ''))))
	    $this->refuse(401, 'Unauthorized (the signature does not match the secret key)');
	$data = json_decode($raw, true);
	if (!is_array($data))
	    $this->refuse(400, 'Not JSON');
	$cred = (string)($cfg['credential'] ?? '') !== '' ? NodeTriggerYousign::credentialOf($wf, $node) : null;
	if ($cred && (bool)($data['sandbox'] ?? false) !== (bool)$cred->sandbox)
	    $this->refuse(403, ($data['sandbox'] ?? false) ? 'A sandbox event, the credential is production\'s' : 'A production event, the credential is the sandbox\'s');
	$this->requireRunnable($wf, $live);

	$event = (string)($data['event_name'] ?? '');
	if (!in_array($event, NodeTriggerYousign::chosenEvents($cfg), true)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => mb_substr($event, 0, 100) ]);
	    return;
	}
	$received = new \MongoDB\BSON\UTCDateTime();
	$value = NodeTriggerYousign::valueOf(self::clean($data), $received->toDateTime()->format(DATE_ATOM));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'yousign';
	$exe = \models\wf\WfExecution::startFor($live, 'yousign', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	$sr = (array)($value['signatureRequest'] ?? []);
	$exe->log($entry->node, "Yousign {$event} received" . (isset($sr['name']) && is_scalar($sr['name']) ? " ({$sr['name']})" : ''));
	$exe->queue();
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ]);
    }
}
