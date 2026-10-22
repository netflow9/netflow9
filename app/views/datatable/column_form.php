<?php
//CLD: 2026-10-14T16:01:34+03:00: util\FormSchema and WidgetsUtil::smartFormFields / formValues are util\SmartForm (::showIf, ::modelFields, ::modelValues, ...)
//CLD: 2026-10-05T21:06:21+03:00: new view: create/update a data table column (modal), same pattern as variable/form.php
//CLD: 2026-10-05T21:34:43+03:00: form rendered with app.smartform (choices: showIf/requiredIf on type == select) instead of
//server-side inputs + toggle script; server errors shown on the fields
//CLD: 2026-10-05T21:39:55+03:00: submitted as a regular form through app.pjax's submit handler (was app.pjax.fetch from a click handler)
$isEdit = !empty($field);
if ($isEdit) {
    $t_ok = 'Update';
    $t_title = 'Update column';
    $actionUrl = \V::app()->createUrl('', [ 'id' => $dt->_id, 'key' => $field->key ]);
} else {
    $t_ok = 'Create';
    $t_title = 'Add column';
    $actionUrl = \V::app()->createUrl('', [ 'id' => $dt->_id ]);
}

$containerId = 'column-form-' . uniqid();
//CLD: 2026-10-05T21:39:55+03:00: the <form> SmartForm renders; the modal's submit button targets it with form="..."
$formId = $containerId . '-form';

//CLD: 2026-10-14T14:18:23+03:00: the fields come from the form model (models\datatable\FieldForm: labels, help, choices, rules -> required / requiredIf, ui settings) through
//util\SmartForm::modelFields; key, type and target are not editable once the column exists (their perm:update); was a hand-written schema
$schema = [
    'id' => $formId,
    'action' => $actionUrl,
    'method' => 'post',
    'fields' => \util\SmartForm::modelFields($form),
];

//current values (the posted ones when the server rejected them)
$values = $form->data();

//server-side errors: first message per field
$errors = [];
foreach((array)$form->errors() as $key => $messages)
    $errors[$key] = is_array($messages) ? (string)reset($messages) : (string)$messages;

//CLD: 2026-10-05T21:46:57+03:00: util\JSON::encode (same flags, shared)
$js = [
    'schema' => \util\JSON::encode($schema),
    'values' => \util\JSON::encode($values),
    'errors' => \util\JSON::encode((object)$errors),
];

$body = "<div id='{$containerId}'></div>";

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' form='{$formId}' class='btn btn-primary'>{$t_ok}</button>
";

$modal = new \sys\html\Modal([
    'title' => $t_title,
    'body' => $body,
    'footer' => $footer,
]);
echo $modal->render();

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
