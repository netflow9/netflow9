<?php

/*
    the session of the initial setup (get_setup_config; the app's sessions are in the database, which is not known yet):
    a plain PHP session kept in tmp/ (cookie RAIL22SETUP),
    with Session's state methods (sys\User keeps its data through them; CSRF uses $_SESSION)
*/

namespace sys;

class SessionNoDB extends BaseObject {

    public function __construct($config = []) {
	parent::__construct($config);
	if (session_status() == PHP_SESSION_NONE) {
	    //the cookie's name from the component's config ('name'; e.g. the docs site's), RAIL22SETUP by default
	    session_name($this->_config['name'] ?? 'RAIL22SETUP');
	    if (is_dir(BASE . '/tmp') && is_writable(BASE . '/tmp'))
		session_save_path(BASE . '/tmp');
	    session_start([
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
		'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
	    ]);
	}
    }

    public function noSessionFor($prefix) {
    }

    public function setPersistent($persistent) {
    }

    public function setState($name, $value = null) {
	if ($value === null)
	    unset($_SESSION[$name]);
	else
	    $_SESSION[$name] = $value;
    }

    public function getState($name) {
	return $_SESSION[$name] ?? null;
    }

    public function hasState($name) {
	return isset($_SESSION[$name]);
    }
}
