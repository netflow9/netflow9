<?php

namespace sys\fields;

class Field_password extends Field {
    public $icon = 'bi bi-key-fill';
    public $name = 'Password';
    public $ops = [
	'eval' => [],
	'set' => [],
    ];

    //SC: 2026-01-09: for password, the cast is kinda shitty: if no value provided, just use the old value
    //this is because in most forms, password is returned empty
    public function cast($value) {
	if (empty($value) || !is_string($value))
	    return $this->binding->data($this->key);
	return $value;
    }

    public function __display(&$options = []) {
	return '******';
    }

    public function __input(&$options = []) {
	$ro = (!$this->checkAccess(\V::app()->user->_id, 'update') ? [ 'readonly' => 'readonly', 'disabled' => 'disabled' ] : []);
	$input = $options['input'] ?? [];

	$__options = [
	    'type' => 'password',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'input' => $input + $ro,
	    'dir' => $options['dir'] ?? null,
	    'bare' => $options['bare'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	];

	return \V::app()->form->field($this->key, $__options);
    }
}