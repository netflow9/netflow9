<?php

namespace sys\fields;

class Field_email extends Field_text {

    public $icon = 'bi bi-at';
    public $name = 'Email';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type; was [ 'is' => 'Is', '!is' => 'Is Not', 'contains' => 'Contains', ]
	'eval' => [ 'eq' => 'Is', 'ne' => 'Is not', 'contains' => 'Contains', 'startsWith' => 'Starts with', 'in' => 'Is one of', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	return $this->__cast_string($value);
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	//add email validation
	//\V::app()->log->debug('Field_email::bindTo: adding email validation to object:' . $object->getObjectName());
	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	$object->validates[$this->key][] = [
	    'email',
	    'message' => 'invalid email address',
	    'skipEmpty' => empty($this->{'prop:required'})
	];
    }
}