<?php

namespace sys\fields;

class Field_oid extends Field {

    public $icon = 'bi bi-input-cursor-text';
    public $name = 'Object ID';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was []
	'eval' => [ 'eq' => 'Is', 'ne' => 'Is not', 'in' => 'Is one of', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function cast($value) {
	return $this->__cast_oid($value);
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';
        return \V::app()->html->escape((string)$this->binding->data($this->key));
    }

    public function __input(&$options = []) {
	return '';
    }
}