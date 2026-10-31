<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new: the public endpoint of the FortiGate event triggers (/fortigate/<token>, NodeTriggerFortiGate): a FortiGate automation stitch's webhook action
    POSTs the log (%%log%%, JSON) with `Authorization: Bearer <the step's secret>`. One run of the published version per request, with the body in the step's variable (JSON as
    sent, else { raw }) and received_at. Refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow; answered 200 when started
*/

namespace controllers;

class FortigateController extends \sys\BaseController {

    const MAX_BODY = 1048576;

    //called by FortiGate: no CSRF token, the URL's token and the secret are the credential
    public function validateRequest() {
    }

    private $wf = null;

    public function receive($req, $resp) {
	$token = (string)($req->query['id'] ?? '');
	if (!\models\TriggerToken::isToken($token, 'hex'))
	    $this->refuse(404, 'Not found');
	$entry = \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::fortigate', 'token' => $token ]);
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
	if ($wf->status !== \models\Workflow::PUBLISHED)
	    $this->refuse(409, 'The workflow is not published');
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $this->refuse(409, 'The workflow has errors and cannot run');

	$data = json_decode($raw, true);
	$received = new \MongoDB\BSON\UTCDateTime();
	$value = (is_array($data) && !array_is_list($data) ? GravityzoneController::clean($data) : [ 'raw' => mb_substr($raw, 0, 100000) ])
	    + [ 'received_at' => $received->toDateTime()->format(DATE_ATOM) ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'fgt';
	$exe = \models\WfExecution::startFor($live, 'fortigate', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	$exe->log($entry->node, 'FortiGate event received' . (isset($value['logid']) ? " (log {$value['logid']})" : ''));
	$exe->queue();
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ]);
    }

    private function refuse($status, $error) {
	if ($this->wf)
	    \models\ExecutionLog::record('fortigate.refused', $this->wf, $status . ': ' . $error . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' from ' . \V::app()->request->userIP . ')');
	\V::app()->response->sendJSON([ 'error' => $error ], true, $status);
    }
}
