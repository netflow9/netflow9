<?php

namespace controllers;

class AccountController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    //CLD: 2026-10-31T11:32:07+02:00: two tabs (?__tab=): general (the settings, Account::update) and usage (was the action usage, Account::usage); the first one the user may see by default
    //CLD: 2026-10-31T19:30:00+02:00: + services (the installation's services, util\Services): without the Admin plugin, for the account's owners (with it: the super-admins' page /services/index)
    public function settings($req, $resp) {
	//CLD: 2026-11-01T02:00:00+02:00: + access (Data access: the projects' MCP server, what the Assistant may read; Account::update, the owners)
	//CLD: 2026-11-01T03:00:00+02:00: the MCP server only (what the Assistant may read: its settings)
	$tabs = array_keys(array_filter([ 'general' => \V::app()->user->can('Account::update'), 'access' => \V::app()->user->can('Account::update'),
	    //CLD: 2026-11-01T16:11:37+02:00: + inbound (the account's inbound mail prefix, InboundMailSettings; was the InboundMail plugin's page): for who may change the account, where Haraka is found
	    'inbound' => \V::app()->user->can('Account::update') && \util\InboundMail::found(), 'usage' => \V::app()->user->can('Account::usage'),
	    'services' => !\util\Services::adminPlugin() && \util\Services::allowed(),
	    //CLD: 2026-11-01T11:55:18+02:00: + tools (the installation's tool servers, util\ToolServers): without the MultiTenant plugin, for the owners (with it: /toolservers/index)
	    'tools' => !\util\Services::adminPlugin() && \util\Services::allowed() ]));
	if (!$tabs)
	    \V::app()->error(403, 'Unauthorized');
	$tab = (string)($req->query['__tab'] ?? '');
	if (!in_array($tab, $tabs, true))
	    $tab = $tabs[0];

	$account = \V::app()->config('rt.account');

	//CLD: 2026-11-01T16:11:37+02:00: Inbound mail: the prefix of the account's Email (inbound) addresses (was plugins\InboundMail\controllers\InboundController::settings)
	if ($tab === 'inbound') {
	    //new settings (not saved yet) for an account without them
	    $settings = \V::app()->om->findOne('InboundMailSettings', [ 'aid' => $account->_id ])
		?: \V::app()->om->create('InboundMailSettings', [ 'aid' => $account->_id, 'prefix' => \models\InboundMailSettings::defaultPrefixFor($account->_id) ]);
	    if ($req->is('post')) {
		$settings->assign($req->data, true);
		if ($settings->validates() && $settings->save())
		    $this->addToast('success', 'OK', 'Inbound mail settings saved!');
		else
		    $this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	    }
	    $domain = \util\InboundMail::domain();
	    $this->render('settings', compact('account', 'tab', 'tabs', 'settings', 'domain'));
	    return;
	}

	if ($tab === 'tools') {
	    $servers = \util\ToolServers::all();
	    $open = \models\ToolServerSlot::open();
	    $enabled = \models\ToolServerAccess::serversOf($account->_id);
	    $this->render('settings', compact('account', 'tab', 'tabs', 'servers', 'open', 'enabled'));
	    return;
	}

	if ($tab === 'services') {
	    $services = \util\Services::withStatus();
	    $this->render('settings', compact('account', 'tab', 'tabs', 'services'));
	    return;
	}

	//CLD: 2026-11-01T02:00:00+02:00: Data access: the projects whose MCP server is on (switches: mcp_projects[]; only the account's projects are kept), what the Assistant may read
	//CLD: 2026-11-01T03:00:00+02:00: what the Assistant may read moved to the Assistant's settings (AssistantSettings data_access)
	if ($tab === 'access') {
	    $projects = [];
	    foreach(\V::app()->om->find('Project', [ 'aid' => $account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p)
		$projects[(string)$p->_id] = $p;
	    if ($req->is('post')) {
		$on = array_values(array_intersect(array_map('strval', (array)($req->data['mcp_projects'] ?? [])), array_keys($projects)));
		$account->mcp_projects = $on;
		if ($account->validates() && $account->save()) {
		    $this->addToast('success', 'OK', 'Data access saved');
		    $this->addEvent('sidebar::refresh');
		}
	    }
	    //CLD: 2026-11-01T03:30:00+02:00: the owners of the personal projects (named after them in the list: every user's is "Personal")
	    $owners = [];
	    $ownerIds = array_values(array_filter(array_map(fn($p) => $p->personal ? $p->owner : null, $projects)));
	    if ($ownerIds)
		foreach(\V::app()->om->find('User', [ 'aid' => $account->_id, '_id' => [ '$in' => $ownerIds ] ]) as $u)
		    $owners[(string)$u->_id] = $u;
	    $this->render('settings', compact('account', 'tab', 'tabs', 'projects', 'owners'));
	    return;
	}

	if ($tab === 'usage') {
	    $usage = \util\Tiers::usage($account);
	    $limits = \util\Tiers::limits($account);
	    $projects = [];
	    foreach(\V::app()->om->find('Project', [ 'aid' => $account->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $p)
		$projects[(string)$p->_id] = $p;
	    $this->render('settings', compact('account', 'tab', 'tabs', 'usage', 'limits', 'projects'));
	    return;
	}

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:44:56+03:00: only the form's field; `hostname` is part of data collection names, `state` is not user-settable (was the whole POST)
	    //CLD: 2026-10-10T18:57:33+03:00: assign with permissions (the form fields the user may update; was onlyFields enforce_mfa)
	    $account->assign($req->data, true);

	    if ($account->validates()) {
		$account->save();
		$this->addToast('success', 'OK', 'Preferences saved!');
	    }
	}


	$this->render('settings', compact('account', 'tab', 'tabs'));
    }

    //CLD: 2026-10-31T11:32:07+02:00: usage() removed: the Usage tab of the settings (what the account uses of its tier's limits, in all and per project)

}