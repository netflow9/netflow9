<?php

namespace sys\fields;

class Field_url extends Field_text {

    public $icon = 'bi bi-link-45deg';
    public $name = 'Web address';

    public function cast($value) {
	return $this->__cast_string($value);
    }

    public function bindTo(&$object) {
	parent::bindTo($object);

	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	$object->validates[$this->key][] = [
	    'url',
	    'message' => 'Enter a web address',
	    'skipEmpty' => true,
	];
    }
}
