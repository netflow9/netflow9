<?php

/*
    CLD: 2026-10-23T12:00:00+03:00: new: the settings pages of the SAML sign-in, served at /saml/<action>, for who may change the account (Account::update, as AccountController::settings):
	settings   the account's SAML settings (models\SamlSettings; posted as JSON: `settings_json`, the form's app.smartform data), the service provider's details for the IdP,
		   the account's SSO users. Made (and saved, off) at the first visit: the SP's key pair and metadata exist before the IdP is set up
	import     POST, JSON: the IdP's metadata (`url`, read here, or the pasted `xml`) read: { ok, values (the form's IdP fields), lines }; nothing saved
	test       a sign-in at the IdP with the saved settings (on or off), whose result is shown instead of signing in (SsoController::begin, ::acs)
	renew      POST: a new SP key pair (the IdP must get the new metadata / certificate)
	unlink     POST ?id=<user>: the user is no longer an SSO user (signs in with a password of this application: "forgot password" sets one)
*/

namespace controllers;

use util\SamlAuth;
use util\SamlException;
use util\SamlSp;

class SamlController extends \sys\BaseController {

    //signed-in people who may change the account
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
    }

    //saved, or made now (off) with its key pair
    protected function settingsOfAccount() {
	$aid = \V::app()->config('rt.account')->_id;
	$s = \V::app()->om->findOne('SamlSettings', [ 'aid' => $aid ]) ?: \V::app()->om->create('SamlSettings', [ 'aid' => $aid ]);
	if (empty($s->_id) || trim((string)$s->spCert) === '') {
	    $s->keys();
	    if (!$s->save())
		throw new \ErrorException('SAML: the settings were not saved: ' . json_encode($s->errors()));
	}
	return $s;
    }

    public function settings($req, $resp) {
	$settings = $this->settingsOfAccount();
	if ($req->is('post')) {
	    $data = json_decode((string)($req->data['settings_json'] ?? ''), true);
	    if (!is_array($data))
		\V::app()->error(400, 'malformed request: settings_json');
	    $settings->assign($data, true);
	    if ($settings->validates() && $settings->save()) {
		SamlAuth::reset();
		$this->addToast('success', 'OK', 'SAML single sign-on settings saved!');
	    } else {
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	    }
	}

	//the account's SSO users
	$om = \V::app()->om;
	$links = [];
	foreach($om->find('SamlLink', [ 'aid' => $settings->aid ], [ 'sort' => [ 'subject' => 1 ] ]) as $l) {
	    $u = $om->findOne('User', [ '_id' => $l->user, 'aid' => $settings->aid ]);
	    if ($u)
		$links[] = [ 'link' => $l, 'user' => $u ];
	}
	$this->render('settings', compact('settings', 'links'));
    }

    public function import($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$url = trim((string)($req->data['url'] ?? ''));
	$xml = trim((string)($req->data['xml'] ?? ''));
	try {
	    if ($xml === '' && $url === '')
		throw new SamlException('give the metadata URL, or paste the metadata');
	    $values = SamlSp::idpMetadata($xml !== '' ? $xml : SamlSp::fetch($url));
	} catch (SamlException $e) {
	    $resp->sendJSON([ 'ok' => false, 'lines' => [ 'Not imported: ' . $e->getMessage() ] ]);
	}
	$formats = $values['nameIdFormats'];
	unset($values['nameIdFormats']);
	$lines = [ 'IdP: ' . $values['idpEntityId'], 'Sign-on URL: ' . $values['idpSsoUrl'], 'Certificates: ' . substr_count($values['idpCerts'], 'BEGIN CERTIFICATE') ];
	if ($formats)
	    $lines[] = 'NameID formats: ' . implode(', ', $formats);
	$lines[] = 'Filled in the form: check, then Save';
	\models\AuditLog::record('saml.import', null, 'IdP metadata read: ' . $values['idpEntityId']);
	$resp->sendJSON([ 'ok' => true, 'values' => $values, 'lines' => $lines ]);
    }

    public function test($req, $resp) {
	$s = $this->settingsOfAccount();
	foreach([ 'idpEntityId', 'idpSsoUrl', 'idpCerts' ] as $key) {
	    if (trim((string)$s->$key) === '')
		\V::app()->error(400, 'Save the identity provider\'s settings first (entity ID, sign-on URL, certificates)');
	}
	\models\AuditLog::record('saml.test', $s, 'sign-in test started');
	SsoController::begin($s, \V::app()->user->_id);
    }

    public function renew($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$s = $this->settingsOfAccount();
	$s->keys(true);
	if (!$s->save())
	    throw new \ErrorException('SAML: the settings were not saved: ' . json_encode($s->errors()));
	\models\AuditLog::record('saml.renew', $s, 'new service provider certificate');
	$this->addToast('success', 'OK', 'New certificate: give the IdP the new metadata (or certificate)');
	$this->redirect('/saml/settings');
    }

    public function unlink($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$oid = $this->getOidOrError();
	$aid = \V::app()->config('rt.account')->_id;
	$user = \V::app()->om->findOne('User', [ '_id' => $oid, 'aid' => $aid ]);
	if (empty($user))
	    \V::app()->error(404, 'Not found');
	foreach(\V::app()->om->find('SamlLink', [ 'aid' => $aid, 'user' => $user->_id ]) as $l)
	    $l->delete();
	\models\AuditLog::record('saml.unlink', $user, 'no longer an SSO user');
	$this->addToast('success', 'OK', \V::app()->html->escape((string)$user) . ' is no longer an SSO user: "Forgot username or password?" sets a password');
	$this->redirect('/saml/settings');
    }
}
