<?php

/*
    CLD: 2026-10-21T10:00:00+03:00: new: the pages of the LDAP sign-in, served at /ldap/<action>, for who may change the account (Account::update, as AccountController::settings):
	settings   the account's LDAP sign-in settings (models\LdapSettings; posted as JSON: `settings_json`, the form's app.smartform data) and its LDAP users
	test       POST, JSON: the settings of the form (not saved) tried: mode `connection` (connect, bind, the Base DN, a few people of the user filter) or `signin` (what the
		   sign-in of `login` / `password` would do: util\LdapAuth::trial); answers { ok, lines }
	unlink     POST ?id=<user>: the user is no longer an LDAP user (signs in with a password of this application: "forgot password" sets one)
*/

namespace controllers;

use models\LdapSettings;
use util\LdapAuth;
use util\LdapClient;

class LdapController extends \sys\BaseController {

    //signed-in people who may change the account
    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
    }

    protected function settingsOfAccount() {
	//CLD: 2026-10-21T11:30:00+03:00: found here (LdapSettings::forAccount removed): saved, or new with the defaults (not saved)
	$aid = \V::app()->config('rt.account')->_id;
	return \V::app()->om->findOne('LdapSettings', [ 'aid' => $aid ]) ?: \V::app()->om->create('LdapSettings', [ 'aid' => $aid ]);
    }

    //the form's values (JSON) assigned to $settings (an empty secret keeps the saved one)
    protected static function assignPosted(LdapSettings $settings, $json) {
	$data = json_decode((string)$json, true);
	if (!is_array($data))
	    \V::app()->error(400, 'malformed request: settings_json');
	$settings->assign($data, true);
    }

    public function settings($req, $resp) {
	$settings = $this->settingsOfAccount();
	if ($req->is('post')) {
	    self::assignPosted($settings, $req->data['settings_json'] ?? null);
	    if ($settings->validates() && $settings->save()) {
		LdapAuth::reset();
		$this->addToast('success', 'OK', 'LDAP sign-in settings saved!');
	    } else {
		$this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	    }
	}

	//the account's LDAP users
	$om = \V::app()->om;
	$links = [];
	foreach($om->find('LdapLink', [ 'aid' => $settings->aid ], [ 'sort' => [ 'login' => 1 ] ]) as $l) {
	    $u = $om->findOne('User', [ '_id' => $l->user, 'aid' => $settings->aid ]);
	    if ($u)
		$links[] = [ 'link' => $l, 'user' => $u ];
	}
	$this->render('settings', compact('settings', 'links'));
    }

    public function test($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$settings = $this->settingsOfAccount();
	self::assignPosted($settings, $req->data['settings_json'] ?? null);
	$settings->enabled = true;
	if (!$settings->validates()) {
	    $errors = [];
	    foreach((array)$settings->errors() as $key => $messages)
		$errors[] = $settings->errorLabel($key) . ': ' . implode(', ', (array)$messages);
	    $resp->sendJSON([ 'ok' => false, 'lines' => array_merge([ 'The settings are not complete:' ], $errors) ]);
	}

	if (($req->data['mode'] ?? '') === 'signin') {
	    $login = trim((string)($req->data['login'] ?? ''));
	    if ($login === '' || (string)($req->data['password'] ?? '') === '')
		$resp->sendJSON([ 'ok' => false, 'lines' => [ 'Give a name and a password to try' ] ]);
	    $r = LdapAuth::trial($settings, $login, (string)$req->data['password']);
	    \models\AuditLog::record('ldap.test', $settings, 'sign-in of ' . $login . ' tried: ' . ($r['ok'] ? 'signed in' : 'refused'));
	    $resp->sendJSON($r);
	}

	$lines = [];
	try {
	    $ok = $settings->withClient(function(LdapClient $client) use ($settings, &$lines) {
		$lines[] = 'Connected to ' . $client->describe();
		$lines[] = trim((string)$settings->bindDn) !== '' ? 'Bound as ' . $settings->bindDn : 'Anonymous (no bind DN)';
		$root = $client->rootDse();
		$vendor = LdapClient::first([ 'attributes' => $root ], 'vendorName') ?: (LdapClient::values([ 'attributes' => $root ], 'forestFunctionality') ? 'Active Directory' : '');
		if ($vendor !== '')
		    $lines[] = 'Server: ' . $vendor;
		if (!$client->read((string)$settings->baseDn, [ '1.1' ])) {
		    $lines[] = 'Base DN ' . $settings->baseDn . ': not found (or the bind DN cannot read it)';
		    return false;
		}
		$lines[] = 'Base DN: found';
		$filter = str_replace('{login}', '*', $settings->get('userFilter'));
		$r = $client->search((string)$settings->baseDn, $filter, 'sub', [ $settings->get('attrLogin'), $settings->get('attrEmail') ], 5);
		$people = array_map(fn($e) => (LdapClient::first($e, $settings->get('attrLogin')) ?: $e['dn']) . (($m = LdapClient::first($e, $settings->get('attrEmail'))) !== '' ? " <{$m}>" : ''), $r['entries']);
		$lines[] = 'User filter: ' . ($people ? count($people) . ($r['truncated'] ? '+' : '') . ' people, e.g. ' . implode(', ', $people) : 'nobody found (is the filter right for this directory?)');
		return (bool)$people;
	    });
	} catch (\Throwable $e) {
	    $lines[] = 'Error: ' . $e->getMessage();
	    $ok = false;
	}
	$resp->sendJSON([ 'ok' => $ok, 'lines' => $lines ]);
    }

    public function unlink($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$oid = $this->getOidOrError();
	$aid = \V::app()->config('rt.account')->_id;
	$user = \V::app()->om->findOne('User', [ '_id' => $oid, 'aid' => $aid ]);
	if (empty($user))
	    \V::app()->error(404, 'Not found');
	foreach(\V::app()->om->find('LdapLink', [ 'aid' => $aid, 'user' => $user->_id ]) as $l)
	    $l->delete();
	\models\AuditLog::record('ldap.unlink', $user, 'no longer an LDAP user');
	$this->addToast('success', 'OK', \V::app()->html->escape((string)$user) . ' is no longer an LDAP user: "Forgot username or password?" sets a password');
	$this->redirect('/ldap/settings');
    }
}
