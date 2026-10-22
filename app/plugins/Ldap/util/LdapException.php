<?php

//CLD: 2026-10-21T10:00:00+03:00: new: an error of an LDAP server or of the connection to it (a \RuntimeException: the LDAP step's On error applies to it).
//  $code is the LDAP result code (49 = invalid credentials, 32 = no such object, ...; -1: no answer / the connection failed), $diagnostic the server's own text
//  (Active Directory: "80090308: LdapErr: ..., data 52e, ..." - adReason() reads its `data` code)

namespace plugins\Ldap\util;

class LdapException extends \RuntimeException {

    //the result codes of RFC 4511 (+ the common ones of the extensions)
    const CODES = [
	0 => 'success', 1 => 'operations error', 2 => 'protocol error', 3 => 'time limit exceeded', 4 => 'size limit exceeded', 7 => 'authentication method not supported',
	8 => 'stronger authentication required', 10 => 'referral', 11 => 'administrative limit exceeded', 12 => 'unavailable critical extension', 13 => 'confidentiality required',
	14 => 'SASL bind in progress', 16 => 'no such attribute', 17 => 'undefined attribute type', 18 => 'inappropriate matching', 19 => 'constraint violation',
	20 => 'attribute or value exists', 21 => 'invalid attribute syntax', 32 => 'no such object', 33 => 'alias problem', 34 => 'invalid DN syntax', 36 => 'alias dereferencing problem',
	48 => 'inappropriate authentication', 49 => 'invalid credentials', 50 => 'insufficient access rights', 51 => 'busy', 52 => 'unavailable', 53 => 'unwilling to perform',
	54 => 'loop detected', 64 => 'naming violation', 65 => 'object class violation', 66 => 'not allowed on non-leaf', 67 => 'not allowed on RDN', 68 => 'entry already exists',
	69 => 'object class modifications prohibited', 71 => 'affects multiple DSAs', 80 => 'other',
    ];

    //Active Directory's reasons of a refused bind (the `data` code of its diagnostic)
    const AD_REASONS = [
	'525' => 'user not found', '52e' => 'invalid credentials', '530' => 'not permitted to sign in at this time', '531' => 'not permitted to sign in at this workstation',
	'532' => 'password expired', '533' => 'account disabled', '701' => 'account expired', '773' => 'the password must be changed', '775' => 'account locked out',
    ];

    const INVALID_CREDENTIALS = 49;
    const NO_SUCH_OBJECT = 32;
    const SIZE_LIMIT = 4;
    //not an LDAP code: no connection, no answer, a broken answer
    const NO_CONNECTION = -1;

    public $ldapCode;
    public $diagnostic;

    public function __construct($ldapCode, $message = '', $diagnostic = '') {
	$this->ldapCode = (int)$ldapCode;
	$this->diagnostic = trim((string)$diagnostic);
	if ($message === '') {
	    $message = self::CODES[$this->ldapCode] ?? ('result code ' . $this->ldapCode);
	    if ($this->ldapCode >= 0)
		$message .= ' (' . $this->ldapCode . ')';
	    if (($ad = $this->adReason()) !== null) {
		if ($ad !== (self::CODES[$this->ldapCode] ?? null))
		    $message .= ': ' . $ad;
	    }
	    elseif ($this->diagnostic !== '')
		$message .= ': ' . mb_substr($this->diagnostic, 0, 300);
	}
	parent::__construct($message, $this->ldapCode);
    }

    //the reason Active Directory gives for a refused bind (null: not Active Directory, or no reason)
    public function adReason() {
	if (preg_match('/\bdata ([0-9a-f]{3,4})\b/i', $this->diagnostic, $m))
	    return self::AD_REASONS[strtolower($m[1])] ?? null;
	return null;
    }

    public function isInvalidCredentials() {
	return $this->ldapCode === self::INVALID_CREDENTIALS;
    }

    public function isConnection() {
	return $this->ldapCode === self::NO_CONNECTION;
    }
}
