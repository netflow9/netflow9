<?php

namespace sys\fields;

class Field_email extends Field_text {

    public $icon = 'bi bi-at';
    public $name = 'Email';
    public $ops = [
	//the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type
	'eval' => self::EVAL_TEXT,
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