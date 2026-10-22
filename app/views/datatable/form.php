<?php
$retUrl = \V::app()->request->query['ret'] ?? '/datatable/list';
if ($dt->exists()) {
    $t_ok = 'Update';
    $t_title = 'Update Data Table';
    $actionUrl = \V::app()->createUrl('', [ 'ret' => $retUrl, 'id' => $dt->_id ]);
} else {
    $t_ok = 'Create';
    $t_title = 'Create Data Table';
    $actionUrl = \V::app()->createUrl('', [ 'ret' => $retUrl ]);
}
$form_begin = \V::app()->form->create($dt, [ 'action' => $actionUrl ]);

$body = '';
foreach(['name' ] as $f)
    $body .= $dt->schema[$f]->input();

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
