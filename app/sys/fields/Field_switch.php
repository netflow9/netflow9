<?php

namespace sys\fields;

class Field_switch extends Field {

    public $icon = 'bi bi-toggles';
    public $name = 'Switch';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was [ 'eq' => 'Is' ]
	'eval' => [ 'eq' => 'Is', 'notEmpty' => 'Is on', 'empty' => 'Is off', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	return $this->__cast_boolean($value);
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
	return $this->binding->data($this->key) ? \V::t('On') : \V::t('Off');
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());
	$inputOptions['id'] = $fieldId;

	return \V::app()->form->field($this->key, [
		'id' => $fieldId,
		'type' => 'switch',
		'help' => $this->getHelp(),
		'label' => $this->getLabel(),
		'input' => $inputOptions,
		'value' => true,
		'checked' => $this->binding->data($this->key) ? 'checked' : '',
		'cols' => $options['cols'] ?? null,
		'bare' => $options['bare'] ?? false,
		'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }


}