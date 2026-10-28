<?php
//CLD: 2026-10-28T10:00:00+02:00: new view: Import, step 3 (ProjectController::import): what was done (util\Transfer::apply), what was not and why; workflows come as drafts
$this->pageTitle = 'Imported: ' . $project->name;
$settingsUrl = \V::app()->createUrl('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'transfer' ]);
?>

<div class="main-header py-2 px-3">
    <?php
    echo (new \sys\html\BtnExpandSidebar())->render();
    echo (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => $project->schema['icon']->display() . ' ' . \V::app()->html->escape((string)$project->name), 'href' => $settingsUrl ],
	[ 'label' => 'Import' ],
    ]]))->render();
    ?>
</div>

<div class="main-body p-4">
    <h5 class="mb-3">Imported <?= \V::app()->html->escape((string)$meta['name']) ?></h5>

    <?php if ($result['done']): ?>
    <ul class="list-unstyled mb-3">
	<?php foreach($result['done'] as $line): ?>
	<li class="mb-1"><i class="bi bi-check2 text-success me-2"></i><?= \V::app()->html->escape($line) ?></li>
	<?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="alert alert-info">Nothing was imported.</div>
    <?php endif; ?>

    <?php if ($result['warnings']): ?>
    <div class="alert alert-warning py-2 small">
	<?php foreach($result['warnings'] as $w): ?>
	<div><i class="bi bi-exclamation-triangle me-2"></i><?= \V::app()->html->escape($w) ?></div>
	<?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="small text-body-secondary">Imported workflows are drafts: check them in the designer and publish them. Credentials imported without their secrets need them entered (menu: Credentials &amp; variables).</p>
    <a class="btn btn-sm btn-secondary" href="<?= $settingsUrl ?>">Back to Export / import</a>
</div>
