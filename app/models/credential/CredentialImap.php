<?php

/*
    an IMAP server with username / password (or app password)
    openImap() / test() are instance methods
*/

namespace models\credential;

class CredentialImap extends \models\Credential {
    public static $label = 'IMAP server (password)';
    public static $icon = 'bi-envelope';
    public static $group = 'Email';
    public static $provides = [ 'imap' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'IMAP server',
		'required' => true,
		'default' => '',
		'ui' => [
		    'width' => 8,
		    'placeholder' => 'imap.example.com'
		]
	    ],
	    [
		'key' => 'port',
		'type' => 'number',
		'label' => 'Port',
		'required' => true,
		'default' => 993,
		'validate' => [
		    [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ]
		],
		'ui' => [
		    'width' => 4,
		    'step' => 1
		],
	    ],
	    [
		'key' => 'security',
		'type' => 'select',
		'label' => 'Security',
		'required' => true,
		'default' => 'ssl',
		'choices' => [
		    'ssl' => 'SSL/TLS (port 993)',
		    'starttls' => 'STARTTLS (port 143)',
		    'none' => 'None (unencrypted)',
		],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'ui' => [
		    'width' => 6
		]
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
		'encrypt' => true,
		'help' => 'Gmail: an app password',
		'ui' => [ 'width' => 6 ]
	    ],
	    [
		'key' => 'email',
		'type' => 'email',
		'label' => 'Email address',
		'default' => '',
		'help' => 'Optional, shown next to the name'
	    ],
	]);
    }

    //a connected, signed-in client; the result is kept on the credential
    public function openImap() {
	return ImapConnect::open($this, $this->host, (int)($this->port ?: 993), (string)($this->security ?: 'ssl'), function($imap) {
	    $imap->login($this->username, $this->password);
	});
    }

    public function test() {
	return ImapConnect::test($this);
    }
}
