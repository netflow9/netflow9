<?php
//CLD: 2026-10-23T12:00:00+03:00: new: "Sign in with SSO" (SsoController::login): the email, whose account's identity provider signs the person in
$this->pageTitle = \V::t('Sign in with SSO');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
	<h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= \V::app()->html->escape(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">
	    <form method="get" action="<?= \V::app()->createUrl('/Saml/sso/login') ?>">
		<h5 class="mb-3 text-center"><?= \V::t('Sign in with SSO') ?></h5>
		<p class="small text-muted text-center"><?= \V::t('Your work email: you sign in at your organization\'s identity provider') ?></p>
		<input type="email" name="email" class="form-control form-control-lg mt-2<?= $error ? ' is-invalid' : '' ?>" placeholder="<?= \V::t('email') ?>"
		    value="<?= \V::app()->html->escape($email) ?>" autofocus required>
		<?php if ($error): ?>
		    <div class="invalid-feedback"><?= \V::app()->html->escape($error) ?></div>
		<?php endif; ?>
		<div class="form-group mb-2 text-center pt-4">
		    <button class="btn btn-primary" type="submit"><?= \V::t('Continue') ?></button>
		</div>
	    </form>
	</div>
    </div>

    <?= \V::app()->html->link(\V::t('Sign in with a password'), '/site/login', [ 'class' => 'pt-2' ]) ?>
</main>
