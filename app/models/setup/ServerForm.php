<?php

//CLD: 2026-10-19T14:09:20+03:00: new form model: step 2 of the initial setup (SetupController::server) - the server's settings written to app.env: the public address, the database,
//the outgoing email (was the first part of SetupForm). Values written to app.env are single-quoted there, so they may not hold a quote or a line break

namespace models\setup;

class ServerForm extends \sys\BaseModel {

    const NO_QUOTE = [ 'regex', 'pattern' => "^[^'\\r\\n]*$", 'skipEmpty' => true, 'message' => 'No quotes (\') or line breaks' ];

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [ 'key' => 'base_url', 'type' => 'text', 'label' => 'Public address', 'required' => true,
		'validate' => [ [ 'url', 'message' => 'A full address, e.g. https://flows.example.com' ], self::NO_QUOTE ] ],
	    [ 'key' => 'mongo_uri', 'type' => 'text', 'label' => 'MongoDB connection string', 'required' => true,
		'validate' => [ [ 'regex', 'pattern' => '^mongodb(\+srv)?://', 'message' => 'Starts with mongodb:// or mongodb+srv://' ], self::NO_QUOTE ] ],
	    [ 'key' => 'mongo_db', 'type' => 'text', 'label' => 'Database name', 'required' => true,
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_-]{1,63}$', 'message' => 'Letters, digits, dash or underscore (at most 63)' ] ] ],
	    [ 'key' => 'smtp_host', 'type' => 'text', 'label' => 'SMTP server', 'required' => true, 'validate' => [ self::NO_QUOTE ] ],
	    [ 'key' => 'smtp_port', 'type' => 'text', 'label' => 'SMTP port', 'required' => true,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'message' => 'A port, 1 to 65535' ] ] ],
	    [ 'key' => 'smtp_secure', 'type' => 'select', 'label' => 'SMTP encryption', 'choices' => [ '' => 'None (STARTTLS when offered)', 'tls' => 'TLS', 'ssl' => 'SSL' ],
		'validate' => [ [ 'inList', 'list' => [ 'tls', 'ssl' ], 'skipEmpty' => true, 'message' => 'Choose one' ] ] ],
	    [ 'key' => 'smtp_username', 'type' => 'text', 'label' => 'SMTP username', 'validate' => [ self::NO_QUOTE ] ],
	    [ 'key' => 'smtp_password', 'type' => 'password', 'label' => 'SMTP password', 'validate' => [ self::NO_QUOTE ] ],
	    [ 'key' => 'mail_from', 'type' => 'text', 'label' => 'Emails come from',
		'validate' => [ [ 'email', 'skipEmpty' => true, 'message' => 'An email address' ], self::NO_QUOTE ] ],
	    //CLD: 2026-10-19T14:18:52+03:00: removed mail_inbound_domain (the inbound email is not set up here)
	];
    }

    //the values of the form's fields (kept in the setup session between the steps)
    public function values() {
	$values = [];
	foreach($this->getSchema() as $f)
	    $values[$f['key']] = $this->{$f['key']};
	return $values;
    }
}
