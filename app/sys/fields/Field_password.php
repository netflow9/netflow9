<?php

namespace sys\fields;

class Field_password extends Field {
    public $icon = 'bi bi-key-fill';
    public $name = 'Password';
    public $ops = [
	'eval' => [],
	'set' => [],
    ];

    //if no value provided, just use the old value
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
	//CLD: 2026-10-31T20:00:00+02:00: an encrypted value is saved: its (empty) input's placeholder says so (empty = keep it, BaseModel::assign),
	//as app.smartform's `stored` password fields
	if (!empty($this->encrypt) && $this->binding && !in_array($this->binding->data($this->key), [ null, '' ], true))
	    $input += [ 'placeholder' => 'Stored; leave empty to keep it' ];

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