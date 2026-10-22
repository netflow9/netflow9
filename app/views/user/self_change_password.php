<?php
$this->pageTitle = 'Change password';

$form_begin = \V::app()->form->create($form, [ 'action' => \V::app()->createUrl('') ]);

$body = '';
foreach(['crt_password', 'new_password', 'cfm_password'] as $f)
    $body .= $form->schema[$f]->input([ 'cols' => '4,8' ]);

$form_end = \V::app()->form->end();

$footer = '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Change</button>
';

$modal = new \sys\html\Modal([
    'title' => 'Change password',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
    'size' => '',
]);
echo $modal->render();
