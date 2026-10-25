<?php

namespace sys\fields;

class Field_select extends Field {
    public $icon = 'bi bi-menu-button-wide';
    public $name = 'Select';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was [ 'is' => 'Is', 'in' => 'In', ]
	'eval' => [ 'eq' => 'Is', 'ne' => 'Is not', 'in' => 'Is one of', 'contains' => 'Contains', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    //`multiple`: a list of choice values (strings; blanks and repeats dropped); was always one string, which broke multiple selects
    public function cast($value) {
	if (empty($this->multiple)) {
	    $value = $this->__cast_string($value);
	    if (empty($value))
		return null;

	    //CLD: 2026-10-18T11:20:00+03:00: kept when there are no choices (e.g. depending on the user, outside a web request), as the multiple select (was dropped: null)
	    $choices = $this->choicesToArray();
	    if (!empty($choices) && !isset($choices[$value]))
		return null;

	    return $value;
	}
	$values = array_map([ $this, '__cast_string' ], $this->__cast_json2array($value));
	$choices = $this->choicesToArray();
	return array_values(array_unique(array_filter($values, fn($v) => $v !== '' && (empty($choices) || array_key_exists($v, $choices)))));
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	$object->validates[$this->key][] = [
	    'choices',
	    'choices' => [ $this, 'choicesToArray' ],
	    'message' => 'Not one of the choices',
	    'skipEmpty' => true,
	    //CLD: 2026-10-18T14:40:00+03:00: `choicesOn` (e.g. 'create'): checked on these events only (a locked value whose choice may go away)
	    'on' => $this->choicesOn ?? null,
	];
    }

    //choices as [ value => label ]; with `choicesAreValues` (columns from the data table designer) the stored
    //choices are a plain list whose entries are both value and label (list storage keeps choice text out of MongoDB keys)
    public function choicesToArray() {
	$choices = $this->choices;
	if (is_callable($choices))
	    $choices = $choices();

	//convert string to array
	if (is_string($choices)) {
	    $_choices = [];
	    foreach(preg_split('/\r\n|\r|\n/', $choices) as $line) {
		$line = trim($line);
		if ($line !== '' && !in_array($line, $_choices, true))
		    $_choices[] = $line;
	    }
	    $choices = $_choices;
	    $choices = array_values(array_map('strval', $choices));
	    return array_combine($choices, $choices);
	}

	if (empty($choices) || !is_array($choices))
	    return [];

	//CLD: 2026-10-18T12:30:00+03:00: a plain list of values (e.g. [ 'GET', 'POST' ]) is value => value; its indexes were taken as the values
	if (array_is_list($choices) && count(array_filter($choices, 'is_scalar')) === count($choices)) {
	    $choices = array_values(array_unique(array_map('strval', $choices)));
	    return array_combine($choices, $choices);
	}

	return $choices;
    }

    public function getSchema() {
	$schema = parent::getSchema();

	$schema['choices'] = [
	    'key' => 'choices',
	    'help' => false,
	    'label' => 'Choices',
	    'type' => 'textarea',
	    'required' => false,
	    'perm:read' => true,
	    'perm:update' => true,
	];
	return $schema;
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());
	$inputOptions['id'] = $fieldId;
	$inputOptions['placeholder'] = 'Search...';
	$inputOptions['multiple'] = !empty($this->multiple) ? 'multiple' : null;

	//choices
	//CLD: 2026-10-05T21:06:21+03:00: via choices() (list support)
	$choices = $this->choicesToArray();

	\V::app()->cs->addPageScript("new app.SelectPlus('#{$fieldId}');");

	return \V::app()->form->field($this->key, [
	    'id' => $fieldId,
	    'type' => 'select',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'list' => $choices,
	    'value' => $this->binding ? $this->binding->data($this->key) : '',
	    'input' => $inputOptions,
	    'bare' => $options['bare'] ?? null,
	    'empty' => !$this->required,
	    'dir' => $options['dir'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';

	$value = $this->binding->data($this->key);
	if (empty($value))
	    return '';

	$choices = $this->choicesToArray();

	//CLD: 2026-10-25T21:30:00+02:00: escaped (display() is HTML, as Field_text's; the label, or a value not in the choices, was as is)
	if (!$this->multiple)
	    return \V::app()->html->escape($choices[$value] ?? $value);

	if (!is_array($value))
	    $value = [ $value ];

	$output = [];
	foreach($value as $__value)
	    $output[] = \V::app()->html->escape($choices[(string)$__value] ?? $__value);

	return implode(',', $output);
    }
}