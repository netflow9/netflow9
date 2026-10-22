<?php
if ($renderEmpty) {
    echo 'OK';
    return;
}

$actionUrl = \V::app()->createUrl('', [ 'id' => $wf->_id ]);
$form_begin = \V::app()->form->create($wf, [ 'action' => $actionUrl ]);

$bodyTpl = '
<div class="mb-2">
    <label class="form-label">Icon and workflow name</label>
    <div class="d-flex gap-2">
	{:icon}
	{:name}
    </div>
</div>
';
$body = \util\VString::insert($bodyTpl, [
    'icon' => $wf->schema['icon']->input([ 'bare' => true ]),
    'name' => $wf->schema['name']->input([ 'bare' => true ]),
]);

$form_end = \V::app()->form->end();

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary'>Update</button>
";

$modal = new \sys\html\Modal([
    'title' => 'Update name',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
    'size' => 'small',
]);
echo $modal->render();
