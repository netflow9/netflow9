<?php
//CLD: 2026-10-14T16:01:34+03:00: util\FormSchema and WidgetsUtil::smartFormFields / formValues are util\SmartForm (::showIf, ::modelFields, ::modelValues, ...)
//form through app.pjax's submit handler, same pattern as column_form.php; server errors shown on the fields
if ($row->exists()) {
    $t_ok = 'Save';
    $t_title = 'Edit row';
    $actionUrl = \V::app()->createUrl('', [ 'id' => $dt->_id, 'row' => (string)$row->_id ]);
} else {
    $t_ok = 'Add';
    $t_title = 'Add row';
    $actionUrl = \V::app()->createUrl('', [ 'id' => $dt->_id ]);
}

$containerId = 'row-form-' . uniqid();
$formId = $containerId . '-form';
//CLD: 2026-10-14T14:38:46+03:00: the fields and their values from the row's model through util\WidgetsUtil (smartFormFields / formValues: types, choices, rules, date formats,
//object columns, value conversion; was a hand mapping here); only the search URL of object columns with many targets is the data table's
$fields = \util\SmartForm::modelFields($row, [ 'objectSearchUrl' => fn($f) => \V::app()->createUrl('/datatable/object_choices', [ 'id' => $dt->_id, 'key' => $f->key ]) ]);
$values = \util\SmartForm::modelValues($row);

//server-side errors: first message per field
$errors = [];
foreach((array)$row->errors() as $key => $messages)
    $errors[$key] = is_array($messages) ? (string)reset($messages) : (string)$messages;

$js = [
    'schema' => \util\JSON::encode([ 'id' => $formId, 'action' => $actionUrl, 'method' => 'post', 'fields' => $fields ]),
    'values' => \util\JSON::encode((object)$values),
    'errors' => \util\JSON::encode((object)$errors),
];

if (empty($fields)) {
    $body = "<p class='text-muted mb-0'>This table has no columns you can edit.</p>";
    $okBtn = '';
} else {
    $body = "<div id='{$containerId}'></div>";
    $okBtn = "<button type='submit' form='{$formId}' class='btn btn-primary'>{$t_ok}</button>";
}

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
{$okBtn}
";

$modal = new \sys\html\Modal([
    'title' => $t_title,
    'body' => $body,
    'footer' => $footer,
]);
echo $modal->render();

if (!empty($fields)) {
    \V::app()->cs->addPageScript("
+function() {
    const container = document.getElementById('{$containerId}');
    if (!container)
	return;

    const form = new app.smartform(container, {$js['schema']});
    form.render();
    form.setData({$js['values']});

    //errors returned by the server
    const errors = {$js['errors']};
    Object.keys(errors).forEach((key) => {
	const field = form.fields.find((f) => f.key === key);
	if (field)
	    field.setInvalid(errors[key]);
    });
}();
");
}
