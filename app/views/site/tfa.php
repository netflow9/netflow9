<?php
    $this->pageTitle = \V::t('Enter 2FA Code');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">

	    <?=\V::app()->form->create()?>

	    <h5 class='mb-3 text-center'><?=\V::t('Enter 2FA Code')?></h5>

	    <?php //CLD: 2026-10-21T18:00:00+03:00: a wrong code or a lock (SiteController::tfa) ?>
	    <?php if (!empty($error)): ?>
	    <div class="alert alert-danger"><?= \V::app()->html->escape($error) ?> <a href="<?= \V::app()->createUrl('/site/login') ?>"><?= \V::t('Sign in') ?></a></div>
	    <?php endif; ?>

	    <?php
	    echo \V::app()->form->field('code', [
		'type' => 'text',
		'label' => false,
		'input' => [ 'placeholder' => \V::t('code'), 'class' => 'form-control form-control-lg mt-2', 'autofocus' => 1 ],
		'bare' => true,
	    ]);
	    ?>

	    <div class='form-group mb-2 text-center pt-4'>
		<button class='btn btn-primary' type='submit'><?= \V::t('Verify') ?></button>
	    </div>

	    <?=\V::app()->form->end()?>

	</div>
    </div>
</main>