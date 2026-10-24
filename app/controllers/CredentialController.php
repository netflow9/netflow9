<?php

namespace controllers;

class CredentialController extends \sys\BaseController {

    use DataTrait;

    //session key of a pending OAuth consent; the consent must come back within this many seconds
    const OAUTH_STATE = 'credential_oauth';
    const OAUTH_TTL = 600;

    public $project = null;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);
	if (empty($this->project))
	    \V::app()->error(404, 'No current project');
    }

    //an unsaved credential of the current project: the object for list/create checks (project scope = membership)
    private function projectCredential($variant = null) {
	return \V::app()->om->create($variant ?: \models\Credential::FAMILY, [
	    'aid' => \V::app()->config('rt.account')->_id,
	    'project' => $this->project->_id,
	]);
    }

    //credential `id` of the current account and project, if the user has $permName
    private function findOrError($permName) {
	$oid = $this->getOidOrError();
	$cred = \V::app()->om->findOne('Credential', [
	    '_id' => \V::app()->om->to_oid($oid),
	    'aid' => \V::app()->config('rt.account')->_id,
	    'project' => $this->project->_id,
	]);
	if (empty($cred))
	    \V::app()->error(404, 'Not found');
	if (!\V::app()->user->can($permName, [ 'object' => $cred ]))
	    \V::app()->error(403, 'Unauthorized');
	return $cred;
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('Credential::list', [ 'object' => $this->projectCredential() ]))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    $response = $this->__data('Credential', [ 'project' => $this->project->_id ]);
	    //CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was write + end: sent as text/html)
	    $resp->sendJSON($response);
	}

	$this->render('list');
    }

    public function create($req, $resp) {
	$type = (string)($req->query['type'] ?? '');
	if (!\V::app()->om->isDefined($type))
	    \V::app()->error(400, 'Unknown credential type');
	//CLD: 2026-10-24T09:45:36+03:00: a type of a feature the account's tier does not have (util\Tiers)
	if (!\util\Tiers::allows(\V::app()->config('rt.account'), $type))
	    \V::app()->error(403, \util\Tiers::featureMessage(\V::app()->config('rt.account'), \V::app()->om->registry[$type]['feature']));

	$cred = $this->projectCredential($type);
	if (!\V::app()->user->can('Credential::create', [ 'object' => $cred ]))
	    \V::app()->error(403, 'Unauthorized');

	if ($req->is('post') && $this->saveForm($cred, $req->data)) {
	    $this->addToast('success', 'OK', $cred::isOAuth() ? 'Credential created: use Connect to sign in at the provider' : 'Credential created');
	    $this->addEvent('object::created', [ 'object' => 'Credential', 'oid' => (string)$cred->_id ]);
	    $this->sendEvents();
	}

	$this->render('form', compact('cred'));
    }

    public function update($req, $resp) {
	$cred = $this->findOrError('Credential::update');
	//a stored variant that is no longer registered loads as a plain Credential
	if (\V::app()->om->isVariant($cred->getObjectName()) !== 'Credential')
	    \V::app()->error(409, 'The type of this credential is not available (plugin disabled?)');

	if ($req->is('post') && $this->saveForm($cred, $req->data)) {
	    $this->addToast('success', 'OK', 'Credential saved');
	    $this->addEvent('object::updated', [ 'object' => 'Credential', 'oid' => (string)$cred->_id ]);
	    $this->sendEvents();
	}

	$this->render('form', compact('cred'));
    }

    //name + the variant's values; the variant never changes; false when invalid
    //the credential is the form model: its form fields are assigned (with the field permissions) and validated together
    private function saveForm($cred, $data) {
	$data = is_array($data) ? $data : [];
	$before = $cred::isOAuth() ? [ $cred->clientId, $cred->tenant ] : null;

	$cred->assign($data, true);
	if (!$cred->validates())
	    return false;

	//another OAuth app: the tokens of the old one do not apply
	if ($cred::isOAuth() && $cred->exists() && $before !== [ $cred->clientId, $cred->tenant ]) {
	    $cred->disconnect();
	    $cred->email = '';
	    $cred->status = 'new';
	    $cred->status_message = 'the app changed: use Connect again';
	}

	$cred->save();
	return true;
    }

    public function delete($req, $resp) {
	$cred = $this->findOrError('Credential::delete');

	if ($req->is('post')) {
	    $cred->delete();
	    $this->addToast('success', 'OK', 'Credential deleted');
	    $this->addEvent('object::deleted', [ 'object' => 'Credential', 'oid' => null ]);
	    $this->sendEvents();
	}
    }

    //the variant's connection test; the result is kept on the credential
    public function test($req, $resp) {
	$cred = $this->findOrError('Credential::update');
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');

	try {
	    if (!$cred::$testable)
		throw new \Exception('this credential type has no connection test');
	    $message = $cred->test();
	    $cred->setStatus('ok', $message);
	    \models\AuditLog::record('credential.test', $cred, 'passed: ' . $message);
	    $this->addToast('success', 'Test passed', \V::app()->html->escape($message));
	} catch (\Exception $e) {
	    if ($cred::$testable)
		$cred->setStatus('error', $e->getMessage());
	    \models\AuditLog::record('credential.test', $cred, 'failed: ' . $e->getMessage());
	    $this->addToast('danger', 'Test failed', \V::app()->html->escape($e->getMessage()));
	}
	$this->addEvent('object::updated', [ 'object' => 'Credential', 'oid' => (string)$cred->_id ]);
	$this->sendEvents();
    }

    //OAuth: go to the provider's consent page
    public function connect($req, $resp) {
	$cred = $this->findOrError('Credential::update');
	if (!$cred::isOAuth())
	    $this->back('danger', 'Not connected', 'This credential does not sign in at a provider');

	$state = bin2hex(random_bytes(16));
	$verifier = \util\OAuth2::newVerifier();
	\V::app()->session->setState(self::OAUTH_STATE, [
	    'state' => $state,
	    'verifier' => $verifier,
	    'credential' => (string)$cred->_id,
	    'expires' => time() + self::OAUTH_TTL,
	]);

	$resp->redirect($cred->authUrl($state, $verifier));
    }

    //OAuth: the provider sends the user back here with ?code&state (or ?error)
    public function oauth_callback($req, $resp) {
	$pending = \V::app()->session->getState(self::OAUTH_STATE);
	\V::app()->session->setState(self::OAUTH_STATE, null);

	$state = (string)($req->query['state'] ?? '');
	if (!is_array($pending) || $state === '' || !hash_equals((string)$pending['state'], $state) || $pending['expires'] < time()) {
	    $this->back('danger', 'Not connected', 'The sign-in expired or did not start here; use Connect again');
	}

	$cred = \V::app()->om->findOne('Credential', [
	    '_id' => \V::app()->om->to_oid($pending['credential']),
	    'aid' => \V::app()->config('rt.account')->_id,
	    'project' => $this->project->_id,
	]);
	if (empty($cred) || !$cred::isOAuth() || !\V::app()->user->can('Credential::update', [ 'object' => $cred ])) {
	    $this->back('danger', 'Not connected', 'Credential not found');
	}

	if (!empty($req->query['error'])) {
	    $this->back('danger', 'Not connected', 'The provider refused: ' . mb_substr((string)($req->query['error_description'] ?? $req->query['error']), 0, 300));
	}

	try {
	    $cred->exchangeCode((string)($req->query['code'] ?? ''), $pending['verifier']);
	    $cred->status = 'new';
	    $cred->status_message = 'signed in, not tested yet';
	    $cred->save();
	} catch (\Exception $e) {
	    $cred->setStatus('error', $e->getMessage());
	    $this->back('danger', 'Not connected', 'Sign-in failed: ' . $e->getMessage());
	}
	$this->back('success', 'Connected', 'Connected ' . ($cred->email ?: $cred->name));
    }

    //back to the list with a toast
    //CLD: 2026-10-24T09:06:00+03:00: a toast (addToast, kept across the redirect by BaseController::redirect) and redirect() (was the message in the list's URL, ?error / ?message)
    private function back($type, $title, $message) {
	$this->addToast($type, $title, \V::app()->html->escape($message));
	$this->redirect('/credential/list');
    }
}
