<?php

/*
    a key to decrypt files with (util\FileDecrypt), for the FTP / SFTP step. Provides `decrypt`.
    method:
	PGP / GPG private key (+ passphrase),
	PGP / GPG passphrase (symmetric),
	OpenSSL password (openssl enc: cipher, key derivation, iterations), S/MIME / CMS
	private key (+ passphrase, + the certificate, optional). The key and passphrase are stored encrypted and never shown again.
	test(): the key is read (PGP: its user ids; CMS: it opens with the passphrase, matches the certificate)
*/

namespace models\credential;

class CredentialDecryption extends \models\Credential {
    public static $label = 'Decryption key';
    public static $icon = 'bi-unlock';
    public static $group = 'Files';
    public static $provides = [ 'decrypt' ];
    public static $testable = true;

    public function getSchema() {
	$keyMethods = [ 'pgp', 'cms' ];
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Encryption',
		'required' => true,
		'default' => 'pgp',
		'choices' => \util\FileDecrypt::METHODS,
	    ],
	    [
		'key' => 'privateKey',
		'type' => 'textarea',
		'label' => 'Private key',
		'encrypt' => true,
		'requiredIf' => [ 'if' => 'method', 'in' => $keyMethods ],
		'help' => 'PGP: -----BEGIN PGP PRIVATE KEY BLOCK----- (gpg --armor --export-secret-keys); S/MIME: -----BEGIN (ENCRYPTED) PRIVATE KEY----- (PEM)',
		//CLD: 2026-10-20T14:03:59+03:00: util\Validator key rules (were checked in validates())
		'validate' => [
		    [ 'notPublicKey', 'when' => [ 'method' => [ 'pgp' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'this is a public key: decrypting needs the private one' ],
		    [ 'pgpPrivateKey', 'when' => [ 'method' => [ 'pgp' ] ], 'skipEmpty' => true, 'message' => 'not an armored PGP private key (-----BEGIN PGP PRIVATE KEY BLOCK-----)' ],
		    [ 'pemPrivateKey', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'message' => 'not a PEM private key (-----BEGIN PRIVATE KEY-----)' ],
		],
		'ui' => [ 'rows' => 8, 'showIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => $keyMethods ] ],
	    ],
	    [
		'key' => 'passphrase',
		'type' => 'password',
		'label' => 'Passphrase / password',
		'encrypt' => true,
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'pgp-symmetric', 'openssl-enc' ] ],
		'help' => 'Of the private key (empty when it has none), or the password the files were encrypted with',
	    ],
	    [
		'key' => 'certificate',
		'type' => 'textarea',
		'label' => 'Certificate',
		'default' => '',
		'help' => 'Optional: the recipient\'s certificate (PEM), when the files were encrypted for several recipients',
		//CLD: 2026-10-20T14:03:59+03:00: util\Validator certificate rule (was checked in validates())
		'validate' => [
		    [ 'pemCertificate', 'when' => [ 'method' => [ 'cms' ] ], 'multiple' => false, 'skipEmpty' => true, 'message' => 'not a PEM certificate (-----BEGIN CERTIFICATE-----)' ],
		],
		'ui' => [ 'rows' => 4, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'cms' ] ],
	    ],
	    [
		'key' => 'cipher',
		'type' => 'select',
		'label' => 'Cipher',
		'default' => 'aes-256-cbc',
		'choices' => \util\FileDecrypt::CIPHERS,
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
		'help' => 'PBKDF2 (-iter); OpenSSL\'s default 10000',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 10000000, 'skipEmpty' => true, 'message' => 'Between 1 and 10000000' ] ],
		'ui' => [ 'width' => 3, 'step' => 1, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'openssl-enc' ] ],
	    ],
	]);
    }

    //CLD: 2026-10-20T14:03:59+03:00: validates() removed: the keys and certificates are checked by the fields' util\Validator rules

    public function test() {
	return \util\FileDecrypt::check($this);
    }
}
