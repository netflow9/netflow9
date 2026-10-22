<?php
//CLD: 2026-10-21T20:10:00+03:00: new view: the accounts a sign-in's name and password fit (SiteController::choose_account)
    $this->pageTitle = \V::t('Choose an account');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">

	    <h5 class='mb-1 text-center'><?=\V::t('Choose an account')?></h5>
	    <p class="text-muted small text-center mb-3"><?=\V::t('You can sign in to more than one account.')?></p>

	    <?php foreach($choices as $id => $c): ?>
	    <?=\V::app()->form->create()?>
		<input type="hidden" name="user" value="<?= \V::app()->html->escape($id) ?>">
		<button class="btn btn-outline-primary w-100 mb-2 text-start" type="submit">
		    <i class="bi bi-building me-2"></i><?= \V::app()->html->escape($c['account']) ?>
		</button>
	    <?=\V::app()->form->end()?>
	    <?php endforeach; ?>

	    <div class="text-center pt-2">
		<a href="<?= \V::app()->createUrl('/site/login') ?>" class="small"><?= \V::t('Back to sign in') ?></a>
	    </div>
	</div>
    </div>
</main>
