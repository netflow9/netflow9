<?php

/*
    a key to encrypt files with before they are sent (util\FileEncrypt), for the FTP / SFTP Upload step. Provides `encrypt`.
	method: PGP / GPG public key(s) of the recipients (+ armored output), PGP / GPG passphrase (symmetric), OpenSSL password (openssl enc: cipher, key derivation,
	iterations, base64), S/MIME / CMS certificate(s) of the recipients. Public keys and certificates are not secret (shown); passphrases are stored encrypted.
	test(): the keys are read (PGP: the recipients; CMS: the certificates' subjects, not expired)
*/

namespace models\credential;

class CredentialEncryption extends \models\Credential {
    public static $label = 'Encryption key';
    public static $icon = 'bi-lock';
    public static $group = 'Files';
    public static $provides = [ 'encrypt' ];
    public static $testable = true;

    public function getSchema() {
	$secret = [ 'pgp-symmetric', 'openssl-enc' ];
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Encryption',
		'required' => true,
		'default' => 'pgp',
		'choices' => \data\Constants::ENCRYPT_METHODS,
	    ],
	    [
		'key' => 'publicKey',
		'type' => 'textarea',
		'label' => 'Public key(s)',
		'default' => '',
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'pgp' ] ],
		'help' => '-----BEGIN PGP PUBLIC KEY BLOCK----- (gpg --armor --export); several keys: each recipient can open the files (e.g. the partner\'s and your own)',
		//util\Validator key rules
		'validate' => [
		    [ 'notPrivateKey', 'when' => [ 'method' => [ 'pgp' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'this is a private key: paste the recipient\'s public key (gpg --armor --export)' ],
		    [ 'pgpPublicKey', 'when' => [ 'method' => [ 'pgp' ] ], 'skipEmpty' => true, 'message' => 'not an armored PGP public key (-----BEGIN PGP PUBLIC KEY BLOCK-----)' ],
		],
		'ui' => [ 'rows' => 8, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'pgp' ] ],
	    ],
	    [
		'key' => 'certificate',
		'type' => 'textarea',
		'label' => 'Certificate(s)',
		'default' => '',
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'cms' ] ],
		'help' => '-----BEGIN CERTIFICATE----- (PEM); several: each recipient can open the files',
		//util\Validator certificate rules
		'validate' => [
		    [ 'notPrivateKey', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'only the certificate: a private key does not belong here' ],
		    [ 'pemCertificate', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'message' => 'not a PEM certificate (-----BEGIN CERTIFICATE-----)' ],
		],
		'ui' => [ 'rows' => 6, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'cms' ] ],
	    ],
	    [
		'key' => 'passphrase',
		'type' => 'password',
		'label' => 'Passphrase / password',
		'encrypt' => true,
		'requiredIf' => [ 'if' => 'method', 'in' => $secret ],
		'help' => 'Shared with the recipient',
		'ui' => [ 'showIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => $secret ] ],
	    ],
	    [
		'key' => 'cipher',
		'type' => 'select',
		'label' => 'Cipher',
		'default' => 'aes-256-cbc',
		'choices' => \sys\Security::CIPHERS,
		'ui' => [ 'width' => 4, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'openssl-enc' ] ],
	    ],
	    [
		'key' => 'kdf',
		'type' => 'select',
		'label' => 'Key derivation',
		'default' => 'pbkdf2',
		'choices' => \util\FileDecrypt::KDF,
		'ui' => [ 'width' => 5, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'openssl-enc' ] ],
	    ],
	    [
		'key' => 'iterations',
		'type' => 'number',
		'label' => 'Iterations',
		'default' => 10000,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 10000000, 'skipEmpty' => true, 'message' => 'Between 1 and 10000000' ] ],
		'ui' => [ 'width' => 3, 'step' => 1, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'openssl-enc' ] ],
	    ],
	    [
		'key' => 'armor',
		'type' => 'switch',
		'label' => 'Text output (armored / base64)',
		'default' => false,
		'help' => 'PGP: .asc instead of .gpg; OpenSSL: base64',
		'ui' => [ 'showIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'pgp', 'pgp-symmetric', 'openssl-enc' ] ] ],
	    ],
	]);
    }

    public function test() {
	return \util\FileEncrypt::checkKeys($this);
    }
}
