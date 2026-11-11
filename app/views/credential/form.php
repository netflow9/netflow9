<?php
//create/update a credential (modal, app.smartform; same pattern as datatable/column_form.php): the name + the fields of its type (the credential is a variant of
//models\Credential: its formFields() include the name; util\SmartForm::modelFields); secrets are never sent to the browser (empty = keep)
$class = get_class($cred);
if ($cred->exists()) {
    $t_ok = 'Save';
    $t_title = 'Update credential: ' . $class::$label;
    $actionUrl = \V::app()->createUrl('', [ 'id' => $cred->_id ]);
} else {
    $t_ok = 'Create';
    $t_title = 'New credential: ' . $class::$label;
    $actionUrl = \V::app()->createUrl('', [ 'type' => $cred->getObjectName() ]);
}

$containerId = 'credential-form-' . uniqid();
$formId = $containerId . '-form';

$schema = [
    'id' => $formId,
    'action' => $actionUrl,
    'method' => 'post',
    'fields' => $cred->formFields(),
];

//util\SmartForm::modelValues of the fields with showInForms; encrypted fields are empty
$values = \util\SmartForm::modelValues($cred);

$errors = [];
foreach((array)$cred->errors() as $key => $messages)
    $errors[$key] = is_array($messages) ? (string)reset($messages) : (string)$messages;

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
    'title' => \V::app()->html->escape($t_title),
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
