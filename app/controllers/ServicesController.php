<?php

/*
    CLD: 2026-10-31T11:05:39+02:00: new (was the Admin plugin's AdminController::index / service): the installation's services, at /services/<action>:
	index      their status and health (util\Services::services, status)
	control    POST id, verb: start / stop / restart one (util\Services::control); in the audit log
    Who may: with the Admin plugin, its super-admins with 2FA (plugins\Admin\Plugin::require, as before); without it, the account's owners (util\Services::ownerSignedIn)
*/

namespace controllers;

use util\Services;

class ServicesController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	if (Services::adminPlugin())
	    \plugins\Admin\Plugin::require();
	elseif (!Services::ownerSignedIn())
	    \V::app()->error(403, 'Unauthorized');
    }

    public function index($req, $resp) {
	$services = [];
	foreach(Services::services() as $id => $s)
	    $services[$id] = $s + [ 'status' => Services::status($s) ];
	$this->render('index', compact('services'));
    }

    public function control($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$id = (string)($req->data['id'] ?? '');
	$verb = (string)($req->data['verb'] ?? '');
	$s = Services::services()[$id] ?? null;
	if (!$s)
	    \V::app()->error(404, 'Not found');
	[ $ok, $message ] = Services::control($s, $verb);
	$who = Services::adminPlugin() ? 'super-admin' : 'owner';
	\V::app()->log->info("Services: {$verb} {$id} by {$who} " . \V::app()->user->name . ': ' . ($ok ? 'ok' : $message));
	\models\AuditLog::record('service_' . $verb, null, "{$who}: {$verb} {$s['label']} (" . ($ok ? 'done' : 'failed: ' . mb_substr($message, 0, 200)) . ')');
	$this->addToast($ok ? 'success' : 'error', $ok ? 'Done' : 'Failed', $message);
	$this->redirect('/services/index');
    }
}
