<?php
$this->pageTitle = 'User::create';

$form_begin = \V::app()->form->create($user, [ 'action' => \V::app()->createUrl('') ]);

$body = '';
foreach(['firstname', 'lastname', 'email', 'role'] as $f)
    $body .= $user->schema[$f]->input();

$form_end = \V::app()->form->end();

$footer = '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Create</button>
';

$modal = new \sys\html\Modal([
    'title' => 'Create a new user',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
]);
echo $modal->render();
