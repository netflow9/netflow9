<?php

namespace sys;

class CSRF {

    public $csrf_key = '_csrf';

    //returns the session token or a new generated token
    public function getToken($regen = false) {
	$key = $this->getKey();
	$token = $_SESSION[$key] ?? null;
        if ($regen || !$this->isWellFormed($token))
	    $token = bin2hex(random_bytes(32));

        $_SESSION[$key] = $token;

        return $token;
    }

    public function getKey() {
	return $this->csrf_key;
    }

    public function validate($token) {
	$expected = $_SESSION[$this->getKey()] ?? null;
	if (!$this->isWellFormed($expected) || !is_string($token))
	    return false;
	return hash_equals($expected, $token);
    }

    //CLD: 2026-10-05T20:20:40+03:00: a token is 64 hex chars (see getToken)
    protected function isWellFormed($token) {
	return is_string($token) && strlen($token) === 64 && ctype_xdigit($token);
    }
}