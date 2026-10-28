<?php
//CLD: 2026-10-28T10:00:00+02:00: new view: Import, step 2 (ProjectController::import): what the file holds, each item with what to do (util\Transfer::plan: its actions, an existing one of
//that name in the project, notes), what the steps use and is not found; Import posts the choices (`do[<list>][<index>]`) with the file's token
$this->pageTitle = 'Import: ' . $project->name;
$settingsUrl = \V::app()->createUrl('/project/settings', [ 'id' => (string)$project->_id, '__tab' => 'transfer' ]);
$sections = [ 'datatables' => [ 'Data tables', 'bi-table' ], 'credentials' => [ 'Credentials & variables', 'bi-key' ], 'workflows' => [ 'Workflows', 'bi-diagram-3' ] ];
$total = count($plan['datatables']) + count($plan['credentials']) + count($plan['workflows']);
$exportedAt = \util\Format::datetime((string)($bundle['exported_at'] ?? ''));
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
    <h5 class="mb-1">Import <?= \V::app()->html->escape((string)$meta['name']) ?></h5>
    <p class="small text-body-secondary mb-3">
	<?= $exportedAt !== '' ? 'Exported ' . \V::app()->html->escape($exportedAt) : 'Export' ?>
	<?= isset($bundle['source']['project']) ? ' from project <b>' . \V::app()->html->escape((string)$bundle['source']['project']) . '</b>' : '' ?>
	<?= isset($bundle['source']['url']) ? ' of ' . \V::app()->html->escape((string)$bundle['source']['url']) : '' ?>;
	<?= !empty($bundle['secrets']) ? 'with the credentials\' secrets.' : 'without the credentials\' secrets.' ?>
	Items are matched to this project's by name. Nothing changes until you choose <b>Import</b>.
    </p>

    <?php if ($plan['warnings']): ?>
    <div class="alert alert-warning py-2 small">
	<?php foreach($plan['warnings'] as $w): ?>
	<div><i class="bi bi-exclamation-triangle me-2"></i><?= \V::app()->html->escape($w) ?></div>
	<?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($total == 0): ?>
    <div class="alert alert-info">The file holds nothing to import.</div>
    <a class="btn btn-sm btn-secondary" href="<?= $settingsUrl ?>">Back</a>
    <?php else: ?>
    <form method="post" action="<?= \V::app()->createUrl('/project/import', [ 'id' => (string)$project->_id ]) ?>">
	<input type="hidden" name="step" value="apply">
	<input type="hidden" name="token" value="<?= \V::app()->html->escape($token) ?>">
	<?php foreach($sections as $list => [ $title, $icon ]): ?>
	<?php if (empty($plan[$list])) continue; ?>
	<h6 class="mt-3 mb-2"><i class="bi <?= $icon ?> me-2"></i><?= $title ?> (<?= count($plan[$list]) ?>)</h6>
	<div class="table-responsive">
	    <table class="table table-sm align-middle">
		<thead><tr><th>In the file</th><th style="width: 14rem">In this project</th><th style="width: 18rem">Import</th></tr></thead>
		<tbody>
		<?php foreach($plan[$list] as $i => $item): ?>
		<tr>
		    <td>
			<?= \V::app()->html->escape($item['label']) ?>
			<?php foreach($item['notes'] as $note): ?>
			<div class="small text-body-secondary"><?= \V::app()->html->escape($note) ?></div>
			<?php endforeach; ?>
		    </td>
		    <td class="small"><?= $item['existing'] ? '<i class="bi bi-check2 me-1 text-success"></i>exists' : '<span class="text-body-secondary">not found</span>' ?></td>
		    <td>
			<select name="do[<?= $list ?>][<?= (int)$i ?>]" class="form-select form-select-sm" aria-label="Import <?= \V::app()->html->escape($item['name']) ?>">
			    <?php foreach($item['actions'] as $key => $label): ?>
			    <option value="<?= $key ?>"<?= $key === $item['default'] ? ' selected' : '' ?>><?= \V::app()->html->escape($label) ?></option>
			    <?php endforeach; ?>
			</select>
		    </td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	    </table>
	</div>
	<?php endforeach; ?>
	<div class="d-flex gap-2 mt-3">
	    <a class="btn btn-sm btn-secondary" href="<?= $settingsUrl ?>">Cancel</a>
	    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>Import</button>
	</div>
    </form>
    <?php endif; ?>
</div>
