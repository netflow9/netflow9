<?php

namespace sys;

class Security extends BaseObject {

    public function __construct($config = []) {
	$defaults = [
	    'cipher' => 'AES-128-CBC',
	    'hashalgo' => 'sha256',
	    'hashcost' => 12,
	    'secret' => '',
	];
	$config += $defaults;
	parent::__construct($config);
    }

    /**
     * Generates a secure hash from a password and a random salt.
     * The generated hash can be stored in database.

     * Later when a password needs to be validated, the hash can be fetched and passed
     * to [[validatePasswordHash()]]. For example,
     *
     * // generates the hash (usually done during user registration or when the password is changed)
     * $hash = \V::app()->security->generatePasswordHash($password);
     * // ...save $hash in database...
     *
     * // during login, validate if the password entered is correct using $hash fetched from database
     * if (\V::app()->security->validatePasswordHash($password, $hash)) {
     *     // password is good
     * } else {
     *     // password is bad
     * }
     * ```
     */
    public function generatePasswordHash($password) {
    	return password_hash($password, PASSWORD_DEFAULT, [ 'cost' => $this->hashcost ]);
    }

    // Verifies a password against a hash.
    public function validatePasswordHash($password, $hash) {
        if (!is_string($password))
    	    $password = '';

    	return password_verify($password, $hash);
    }


    //CLD: 2026-10-05T20:06:50+03:00: fail loudly instead of encrypting with an empty or guessable key
    protected function requireSecret() {
	if (!is_string($this->secret) || strlen($this->secret) < 32)
	    throw new \ErrorException('Security: APP_SECRET is missing or shorter than 32 characters (see app.env)');
    }

    //CLD: 2026-10-22T00:00:00+03:00: a key of its own for $purpose, derived from the secret (HMAC-SHA256), e.g. the audit log's seal (models\AuditLog)
    public function deriveKey($purpose) {
	$this->requireSecret();
	return hash_hmac('sha256', 'netflow9:' . $purpose, $this->secret, true);
    }

    // * Encrypts data.
    public function encrypt($cleardata) {
	//CLD: 2026-10-05T20:06:50+03:00: require a configured secret
	$this->requireSecret();
	$ivlen = openssl_cipher_iv_length($this->cipher);
	$iv = openssl_random_pseudo_bytes($ivlen);
	$cipherdata_raw = openssl_encrypt($cleardata, $this->cipher, $this->secret, $options=OPENSSL_RAW_DATA, $iv);
	$hmac = hash_hmac($this->hashalgo, $cipherdata_raw, $this->secret, $as_binary = true);
	return $cipherdata = base64_encode( $iv . $hmac . $cipherdata_raw );
    }

    // * Decrypts data.
    public function decrypt($cipherdata) {
	//CLD: 2026-10-05T20:06:50+03:00: require a configured secret (outside the try: a missing secret is a configuration error, not bad data)
	$this->requireSecret();
	try {
	    $c = base64_decode($cipherdata);
	    $ivlen = openssl_cipher_iv_length($this->cipher);
	    $iv = substr($c, 0, $ivlen);
	    $hmac = substr($c, $ivlen, $sha2len = 32);
	    $cipherdata_raw = substr($c, $ivlen + $sha2len);
	    $cleardata = openssl_decrypt($cipherdata_raw, $this->cipher, $this->secret, $options = OPENSSL_RAW_DATA, $iv);
	    $calcmac = hash_hmac($this->hashalgo, $cipherdata_raw, $this->secret, $as_binary=true);
	    if (hash_equals($hmac, $calcmac)) { //PHP 5.6+ timing attack safe comparison
		return $cleardata;
	    }
	} catch (\ErrorException $e) {
	}
	return null;
    }
}
