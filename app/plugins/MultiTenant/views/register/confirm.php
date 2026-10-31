<?php
$this->pageTitle = \V::t('Create an account');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:460px">
	<div class="card-body p-4">
	<?php if (!$signup): ?>
	    <h5 class="mb-3 text-center"><?= \V::t('This link does not work any more') ?></h5>
	    <p class="mb-3"><?= \V::t('Links work once, for 24 hours. If you already finished, sign in; else ask for a new link.') ?></p>
	    <div class="d-flex gap-2 justify-content-center">
		<a class="btn btn-primary" href="/MultiTenant/register"><?= \V::t('Get a new link') ?></a>
		<a class="btn btn-outline-secondary" href="<?= \V::app()->user->getLoginUrl() ?>"><?= \V::t('Sign in') ?></a>
	    </div>
	<?php else: ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/MultiTenant/register/confirm?t=' . rawurlencode($token) ])?>
	    <h5 class="mb-1 text-center"><?= \V::t('Create your account') ?></h5>
	    <p class="text-center text-muted small mb-3"><?= \V::app()->html->escape((string)$signup->email) ?></p>
	    <?php
	    echo \V::app()->form->field('account_name', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Company or team'), 'input' => [ 'autofocus' => 1, 'autocomplete' => 'organization' ] ]);
	    echo '<div class="row g-2"><div class="col-sm-6">';
	    echo \V::app()->form->field('firstname', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('First name'), 'input' => [ 'autocomplete' => 'given-name' ] ]);
	    echo '</div><div class="col-sm-6">';
	    echo \V::app()->form->field('lastname', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Last name'), 'input' => [ 'autocomplete' => 'family-name' ] ]);
	    echo '</div></div>';
	    echo \V::app()->form->field('new_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('Password'), 'input' => [ 'autocomplete' => 'new-password' ],
		'help' => \V::t('At least 8 characters, with lower and upper case letters, a digit and a symbol') ]);
	    echo \V::app()->form->field('cfm_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('Password again'), 'input' => [ 'autocomplete' => 'new-password' ] ]);
	    ?>
	    <div class="form-group mb-2 text-center pt-3">
		<button class="btn btn-primary" type="submit"><?= \V::t('Create the account') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	<?php endif; ?>
	</div>
    </div>
</main>
