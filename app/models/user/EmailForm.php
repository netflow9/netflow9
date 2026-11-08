<?php

/*
    the sign-up page (its only field: the email)
*/

namespace models\user;

class EmailForm extends \sys\BaseModel {

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [ 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'transform' => 'tolower' ],
	];
    }
}
