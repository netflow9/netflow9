<?php

/*
    what the files' senders sign with, to check their signatures (util\FileVerify), for the FTP / SFTP step.
    Provides `verify`. Nothing secret (public keys, certificates: shown).
	method: PGP / GPG public key(s) of the signers, S/MIME / CMS trusted certificate(s) (the signer's own, or the CA that issued it), or a public key for plain signatures
	(RSA, EC, Ed25519; PEM; + the digest it was made with).
	test(): the keys are read (PGP: the signers; CMS: subjects, not expired; plain: the key's type)
*/

namespace models\credential;

class CredentialVerification extends \models\Credential {
    public static $label = 'Verification key';
    public static $icon = 'bi-patch-check';
    public static $group = 'Files';
    public static $provides = [ 'verify' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Signature',
		'required' => true,
		'default' => 'pgp',
		'choices' => \data\Constants::VERIFY_METHODS,
	    ],
	    [
		'key' => 'publicKey',
		'type' => 'textarea',
		'label' => 'Public key(s)',
		'default' => '',
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'pgp', 'raw' ] ],
		'help' => 'PGP: -----BEGIN PGP PUBLIC KEY BLOCK----- of the sender (several: any of them may sign); plain: -----BEGIN PUBLIC KEY----- (PEM)',
		//util\Validator key rules
		'validate' => [
		    [ 'notPrivateKey', 'skipEmpty' => true, 'last' => true, 'message' => 'this is a private key: paste the sender\'s public key' ],
		    [ 'pgpPublicKey', 'when' => [ 'method' => [ 'pgp' ] ], 'skipEmpty' => true, 'message' => 'not an armored PGP public key (-----BEGIN PGP PUBLIC KEY BLOCK-----)' ],
		    [ 'pemPublicKey', 'when' => [ 'method' => [ 'raw' ] ], 'skipEmpty' => true, 'message' => 'not a PEM public key (-----BEGIN PUBLIC KEY-----)' ],
		],
		'ui' => [ 'rows' => 8, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'pgp', 'raw' ] ] ],
	    ],
	    [
		'key' => 'certificate',
		'type' => 'textarea',
		'label' => 'Trusted certificate(s)',
		'default' => '',
		'requiredIf' => [ 'if' => 'method', 'in' => [ 'cms' ] ],
		'help' => 'The signer\'s certificate, or the CA that issued it (PEM: -----BEGIN CERTIFICATE-----); several: any of them',
		//util\Validator certificate rules
		'validate' => [
		    [ 'notPrivateKey', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'only certificates: a private key does not belong here' ],
		    [ 'pemCertificate', 'when' => [ 'method' => [ 'cms' ] ], 'skipEmpty' => true, 'message' => 'not a PEM certificate (-----BEGIN CERTIFICATE-----)' ],
		],
		'ui' => [ 'rows' => 6, 'spellcheck' => false, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'cms' ] ],
	    ],
	    [
		'key' => 'digest',
		'type' => 'select',
		'label' => 'Digest',
		'default' => 'sha256',
		'choices' => \sys\Security::DIGESTS,
		'help' => 'The one the sender signs with (openssl dgst -sha256 ...); Ed25519: none',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'method', 'operator' => 'eq', 'value' => 'raw' ] ],
	    ],
	]);
    }

    public function test() {
	return \util\FileVerify::checkKeys($this);
    }
}
