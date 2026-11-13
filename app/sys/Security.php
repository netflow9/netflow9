<?php

namespace sys;

class Security extends BaseObject {

    //digests for signing / verifying files: [ openssl name => label ]
    const DIGESTS = [
	'sha256' => 'SHA-256',
	'sha384' => 'SHA-384',
	'sha512' => 'SHA-512'
    ];

    //openssl enc ciphers for encrypting / decrypting files: [ openssl name => label ]
    const CIPHERS = [
	'aes-256-cbc' => 'AES-256-CBC',
	'aes-192-cbc' => 'AES-192-CBC',
	'aes-128-cbc' => 'AES-128-CBC',
	'aes-256-ctr' => 'AES-256-CTR',
	'aes-128-ctr' => 'AES-128-CTR',
	'des-ede3-cbc' => '3DES (DES-EDE3-CBC)',
	'chacha20' => 'ChaCha20',
    ];

    //XML signature (SAML) signature methods: [ algorithm URI => [ key type, openssl digest ] ]
    const XMLDSIG_SIGNATURES = [
	'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => [ 'rsa', 'sha1' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => [ 'rsa', 'sha256' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => [ 'rsa', 'sha384' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => [ 'rsa', 'sha512' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' => [ 'ecdsa', 'sha256' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384' => [ 'ecdsa', 'sha384' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512' => [ 'ecdsa', 'sha512' ],
    ];

    //XML signature / encryption (SAML) digests: [ algorithm URI => openssl digest ]
    const XMLDSIG_DIGESTS = [
	'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
	'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
	'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
	'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];

    //XML encryption (SAML) content ciphers: [ algorithm URI => [ openssl cipher, key length, GCM ] ]
    const XMLENC_CIPHERS = [
	'http://www.w3.org/2001/04/xmlenc#aes128-cbc' => [ 'aes-128-cbc', 16, false ],
	'http://www.w3.org/2001/04/xmlenc#aes192-cbc' => [ 'aes-192-cbc', 24, false ],
	'http://www.w3.org/2001/04/xmlenc#aes256-cbc' => [ 'aes-256-cbc', 32, false ],
	'http://www.w3.org/2009/xmlenc11#aes128-gcm' => [ 'aes-128-gcm', 16, true ],
	'http://www.w3.org/2009/xmlenc11#aes192-gcm' => [ 'aes-192-gcm', 24, true ],
	'http://www.w3.org/2009/xmlenc11#aes256-gcm' => [ 'aes-256-gcm', 32, true ],
    ];

    //XML encryption (SAML) MGF1 digests of xmlenc11 rsa-oaep: [ algorithm URI => openssl digest ]
    const XMLENC_MGF = [
	'http://www.w3.org/2009/xmlenc11#mgf1sha1' => 'sha1',
	'http://www.w3.org/2009/xmlenc11#mgf1sha224' => 'sha224',
	'http://www.w3.org/2009/xmlenc11#mgf1sha256' => 'sha256',
	'http://www.w3.org/2009/xmlenc11#mgf1sha384' => 'sha384',
	'http://www.w3.org/2009/xmlenc11#mgf1sha512' => 'sha512',
    ];

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


    //a digest from DIGESTS, sha256 when empty or unknown
    public static function digest($name) {
	$d = (string)($name ?: 'sha256');
	return isset(self::DIGESTS[$d]) ? $d : 'sha256';
    }

    //a cipher from CIPHERS, aes-256-cbc when empty; an unknown one: \RuntimeException
    public static function cipher($name) {
	$c = (string)($name ?: 'aes-256-cbc');
	if (!isset(self::CIPHERS[$c]))
	    throw new \RuntimeException("unknown cipher `{$c}`");
	return $c;
    }

    //fail loudly instead of encrypting with an empty or guessable key
    protected function requireSecret() {
	if (!is_string($this->secret) || strlen($this->secret) < 32)
	    throw new \ErrorException('Security: APP_SECRET is missing or shorter than 32 characters (see app.env)');
    }

    //a key of its own for $purpose, derived from the secret (HMAC-SHA256), e.g. the audit log's seal (models\AuditLog)
    public function deriveKey($purpose) {
	$this->requireSecret();
	//CLD: 2026-11-13T21:20:00+02:00: the 'rail22:' prefix stays: it is part of every derived key, changing it breaks the seals already stored (audit log)
	return hash_hmac('sha256', 'rail22:' . $purpose, $this->secret, true);
    }

    // * Encrypts data.
    public function encrypt($cleardata) {
	//require a configured secret
	$this->requireSecret();
	$ivlen = openssl_cipher_iv_length($this->cipher);
	$iv = openssl_random_pseudo_bytes($ivlen);
	$cipherdata_raw = openssl_encrypt($cleardata, $this->cipher, $this->secret, $options=OPENSSL_RAW_DATA, $iv);
	$hmac = hash_hmac($this->hashalgo, $cipherdata_raw, $this->secret, $as_binary = true);
	return $cipherdata = base64_encode( $iv . $hmac . $cipherdata_raw );
    }

    // * Decrypts data.
    public function decrypt($cipherdata) {
	//require a configured secret
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
