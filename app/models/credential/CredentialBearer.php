<?php

//CLD: 2026-10-08T14:34:02+03:00: new credential type: a fixed bearer token (Authorization: Bearer ...); for HTTP nodes (httpAuth)
//CLD: 2026-10-09T17:21:14+03:00: a variant of models\Credential (was CredentialTypeBearer, a CredentialType form model): its fields are the credential's,
//httpAuth() is an instance method (was static, given the credential)

namespace models\credential;

class CredentialBearer extends \models\Credential {
    public static $label = 'Bearer token';
    public static $icon = 'bi-shield-lock';
    public static $group = 'HTTP';
    public static $provides = [ 'http' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    //CLD: 2026-10-10T09:14:41+03:00: 'encrypt' => true (stored encrypted)
	    [ 'key' => 'token', 'type' => 'password', 'label' => 'Token', 'required' => true, 'encrypt' => true ],
	]);
    }

    public function httpAuth() {
	return [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->token ], 'query' => [] ];
    }
}
