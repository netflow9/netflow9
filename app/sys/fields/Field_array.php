<?php

namespace sys\fields;

class Field_array extends Field {
    public $icon = 'bi bi-code-square';
    public $name = 'Array';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was [ 'OpIsEmpty', 'OpIsNotEmpty' ]
	'eval' => [ 'contains' => 'Contains', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'OpSet' ],
    ];

    public function cast($value) {
	return $this->__cast_json2array($value);
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	//initialize the value
	if (empty($object->{$this->key}))
	    $object->{$this->key} = [];
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
	return print_r($this->binding->data($this->key), true);
    }

    public function __input(&$options = []) {
	$ro = (!$this->checkAccess(\V::app()->user->_id, 'edit') ? [ 'readonly' => 'readonly', 'disabled' => 'disabled' ] : []);
	$input = $options['input'] ?? [];

	//the value
	$value = $this->binding->data($this->key);
	if (empty($value))
	    $value = [];

	if (is_array($value))
	    $value = json_encode($value);

	return \V::app()->form->field($this->key, [
		'id' => $field_id,
		'type' => 'textarea',
		'label' => $this->getLabel(),
		'help' => $this->getHelp(),
		'value' => $value,
		'bare' => $options['bare'] ?? false,
		'dir' => $options['dir'] ?? null,
		'input' => $input + $ro,
		'cols' => $options['cols'] ?? null,
		'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }
}