<?php

namespace sys\fields;

class Field_textarea extends Field {
    public $icon = 'bi bi-card-text';
    public $name = 'Multi line';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type; was [ 'is' => 'Is', '!is' => 'Is Not', 'contains' => 'Contains', ]
	'eval' => [ 'eq' => 'Is', 'ne' => 'Is not', 'contains' => 'Contains', 'startsWith' => 'Starts with', 'in' => 'Is one of', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	return $this->__cast_string($value);
    }


    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
	return nl2br(\V::app()->html->escape($this->binding->data($this->key)));
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());

	//render
	return \V::app()->form->field($this->key, [
	    'id' => $fieldId,
	    'type' => 'textarea',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'input' => $inputOptions,
	    'value' => $this->binding->data($this->key),
	    'bare' => $options['bare'] ?? false,
	    'dir' => $options['dir'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }


}