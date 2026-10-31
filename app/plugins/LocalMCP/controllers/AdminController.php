<?php

/*
    the tool servers in the administration (the MultiTenant plugin's account page): /LocalMCP/admin/toggle (POST: id = account, server, on = 1 | 0)
*/

namespace plugins\LocalMCP\controllers;

use plugins\LocalMCP\Plugin;
use plugins\LocalMCP\models\LocalMcpAccess;

class AdminController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	//CLD: 2026-10-31T21:00:00+02:00: the administration is the MultiTenant plugin's (was: the Admin plugin's class exists)
	if (!\plugins\MultiTenant\Plugin::enabled())
	    \V::app()->error(404, 'Not found');
	\plugins\MultiTenant\Plugin::require();
    }

    public function toggle($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$id = (string)($req->data['id'] ?? '');
	$account = preg_match('/^[a-f0-9]{24}$/', $id) ? \V::app()->om->acrossAccounts(fn() => \V::app()->om->findById('Account', $id)) : null;
	$server = (string)($req->data['server'] ?? '');
	if (!$account || !isset(Plugin::servers()[$server]))
	    \V::app()->error(404, 'Not found');
	$on = !empty($req->data['on']);
	\V::app()->om->acrossAccounts(fn() => LocalMcpAccess::set($account->_id, $server, $on));
	\plugins\MultiTenant\Plugin::audit($on ? 'tool_server_enable' : 'tool_server_disable', $account, ($on ? 'enabled' : 'disabled') . ' the tool server ' . Plugin::servers()[$server]['label']);
	//CLD: 2026-10-27T21:47:05+02:00: a toast (addToast + $this->redirect), as in the other controllers; was the Admin plugin's session flash (admin_flash)
	$this->addToast('success', 'Saved', Plugin::servers()[$server]['label'] . ($on ? ' enabled' : ' disabled') . ' for this account');
	$this->redirect('/MultiTenant/admin/account', [ 'id' => (string)$account->_id ]);
    }
}
