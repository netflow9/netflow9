<?php
//CLD: 2026-10-11T20:51:55+03:00: new view: Import, step 2 (DatatableController::import): the table's name, the sheet (Excel), whether the first row is a header, the
//columns found (import or not, name, key, type; sample values) and the first rows; changing the sheet or the header choice posts step=preview (the
//columns are guessed again); Import posts step=import. $errors: [ 'name' | column index => [ messages ] ]
$esc = fn($v) => \V::app()->html->escape((string)$v);
$actionUrl = \V::app()->createUrl('/datatable/import');
$formId = 'dt-import-' . uniqid();
$refresh = "this.form.elements.step.value='preview'; this.form.requestSubmit()";
$errorList = fn($list) => $list ? "<div class='invalid-feedback d-block'>" . implode('<br>', array_map($esc, (array)$list)) . "</div>" : '';

$sheetSelect = '';
if (count($sheets) > 1) {
    $options = '';
    foreach($sheets as $s)
	$options .= "<option value='" . $esc($s) . "'" . ($s === $sheet ? ' selected' : '') . ">" . $esc($s) . "</option>";
    $sheetSelect = "<div class='col-md-4'><label class='form-label'>Sheet</label><select name='sheet' class='form-select form-select-sm' onchange=\"{$refresh}\">{$options}</select></div>";
} elseif ($sheet !== null) {
    $sheetSelect = "<input type='hidden' name='sheet' value='" . $esc($sheet) . "'>";
}

$typeOptions = function($selected) use ($types, $esc) {
    $html = '';
    foreach($types as $value => $label)
	$html .= "<option value='" . $esc($value) . "'" . ($value === $selected ? ' selected' : '') . ">" . $esc($label) . "</option>";
    return $html;
};

$colRows = '';
foreach($columns as $i => $c) {
    $samples = implode(', ', array_map($esc, $c['samples']));
    $colRows .= "
    <tr>
	<td><input type='checkbox' class='form-check-input' name='cols[{$i}][on]' value='1'" . (!empty($c['on']) ? ' checked' : '') . "></td>
	<td><input type='text' class='form-control form-control-sm' name='cols[{$i}][label]' value='" . $esc($c['label']) . "' maxlength='100'></td>
	<td><input type='text' class='form-control form-control-sm font-monospace' name='cols[{$i}][key]' value='" . $esc($c['key']) . "' maxlength='50'></td>
	<td><select class='form-select form-select-sm' name='cols[{$i}][type]'>" . $typeOptions($c['type']) . "</select></td>
	<td class='small text-muted text-truncate' style='max-width: 14rem' title='{$samples}'>{$samples}</td>
    </tr>" . (!empty($errors[$i]) ? "<tr><td></td><td colspan='4' class='pt-0'>" . $errorList($errors[$i]) . "</td></tr>" : '');
}

//the first rows as read
$head = '';
foreach($columns as $c)
    $head .= '<th class="text-nowrap">' . $esc($c['label']) . '</th>';
$body = '';
foreach(array_slice($dataRows, 0, 5) as $row) {
    $body .= '<tr>';
    foreach($row as $v)
	$body .= '<td class="text-nowrap text-truncate" style="max-width: 12rem">' . $esc(mb_substr(\util\TableImport::text($v), 0, 60)) . '</td>';
    $body .= '</tr>';
}

$content = "
<form id='{$formId}' method='post' action='{$actionUrl}'>
    <input type='hidden' name='token' value='{$token}'>
    <input type='hidden' name='step' value='import'>
    <input type='hidden' name='header_set' value='1'>
    <input type='hidden' name='sheet_shown' value='" . $esc($sheet) . "'>
    <p class='small text-muted mb-3'>" . $esc($meta['name']) . ': ' . number_format(count($dataRows)) . ' rows, ' . count($columns) . " columns. Types were guessed from the values: check them before importing.</p>
    <div class='row g-3 mb-3 align-items-end'>
	<div class='col-md-5'>
	    <label class='form-label'>Table name</label>
	    <input type='text' name='name' class='form-control form-control-sm" . (!empty($errors['name']) ? ' is-invalid' : '') . "' value='" . $esc($name) . "' maxlength='100' required>
	    " . $errorList($errors['name'] ?? []) . "
	    <div class='form-text'>Letters, digits and underscore</div>
	</div>
	{$sheetSelect}
	<div class='col-md-3'>
	    <div class='form-check form-switch mb-1'>
		<input class='form-check-input' type='checkbox' role='switch' id='{$formId}-header' name='header' value='1'" . ($header ? ' checked' : '') . " onchange=\"{$refresh}\">
		<label class='form-check-label' for='{$formId}-header'>First row is a header</label>
	    </div>
	</div>
    </div>
    <h6 class='mb-2'>Columns</h6>
    <div class='table-responsive mb-3'>
	<table class='table table-sm align-middle mb-0'>
	    <thead><tr><th style='width: 2rem'></th><th>Name</th><th>Key</th><th style='width: 9rem'>Type</th><th>Values</th></tr></thead>
	    <tbody>{$colRows}</tbody>
	</table>
    </div>
    <h6 class='mb-2'>First rows</h6>
    <div class='table-responsive'>
	<table class='table table-sm table-bordered small mb-0'><thead class='table-light'><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
    </div>
</form>";

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary' form='{$formId}'><i class='bi bi-upload me-1'></i>Import</button>
";

$modal = new \sys\html\Modal([
    'title' => 'Import a data table',
    'body' => $content,
    'footer' => $footer,
    'size' => 'xl',
]);
echo $modal->render();
