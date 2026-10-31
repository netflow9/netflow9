<?php

/*
    the Assistant's panel on the right of the workflow designer,
    rendered by views/workflow/index.php; $wf, $viewVersion.
    Opened / closed by the top-bar button (views/workflow/index.php), as last time + a close button
*/
use util\WorkflowAssistant;

//the draft only, for people who may change it; Claude or an OpenAI-compatible model
//CLD: 2026-10-20T10:00:00+03:00: the server, key and model of Assistant settings only (were a credential of the workflow's project, or the account's server): no credential
//to choose; not set up (no model): a link to the settings
$assistantOn = !$viewVersion && \V::app()->user->can('Workflow::update', [ 'object' => $wf ]);
$assistantSettings = \V::app()->om->findOne('AssistantSettings', [ 'aid' => $wf->aid ]);
$assistantReady = WorkflowAssistant::isReady($assistantSettings);
//CLD: 2026-10-23T23:45:00+03:00: + the kept conversation (models\AssistantChat: messages, chatUrl, clearUrl) and who is viewing (uid: the others' messages are signed)
$assistantJs = \util\JSON::encode([
    'wfid' => (string)$wf->_id,
    'url' => \V::app()->createUrl('/assistant/ask', [ 'id' => (string)$wf->_id ]),
    'chatUrl' => \V::app()->createUrl('/assistant/chat_add', [ 'id' => (string)$wf->_id ]),
    'clearUrl' => \V::app()->createUrl('/assistant/chat_clear', [ 'id' => (string)$wf->_id ]),
    'uid' => (string)\V::app()->user->_id,
    'messages' => $assistantOn && $assistantReady ? \models\AssistantChat::messagesOf($wf) : [],
]);

//after the designer's own script (new app.Workflow): the panel as last time, then the conversation (only when it is there: the draft, people who may change it, set up)
//CLD: 2026-10-19T21:40:00+03:00: new app.WfAssistant (was app.wfassistant.panel() + mount()): it sets up both; the one of a previous designer page is destroyed by it
\V::app()->cs->addPageScript("
+function() {
    new app.WfAssistant(document.getElementById('wf-assistant-panel'), {$assistantJs});
}();
");
?>

<!-- ---------- Right panel: the Assistant ---------- -->
<div id="wf-assistant-panel" class="inspector border-start bg-white position-relative d-none" data-min-width="320" data-max-width="640" data-resize-direction="horizontal">
    <div class="inspector-resize-handle"></div>
    <?php //the conversation, the request. No `name` on its inputs: the panel is inside the canvas form ?>
    <div class="inspector-body wf-assistant p-0">
	<div class="d-flex align-items-center gap-2 p-2 border-bottom">
	    <i class="bi bi-stars text-primary"></i>
	    <span class="fw-semibold">Assistant</span>
	    <div class="ms-auto d-flex align-items-center gap-1">
		<?php if ($assistantOn && $assistantReady): ?>
		<button type="button" class="btn btn-sm btn-icon wfa-clear" title="New conversation"><i class="bi bi-plus-square"></i></button>
		<?php endif; ?>
		<button type="button" class="btn btn-sm btn-icon wfa-close" title="Close the Assistant"><i class="bi bi-x-lg"></i></button>
	    </div>
	</div>
	<?php if (!$assistantOn): ?>
	<div class="p-3 small text-muted">
	    <?= $viewVersion ? 'You are viewing a published version: the Assistant works on the draft (Back to the draft).' : 'You may not change this workflow, so the Assistant cannot build in it.' ?>
	</div>
	<?php elseif (!$assistantReady): ?>
	<div class="p-3 small text-muted">
	    <p class="mb-2">Describe the workflow you need in your own words, and the Assistant builds its steps and connections here.</p>
	    <?php //CLD: 2026-10-20T10:00:00+03:00: not set up: Assistant settings (was: add a credential to the project) ?>
	    <?php //CLD: 2026-10-22T14:00:00+03:00: the providers: System, or your own key (Claude, OpenAI, Gemini, Ollama, Lemonade) ?>
	    <p class="mb-0"><i class="bi bi-plug me-1"></i>It is not set up yet: in <a href="<?= \V::app()->createUrl('/assistant/settings') ?>">Assistant settings</a>, choose System, or a provider (Claude, OpenAI, Gemini, Ollama, Lemonade) with your key, and a model<?= \V::app()->user->can('Account::update') ? '' : ' (ask who manages the account)' ?>, then reopen this workflow.</p>
	</div>
	<?php else: ?>
	    <div class="wfa-messages flex-grow-1 overflow-auto px-3 py-2" style="min-height: 0"></div>
	    <div class="border-top p-2">
		<textarea class="form-control form-control-sm mb-2 wfa-input" rows="3" placeholder="Describe the workflow or the change&hellip; (Enter sends, Shift+Enter: new line)"></textarea>
		<div class="d-flex align-items-center gap-2">
		    <span class="small text-muted text-truncate">Changes are saved automatically (Undo: Ctrl+Z)</span>
		    <button type="button" class="btn btn-sm btn-primary ms-auto wfa-send"><i class="bi bi-send me-1"></i>Send</button>
		    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto wfa-stop d-none"><i class="bi bi-stop-fill me-1"></i>Stop</button>
		</div>
	    </div>
	<?php endif; ?>
    </div>
</div>
