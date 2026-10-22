<?php
//CLD: 2026-10-16T13:20:28+03:00: new view: the page of a "forgot" link (SiteController::reset): the new password, twice; an expired / used link says so
$this->pageTitle = \V::t('Set a new password');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">
	<?php if (!$user): ?>
	    <h5 class="mb-3 text-center"><?= \V::t('This link does not work any more') ?></h5>
	    <p class="mb-3"><?= \V::t('Links work once, for 24 hours. Ask for a new link, or sign in if you already set the password.') ?></p>
	    <div class="d-flex gap-2 justify-content-center">
		<a class="btn btn-primary" href="/site/forgot"><?= \V::t('Get a new link') ?></a>
		<a class="btn btn-outline-secondary" href="<?= \V::app()->user->getLoginUrl() ?>"><?= \V::t('Sign in') ?></a>
	    </div>
	<?php else: ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/site/reset?t=' . rawurlencode($token) ])?>
	    <h5 class="mb-1 text-center"><?= \V::t('Set a new password') ?></h5>
	    <p class="text-center text-muted small mb-3"><?= \V::app()->html->escape((string)($user->username ?: $user->email)) ?></p>
	    <?php
	    echo \V::app()->form->field('new_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('New password'), 'input' => [ 'autofocus' => 1, 'autocomplete' => 'new-password' ],
		'help' => \V::t('At least 8 characters, with lower and upper case letters, a digit and a symbol') ]);
	    echo \V::app()->form->field('cfm_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('New password again'), 'input' => [ 'autocomplete' => 'new-password' ] ]);
	    ?>
	    <p class="small text-muted mt-2 mb-0"><?= \V::t('You will be signed out everywhere else.') ?></p>
	    <div class="form-group mb-2 text-center pt-3">
		<button class="btn btn-primary" type="submit"><?= \V::t('Set the password') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	<?php endif; ?>
	</div>
    </div>
</main>
