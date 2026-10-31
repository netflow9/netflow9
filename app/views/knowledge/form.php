<?php
//CLD: 2026-10-31T01:00:00+02:00: new: a knowledge base's settings in a dialog (KnowledgeController::create / update): name, description, chunk size, overlap; the embedding model is the
//account's (shown, not chosen here)
use models\KnowledgeBase;

if ($kb->exists()) {
    $t_ok = 'Save';
    $t_title = 'Knowledge base settings';
    $actionUrl = \V::app()->createUrl('', [ 'id' => $kb->_id ]);
} else {
    $t_ok = 'Create';
    $t_title = 'Create a knowledge base';
    $actionUrl = \V::app()->createUrl('');
}
$form_begin = \V::app()->form->create($kb, [ 'action' => $actionUrl ]);

$body = $kb->schema['name']->input();
$body .= $kb->schema['description']->input([ 'input' => [ 'rows' => 3 ] ]);

//CLD: 2026-10-31T02:00:00+02:00: chunk size, overlap and the embedding model in a closed "Advanced…" section, as the steps' technical settings (app.smartform's section: a link with a
//chevron, a collapse); open when one of them is not at its default or is invalid
$errors = (array)$kb->errors();
$open = (int)$kb->chunkSize !== KnowledgeBase::DEFAULT_CHUNK_SIZE || (int)$kb->overlap !== KnowledgeBase::DEFAULT_OVERLAP || isset($errors['chunkSize']) || isset($errors['overlap']);
$sectionId = 'kb-advanced-' . uniqid();
$advancedTpl = '
<style>.kb-advanced-toggle i { transition: transform .2s; } .kb-advanced-toggle[aria-expanded=true] i { transform: rotate(90deg); }</style>
<div class="form-section mb-3">
    <button type="button" class="btn btn-link p-0 text-decoration-none d-inline-flex align-items-center gap-1 kb-advanced-toggle" data-bs-toggle="collapse" data-bs-target="#{:id}"
	aria-controls="{:id}" aria-expanded="{:expanded}"><i class="bi bi-chevron-right small"></i><span>Advanced…</span></button>
    <div class="collapse{:show}" id="{:id}"><div class="pt-3">
	<div class="row">
	    <div class="col-6">{:chunkSize}</div>
	    <div class="col-6">{:overlap}</div>
	</div>
	{:note}
	<div class="small text-muted"><i class="bi bi-cpu me-1"></i>Embedding model: <code>{:model}</code> (the account\'s: Settings, Knowledge bases)</div>
    </div></div>
</div>
';
$model = $kb->exists() && (string)$kb->model !== '' ? (string)$kb->model : (string)$settings->model;
$body .= \util\VString::insert($advancedTpl, [
    'id' => $sectionId,
    'expanded' => $open ? 'true' : 'false',
    'show' => $open ? ' show' : '',
    'chunkSize' => $kb->schema['chunkSize']->input([ 'input' => [ 'inputmode' => 'numeric' ] ]),
    'overlap' => $kb->schema['overlap']->input([ 'input' => [ 'inputmode' => 'numeric' ] ]),
    'note' => $kb->exists() && (int)$kb->chunks > 0
	? '<div class="form-text mb-2">A new chunk size or overlap applies to the documents added from now on; the others keep how they were cut until they are added again.</div>'
	: '',
    'model' => \V::app()->html->escape($model !== '' ? $model : 'none set'),
]);

$form_end = \V::app()->form->end();

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary'>{$t_ok}</button>
";

$modal = new \sys\html\Modal([
    'title' => $t_title,
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
]);
echo $modal->render();
