<?php

//CLD: 2026-10-16T12:14:57+03:00: new form model: the sign-up page (its only field: the email)
//CLD: 2026-10-16T13:15:15+03:00: renamed SignupForm -> EmailForm: also the "forgot username or password" page
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
