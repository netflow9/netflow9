<?php
//CLD: 2026-10-25T11:20:00+02:00: new view: edit a user (UserController::update), a modal as create: name, email, role, state; Send sign-in link (UserController::send_link) for a
//user who signs in with a password here
$this->pageTitle = 'User::update';

$form_begin = \V::app()->form->create($user, [ 'action' => \V::app()->createUrl('/user/update', [ 'id' => (string)$user->_id ]) ]);

$body = '';
foreach(['firstname', 'lastname', 'email', 'role', 'state'] as $f)
    $body .= $user->schema[$f]->input();

$form_end = \V::app()->form->end();

$sendLink = '';
if ($canSendLink)
    $sendLink = \V::app()->html->link(
	'<i class="bi bi-envelope"></i> Send sign-in link',
	\V::app()->createUrl('/user/send_link', [ 'id' => (string)$user->_id ]),
	[ 'class' => 'btn btn-outline-secondary me-auto', 'escape' => false, 'data-method' => 'post',
	  'data-confirm' => 'Email ' . (string)$user->email . ' a link to choose a new password? It works once, for 7 days.' ]
    );

$footer = $sendLink . '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Save</button>
';

$modal = new \sys\html\Modal([
    'title' => 'Edit user',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
]);
echo $modal->render();
