<?php
//CLD: 2026-10-28T10:00:00+02:00: new tab: Export / import (Project::transfer; ProjectController::export / import, util\Transfer). Export: the project's workflows, credentials and
//data tables to choose (each listed with its read permission), + what the chosen workflows use, the tables' rows, a passphrase (seals the file, puts the secrets in it);
//a download, so the form is not pjax (its CSRF token is a field). Import: an export file (+ its passphrase), then the preview
$om = \V::app()->om;
$inProject = [ 'aid' => $project->aid, 'project' => $project->_id ];
$can = fn($o, $perm) => \V::app()->user->can($perm, [ 'object' => $o ]);
$workflows = array_filter(iterator_to_array($om->find('Workflow', $inProject, [ 'sort' => [ 'name' => 1 ] ]), false), fn($o) => $can($o, 'Workflow::read'));
$credentials = array_filter(iterator_to_array($om->find('Credential', $inProject, [ 'sort' => [ 'name' => 1 ] ]), false), fn($o) => $can($o, 'Credential::read'));
$tables = array_filter(iterator_to_array($om->find('DataTableDef', $inProject, [ 'sort' => [ 'name' => 1 ] ]), false), fn($o) => $can($o, 'DataTable::readData'));
$formId = 'transfer-export-' . uniqid();

//a group of checkboxes `$name[]`, with one to check them all; $extra($o): more after the label
$group = function($title, $name, array $items, $label, $extra = null) use ($formId) {
    $html = "<div class='col-12 col-lg-4'><div class='border rounded p-2 h-100'>"
	. "<div class='form-check border-bottom pb-1 mb-1'><input class='form-check-input' type='checkbox' id='{$formId}-{$name}-all'"
	. " onchange=\"this.closest('.border').querySelectorAll('input[name=&quot;{$name}[]&quot;]').forEach(c => c.checked = this.checked)\"" . ($items ? '' : ' disabled') . ">"
	. "<label class='form-check-label fw-semibold' for='{$formId}-{$name}-all'>{$title} (" . count($items) . ")</label></div>";
    if (!$items)
	$html .= "<div class='small text-body-secondary'>None in this project</div>";
    foreach($items as $o) {
	$id = "{$formId}-{$name}-" . $o->_id;
	$html .= "<div class='d-flex align-items-center gap-2'><div class='form-check flex-fill text-truncate'><input class='form-check-input' type='checkbox' name='{$name}[]' value='" . $o->_id . "' id='{$id}'>"
	    . "<label class='form-check-label' for='{$id}'>" . $label($o) . "</label></div>" . ($extra ? $extra($o) : '') . "</div>";
    }
    return $html . '</div></div>';
};

echo '<div class="w-100 mt-4"></div>';
?>
<h6 class="mb-1"><i class="bi bi-box-arrow-up me-2"></i>Export</h6>
<p class="small text-body-secondary mb-3">A file to import in a project of another NetFlow9 server, e.g. from development to pre-production. Workflows go as their drafts (the steps as saved in the designer).</p>

<form id="<?= $formId ?>" method="post" action="<?= \V::app()->createUrl('/project/export', [ 'id' => (string)$project->_id ]) ?>" no-pjax>
    <input type="hidden" name="<?= \V::app()->html->escape(\V::app()->csrf->getKey()) ?>" value="<?= \V::app()->html->escape(\V::app()->csrf->getToken()) ?>">
    <div class="row g-3 mb-3">
	<?php
	echo $group('Workflows', 'workflows', $workflows, fn($o) => $o->schema['icon']->display() . ' ' . \V::app()->html->escape((string)$o->name));
	echo $group('Credentials & variables', 'credentials', $credentials, fn($o) => \V::app()->html->escape((string)$o->name)
	    . " <span class='small text-body-secondary'>" . \V::app()->html->escape($o::$label) . '</span>');
	echo $group('Data tables', 'datatables', $tables, fn($o) => \V::app()->html->escape((string)$o->name), fn($o) => "<div class='form-check form-check-inline small me-0 flex-shrink-0'>"
	    . "<input class='form-check-input' type='checkbox' name='rows[]' value='" . $o->_id . "' id='{$formId}-rows-" . $o->_id . "'>"
	    . "<label class='form-check-label' for='{$formId}-rows-" . $o->_id . "'>with rows</label></div>");
	?>
    </div>
    <div class="form-check mb-3">
	<input class="form-check-input" type="checkbox" name="used" value="1" id="<?= $formId ?>-used" checked>
	<label class="form-check-label" for="<?= $formId ?>-used">Also the credentials and data tables the chosen workflows use</label>
	<div class="form-text mt-0">Data tables added this way go without their rows unless <i>with rows</i> is checked for them.</div>
    </div>
    <div class="row g-2 mb-1" style="max-width: 40rem">
	<div class="col-12 col-sm-6">
	    <label class="form-label small mb-1" for="<?= $formId ?>-pass">Passphrase</label>
	    <input type="password" class="form-control form-control-sm" name="passphrase" id="<?= $formId ?>-pass" autocomplete="new-password" minlength="8">
	</div>
	<div class="col-12 col-sm-6">
	    <label class="form-label small mb-1" for="<?= $formId ?>-pass2">Passphrase again</label>
	    <input type="password" class="form-control form-control-sm" name="passphrase2" id="<?= $formId ?>-pass2" autocomplete="new-password">
	</div>
    </div>
    <div class="form-text mb-3" style="max-width: 40rem">With a passphrase, the file is encrypted and holds the credentials' secrets (passwords, keys, tokens): the import asks for it. Without one, the secrets are left out and are entered again on the other server.</div>
    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Export</button>
</form>

<hr class="my-4">

<h6 class="mb-1"><i class="bi bi-box-arrow-in-down me-2"></i>Import</h6>
<p class="small text-body-secondary mb-3">An export file of this or another server. The next step lists what it holds and what is already in this project, to choose what to do with each before anything changes.</p>
<form method="post" action="<?= \V::app()->createUrl('/project/import', [ 'id' => (string)$project->_id ]) ?>" enctype="multipart/form-data" class="row g-2 align-items-end" style="max-width: 40rem">
    <div class="col-12 col-sm-7">
	<label class="form-label small mb-1" for="<?= $formId ?>-file">Export file</label>
	<input type="file" class="form-control form-control-sm" name="file" id="<?= $formId ?>-file" accept=".json,application/json" required>
    </div>
    <div class="col-12 col-sm-5">
	<label class="form-label small mb-1" for="<?= $formId ?>-ipass">Passphrase <span class="text-body-secondary">(if it has one)</span></label>
	<input type="password" class="form-control form-control-sm" name="passphrase" id="<?= $formId ?>-ipass" autocomplete="off">
    </div>
    <div class="col-12 mt-3">
	<button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Next</button>
    </div>
</form>
