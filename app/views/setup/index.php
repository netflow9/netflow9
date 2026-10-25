<?php
//the initial setup, step 1 (SetupController::index): the prerequisites; when the required ones pass, a link to step 2
//CLD: 2026-10-19T14:09:20+03:00: step 1 only (the server's settings and the first user are views/setup/server.php and account.php; "done" is done.php)
$this->pageTitle = \V::t('Setup');
?>

<main class="position-relative d-flex flex-column align-items-center h-100 pb-5 overflow-auto">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= \V::app()->html->escape(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:720px">
	<div class="card-body p-4">
	    <?= $this->renderFile('setup/_steps', [ 'crt' => 'index' ]) ?>
	    <h5 class="mb-1"><?= \V::t('System prerequisites') ?></h5>
	    <p class="text-muted small mb-3"><?= \V::t('The setup is shown until the settings file (app.env) exists.') ?></p>

	    <table class="table table-sm small align-middle mb-3">
		<tbody>
		<?php foreach($checks as $c): ?>
		    <tr>
			<td style="width:1.5rem"><?php if ($c['ok']): ?><i class="bi bi-check-circle-fill text-success"></i><?php elseif ($c['required']): ?><i class="bi bi-x-circle-fill text-danger"></i><?php else: ?><i class="bi bi-exclamation-triangle-fill text-warning"></i><?php endif; ?></td>
			<td><?= \V::app()->html->escape($c['name']) ?><?php if (!$c['required']): ?> <span class="text-muted">(<?= \V::t('recommended') ?>)</span><?php endif; ?></td>
			<td class="text-nowrap"><?= \V::app()->html->escape($c['value']) ?></td>
			<td class="text-muted"><?= \V::app()->html->escape($c['need']) ?></td>
		    </tr>
		<?php endforeach; ?>
		</tbody>
	    </table>

	    <?php if (!$ready): ?>
		<div class="alert alert-danger mb-0"><?= \V::t('Fix the failed prerequisites, then reload this page.') ?></div>
	    <?php else: ?>
		<div class="text-end"><a class="btn btn-primary" href="/setup/server"><?= \V::t('Continue') ?> <i class="bi bi-arrow-right"></i></a></div>
	    <?php endif; ?>
	</div>
    </div>
</main>
