<?php
/*
    CLD: 2026-10-31T01:00:00+02:00: new: the account's embedding model (KnowledgeController::settings; models\EmbeddingSettings): provider, Server URL, API key, then Connect
    (KnowledgeController::connect) lists the server's models (the embedding ones first) for Model; the saved model is shown until the next Connect (the page connects by itself
    when there is one). $stale: the knowledge bases indexed with another model (re-index them)
*/
use models\EmbeddingSettings;

$this->pageTitle = 'Knowledge base settings';

$model = (string)$settings->model;
$connectJs = \util\JSON::encode([ 'url' => \V::app()->createUrl('/knowledge/connect'), 'auto' => $model !== '', 'urls' => EmbeddingSettings::DEFAULT_URLS ]);

\V::app()->cs->addPageScript("+function(o) {" . <<<'JS'
    const form = document.getElementById('embedding-settings');
    if (!form)
	return;
    const provider = form.querySelector('[name=provider]');
    const select = form.querySelector('#embedding-model');
    const btn = form.querySelector('.btn-embedding-connect');
    const status = form.querySelector('.embedding-connect-status');
    const urlInput = form.querySelector('[name=url]');
    const esc = (v) => app.utils.escapeHtml(String(v ?? ''));

    const setStatus = (cls, html) => {
	status.className = 'embedding-connect-status small ' + cls;
	status.innerHTML = html;
    };
    //the models offered: the server's, the current one chosen when it is among them (else the first)
    const setModels = (models) => {
	const current = select.value;
	select.innerHTML = models.map((m) => `<option value="${esc(m.id)}">${esc(m.label)}</option>`).join('');
	select.value = models.some((m) => m.id === current) ? current : models[0].id;
    };
    const connect = () => {
	btn.disabled = true;
	setStatus('text-muted', '<span class="spinner-border spinner-border-sm me-1"></span>Connecting&hellip;');
	$.ajax({
	    url: o.url,
	    method: 'POST',
	    dataType: 'json',
	    headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
	    data: { provider: provider.value, url: urlInput.value, api_key: form.querySelector('[name=api_key]').value },
	}).done((r) => {
	    setModels(r.models || []);
	    setStatus('text-success', `<i class="bi bi-check-circle me-1"></i>Connected: ${(r.models || []).length} model(s). Choose one, then Save.`);
	}).fail((x, s) => {
	    setStatus('text-danger', '<i class="bi bi-exclamation-triangle me-1"></i>' + esc((x.responseJSON && x.responseJSON.error) || 'Could not connect (' + (x.status || s) + ')'));
	}).always(() => {
	    btn.disabled = false;
	});
    };

    //CLD: 2026-10-31T20:00:00+02:00: the API key's "Stored" placeholder (sys\fields\Field_password) only while the provider and the server are the saved ones: otherwise the saved key
    //is not kept (KnowledgeController::settings)
    const keyInput = form.querySelector('[name=api_key]');
    const storedHint = keyInput.placeholder;
    const saved = [ provider.value, urlInput.value.trim() ];
    const keyHint = () => {
	keyInput.placeholder = provider.value === saved[0] && urlInput.value.trim() === saved[1] ? storedHint : '';
    };
    provider.addEventListener('change', keyHint);
    urlInput.addEventListener('input', keyHint);
    provider.addEventListener('change', () => {
	urlInput.placeholder = o.urls[provider.value] || '';
	select.innerHTML = '<option value="">Connect to list the models</option>';
	setStatus('text-muted', 'Not connected');
	//the saved key is not kept for another provider (KnowledgeController::settings)
	form.querySelector('[name=api_key]').value = '';
    });
    form.querySelectorAll('[name=url], [name=api_key]').forEach((el) => el.addEventListener('input', () => setStatus('text-muted', 'Changed: Connect again to check')));
    btn.addEventListener('click', (e) => {
	e.preventDefault();
	connect();
    });
    urlInput.placeholder = o.urls[provider.value] || '';
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
	[ 'label' => 'Knowledge base settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($settings, [ 'id' => 'embedding-settings' ]) ?>
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Knowledge base settings</h5>
		<span class="text-muted">The embedding model that turns the documents of the knowledge bases into searchable vectors, for every project of the account</span>
	    </div>
	    <div class="flex-fill"></div>
	</div>

	<div class="pt-4 w-50">
	    <?= $settings->schema['provider']->input([ 'cols' => '3,9' ]) ?>
	    <?= $settings->schema['url']->input([ 'cols' => '3,9' ]) ?>
	    <?= $settings->schema['api_key']->input([ 'cols' => '3,9', 'input' => [ 'autocomplete' => 'new-password' ] ]) ?>

	    <div class="form-group row mb-2">
		<div class="col-sm-9 offset-sm-3 d-flex align-items-center gap-2">
		    <button type="button" class="btn btn-sm btn-outline-primary btn-embedding-connect"><i class="bi bi-plug me-1"></i>Connect</button>
		    <span class="embedding-connect-status small text-muted"><?= $model !== '' ? 'Saved model: ' . \V::app()->html->escape($model) : 'Not connected' ?></span>
		</div>
	    </div>

	    <?= \V::app()->form->field('model', [
		'id' => 'embedding-model',
		'type' => 'select',
		'label' => 'Embedding model',
		'help' => 'The models the server lists (Connect), the embedding ones first',
		'list' => $model !== '' ? [ $model => $model ] : [ '' => 'Connect to list the models' ],
		'value' => $model,
		'cols' => '3,9',
	    ]) ?>

	    <div class="form-group row mb-2">
		<div class="col-sm-9 offset-sm-3">
		    <div class="form-text">E.g. <code>nomic-embed-text</code> or <code>bge-m3</code> (Ollama), <code>text-embedding-3-small</code> (OpenAI), <code>gemini-embedding-001</code> (Gemini).
			Every knowledge base is indexed with this model: after a change, re-index them (their documents' chunks are embedded again).</div>
		</div>
	    </div>

	    <?php if ($stale): ?>
	    <div class="alert alert-warning small mt-3">
		<i class="bi bi-exclamation-triangle me-1"></i>Indexed with another model (re-index them on their page before they are searched again):
		<ul class="mb-0 mt-1">
		    <?php foreach($stale as $kb):
			//a link for those of the current project (the page opens the current project's)
			$project = \V::app()->om->findOne('Project', [ '_id' => $kb->project, 'aid' => $kb->aid ]);
			$here = (string)$kb->project === (string)\V::app()->user->crtProject;
		    ?>
		    <li><?php if ($here): ?><a href="<?= \V::app()->createUrl('/knowledge/manage', [ 'id' => (string)$kb->_id ]) ?>"><?= \V::app()->html->escape($kb->name) ?></a><?php else: ?><?= \V::app()->html->escape($kb->name) ?><?php endif; ?>
			&middot; project <?= \V::app()->html->escape($project ? (string)$project->name : '?') ?> &middot; <code><?= \V::app()->html->escape($kb->model) ?></code></li>
		    <?php endforeach; ?>
		</ul>
	    </div>
	    <?php endif; ?>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
</div>
