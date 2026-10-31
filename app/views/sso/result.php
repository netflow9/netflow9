<?php
//CLD: 2026-10-23T12:00:00+03:00: new: the end of a SAML sign-in that did not sign in (SsoController::acs, ::start): why ($message), and the steps of a settings test ($lines)
$this->pageTitle = \V::t('Single sign-on');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
	<h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= \V::app()->html->escape(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:<?= $lines ? '720px' : '420px' ?>">
	<div class="card-body p-4">
	    <h5 class="mb-3 text-center"><?= \V::t('Single sign-on') ?></h5>
	    <div class="alert <?= $ok ? 'alert-success' : ($lines ? 'alert-info' : 'alert-warning') ?> small"><?= \V::app()->html->escape($message) ?></div>
	    <?php if ($lines): ?>
		<div class="small font-monospace">
		    <?php foreach($lines as $l): ?>
			<div class="text-break"><?= \V::app()->html->escape($l) ?></div>
		    <?php endforeach; ?>
		</div>
	    <?php endif; ?>
	</div>
    </div>

    <?= \V::app()->html->link(\V::t('Back to sign in'), '/site/login', [ 'class' => 'pt-2' ]) ?>
</main>
