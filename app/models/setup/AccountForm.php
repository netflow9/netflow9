<?php

//CLD: 2026-10-19T14:09:20+03:00: new form model: step 3 of the initial setup (SetupController::account) - the first account and its owner: username, email, names, the password twice
//(was the second part of SetupForm). The password fields and their rules are ChangePasswordForm's (create it with [ 'validateCurrentPassword' => false ])

namespace models\setup;

class AccountForm extends \models\user\ChangePasswordForm {

    public function getSchema() {
	$password = array_values(array_filter(parent::getSchema(), fn($f) => $f['key'] !== 'crt_password'));
	return array_merge([
	    [ 'key' => 'account_name', 'type' => 'text', 'label' => 'Company or team', 'required' => true,
		'validate' => [ [ 'lengthBetween', 'min' => 1, 'max' => 100, 'message' => 'At most 100 characters' ] ] ],
	    [ 'key' => 'username', 'type' => 'text', 'label' => 'Username', 'required' => true,
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]{2,40}$', 'message' => 'Letters, digits, dot, dash or underscore (2 to 40)' ] ] ],
	    [ 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'transform' => 'tolower' ],
	    [ 'key' => 'firstname', 'type' => 'text', 'label' => 'First name', 'required' => true ],
	    [ 'key' => 'lastname', 'type' => 'text', 'label' => 'Last name', 'required' => true ],
	], $password);
    }
}
