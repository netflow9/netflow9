<?php

/*
    CLD: 2026-10-19T20:10:00+03:00: new: the workflow designer's Assistant: describe a workflow or a change in your own words, a model builds it on the canvas.
    Enabled in app/plugins.php; without it the designer has no Assistant button / panel, the Settings menu no Assistant link.
      util/WorkflowAssistant.php         the agent loop (Anthropic Messages API or an OpenAI-compatible server)
      controllers/AssistantController    /Assistant/assistant/ask (the designer's requests), /Assistant/assistant/script (js/)
      js/app.wfassistant.js              the panel
      models/AssistantChat.php           CLD: 2026-10-23T23:45:00+03:00: the conversation of each workflow (/Assistant/assistant/chat_add, chat_clear)
      views/designer_panel.php           the panel in the designer (hook workflow:designer-panels); the top-bar button: designerToolbar (workflow:designer-toolbar)
    //CLD: 2026-10-23T10:00:00+03:00: the provider System is the System AI (plugins\SystemAI: its server, credits and rates; enabled before this plugin in app/plugins.php)
*/

namespace plugins\Assistant;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'AssistantSettings' => [
		'class' => __NAMESPACE__ . '\\models\\AssistantSettings',
	    ],
	    //CLD: 2026-10-22T14:00:00+03:00: what the Assistant spent per account and month (the monthly budget)
	    'AssistantUsage' => [
		'class' => __NAMESPACE__ . '\\models\\AssistantUsage',
	    ],
	    //CLD: 2026-10-23T10:00:00+03:00: the System provider's credits: plugins\SystemAI (were AssistantWallet, AssistantCreditLedger here)
	    //CLD: 2026-10-23T23:45:00+03:00: the conversation of each workflow (was the browser tab's sessionStorage)
	    'AssistantChat' => [
		'class' => __NAMESPACE__ . '\\models\\AssistantChat',
		//CLD: 2026-10-24T00:04:00+03:00: not in the audit log: deleting a workflow would copy its conversation there (SOC2.md gap 11); the workflow's deletion is recorded
		'auditLog' => false,
	    ],
	]);
	//CLD: 2026-10-23T23:45:00+03:00: a deleted workflow's conversation goes with it (in every mode: the console deletes workflows too)
	\V::app()->hooks->addHook('workflow:before-delete', [ models\AssistantChat::class, 'beforeWorkflowDelete' ]);

	if (\V::app()->config('app.mode') != 'web')
	    return;

	if (!\V::app()->user->isGuest) {
	    //CLD: 2026-10-25T18:45:00+02:00: the panel's CSS moved to the theme (_workflow.scss: .wf-assistant)
	    \V::app()->cs->addPageScriptFile(
		    \V::app()->am->publishFile(__DIR__ . '/js/app.wfassistant.js')
	    );
	}

	//CLD: 2026-10-22T23:00:00+03:00: the administration (the Admin plugin): an account's System credits (plan, top-ups, ledger: controllers/AdminController), the System server and the
	//credit rates; read across the accounts (the super-admin's own account is not the one shown)
	//CLD: 2026-10-23T10:00:00+03:00: the credits, the System AI and the rates: plugins\SystemAI; here the account's Assistant: its provider, its spending on its own key
	\V::app()->hooks->addHook('admin:account-panels', function($account) {
	    $om = \V::app()->om;
	    $data = $om->acrossAccounts(fn() => [
		'settings' => $om->findOne('AssistantSettings', [ 'aid' => $account->_id ]),
		'usage' => models\AssistantUsage::current($account->_id),
	    ]);
	    echo \V::app()->view->renderFile('/plugins/Assistant/views/admin/account_panel', $data);
	});

	\V::app()->hooks->addHook('workflow:designer-toolbar', [ $this, 'designerToolbar' ]);
	\V::app()->hooks->addHook('workflow:designer-panels', [ $this, 'designerPanel' ]);
	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update'))
		echo '<a href="' . \V::app()->createUrl('/Assistant/assistant/settings') . '" class="nav-link"><i class="bi bi-stars"></i> Assistant</a>';
	});
    }

    //the top-bar button of the designer (views/workflow/index.php): opens / closes the panel (app.WfAssistant)
    public function designerToolbar($wf, $viewVersion) {
	echo '<a href="#" class="px-1 btn btn-sm btn-icon btn-assistant-toggle d-inline" title="Assistant: describe the workflow or a change" aria-pressed="false"><i class="bi bi-stars"></i></a>';
    }

    //the panel on the right of the designer's canvas
    public function designerPanel($wf, $viewVersion) {
	echo \V::app()->view->renderFile('/plugins/Assistant/views/designer_panel', [ 'wf' => $wf, 'viewVersion' => $viewVersion ]);
    }
}
