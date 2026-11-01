<?php

/*
    CLD: 2026-11-01T11:55:18+02:00: new: the installation's tool servers (util\ToolServers: mcp.json), at /toolservers/<action>, as the Services (ServicesController):
	index      the servers (with the MultiTenant plugin its super-admins' page; without it a tab of the account's settings: AccountController::settings, views/account/_tools.php)
	edit       GET ?id= (none: a new one): the form; POST: saves it (util\ToolServers::validate, put)
	remove     POST id
	test       POST id: connects, the tools, the server's own checks (util\ToolServers::test), as a toast
	toggle     POST server, on = 1 | 0: the signed-in user's account may use it (the owners, without the MultiTenant plugin; with it the super-admins switch them on its account page)
    A server an installer wrote (managedBy, e.g. scripts/playwright-mcp.sh) is shown and tested, not edited or removed. Every change is in the audit log.
    Who may: with the MultiTenant plugin, its super-admins with 2FA; without it, the account's owners (util\Services::admin / ownerSignedIn)
*/

namespace controllers;

use util\Services;
use util\ToolServers;

class ToolserversController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	if (($admin = Services::admin()) !== null)
	    ($admin['require'])();
	elseif (!Services::ownerSignedIn())
	    \V::app()->error(403, 'Unauthorized');
    }

    public function index($req, $resp) {
	if (!Services::adminPlugin()) {
	    \V::app()->response->redirect(ToolServers::pageUrl());
	    return;
	}
	$servers = ToolServers::all();
	$open = \models\ToolServerSlot::open();
	$this->render('index', compact('servers', 'open'));
    }

    public function edit($req, $resp) {
	$id = (string)($req->is('post') ? ($req->data['id'] ?? '') : ($req->query['id'] ?? ''));
	$old = $id !== '' ? (ToolServers::all()[$id] ?? null) : null;
	$isNew = $req->is('post') ? empty($req->data['existing']) : $old === null;
	if (!$isNew && !$old)
	    \V::app()->error(404, 'Not found');
	if ($old && !empty($old['managed_by'])) {
	    $this->addToast('error', 'Not editable', "{$old['label']} was written by {$old['managed_by']}: change it there");
	    $this->back();
	}
	//the form's values: the server's, or the ones posted
	$values = $old ? [ 'id' => $id, 'label' => $old['label'], 'description' => $old['description'], 'url' => $old['url'],
	    'headers' => implode("\n", array_map(fn($k, $v) => "{$k}: {$v}", array_keys($old['headers']), $old['headers'])),
	    'tools' => implode("\n", (array)$old['tools']), 'maxSessions' => $old['max_sessions'], 'maxSessionsPerAccount' => $old['max_sessions_per_account'] ]
	    : [ 'id' => '', 'label' => '', 'description' => '', 'url' => '', 'headers' => '', 'tools' => '', 'maxSessions' => ToolServers::MAX_SESSIONS,
	    'maxSessionsPerAccount' => ToolServers::MAX_SESSIONS_PER_ACCOUNT ];
	if ($req->is('post')) {
	    foreach(array_keys($values) as $k)
		$values[$k] = trim((string)($req->data[$k] ?? ''));
	    $values['id'] = $id;
	    $problem = null;
	    if ($isNew && isset(ToolServers::all()[$id]))
		$problem = "Id: `{$id}` is taken";
	    if ($problem === null)
		[ $entry, $problem ] = ToolServers::validate($id, $values);
	    if ($problem === null) {
		try {
		    ToolServers::put($id, $entry);
		} catch (\ErrorException $e) {
		    $problem = $e->getMessage();
		}
	    }
	    if ($problem === null) {
		\models\AuditLog::record($isNew ? 'tool_server_add' : 'tool_server_change', null, ($isNew ? 'added' : 'changed') . " the tool server {$entry['label']} ({$id}, {$entry['url']})");
		$this->addToast('success', 'Saved', $entry['label'] . ($isNew ? ' added' : ' saved'));
		$this->back();
	    }
	    $this->addToast('error', 'Not saved', $problem);
	}
	$this->render('edit', compact('values', 'isNew'));
    }

    public function remove($req, $resp) {
	$s = $this->server($req);
	$id = (string)$req->data['id'];
	if (!empty($s['managed_by'])) {
	    $this->addToast('error', 'Not removed', "{$s['label']} was written by {$s['managed_by']}: its uninstall removes it");
	    $this->back();
	}
	try {
	    ToolServers::remove($id);
	} catch (\ErrorException $e) {
	    $this->addToast('error', 'Not removed', $e->getMessage());
	    $this->back();
	}
	\models\AuditLog::record('tool_server_remove', null, "removed the tool server {$s['label']} ({$id})");
	$this->addToast('success', 'Removed', $s['label'] . ' removed: the AI steps that use it fail until it is removed from them');
	$this->back();
    }

    public function test($req, $resp) {
	$s = $this->server($req);
	[ $ok, $lines ] = ToolServers::test($s, 60);
	$this->addToast($ok ? 'success' : 'error', $s['label'] . ($ok ? ': OK' : ': failed'), implode('; ', $lines));
	$this->back();
    }

    public function toggle($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (Services::adminPlugin())
	    \V::app()->error(404, 'Not found');
	$id = (string)($req->data['server'] ?? '');
	$s = ToolServers::get($id);
	if (!$s)
	    \V::app()->error(404, 'Not found');
	$on = !empty($req->data['on']);
	\models\ToolServerAccess::set(\V::app()->user->aid, $id, $on);
	\models\AuditLog::record($on ? 'tool_server_enable' : 'tool_server_disable', null, ($on ? 'enabled' : 'disabled') . " the tool server {$s['label']} for the account");
	$this->addToast('success', 'Saved', $s['label'] . ($on ? ' enabled' : ' disabled') . ' for this account');
	$this->back();
    }

    //to where the servers are (a route: the toasts kept for that page)
    private function back() {
	if (Services::adminPlugin())
	    $this->redirect('/toolservers/index');
	$this->redirect('/account/settings', [ '__tab' => 'tools' ]);
    }

    //POST id: the server
    private function server($req) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$s = ToolServers::get((string)($req->data['id'] ?? ''));
	if (!$s)
	    \V::app()->error(404, 'Not found');
	return $s;
    }
}
