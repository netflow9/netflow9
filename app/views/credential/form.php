<?php
//CLD: 2026-10-14T16:01:34+03:00: util\FormSchema and WidgetsUtil::smartFormFields / formValues are util\SmartForm (::showIf, ::modelFields, ::modelValues, ...)
//CLD: 2026-10-08T14:37:11+03:00: new view: create/update a credential (modal, app.smartform; same pattern as datatable/column_form.php): the name + the
//fields of its type (models\CredentialType::fields); secrets are never sent to the browser (empty = keep)
//CLD: 2026-10-09T17:21:14+03:00: the credential is a variant of models\Credential: its formFields() include the name (was: name + the type's fields)
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

//CLD: 2026-10-10T18:35:28+03:00: WidgetsUtil::formValues directly (Credential::formValues() removed); encrypted fields are empty
//CLD: 2026-10-10T18:44:22+03:00: the fields with showInForms (was formKeys())
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
