<?php

namespace models\user;

class LoginForm extends \sys\BaseModel {

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    //username
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
	    ],
	    //password
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
	    ],
	    //rememberme
	    [
		'key' => 'rememberme',
		'type' => 'checkbox',
		'label' => 'Remember me',
		'required' => false,
		'default' => false,
	    ],
	];
    }
}