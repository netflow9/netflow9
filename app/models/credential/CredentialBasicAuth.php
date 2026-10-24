<?php

/*
    username / password sent as HTTP Basic authentication; for HTTP nodes (httpAuth)
    its fields are the credential's,
    httpAuth() is an instance method
*/

namespace models\credential;

class CredentialBasicAuth extends \models\Credential {
    public static $label = 'Basic auth';
    public static $icon = 'bi-person-lock';
    public static $group = 'HTTP';
    public static $provides = [ 'http' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'username', 'type' => 'text', 'label' => 'Username', 'required' => true, 'default' => '', 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'password', 'type' => 'password', 'label' => 'Password', 'encrypt' => true, 'ui' => [ 'width' => 6 ] ],
	]);
    }

    public function httpAuth() {
	$pair = $this->username . ':' . (string)$this->password;
	return [ 'headers' => [ 'Authorization' => 'Basic ' . base64_encode($pair) ], 'query' => [] ];
    }
}
