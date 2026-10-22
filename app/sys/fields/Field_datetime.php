<?php

namespace sys\fields;

class Field_datetime extends Field {
    public $name = 'DateTime';
    public $icon = 'bi bi-calendar-event';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was [ 'before' => 'Before', 'after' => 'After', ]
	'eval' => [ 'lt' => 'Before', 'gt' => 'After', 'lte' => 'On or before', 'gte' => 'On or after', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'OpTimeSetCrt', 'OpTimeAdd', 'OpTimeSub', 'OpSet', 'OpUnset'],
    ];

    public function _init() {
	parent::_init();

	$this->_data['format'] = $this->_data['format'] ?? 'datetime';
    }

    public function cast($value) {
	if (is_null($value))
	    return $value;

	if ($value instanceof \MongoDB\BSON\UTCDateTime)
	    return $value;

	if ($value instanceof \DateTime)
	    return new \MongoDB\BSON\UTCDateTime($value->getTimestamp() * 1000);

	if (is_numeric($value) && $value > 0) {
	    //value in seconds ?
	    if ($value < 2147483647)
		$value = $value * 1000;
	    //value in milliseconds
	    return new \MongoDB\BSON\UTCDateTime($value);
	}

	if (is_string($value)) {
	    $__value = strtotime($value);
	    if ($__value)
		return new \MongoDB\BSON\UTCDateTime($__value * 1000);
	    return null;
	}

	return $value;
    }

    //add data:object field
    public function getSchema() {
	$schema = parent::getSchema();

	$schema[] = [
	    'key' => 'format',
	    'help' => false,
	    'label' => 'Format',
	    'type' => 'select',
	    'default' => 'datetime',
	    'required' => true,
	    'perm:read' => 'everybody',
	    'perm:update' => 'everybody',
	    'choices' => [
		'datetime' => 'Datetime',
		'date' => 'Date',
	    ]
	];
	return $schema;
    }


    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';

	$format = $options['format'] ?? null;
	if (empty($format))
	    $format = \V::app()->config("app.format.{$this->format}");

	if (empty($value = $this->binding->data($this->key)))
	    return '';

	$value = $value->toDateTime();
	$value->setTimezone(new \DateTimeZone(date_default_timezone_get())); //Set timezone
	return $value->format($format);
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());
	$inputOptions['id'] = $fieldId;

	\V::app()->cs->addPageScript("new app.DatePicker('#{$fieldId}', { mode: '{$this->format}' });");

	return \V::app()->form->field($this->key, [
	    'id' => $fieldId,
	    'type' => 'text',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'input' => $inputOptions,
	    //'value' => $this->binding ? $this->binding->data($this->key) : '',
	    'bare' => $options['bare'] ?? false,
	    'dir' => $options['dir'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }


}