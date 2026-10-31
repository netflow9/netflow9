<?php

/*
    util\FormSchema and WidgetsUtil::smartFormFields / formValues are util\SmartForm (::showIf, ::modelFields, ::modelValues, ...)
*/

namespace models\datatable;

class FieldForm extends \sys\BaseModel {
    //CLD: 2026-10-26T07:23:58+02:00: the choices are the registry's types and objects (a table created in the request is added): not cached (BaseModel::attachSchema)
    protected static $cacheSchema = false;


    //like Field::afterAttachSchema: once the field exists, key, type and target are readonly
    //(assign(..., true) skips them, the form shows them disabled)
    public function afterAttachSchema() {
	$readonly = ($this->exists() ? 'nobody' : 'everybody');
	foreach([ 'key', 'type', 'object' ] as $key)
	    $this->schema[$key]->{'perm:update'} = $readonly;
    }

    public function getSchema() {
	return [
	    //objectName
	    [
		'key' => 'objectName',
		'type' => 'text',
		'label' => 'Object Name',
		'help' => 'the object that we are describing',
		'required' => true,
		'perm:update' => 'nobody',
		'perm:read' => 'nobody',
	    ],
	    //columns made in the designer can be deleted from the grid (WidgetsUtil::getSchema: perm:delete != nobody);
	    //not postable, saved with the field through data()
	    [
		'key' => 'perm:delete',
		'type' => 'text',
		'label' => 'Delete by',
		'default' => 'everybody',
		'perm:update' => 'nobody',
		'perm:read' => 'nobody',
	    ],
	    //label
	    [
		'key' => 'label',
		'type' => 'text',
		'label' => 'Label',
		'help' => 'Column title',
		'required' => true,
	    ],
	    //key, readonly once created
	    [
		'key' => 'key',
		'type' => 'text',
		'label' => 'Key',
		'help' => 'Letters, digits or underscore, not starting with a digit; cannot be changed later',
		'required' => true,
		'validate' => [
		    [ 'identifier', 'message' => 'Only letters, digits or underscore, not starting with a digit' ],
		    [ 'fieldKeyNotReserved', 'message' => 'This key is reserved', 'on' => 'create' ],
		    [ 'uniqueInObject', 'message' => 'This key already exists in the table', 'collection' => \sys\fields\Field::_collection() ],
		],
	    ],
	    //type, readonly once created
	    [
		'key' => 'type',
		'type' => 'select',
		'label' => 'Type',
		'help' => 'Cannot be changed later',
		'required' => true,
		'default' => 'text',
		'choices' => \V::app()->om->getFieldTypes(),
	    ],
	    //target of `object` fields (objectName), readonly once created
	    [
		'key' => 'object',
		'type' => 'select',
		'label' => 'Points to',
		'help' => false,
		'default' => '',
		'choices' => \V::app()->om->getSelectableObjects(),
		//CLD: 2026-10-06T21:22:22+03:00: required for object columns, and one of the user-selectable objects (checked on create: it is locked afterwards
		//and may no longer be listed, e.g. a deleted table)
		//CLD: 2026-10-14T15:30:03+03:00: a field property (Field adds the rule; was the requiredIf rule in `validate`)
		'requiredIf' => [ 'if' => 'type', 'in' => [ 'object' ], 'message' => 'Choose what this column points to' ],
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo), on create only (as the rule was)
		'choicesOn' => 'create',
		//CLD: 2026-10-14T14:18:23+03:00: shown for object columns (required there by the rule above: util\WidgetsUtil makes the form's requiredIf from it)
		//CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by util\SmartForm::showIf)
		'ui' => [ 'showIf' => [ 'key' => 'type', 'operator' => 'eq', 'value' => 'object' ] ],
	    ],
	    //required
	    [
		'key' => 'required',
		'type' => 'checkbox',
		'label' => 'Required',
		'help' => false,
	    ],
	    //choices, for `select` fields: one per line
	    [
		'key' => 'choices',
		'type' => 'textarea',
		'label' => 'Choices',
		'help' => 'One choice per line',
		'default' => '',
		//required for select columns, checked at validation time with the posted type (`required` computed here
		'requiredIf' => [ 'if' => 'type', 'in' => [ 'select' ], 'message' => 'Enter at least one choice' ],
		//shown for select columns (required there by the rule above: util\WidgetsUtil makes the form's requiredIf from it)
		'ui' => [ 'rows' => 4, 'showIf' => [ 'key' => 'type', 'operator' => 'eq', 'value' => 'select' ] ],
	    ],
	];
    }
}
