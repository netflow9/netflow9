<?php

//CLD: 2026-10-16T12:14:57+03:00: new form model: the page of a confirmed sign-up - the account's name, the user's name, the password (twice). The password fields
//and their rules (complex, the two match) are ChangePasswordForm's (create it with [ 'validateCurrentPassword' => false ]: no current password)

//CLD: 2026-10-19T11:21:19+03:00: moved to the Registration plugin (was models\user\RegisterForm)

namespace plugins\Registration\models;

class RegisterForm extends \models\user\ChangePasswordForm {

    public function getSchema() {
	$password = array_values(array_filter(parent::getSchema(), fn($f) => $f['key'] !== 'crt_password'));
	return array_merge([
	    [ 'key' => 'account_name', 'type' => 'text', 'label' => 'Company or team', 'required' => true,
		'validate' => [ [ 'lengthBetween', 'min' => 1, 'max' => 100, 'message' => 'At most 100 characters' ] ] ],
	    [ 'key' => 'firstname', 'type' => 'text', 'label' => 'First name', 'required' => true ],
	    [ 'key' => 'lastname', 'type' => 'text', 'label' => 'Last name', 'required' => true ],
	], $password);
    }
}
