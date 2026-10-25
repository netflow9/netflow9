<?php

/*
    the tool servers in the administration (the Admin plugin's account page): /LocalMCP/admin/toggle (POST: id = account, server, on = 1 | 0)
*/

namespace plugins\LocalMCP\controllers;

use plugins\LocalMCP\Plugin;
use plugins\LocalMCP\models\LocalMcpAccess;

class AdminController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	if (!class_exists(\plugins\Admin\Plugin::class))
	    \V::app()->error(404, 'Not found');
	\plugins\Admin\Plugin::require();
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
	\plugins\Admin\Plugin::audit($on ? 'tool_server_enable' : 'tool_server_disable', $account, ($on ? 'enabled' : 'disabled') . ' the tool server ' . Plugin::servers()[$server]['label']);
	$_SESSION['admin_flash'] = [ 'type' => 'success', 'message' => Plugin::servers()[$server]['label'] . ($on ? ' enabled' : ' disabled') . ' for this account' ];
	$resp->redirect('/Admin/admin/account?id=' . $account->_id);
    }
}
