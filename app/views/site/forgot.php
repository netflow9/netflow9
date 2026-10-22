<?php
//CLD: 2026-10-16T13:20:28+03:00: new view: "forgot username or password" (SiteController::forgot): the email; once sent, "check your inbox" (the same whether or not the address has an account)
$this->pageTitle = \V::t('Forgot username or password');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">
	<?php if ($sent): ?>
	    <h5 class="mb-3 text-center"><i class="bi bi-envelope-check text-success me-1"></i><?= \V::t('Check your inbox') ?></h5>
	    <p class="mb-2"><?= \V::t('If an account uses') ?> <strong><?= \V::app()->html->escape((string)$form->email) ?></strong>, <?= \V::t('we sent it an email with the username and a link to set a new password.') ?></p>
	    <p class="text-muted small mb-0"><?= \V::t('The link works once, for 24 hours. No email? Look in the spam folder, or try again.') ?></p>
	<?php else: ?>
	    <?=\V::app()->form->create($form)?>
	    <h5 class="mb-3 text-center"><?= \V::t('Forgot username or password?') ?></h5>
	    <?php
	    echo \V::app()->form->field('email', [
		'type' => 'text',
		'label' => false,
		'input' => [ 'placeholder' => \V::t('your email'), 'class' => 'form-control form-control-lg mt-2', 'autofocus' => 1, 'autocomplete' => 'email' ],
	    ]);
	    ?>
	    <p class="small text-muted mt-3 mb-0"><?= \V::t('We will send you the username and a link to set a new password.') ?></p>
	    <div class="form-group mb-2 text-center pt-4">
		<button class="btn btn-primary" type="submit"><?= \V::t('Send') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	<?php endif; ?>
	</div>
    </div>

    <?=\V::app()->html->link(\V::t('Back to sign in'), \V::app()->user->getLoginUrl(), [ 'class' => 'pt-2' ])?>
</main>
