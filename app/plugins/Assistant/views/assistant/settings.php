<?php
/*
    the Assistant's settings page (AssistantController::settings;
    Only the settings of the chosen provider are shown (all are saved)
    //CLD: 2026-10-20T10:00:00+03:00: one server for both providers (Server URL, API key), then Connect (AssistantController::connect): when it answers, the models it lists
    //are offered in Model; Effort for Claude only. The saved model is shown until the next Connect (the page connects by itself when there is one)
*/
$this->pageTitle = 'Assistant settings';

$model = (string)$settings->model;
$connectJs = \util\JSON::encode([ 'url' => \V::app()->createUrl('/Assistant/assistant/connect'), 'auto' => $model !== '' ]);

//CLD: 2026-10-20T10:00:00+03:00: nowdoc: the script's text as is
\V::app()->cs->addPageScript("+function(o) {" . <<<'JS'
    const form = document.getElementById('assistant-settings');
    if (!form)
	return;
    const provider = form.querySelector('[name=provider]');
    const select = form.querySelector('#assistant-model');
    const btn = form.querySelector('.btn-assistant-connect');
    const status = form.querySelector('.assistant-connect-status');
    const esc = (v) => app.utils.escapeHtml(String(v ?? ''));

    //the settings of the chosen provider only
    const show = () => form.querySelectorAll('[data-assistant-provider]').forEach((el) => el.classList.toggle('d-none', el.dataset.assistantProvider !== provider.value));

    const setStatus = (cls, html) => {
	status.className = 'assistant-connect-status small ' + cls;
	status.innerHTML = html;
    };

    //the models offered: the server's, the current one chosen when it is among them (else the first)
    const setModels = (models) => {
	const current = select.value;
	select.innerHTML = models.map((m) => `<option value="${esc(m.id)}">${esc(m.label)}</option>`).join('');
	select.value = models.some((m) => m.id === current) ? current : models[0].id;
    };

    //another provider: the models listed no longer apply (saved so, the Assistant is off until a model is chosen)
    const disconnected = () => {
	select.innerHTML = '<option value="">Connect to list the models</option>';
	setStatus('text-muted', 'Not connected');
    };

    const connect = () => {
	btn.disabled = true;
	setStatus('text-muted', '<span class="spinner-border spinner-border-sm me-1"></span>Connecting&hellip;');
	$.ajax({
	    url: o.url,
	    method: 'POST',
	    dataType: 'json',
	    headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
	    data: { provider: provider.value, url: form.querySelector('[name=url]').value, api_key: form.querySelector('[name=api_key]').value },
	}).done((r) => {
	    setModels(r.models || []);
	    setStatus('text-success', `<i class="bi bi-check-circle me-1"></i>Connected: ${(r.models || []).length} model(s). Choose one, then Save.`);
	}).fail((x, s) => {
	    setStatus('text-danger', '<i class="bi bi-exclamation-triangle me-1"></i>' + esc((x.responseJSON && x.responseJSON.error) || 'Could not connect (' + (x.status || s) + ')'));
	}).always(() => {
	    btn.disabled = false;
	});
    };

    provider.addEventListener('change', () => {
	show();
	disconnected();
    });
    form.querySelectorAll('[name=url], [name=api_key]').forEach((el) => el.addEventListener('input', () => setStatus('text-muted', 'Changed: Connect again to check')));
    btn.addEventListener('click', (e) => {
	e.preventDefault();
	connect();
    });
    show();
    if (o.auto)
	connect();
JS . "}({$connectJs});");
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Assistant settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($settings, [ 'id' => 'assistant-settings' ]) ?>
	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Assistant settings</h5>
		<span class="text-muted">Who builds workflows from your descriptions in the workflow designer, for every project of the account</span>
	    </div>
	    <div class="flex-fill"></div>
	</div>

	<div class="pt-4 w-50">
	    <?= $settings->schema['provider']->input([ 'cols' => '3,9' ]) ?>
	    <?= $settings->schema['url']->input([ 'cols' => '3,9' ]) ?>
	    <?= $settings->schema['api_key']->input([ 'cols' => '3,9', 'input' => [ 'autocomplete' => 'new-password' ] ]) ?>

	    <?php //CLD: 2026-10-20T10:00:00+03:00: Connect: the server's models in Model ?>
	    <div class="form-group row mb-2">
		<div class="col-sm-9 offset-sm-3 d-flex align-items-center gap-2">
		    <button type="button" class="btn btn-sm btn-outline-primary btn-assistant-connect"><i class="bi bi-plug me-1"></i>Connect</button>
		    <span class="assistant-connect-status small text-muted"><?= $model !== '' ? 'Saved model: ' . \V::app()->html->escape($model) : 'Not connected' ?></span>
		</div>
	    </div>

	    <?= \V::app()->form->field('model', [
		'id' => 'assistant-model',
		'type' => 'select',
		'label' => 'Model',
		'help' => 'The models the server lists (Connect)',
		'list' => $model !== '' ? [ $model => $model ] : [ '' => 'Connect to list the models' ],
		'value' => $model,
		'cols' => '3,9',
	    ]) ?>

	    <div data-assistant-provider="anthropic">
		<?= $settings->schema['effort']->input([ 'cols' => '3,9' ]) ?>
	    </div>
	    <div data-assistant-provider="openai">
		<div class="form-text">The Server URL is reached from this application's server, so localhost means that machine. A local model needs a large context (the step types alone take about 10,000 tokens) and good tool calling.</div>
	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
</div>
