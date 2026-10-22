<?php

namespace sys\fields;

/*
    a nested object (or, with 'multiple' => true, a list of them), stored inside the model's document.
    `fields` are the nested object's field definitions (the same as a model's schema; a `nested` field may hold `nested` fields):
    [
	'key' => 'address',
	'type' => 'nested',
	'label' => 'Address',
	'fields' => [
    	    [
    		'key' => 'city',
    		'type' => 'text',
    		'label' => 'City',
    		'required' => true
    	    ],
    	    [
    		'key' => 'zip',
    		'type' => 'text',
    		'label' => 'Zip'
    	    ],
	],
    ]
    -- or --
    [
	'key' => 'address',
	'type' => 'nested',
	'label' => 'Address',
	'object' => 'AddressObject',
    ]
    created_at / updated_at, left out; `unique` dropped: a value inside a document cannot be checked for uniqueness):

    CLD: 2026-10-18T10:20:00+03:00: 'itemLabel' => one item's label in the error messages of a list ("Condition 1: Test: ..."; default: the field's label)

    CLD: 2026-10-18T10:45:00+03:00: 'validateIf' => [ 'if' => <field>, 'in' => [ values ] ]: the nested object(s) are validated only when field `if` of the model has one of
    the values (as `requiredIf`; e.g. the Data Table's filter rows, used by some operations only); they are cast always

    The value is cast by the nested fields (unknown keys dropped, defaults set);
    the model's validates() also validates each nested object (validateBinding),
    its errors keyed by path: `address.city`, `addresses.1.city`
*/

class Field_nested extends Field {
    public $icon = 'bi bi-diagram-3';
    public $name = 'Nested object';
    public $ops = [
	'eval' => [ 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function _init() {
	parent::_init();

	$this->_data['multiple'] = !empty($this->_data['multiple']);
	$this->_data['fields'] = is_array($this->_data['fields'] ?? null) ? $this->_data['fields'] : [];
    }

    //CLD: 2026-10-18T09:58:40+03:00: the nested fields (resolved once)
    protected $_nestedFields = null;

    //CLD: 2026-10-18T09:58:40+03:00: the nested field definitions: those of object `object` when set, else `fields`
    public function nestedFields() {
	if ($this->_nestedFields !== null)
	    return $this->_nestedFields;

	if (empty($this->_data['object']))
	    return $this->_nestedFields = $this->_data['fields'];

	$objectName = (string)$this->_data['object'];
	if (!\V::app()->om->isDefined($objectName))
	    throw new \ErrorException("Field `{$this->key}`: unknown object `{$objectName}`");

	//the object's schema, not attached (its definitions, not fields bound to it)
	$object = \V::app()->om->create($objectName, [], [ 'attachSchema' => false ]);
	$system = array_column($object->getSystemSchema(), 'key');
	$fields = [];
	foreach($object->getSchema() as $fDef) {
	    //a field loaded from the database (data table columns): its definition, without the record's own keys
	    if (is_object($fDef))
		$fDef = array_diff_key($fDef->data(), array_flip([ '_id', 'aid', 'objectName', 'objectId', 'created_at', 'updated_at' ]));
	    if (!is_array($fDef) || empty($fDef['key']) || in_array($fDef['key'], $system, true))
		continue;
	    unset($fDef['unique']);
	    $fields[] = $fDef;
	}
	return $this->_nestedFields = $fields;
    }

    //a nested object of this field's nested fields holding $data: cast, or as it is ($cast false)
    public function nestedObject($data = [], $cast = true) {
	return \sys\NestedModel::forFields($this->nestedFields(), $data, [], $cast);
    }

    //an object (array) cast by the nested fields; multiple: a list of them. JSON text is decoded. A value that is not an object (or a list of
    //them) is kept as it is: validation refuses it (validateBinding)
    public function cast($value) {
	try {
	    $value = $this->__cast_json2array($value);
	} catch (\ErrorException $e) {
	    return $value;
	}

	if (!$this->multiple) {
	    if (empty($value))
		return null;
	    return self::isObject($value) ? $this->nestedObject($value)->data() : $value;
	}

	if (!array_is_list($value))
	    return $value;
	return array_map(fn($item) => self::isObject($item) ? $this->nestedObject($item)->data() : $item, $value);
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	//a list is never null
	if ($this->multiple && $object->{$this->key} === null) {
	    $object->_trackChanges = false;
	    $object->{$this->key} = [];
	    $object->_trackChanges = true;
	}
    }

    //an object: an array with keys (or empty); a list of values is not one
    protected static function isObject($value) {
	return is_array($value) && ($value === [] || !array_is_list($value));
    }

    //the errors of the nested object(s): [ "<key>.<nested key>" => [ messages ] ] (multiple: "<key>.<index>.<nested key>"), validated on the
    //model's $events; each object as it is held (not cast again: as the model's own values); blank values are left to the field's own rules (required)
    public function validateBinding($events = null) {
	$value = $this->binding->data()[$this->key] ?? null;
	if ($value === null || $value === '' || $value === [])
	    return [];
	//CLD: 2026-10-18T10:45:00+03:00: `validateIf`: not validated unless field `if` has one of the values `in`
	if (!empty($this->validateIf['if']) && !in_array($this->binding->data()[(string)$this->validateIf['if']] ?? null, (array)($this->validateIf['in'] ?? []), true))
	    return [];

	$items = $this->multiple ? $value : [ $value ];
	if ($this->multiple && (!is_array($value) || !array_is_list($value)))
	    return [ $this->key => [ \V::t('This value is not a list of objects') ] ];

	$errors = [];
	foreach($items as $index => $item) {
	    $path = $this->multiple ? "{$this->key}.{$index}" : $this->key;
	    if (!self::isObject($item)) {
		$errors[$path][] = \V::t('This value is not an object');
		continue;
	    }

	    $o = $this->nestedObject($item, false);
	    $options = ($events === null) ? [] : [ 'events' => $events ];
	    if (!$o->validates($options)) {
		foreach($o->errors() as $key => $messages)
		    $errors["{$path}.{$key}"] = $messages;
	    }
	}
	return $errors;
    }

    //CLD: 2026-10-18T10:20:00+03:00: the label of an error path: "<label>: <nested label>", a list's item "<itemLabel> <n>: <nested label>" (n from 1)
    public function pathLabel(array $parts = []) {
	$label = (string)$this->label;
	if ($this->multiple && $parts && ctype_digit((string)$parts[0]))
	    $label = $this->itemPrefix((int)array_shift($parts));
	return $parts ? $label . ': ' . $this->nestedObject()->errorLabel(implode('.', $parts)) : $label;
    }

    //CLD: 2026-10-18T10:20:00+03:00: the label of item $index of a list (from 0): "<itemLabel> <n>"; else the field's label
    public function itemPrefix($index = null) {
	return ($this->multiple && $index !== null) ? (string)($this->itemLabel ?? $this->label) . ' ' . ($index + 1) : (string)$this->label;
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
	$value = $this->binding->data($this->key);
	if (empty($value))
	    return '';
	return \V::app()->html->escape(\util\JSON::encode($value));
    }

    //the value as JSON text (as Field_array)
    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());

	$value = $this->binding ? $this->binding->data($this->key) : null;
	if (is_array($value))
	    $value = \util\JSON::encode($value);

	return \V::app()->form->field($this->key, [
	    'id' => $fieldId,
	    'type' => 'textarea',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'input' => $inputOptions,
	    'value' => $value ?? '',
	    'bare' => $options['bare'] ?? false,
	    'dir' => $options['dir'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }
}
