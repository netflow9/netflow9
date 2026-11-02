<?php

/*
    the controllers called by other systems or by pages without a session, not by a signed-in browser: POSTs carry no CSRF token,
    each controller checks its own credential (a token in the URL, a secret, a bearer token, a conversation's key).

    The endpoints of the triggers set $wf once the workflow is known and name their execution log event in REFUSED.
*/

namespace sys;

class APIController extends BaseController {
    //levels kept of the data a caller sends (clean)
    const MAX_DEPTH = 32;
    //the execution log event of a refused call (models\ExecutionLog::ACTIONS)
    const REFUSED = '';

    //the workflow of the call, once known: refusals are written to its account's execution log
    protected $wf = null;

    public function validateRequest() {
    }

    //a refused call: the JSON { error } answer, and, for a known workflow, an execution log entry (unknown tokens: no account to write to)
    protected function refuse($status, $error) {
	if ($this->wf)
	    \models\ExecutionLog::record(static::REFUSED, $this->wf, $status . ': ' . $error . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' from ' . \V::app()->request->userIP . ')');
	\V::app()->response->sendJSON([ 'error' => $error ], true, $status);
    }

    //the trigger of $type with token $token, still present in the published version of its workflow: [ its TriggerToken, the workflow, a copy of the workflow holding that
    //version's steps (Workflow::forVersion: the index is built from it, the workflow's nodes are the draft), the step ]; refused with 404 otherwise.
    //Sets $wf (refusals are logged from here on) and the account
    protected function triggerOf($type, $token) {
	if (!\models\TriggerToken::isToken($token, 'hex'))
	    $this->refuse(404, 'Not found');
	$entry = \V::app()->om->findOne('TriggerToken', [ 'type' => $type, 'token' => $token ]);
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
	return [ $entry, $wf, $live, $node ];
    }

    //refused with 401 unless the request has `Authorization: Bearer <$secret>`
    protected function requireBearer($secret) {
	$secret = (string)$secret;
	$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
	if ($secret === '' || stripos($auth, 'Bearer ') !== 0 || !hash_equals($secret, trim(substr($auth, 7))))
	    $this->refuse(401, 'Unauthorized');
    }

    //refused with 409 unless the workflow can start: drafts are not started by their triggers, the published version must have no errors.
    //Called after authentication: nothing is revealed before
    protected function requireRunnable($wf, $live) {
	if ($wf->status !== \models\Workflow::PUBLISHED)
	    $this->refuse(409, 'The workflow is not published');
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $this->refuse(409, 'The workflow has errors and cannot run');
    }

    //data sent by a caller as a variable value: keys a database field cannot have (`.`, leading `$`) left out, levels below MAX_DEPTH become null
    protected static function clean(array $data, $depth = 0) {
	$out = [];
	foreach($data as $key => $value) {
	    if (is_string($key) && ($key === '' || str_contains($key, '.') || $key[0] === '$'))
		continue;
	    $out[$key] = is_array($value) ? ($depth + 1 >= self::MAX_DEPTH ? null : self::clean($value, $depth + 1)) : $value;
	}
	return $out;
    }
}
