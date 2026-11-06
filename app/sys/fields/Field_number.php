<?php

namespace sys\fields;

class Field_number extends Field {

    public $icon = 'bi bi-123';
    public $name = 'Number';
    public $ops = [
	//the conditions' operators (util\Conditions): one vocabulary for every type, labels per type;
	'eval' => [ 'eq' => 'Equals', 'ne' => 'Not equals', 'gt' => 'Greater than', 'gte' => 'Greater or equal', 'lt' => 'Less than', 'lte' => 'Less or equal', 'in' => 'Is one of',
	    'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	if (is_numeric($value))
	    return 1 * $value;
	return $value;
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	//add string validation
	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	//always skips an empty value: a required field's empty value is reported once, by `required`
	$object->validates[$this->key][] = [
	    'numeric',
	    'message' => 'only numbers',
	    'skipEmpty' => true,
	];
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
	$value = $this->binding->data($this->key);
	switch($this->format) {
	    //util\Format::bytes: "1.5 MB"
	    case 'bytes-human':
    		return \util\Format::bytes($value, 2);
	    default:
    		return \V::app()->html->escape((string)$this->binding->data($this->key));
	}
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());
	$inputOptions['id'] = $fieldId;

	$__options = [
		'id' => $fieldId,
		'type' => 'text',
		'label' => $this->getLabel(),
		'help' => $this->getHelp(),
		'input' => $inputOptions,
		'value' => $this->binding ? $this->binding->data($this->key) : '',
		'bare' => $options['bare'] ?? false,
		'dir' => $options['dir'] ?? null,
		'cols' => $options['cols'] ?? null,
		'wrap_options' => $options['wrap_options'] ?? null,
	];

	return \V::app()->form->field($this->key, $__options);
    }
}