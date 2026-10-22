<?php
//CLD: 2026-10-19T14:09:20+03:00: new view: the initial setup is done (SetupController::account; was part of views/setup/index.php): a link to sign in (the next request
//runs with the app.env just written); $user: the owner
$this->pageTitle = \V::t('Setup');
$e = fn($s) => \V::app()->html->escape((string)$s);
?>

<main class="position-relative d-flex flex-column align-items-center h-100 pb-5 overflow-auto">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= $e(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:720px">
	<div class="card-body p-4">
	    <?= $this->renderFile('setup/_steps', [ 'crt' => 'done' ]) ?>
	    <h5 class="mb-3 text-center"><i class="bi bi-check-circle text-success me-1"></i><?= \V::t('Setup complete') ?></h5>
	    <p class="mb-2"><?= \V::t('The account and its owner were created, and the settings were saved in') ?> <code><?= $e(BASE . '/app.env') ?></code>.</p>
	    <p class="text-muted small mb-3"><?= \V::t('Sign in as') ?> <strong><?= $e($user->username) ?></strong> <?= \V::t('(or with') ?> <?= $e($user->email) ?>).
		<?= \V::t('Start the daemon (app/v daemon) so scheduled and queued work runs.') ?></p>
	    <div class="text-center"><a class="btn btn-primary" href="/site/login"><?= \V::t('Sign in') ?></a></div>
	</div>
    </div>
</main>
