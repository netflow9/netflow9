<?php

//CLD: 2026-10-17T22:00:00+03:00: new form model: the page of a project invitation (SiteController::invite) - the user's name, the password (twice). The password fields and
//their rules are ChangePasswordForm's (create it with [ 'validateCurrentPassword' => false ]: no current password)

namespace models\user;

class InviteForm extends ChangePasswordForm {

    //the names are required (the schema's `required` is not checked by form models)
    public function _init() {
	parent::_init();
	$this->validates['firstname'] = [ [ 'notEmpty', 'message' => 'Enter your first name' ] ];
	$this->validates['lastname'] = [ [ 'notEmpty', 'message' => 'Enter your last name' ] ];
    }

    public function getSchema() {
	$password = array_values(array_filter(parent::getSchema(), fn($f) => $f['key'] !== 'crt_password'));
	return array_merge([
	    [ 'key' => 'firstname', 'type' => 'text', 'label' => 'First name', 'required' => true ],
	    [ 'key' => 'lastname', 'type' => 'text', 'label' => 'Last name', 'required' => true ],
	], $password);
    }
}
