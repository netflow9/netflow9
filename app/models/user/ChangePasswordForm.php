<?php

namespace models\user;

class ChangePasswordForm extends \sys\BaseModel {

    public function __construct($config = []) {
	$defaults  = [
	    'options' => [
		'validateCurrentPassword' => true,
	    ],
	];
	$config = \util\Set::merge($defaults, $config);
	parent::__construct($config);
    }

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;


	\util\Validator::add('matchConfirm', function($value, $format, $options) {
	    return ($value == @$options['values']['new_password']);
	});
	\util\Validator::add('isComplex', function($value) {
	    if ( !preg_match('/[a-z]/', $value) || !preg_match('/[A-Z]/', $value) ||
		 !preg_match('/[0-9]/', $value) || !preg_match('/[^\w]/', $value) || strlen($value) < 8)
		return false;
	    return true;
	});
	\util\Validator::add('notCurrentPassword', function($value, $format, $options) {
	    $user = \V::app()->om->findById('User', \V::app()->user->_id);
	    return !\V::app()->security->validatePasswordHash($value, $user->password);
	});
	\util\Validator::add('validCurrentPassword', function($value, $format, $options) {
	    $user = \V::app()->om->findById('User', \V::app()->user->_id);
	    return \V::app()->security->validatePasswordHash($value, $user->password);
	});

	//CLD: 2026-10-19T12:52:41+03:00: added to the rules of the schema (parent::_init: `required`, `validate` of the fields); they were replaced, so the forms built on this one
	//(RegisterForm, InviteForm, setup\AccountForm) did not check their own fields' rules
	$this->validates['new_password'][] = [ 'isComplex', 'message' => 'Password should contain: letter, capitals, digits and special chars' ];
	$this->validates['cfm_password'][] = [ 'matchConfirm', 'message'=> 'Passwords dont match', ];
	
	if (!\V::app()->user->isGuest)
	    $this->validates['new_password'][] = [ 'notCurrentPassword', 'message' => 'New password cannot be the same as last one' ];

	if ($this->_config['validateCurrentPassword']) {
	    $this->validates['crt_password'] = [
		[ 'notEmpty', 'message' => 'Password is empty', ],
		[ 'validCurrentPassword', 'message'=> 'Incorrect password', ],
	    ];
	}
    }

    public function getSchema() {
	return [
	    //current password
	    [
		'key' => 'crt_password',
		'type' => 'password',
		'label' => 'Current Password',
		//'required' => true,
	    ],
	    //new password
	    [
		'key' => 'new_password',
		'type' => 'password',
		'label' => 'New Password',
		'required' => true,
	    ],
	    //confirm password
	    [
		'key' => 'cfm_password',
		'type' => 'password',
		'label' => 'Confirm Password',
		'required' => true,
	    ],
	];
    }
}