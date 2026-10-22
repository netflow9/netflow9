<?php

namespace sys\http;

// * A Cookie instance stores a single cookie, including the cookie name, value, domain, path, expire, and secure.
class Cookie extends \sys\BaseObject {

    public function __construct($config = []) {
	$defaults = [
	    'name' => 'dflt_cookie_name',
	    'value' => '',
	    'domain' => '',
	    'expire' => 0,
	    'path' => '/',
	    'secure' => false,
	    'httpOnly' => false,
	];
	$config += $defaults;
	parent::__construct($config);
    }
}
