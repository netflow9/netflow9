<?php
    $this->pageTitle = \V::t('Login');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">

	    <?=\V::app()->form->create($form)?>

	    <h5 class='mb-3 text-center'><?=\V::t('Sign in')?></h5>

	    <?php
	    echo \V::app()->form->field('username', [
		'type' => 'text',
		'label' => false,
		'input' => [ 'placeholder' => \V::t('username'), 'class' => 'form-control form-control-lg mt-2', 'autofocus' => 1 ],
		'bare' => true,
	    ]);

	    echo \V::app()->form->field('password', [
		'type' => 'password',
		'label' => false,
		'input' => [ 'placeholder' => \V::t('password'), 'class' => 'form-control form-control-lg mt-2' ],
		'bare' => true,
	    ]);

	    //CLD: 2026-10-05T20:02:30+03:00: enabled: "remember me" now selects a persistent session
	    echo \V::app()->form->field('rememberme', [
		'type' => 'checkbox',
		'label' => \V::t('Remember me'),
		'hidden' => false,
		'bare' => true,
	    ]);
	    ?>

	    <div class='form-group mb-2 text-center pt-4'>
		<button class='btn btn-primary' type='submit'><?= \V::t('Login') ?></button>
	    </div>

	    <?=\V::app()->form->end()?>

	</div>
    </div>

    <?=\V::app()->html->link(\V::t('Forgot username or password?'), '/site/forgot', [ 'class' => 'pt-2' ])?>
    <?php if (\util\SamlAuth::anyEnabled()): ?>
    <?=\V::app()->html->link(\V::t('Sign in with SSO'), '/sso/login', [ 'class' => 'pt-1' ])?>
    <?php endif; ?>
    <?php \V::app()->hooks->applyHook('site:login-links'); ?>
</main>