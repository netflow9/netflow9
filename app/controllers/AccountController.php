<?php

namespace controllers;

class AccountController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
	//CLD: 2026-11-06T23:25:44+02:00: the request's account (rt.account, set at sign-in), for the actions
	$this->account = \V::app()->config('rt.account');
    }

    //several tabs (?__tab=):
    // general (the settings, Account::update) and usage (Account::usage); the first one the user may see by default
    // services (the installation's services, util\Services): without the MultiTenant plugin, for the account's owners (with it: the super-admins' page /services/index)
    // access (Data access: the projects' MCP server, what the Assistant may read; Account::update, the owners)
    //     the MCP server only (what the Assistant may read: its settings)
    // inbound (the account's inbound mail prefix, InboundMailSettings): for who may change the account, where Haraka is found
    // tools (the installation's tool servers, util\ToolServers): without the MultiTenant plugin, for the owners (with it: /toolservers/index)
    // ai (what the agents may do: models\agent\AgentPolicy): for the owners (util\Services::ownerSignedIn: the role `owner`)
    public function settings($req, $resp) {
	$tabs = array_keys(array_filter([ 'general' => \V::app()->user->can('Account::update'), 'access' => \V::app()->user->can('Account::update'), 'ai' => \util\Services::ownerSignedIn(),
	    'inbound' => \V::app()->user->can('Account::update') && \util\InboundMail::found(), 'usage' => \V::app()->user->can('Account::usage'),
	    'services' => !\util\Services::adminPlugin() && \util\Services::allowed(),
	    'tools' => !\util\Services::adminPlugin() && \util\Services::allowed() ]));
	if (!$tabs)
	    \V::app()->error(403, 'Unauthorized');
	$tab = (string)($req->query['__tab'] ?? '');
	if (!in_array($tab, $tabs, true))
	    $tab = $tabs[0];

	$account = $this->account;

	if ($tab === 'inbound') {
	    //new settings (not saved yet) for an account without them
	    $settings = \V::app()->om->findOne('InboundMailSettings', [ 'aid' => $account->_id ])
		?: \V::app()->om->create('InboundMailSettings', [ 'aid' => $account->_id, 'prefix' => \models\InboundMailSettings::DEFAULT_PREFIX ]);
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

	//CLD: 2026-11-02T10:00:00+02:00: the AI policy: the switches name the families allowed (denied_families: the others); a new document saved only when something is posted
	if ($tab === 'ai') {
	    $policy = \models\agent\AgentPolicy::ofAccount($account->_id);
	    if ($req->is('post')) {
		$data = $req->data;
		$data['denied_families'] = array_values(array_diff(array_keys(\util\AgentTools::families()), array_map('strval', (array)($data['allowed_families'] ?? []))));
		$data['allowed_writes'] = array_map('strval', (array)($data['allowed_writes'] ?? []));
		unset($data['allowed_families']);
		$policy->assign($data, true);
		$policy->aid = $account->_id;
		if ($policy->validates() && $policy->save())
		    $this->addToast('success', 'OK', 'AI policy saved');
		else
		    $this->addToast('error', 'Not saved', 'Check the settings marked in the form');
	    }
	    $spent = \models\agent\AgentUsage::spent($account->_id);
	    $this->render('settings', compact('account', 'tab', 'tabs', 'policy', 'spent'));
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

	//Data access: the projects whose MCP server is on (switches: mcp_projects[]; only the account's projects are kept)
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
	    $account->assign($req->data, true);

	    if ($account->validates()) {
		$account->save();
		$this->addToast('success', 'OK', 'Preferences saved!');
	    }
	}


	$this->render('settings', compact('account', 'tab', 'tabs'));
    }
}