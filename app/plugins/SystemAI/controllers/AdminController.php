<?php

/*
    CLD: 2026-10-22T23:00:00+03:00: new: an account's System credits in the administration (the Admin plugin's account page; super-admins only):
    CLD: 2026-10-23T10:00:00+03:00: the System AI's (were /Assistant/admin/<action>)
	/SystemAI/admin/plan    POST: id = account, credits (empty: the installation's monthly allowance)
	/SystemAI/admin/grant   POST: id = account, credits, note: a top-up (models\SystemAiWallet::grant)
*/

namespace plugins\SystemAI\controllers;

use plugins\SystemAI\models\SystemAiWallet;

class AdminController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	if (!class_exists(\plugins\Admin\Plugin::class))
	    \V::app()->error(404, 'Not found');
	\plugins\Admin\Plugin::require();
    }

    public function plan($req, $resp) {
	$account = $this->account($req);
	$credits = trim((string)($req->data['credits'] ?? ''));
	if ($credits !== '' && (!ctype_digit($credits) || (int)$credits > 100000000))
	    $this->back($account, 'danger', 'The allowance: a whole number of credits, or empty');
	$plan = \V::app()->om->acrossAccounts(fn() => SystemAiWallet::setPlan($account->_id, $credits === '' ? null : (int)$credits));
	\plugins\Admin\Plugin::audit('credits_plan', $account, "System AI monthly allowance: {$plan} credits" . ($credits === '' ? ' (the installation\'s)' : ''));
	$this->back($account, 'success', "Monthly allowance: {$plan} credits");
    }

    public function grant($req, $resp) {
	$account = $this->account($req);
	$credits = trim((string)($req->data['credits'] ?? ''));
	if (!ctype_digit($credits) || (int)$credits < 1 || (int)$credits > 100000000)
	    $this->back($account, 'danger', 'The top-up: a whole number of credits, at least 1');
	$note = trim(mb_substr((string)($req->data['note'] ?? ''), 0, 200));
	$t = \V::app()->om->acrossAccounts(fn() => SystemAiWallet::grant($account->_id, (int)$credits, $note));
	\plugins\Admin\Plugin::audit('credits_topup', $account, "System AI top-up: +{$credits} credits until " . gmdate('Y-m-d', $t['expires']) . ($note !== '' ? " ({$note})" : ''));
	$this->back($account, 'success', "+{$credits} credits, until " . gmdate('Y-m-d', $t['expires']));
    }

    //the account of POST `id`
    private function account($req) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$id = (string)($req->data['id'] ?? '');
	$account = preg_match('/^[a-f0-9]{24}$/', $id) ? \V::app()->om->acrossAccounts(fn() => \V::app()->om->findById('Account', $id)) : null;
	if (!$account)
	    \V::app()->error(404, 'Not found');
	return $account;
    }

    private function back($account, $type, $message) {
	$_SESSION['admin_flash'] = [ 'type' => $type, 'message' => $message ];
	\V::app()->response->redirect('/Admin/admin/account?id=' . $account->_id);
    }
}
