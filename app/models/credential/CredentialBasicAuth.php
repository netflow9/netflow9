<?php

//CLD: 2026-10-08T14:34:02+03:00: new credential type: username / password sent as HTTP Basic authentication; for HTTP nodes (httpAuth)
//CLD: 2026-10-09T17:21:14+03:00: a variant of models\Credential (was CredentialTypeBasicAuth, a CredentialType form model): its fields are the credential's,
//httpAuth() is an instance method (was static, given the credential)

namespace models\credential;

class CredentialBasicAuth extends \models\Credential {
    public static $label = 'Basic auth';
    public static $icon = 'bi-person-lock';
    public static $group = 'HTTP';
    public static $provides = [ 'http' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    //CLD: 2026-10-10T18:44:22+03:00: 'default' => '' (in every document; was set by Credential::_init)
	    [ 'key' => 'username', 'type' => 'text', 'label' => 'Username', 'required' => true, 'default' => '', 'ui' => [ 'width' => 6 ] ],
	    //CLD: 2026-10-10T09:14:41+03:00: 'encrypt' => true (stored encrypted)
	    //CLD: 2026-10-23T16:30:00+03:00: password optional (some APIs take the username / key alone: "user:")
	    [ 'key' => 'password', 'type' => 'password', 'label' => 'Password', 'encrypt' => true, 'ui' => [ 'width' => 6 ] ],
	]);
    }

    public function httpAuth() {
	//CLD: 2026-10-23T16:30:00+03:00: no password: "username:"
	$pair = $this->username . ':' . (string)$this->password;
	return [ 'headers' => [ 'Authorization' => 'Basic ' . base64_encode($pair) ], 'query' => [] ];
    }
}
