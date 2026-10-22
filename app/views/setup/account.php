<?php
//CLD: 2026-10-19T14:09:20+03:00: new view: the initial setup, step 3 (SetupController::account): the first account and its owner (models\setup\AccountForm; were on
//views/setup/index.php); $error: why the setup failed (e.g. the database of step 2), shown above the form
$this->pageTitle = \V::t('Setup');
$e = fn($s) => \V::app()->html->escape((string)$s);
//the field's options first: they override the defaults (`type` => password / select)
$v = fn($name, $label, $opts = []) => \V::app()->form->field($name, $opts + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t($label) ]);
?>

<main class="position-relative d-flex flex-column align-items-center h-100 pb-5 overflow-auto">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= $e(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:720px">
	<div class="card-body p-4">
	    <?= $this->renderFile('setup/_steps', [ 'crt' => 'account' ]) ?>
	    <?php if ($error): ?>
		<div class="alert alert-danger"><?= $e($error) ?> <a href="/setup/server" class="alert-link"><?= \V::t('Check the server settings') ?></a></div>
	    <?php endif; ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/setup/account' ])?>
	    <h5 class="mb-1"><?= \V::t('First account') ?></h5>
	    <p class="text-muted small mb-3"><?= \V::t('Its owner can invite the others. Database:') ?> <?= $e($server['mongo_db']) ?></p>
	    <?= $v('account_name', 'Company or team', [ 'input' => [ 'autocomplete' => 'organization', 'autofocus' => 1 ] ]) ?>
	    <div class="row g-2">
		<div class="col-sm-6"><?= $v('username', 'Username', [ 'input' => [ 'autocomplete' => 'username' ] ]) ?></div>
		<div class="col-sm-6"><?= $v('email', 'Email', [ 'input' => [ 'autocomplete' => 'email' ], 'help' => \V::t('Also receives the error reports') ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= $v('firstname', 'First name', [ 'input' => [ 'autocomplete' => 'given-name' ] ]) ?></div>
		<div class="col-sm-6"><?= $v('lastname', 'Last name', [ 'input' => [ 'autocomplete' => 'family-name' ] ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= $v('new_password', 'Password', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ],
		    'help' => \V::t('At least 8 characters, with lower and upper case letters, a digit and a symbol') ]) ?></div>
		<div class="col-sm-6"><?= $v('cfm_password', 'Password again', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ] ]) ?></div>
	    </div>

	    <div class="d-flex justify-content-between pt-3">
		<a class="btn btn-outline-secondary" href="/setup/server"><i class="bi bi-arrow-left"></i> <?= \V::t('Back') ?></a>
		<button class="btn btn-primary" type="submit"><?= \V::t('Set up') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	</div>
    </div>
</main>
