<?php

namespace sys\fields;

class Field_text extends Field {

    public $icon = 'bi bi-input-cursor-text';
    public $name = 'Text line';
    public $ops = [
	//the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type;
	'eval' => self::EVAL_TEXT,
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	$value = $this->__cast_string($value);
    
	switch($this->{'transform'}) {
	    case 'ucfirst':
		$value = ucfirst($value);
		break;
	    case 'ucwords':
		$value = ucwords($value);
		break;
	    case 'tolower':
		$value = strtolower($value);
		break;
	    case 'toupper':
		$value = strtoupper($value);
		break;
	    case 'trim':
		$value = trim($value);
		break;
	    case 'none':
		break;
	}
	return $value;
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	//add string validation
	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	$object->validates[$this->key][] = [
	    'string',
	    'message' => 'only letters, numbers and symbols',
	    'skipEmpty' => empty($this->{'required'})
	];
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
        return \V::app()->html->escape((string)$this->binding->data($this->key));
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