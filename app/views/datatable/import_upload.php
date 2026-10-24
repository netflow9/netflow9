<?php
//CLD: 2026-10-24T16:30:00+03:00: the limits: util\Format::bytes / plural (were MB inline, number_format)
//CLD: 2026-10-11T20:51:55+03:00: new view: Import, step 1 (DatatableController::import): choose a CSV or Excel file; $error: why the last file was refused
$esc = fn($v) => \V::app()->html->escape((string)$v);
$actionUrl = \V::app()->createUrl('/datatable/import');
$formId = 'dt-import-' . uniqid();

$body = ($error ? "<div class='alert alert-danger py-2'>" . $esc($error) . "</div>" : '') . "
<form id='{$formId}' method='post' action='{$actionUrl}' enctype='multipart/form-data'>
    <label class='form-label' for='{$formId}-file'>File</label>
    <input type='file' class='form-control' id='{$formId}-file' name='file' accept='.csv,.txt,.tsv,.xlsx' required>
    <div class='form-text'>CSV or Excel (.xlsx), up to " . \util\Format::bytes(\util\TableImport::MAX_BYTES) . " and " . \util\Format::plural(\util\TableImport::MAX_ROWS, 'row') . ". The next step shows the columns found (names from the header row, if there is one; types from the values), to check before importing.</div>
</form>";

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary' form='{$formId}'>Next</button>
";

$modal = new \sys\html\Modal([
    'title' => 'Import a data table',
    'body' => $body,
    'footer' => $footer,
]);
echo $modal->render();
