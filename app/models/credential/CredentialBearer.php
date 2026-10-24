<?php

/*
    a fixed bearer token (Authorization: Bearer ...); for HTTP nodes (httpAuth)
    its fields are the credential's,
    httpAuth() is an instance method
*/

namespace models\credential;

class CredentialBearer extends \models\Credential {
    public static $label = 'Bearer token';
    public static $icon = 'bi-shield-lock';
    public static $group = 'HTTP';
    public static $provides = [ 'http' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'token', 'type' => 'password', 'label' => 'Token', 'required' => true, 'encrypt' => true ],
	]);
    }

    public function httpAuth() {
	return [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->token ], 'query' => [] ];
    }
}
