<?php

/*
    CLD: 2026-10-19T20:10:00+03:00: new: the workflow designer's Assistant (was in the core): describe a workflow or a change in your own words, a model builds it on the canvas.
    Enabled in app/plugins.php; without it the designer has no Assistant button / panel, the Settings menu no Assistant link.
      util/WorkflowAssistant.php         the agent loop (Anthropic Messages API or an OpenAI-compatible server; was util\WorkflowAssistant)
      controllers/AssistantController    /Assistant/assistant/ask (the designer's requests; was WorkflowController::assistant), /Assistant/assistant/script (js/)
      js/app.wfassistant.js              the panel (was public_html/js)
      views/designer_panel.php           the panel in the designer (hook workflow:designer-panels); the top-bar button: designerToolbar (workflow:designer-toolbar)
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
	    //CLD: 2026-10-22T15:00:00+03:00: the System provider's credits per account, and their ledger
	    'AssistantWallet' => [
		'class' => __NAMESPACE__ . '\\models\\AssistantWallet',
	    ],
	    'AssistantCreditLedger' => [
		'class' => __NAMESPACE__ . '\\models\\AssistantCreditLedger',
	    ],
	]);

	if (\V::app()->config('app.mode') != 'web')
	    return;

	if (!\V::app()->user->isGuest) {
	    \V::app()->cs->addPageCss("
		.wf-assistant .wfa-msg { max-width: 92%; overflow-wrap: anywhere; }
		.wf-assistant .wfa-user { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
		.wf-assistant .wfa-bot { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color-translucent); }
		.wf-assistant .wfa-input { resize: none; }
	    ");
	    \V::app()->cs->addPageScriptFile(
		    \V::app()->am->publishFile(__DIR__ . '/js/app.wfassistant.js')
	    );
	}

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
