<?php

/*
    CLD: 2026-10-20T19:00:00+03:00: new credential type: your private key to sign files with before they are sent (util\FileSign), for the FTP / SFTP Upload step.
    Provides `sign`.
	method: PGP / GPG private key (+ passphrase, a key id when the text has several), S/MIME / CMS (X.509 private key + its certificate + the chain), or a private key
	for plain signatures (RSA, EC, Ed25519; PEM). Digest SHA-256 / 384 / 512; armored / base64 output. The key and passphrase are stored encrypted and never shown again;
	the certificates are not secret (shown).
	test(): the key opens and signs (PGP), belongs to its certificate, which has not expired (CMS)
*/

namespace models\credential;

class CredentialSigning extends \models\Credential {
    public static $label = 'Signing key';
    public static $icon = 'bi-pen';
    public static $group = 'Files';
    public static $provides = [ 'sign' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Signature',
		'required' => true,
		'default' => 'pgp',
		'choices' => \util\FileSign::METHODS,
	    ],
	    [
		'key' => 'privateKey',
		'type' => 'textarea',
		'label' => 'Private key',
		'required' => true,
		'encrypt' => true,
		'help' => 'PGP: -----BEGIN PGP PRIVATE KEY BLOCK----- (gpg --armor --export-secret-keys); the others: -----BEGIN (ENCRYPTED / RSA / EC) PRIVATE KEY----- (PEM)',
		//CLD: 2026-10-20T14:07:31+03:00: util\Validator key rules (were checked in validates())
		'validate' => [
		    [ 'notPublicKey', 'skipEmpty' => true, 'last' => true, 'message' => 'this is a public key: signing needs your private key' ],
		    [ 'pgpPrivateKey', 'when' => [ 'method' => [ 'pgp' ] ], 'multiple' => true, 'skipEmpty' => true, 'message' => 'not an armored PGP private key (-----BEGIN PGP PRIVATE KEY BLOCK-----)' ],
		    [ 'pemPrivateKey', 'when' => [ 'method' => [ 'cms', 'raw' ] ], 'skipEmpty' => true, 'message' => 'not a PEM private key (-----BEGIN PRIVATE KEY-----)' ],
		],
		'ui' => [ 'rows' => 8 ],
	    ],
	    [
		'key' => 'passphrase',
		'type' => 'password',
		'label' => 'Key passphrase',
		'encrypt' => true,
		'help' => 'Empty when the key has none',
	    ],
	    [
		'key' => 'keyId',
		'type' => 'text',
		'label' => 'Key to sign with',
		'default' => '',
		'help' => 'Optional, when the text has several keys: a key id, fingerprint or e-mail address',
		'ui' => [ 'placeholder' => 'billing@example.com', 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'pgp' ] ],
	    ],
	    [
		'key' => 'certificate',
		'type' => 'textarea',
		'label' => 'Certificate',
		'default' => '',
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'cms' ] ],
		'help' => 'The certificate of the private key (PEM: -----BEGIN CERTIFICATE-----)',
		//CLD: 2026-10-20T14:07:31+03:00: util\Validator certificate rules (were checked in validates())
		'validate' => [
		    [ 'notPrivateKey', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'only the certificate: the private key goes in its own field' ],
		    [ 'pemCertificate', 'when' => [ 'method' => [ 'cms' ] ], 'multiple' => false, 'skipEmpty' => true, 'message' => 'not a PEM certificate (-----BEGIN CERTIFICATE-----)' ],
		],
		'ui' => [ 'rows' => 5, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'cms' ] ],
	    ],
	    [
		'key' => 'chain',
		'type' => 'textarea',
		'label' => 'Certificate chain',
		'default' => '',
		'help' => 'Optional: the intermediate certificates (PEM), sent in the signature',
		//CLD: 2026-10-20T14:07:31+03:00: the chain was not checked
		'validate' => [
		    [ 'notPrivateKey', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'only certificates: a private key does not belong here' ],
		    [ 'pemCertificate', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'message' => 'not PEM certificates (-----BEGIN CERTIFICATE-----)' ],
		],
		'ui' => [ 'rows' => 4, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'cms' ] ],
	    ],
	    [
		'key' => 'digest',
		'type' => 'select',
		'label' => 'Digest',
		'default' => 'sha256',
		'choices' => \util\FileSign::DIGESTS,
		'help' => 'Ed25519 keys sign the whole file (no digest)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'armor',
		'type' => 'switch',
		'label' => 'Text output (armored / base64)',
		'default' => false,
		'help' => 'PGP: .asc; a plain signature: base64',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'pgp', 'raw' ] ] ],
	    ],
	]);
    }

    //CLD: 2026-10-20T14:07:31+03:00: validates() removed: the keys and certificates are checked by the fields' util\Validator rules

    public function test() {
	return \util\FileSign::checkKey($this);
    }
}
