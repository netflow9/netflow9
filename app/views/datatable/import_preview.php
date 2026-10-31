<?php
//CLD: 2026-10-24T16:30:00+03:00: the rows / columns: util\Format::plural (was number_format, columns always plural)
//CLD: 2026-10-11T20:51:55+03:00: new view: Import, step 2 (DatatableController::import): the table's name, the sheet (Excel), whether the first row is a header, the
//columns found (import or not, name, key, type; sample values) and the first rows; changing the sheet or the header choice posts step=preview (the
//columns are guessed again); Import posts step=import. $errors: [ 'name' | column index => [ messages ] ]
$actionUrl = \V::app()->createUrl('/datatable/import');
$formId = 'dt-import-' . uniqid();
$refresh = "this.form.elements.step.value='preview'; this.form.requestSubmit()";
$errorList = fn($list) => $list
    ? \util\VString::insert('<div class="invalid-feedback d-block">{:messages}</div>', [ 'messages' => implode('<br>', \V::app()->html->escape((array)$list)) ])
    : '';
$optionTpl = '<option value="{:value}"{:selected}>{:label}</option>';

$sheetSelect = '';
if (count($sheets) > 1) {
    $options = '';
    foreach($sheets as $s)
	$options .= \util\VString::insert($optionTpl, [
	    'value' => \V::app()->html->escape($s),
	    'selected' => $s === $sheet ? ' selected' : '',
	    'label' => \V::app()->html->escape($s),
	]);
    $sheetSelect = \util\VString::insert('<div class="col-md-4"><label class="form-label">Sheet</label><select name="sheet" class="form-select form-select-sm" onchange="{:refresh}">{:options}</select></div>', [
	'refresh' => $refresh,
	'options' => $options,
    ]);
} elseif ($sheet !== null) {
    $sheetSelect = \util\VString::insert('<input type="hidden" name="sheet" value="{:value}">', [ 'value' => \V::app()->html->escape($sheet) ]);
}

$typeOptions = function($selected) use ($types, $optionTpl) {
    $html = '';
    foreach($types as $value => $label)
	$html .= \util\VString::insert($optionTpl, [
	    'value' => \V::app()->html->escape($value),
	    'selected' => $value === $selected ? ' selected' : '',
	    'label' => \V::app()->html->escape($label),
	]);
    return $html;
};

$colTpl = '
    <tr>
	<td><input type="checkbox" class="form-check-input" name="cols[{:i}][on]" value="1"{:checked}></td>
	<td><input type="text" class="form-control form-control-sm" name="cols[{:i}][label]" value="{:label}" maxlength="100"></td>
	<td><input type="text" class="form-control form-control-sm font-monospace" name="cols[{:i}][key]" value="{:key}" maxlength="50"></td>
	<td><select class="form-select form-select-sm" name="cols[{:i}][type]">{:types}</select></td>
	<td class="small text-muted text-truncate" style="max-width: 14rem" title="{:samples}">{:samples}</td>
    </tr>{:errors}';
$colRows = '';
foreach($columns as $i => $c) {
    $colRows .= \util\VString::insert($colTpl, [
	'i' => $i,
	'checked' => !empty($c['on']) ? ' checked' : '',
	'label' => \V::app()->html->escape($c['label']),
	'key' => \V::app()->html->escape($c['key']),
	'types' => $typeOptions($c['type']),
	'samples' => implode(', ', \V::app()->html->escape($c['samples'])),
	'errors' => !empty($errors[$i])
	    ? \util\VString::insert('<tr><td></td><td colspan="4" class="pt-0">{:list}</td></tr>', [ 'list' => $errorList($errors[$i]) ])
	    : '',
    ]);
}

//the first rows as read
$head = '';
foreach($columns as $c)
    $head .= \util\VString::insert('<th class="text-nowrap">{:label}</th>', [ 'label' => \V::app()->html->escape($c['label']) ]);
$body = '';
foreach(array_slice($dataRows, 0, 5) as $row) {
    $cells = '';
    foreach($row as $v)
	$cells .= \util\VString::insert('<td class="text-nowrap text-truncate" style="max-width: 12rem">{:v}</td>', [ 'v' => \V::app()->html->escape(mb_substr(\util\TableImport::text($v), 0, 60)) ]);
    $body .= \util\VString::insert('<tr>{:cells}</tr>', [ 'cells' => $cells ]);
}

$contentTpl = '
<form id="{:formId}" method="post" action="{:action}">
    <input type="hidden" name="token" value="{:token}">
    <input type="hidden" name="step" value="import">
    <input type="hidden" name="header_set" value="1">
    <input type="hidden" name="sheet_shown" value="{:sheet}">
    <p class="small text-muted mb-3">{:file}: {:rows}, {:columns}. Types were guessed from the values: check them before importing.</p>
    <div class="row g-3 mb-3 align-items-end">
	<div class="col-md-5">
	    <label class="form-label">Table name</label>
	    <input type="text" name="name" class="form-control form-control-sm{:nameInvalid}" value="{:name}" maxlength="100" required>
	    {:nameErrors}
	    <div class="form-text">Letters, digits and underscore</div>
	</div>
	{:sheetSelect}
	<div class="col-md-3">
	    <div class="form-check form-switch mb-1">
		<input class="form-check-input" type="checkbox" role="switch" id="{:formId}-header" name="header" value="1"{:headerChecked} onchange="{:refresh}">
		<label class="form-check-label" for="{:formId}-header">First row is a header</label>
	    </div>
	</div>
    </div>
    <h6 class="mb-2">Columns</h6>
    <div class="table-responsive mb-3">
	<table class="table table-sm align-middle mb-0">
	    <thead><tr><th style="width: 2rem"></th><th>Name</th><th>Key</th><th style="width: 9rem">Type</th><th>Values</th></tr></thead>
	    <tbody>{:colRows}</tbody>
	</table>
    </div>
    <h6 class="mb-2">First rows</h6>
    <div class="table-responsive">
	<table class="table table-sm table-bordered small mb-0"><thead class="table-light"><tr>{:head}</tr></thead><tbody>{:body}</tbody></table>
    </div>
</form>
';
$content = \util\VString::insert($contentTpl, [
    'formId' => $formId,
    'action' => $actionUrl,
    'token' => $token,
    'sheet' => \V::app()->html->escape($sheet),
    'file' => \V::app()->html->escape($meta['name']),
    'rows' => \util\Format::plural(count($dataRows), 'row'),
    'columns' => \util\Format::plural(count($columns), 'column'),
    'nameInvalid' => !empty($errors['name']) ? ' is-invalid' : '',
    'name' => \V::app()->html->escape($name),
    'nameErrors' => $errorList($errors['name'] ?? []),
    'sheetSelect' => $sheetSelect,
    'headerChecked' => $header ? ' checked' : '',
    'refresh' => $refresh,
    'colRows' => $colRows,
    'head' => $head,
    'body' => $body,
]);

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
