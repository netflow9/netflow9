<?php
//create an MCP server token (modal: its name)
$form_begin = \V::app()->form->create($token, [ 'action' => \V::app()->createUrl('/SysMCPTools/token/create') ]);
$body = $token->schema['name']->input()
    . "<div class='form-text'>The token acts as you (" . \V::app()->html->escape((string)\V::app()->user->name) . "), within your permissions, on this project only. It is shown once.</div>";
$form_end = \V::app()->form->end();

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary'>Create</button>
";

$modal = new \sys\html\Modal([
    'title' => 'New MCP server token',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
]);
echo $modal->render();
