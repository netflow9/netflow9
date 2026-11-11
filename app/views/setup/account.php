<?php
//the initial setup, step 3 (SetupController::account): the first account and its owner (models\setup\AccountForm); $error: why the setup failed
//(e.g. the database of step 2), shown above the form
$this->pageTitle = \V::t('Setup');
//the field's options first: they override the defaults (`type` => password / select)
?>

<main class="position-relative d-flex flex-column align-items-center h-100 pb-5 overflow-auto">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= \V::app()->html->escape(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:720px">
	<div class="card-body p-4">
	    <?= $this->renderFile('setup/_steps', [ 'crt' => 'account' ]) ?>
	    <?php if ($error): ?>
		<div class="alert alert-danger"><?= \V::app()->html->escape($error) ?> <a href="/setup/server" class="alert-link"><?= \V::t('Check the server settings') ?></a></div>
	    <?php endif; ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/setup/account' ])?>
	    <h5 class="mb-1"><?= \V::t('First account') ?></h5>
	    <p class="text-muted small mb-3"><?= \V::t('Its owner can invite the others. Database:') ?> <?= \V::app()->html->escape($server['mongo_db']) ?></p>
	    <?= \V::app()->form->field('account_name', [ 'input' => [ 'autocomplete' => 'organization', 'autofocus' => 1 ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Company or team') ]) ?>
	    <div class="row g-2">
		<div class="col-sm-6"><?= \V::app()->form->field('username', [ 'input' => [ 'autocomplete' => 'username' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Username') ]) ?></div>
		<div class="col-sm-6"><?= \V::app()->form->field('email', [ 'input' => [ 'autocomplete' => 'email' ], 'help' => \V::t('Also receives the error reports') ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Email') ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= \V::app()->form->field('firstname', [ 'input' => [ 'autocomplete' => 'given-name' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('First name') ]) ?></div>
		<div class="col-sm-6"><?= \V::app()->form->field('lastname', [ 'input' => [ 'autocomplete' => 'family-name' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Last name') ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= \V::app()->form->field('new_password', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ],
		    'help' => \V::t('At least 8 characters, with lower and upper case letters, a digit and a symbol') ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Password') ]) ?></div>
		<div class="col-sm-6"><?= \V::app()->form->field('cfm_password', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Password again') ]) ?></div>
	    </div>

	    <div class="d-flex justify-content-between pt-3">
		<a class="btn btn-outline-secondary" href="/setup/server"><i class="bi bi-arrow-left"></i> <?= \V::t('Back') ?></a>
		<button class="btn btn-primary" type="submit"><?= \V::t('Set up') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	</div>
    </div>
</main>
