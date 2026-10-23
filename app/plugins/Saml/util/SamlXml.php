<?php

//CLD: 2026-10-23T12:00:00+03:00: new: the XML security of SAML messages, with PHP's DOM and openssl only (no library):
//  load($xml)                    a document; no DOCTYPE / entities, no network, a size limit
//  verify($element, $certs)      the enveloped signature of $element (its ds:Signature child, a single Reference to the element's ID, unique in the document), checked
//                                with one of the IdP's certificates (PEM; the certificate in the message's KeyInfo is never used); the signature algorithm, else SamlException
//  decrypt($encrypted, $keyPem)  the XML of an EncryptedAssertion (or EncryptedID ...) with the service provider's private key: AES-CBC / AES-GCM, the key RSA-OAEP
//  sign($element, $key, $cert)   an enveloped signature added to $element (after its Issuer): RSA-SHA256, exclusive C14N (the check suite's IdP; signed messages of the SP)
//  encrypt($element, $certPem)   $element replaced by its encrypted form (the check suite's IdP)

namespace plugins\Saml\util;

class SamlXml {
    const MAX_SIZE = 1048576;

    const SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
    const SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';
    const MD = 'urn:oasis:names:tc:SAML:2.0:metadata';
    const DS = 'http://www.w3.org/2000/09/xmldsig#';
    const XENC = 'http://www.w3.org/2001/04/xmlenc#';
    const XENC11 = 'http://www.w3.org/2009/xmlenc11#';
    const EXC_NS = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    const RSA_OAEP_MGF1P = 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p';
    const RSA_OAEP = 'http://www.w3.org/2009/xmlenc11#rsa-oaep';
    const AES256_GCM = 'http://www.w3.org/2009/xmlenc11#aes256-gcm';

    //canonicalization: [ exclusive, with comments ]
    const C14N = [
	'http://www.w3.org/2001/10/xml-exc-c14n#' => [ true, false ],
	'http://www.w3.org/2001/10/xml-exc-c14n#WithComments' => [ true, true ],
	'http://www.w3.org/TR/2001/REC-xml-c14n-20010315' => [ false, false ],
	'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments' => [ false, true ],
    ];

    //signatures: [ key type, openssl digest ]
    const SIGNATURES = [
	'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => [ 'rsa', 'sha1' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => [ 'rsa', 'sha256' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => [ 'rsa', 'sha384' ],
	'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => [ 'rsa', 'sha512' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' => [ 'ecdsa', 'sha256' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384' => [ 'ecdsa', 'sha384' ],
	'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512' => [ 'ecdsa', 'sha512' ],
    ];

    const DIGESTS = [
	'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
	'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
	'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
	'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];

    //content encryption: [ openssl cipher, key length, GCM ]
    const CIPHERS = [
	'http://www.w3.org/2001/04/xmlenc#aes128-cbc' => [ 'aes-128-cbc', 16, false ],
	'http://www.w3.org/2001/04/xmlenc#aes192-cbc' => [ 'aes-192-cbc', 24, false ],
	'http://www.w3.org/2001/04/xmlenc#aes256-cbc' => [ 'aes-256-cbc', 32, false ],
	'http://www.w3.org/2009/xmlenc11#aes128-gcm' => [ 'aes-128-gcm', 16, true ],
	'http://www.w3.org/2009/xmlenc11#aes192-gcm' => [ 'aes-192-gcm', 24, true ],
	'http://www.w3.org/2009/xmlenc11#aes256-gcm' => [ 'aes-256-gcm', 32, true ],
    ];

    //the MGF1 digests of xmlenc11 rsa-oaep
    const MGF = [
	'http://www.w3.org/2009/xmlenc11#mgf1sha1' => 'sha1',
	'http://www.w3.org/2009/xmlenc11#mgf1sha224' => 'sha224',
	'http://www.w3.org/2009/xmlenc11#mgf1sha256' => 'sha256',
	'http://www.w3.org/2009/xmlenc11#mgf1sha384' => 'sha384',
	'http://www.w3.org/2009/xmlenc11#mgf1sha512' => 'sha512',
    ];

    // ------------------------------------------------------------------------------------
    //reading

    public static function load($xml) {
	$xml = (string)$xml;
	if (trim($xml) === '')
	    throw new SamlException('empty message');
	if (strlen($xml) > self::MAX_SIZE)
	    throw new SamlException('message too large');
	//no DTD: no entity expansion (billion laughs), no external entities
	if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml))
	    throw new SamlException('XML with a DOCTYPE is refused');
	$prev = libxml_use_internal_errors(true);
	try {
	    $doc = new \DOMDocument();
	    if (!$doc->loadXML($xml, LIBXML_NONET) || !$doc->documentElement || $doc->doctype) {
		$error = libxml_get_last_error();
		throw new SamlException('not XML' . ($error ? ': ' . trim($error->message) : ''));
	    }
	    return $doc;
	} finally {
	    libxml_clear_errors();
	    libxml_use_internal_errors($prev);
	}
    }

    public static function xpath(\DOMDocument $doc) {
	$xp = new \DOMXPath($doc);
	foreach([ 'samlp' => self::SAMLP, 'saml' => self::SAML, 'md' => self::MD, 'ds' => self::DS, 'xenc' => self::XENC ] as $prefix => $ns)
	    $xp->registerNamespace($prefix, $ns);
	return $xp;
    }

    //the element children of $el named $ns:$local
    public static function children(\DOMNode $el, $ns, $local) {
	$found = [];
	foreach($el->childNodes as $c) {
	    if ($c instanceof \DOMElement && $c->namespaceURI === $ns && $c->localName === $local)
		$found[] = $c;
	}
	return $found;
    }

    //the single child $ns:$local of $el, else SamlException
    public static function one(\DOMNode $el, $ns, $local) {
	$found = self::children($el, $ns, $local);
	if (count($found) !== 1)
	    throw new SamlException(($found ? 'several ' : 'no ') . $local . ' in ' . $el->localName);
	return $found[0];
    }

    //the elements of the document with ID $id
    public static function byId(\DOMDocument $doc, $id) {
	$found = [];
	foreach((new \DOMXPath($doc))->query('//@ID') as $attr) {
	    if ($attr->value === $id)
		$found[] = $attr->ownerElement;
	}
	return $found;
    }

    public static function base64($text) {
	$v = base64_decode(preg_replace('/\s+/', '', (string)$text), true);
	if ($v === false)
	    throw new SamlException('not base64');
	return $v;
    }

    //the canonical form of $el by the algorithm of $method (an element with Algorithm, and an InclusiveNamespaces child); null: inclusive C14N
    protected static function canonical(\DOMNode $el, $method) {
	$alg = $method ? $method->getAttribute('Algorithm') : 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
	if (!isset(self::C14N[$alg]))
	    throw new SamlException('canonicalization not supported: ' . $alg);
	[ $exclusive, $comments ] = self::C14N[$alg];
	$prefixes = null;
	if ($exclusive && $method) {
	    foreach(self::children($method, self::EXC_NS, 'InclusiveNamespaces') as $inc)
		$prefixes = array_values(array_filter(preg_split('/\s+/', trim($inc->getAttribute('PrefixList'))), fn($p) => $p !== ''));
	}
	$text = $el->C14N($exclusive, $comments, null, $prefixes);
	if ($text === false)
	    throw new SamlException('the XML could not be canonicalized');
	return $text;
    }

    // ------------------------------------------------------------------------------------
    //signatures

    public static function verify(\DOMElement $element, array $certs) {
	$sigs = self::children($element, self::DS, 'Signature');
	if (count($sigs) !== 1)
	    throw new SamlException($sigs ? 'several signatures on ' . $element->localName : $element->localName . ' not signed');
	$sig = $sigs[0];
	$info = self::one($sig, self::DS, 'SignedInfo');
	$method = self::one($info, self::DS, 'SignatureMethod')->getAttribute('Algorithm');
	if (!isset(self::SIGNATURES[$method]))
	    throw new SamlException('signature algorithm not supported: ' . $method);

	//one reference: this element, by an ID found once in the document (the signature wrapping attacks)
	$ref = self::one($info, self::DS, 'Reference');
	$id = $element->getAttribute('ID');
	if ($id === '' || $ref->getAttribute('URI') !== '#' . $id)
	    throw new SamlException('the signature is not of the ' . $element->localName);
	if (count(self::byId($element->ownerDocument, $id)) !== 1)
	    throw new SamlException('the ID ' . $id . ' is in the message more than once');
	$enveloped = false;
	$c14n = null;
	foreach(self::children($ref, self::DS, 'Transforms') as $list) {
	    foreach(self::children($list, self::DS, 'Transform') as $t) {
		$alg = $t->getAttribute('Algorithm');
		if ($alg === self::ENVELOPED)
		    $enveloped = true;
		elseif (isset(self::C14N[$alg]))
		    $c14n = $t;
		else
		    throw new SamlException('signature transform not supported: ' . $alg);
	    }
	}
	if (!$enveloped)
	    throw new SamlException('not an enveloped signature');
	$digestAlg = self::one($ref, self::DS, 'DigestMethod')->getAttribute('Algorithm');
	if (!isset(self::DIGESTS[$digestAlg]))
	    throw new SamlException('digest not supported: ' . $digestAlg);

	//the digest: the element without this signature, in a copy of the document (its namespaces in scope)
	$copy = $element->ownerDocument->cloneNode(true);
	$target = self::byId($copy, $id)[0];
	foreach(self::children($target, self::DS, 'Signature') as $s)
	    $target->removeChild($s);
	$digest = hash(self::DIGESTS[$digestAlg], self::canonical($target, $c14n), true);
	if (!hash_equals(self::base64(self::one($ref, self::DS, 'DigestValue')->textContent), $digest))
	    throw new SamlException('the digest of the ' . $element->localName . ' does not match: the signed content was changed');

	//the signature of SignedInfo
	$signed = self::canonical($info, self::one($info, self::DS, 'CanonicalizationMethod'));
	[ $type, $hash ] = self::SIGNATURES[$method];
	$value = self::base64(self::one($sig, self::DS, 'SignatureValue')->textContent);
	if ($type === 'ecdsa')
	    $value = self::ecdsaDer($value);
	foreach($certs as $pem) {
	    $key = @openssl_pkey_get_public($pem);
	    if ($key && openssl_verify($signed, $value, $key, $hash) === 1)
		return $method;
	}
	throw new SamlException('the signature of the ' . $element->localName . ' is not the IdP\'s (not valid for its certificates)');
    }

    //an XML-DSig ECDSA signature (r || s) as DER, for openssl
    protected static function ecdsaDer($raw) {
	$int = function($b) {
	    $b = ltrim($b, "\0");
	    if ($b === '' || ord($b[0]) > 0x7f)
		$b = "\0" . $b;
	    return "\x02" . self::derLength(strlen($b)) . $b;
	};
	$half = intdiv(strlen($raw), 2);
	$seq = $int(substr($raw, 0, $half)) . $int(substr($raw, $half));
	return "\x30" . self::derLength(strlen($seq)) . $seq;
    }

    protected static function derLength($n) {
	return $n < 0x80 ? chr($n) : ($n < 0x100 ? "\x81" . chr($n) : "\x82" . pack('n', $n));
    }

    public static function sign(\DOMElement $element, $keyPem, $certPem) {
	$doc = $element->ownerDocument;
	$add = function(\DOMNode $parent, $name, $attrs = [], $text = null) use ($doc) {
	    $el = $doc->createElementNS(self::DS, 'ds:' . $name);
	    foreach($attrs as $k => $v)
		$el->setAttribute($k, $v);
	    if ($text !== null)
		$el->appendChild($doc->createTextNode($text));
	    return $parent->appendChild($el);
	};
	$digest = base64_encode(hash('sha256', $element->C14N(true, false), true));
	$sig = $doc->createElementNS(self::DS, 'ds:Signature');
	$info = $add($sig, 'SignedInfo');
	$add($info, 'CanonicalizationMethod', [ 'Algorithm' => self::EXC_C14N ]);
	$add($info, 'SignatureMethod', [ 'Algorithm' => self::RSA_SHA256 ]);
	$ref = $add($info, 'Reference', [ 'URI' => '#' . $element->getAttribute('ID') ]);
	$tr = $add($ref, 'Transforms');
	$add($tr, 'Transform', [ 'Algorithm' => self::ENVELOPED ]);
	$add($tr, 'Transform', [ 'Algorithm' => self::EXC_C14N ]);
	$add($ref, 'DigestMethod', [ 'Algorithm' => self::SHA256 ]);
	$add($ref, 'DigestValue', [], $digest);
	//after the Issuer (the SAML schemas' order)
	$issuer = self::children($element, self::SAML, 'Issuer')[0] ?? null;
	$element->insertBefore($sig, $issuer ? $issuer->nextSibling : $element->firstChild);
	if (!openssl_sign($info->C14N(true, false), $value, $keyPem, 'sha256'))
	    throw new SamlException('could not sign: ' . openssl_error_string());
	$add($sig, 'SignatureValue', [], base64_encode($value));
	$add($add($add($sig, 'KeyInfo'), 'X509Data'), 'X509Certificate', [], self::certBody($certPem));
	return $sig;
    }

    //the base64 body of a PEM certificate
    public static function certBody($pem) {
	return preg_replace('/-----[^-]+-----|\s+/', '', (string)$pem);
    }

    //a PEM certificate of a base64 body
    public static function certPem($body) {
	return "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', (string)$body), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    // ------------------------------------------------------------------------------------
    //encryption

    public static function decrypt(\DOMElement $container, $keyPem) {
	$data = self::one($container, self::XENC, 'EncryptedData');
	$alg = self::one($data, self::XENC, 'EncryptionMethod')->getAttribute('Algorithm');
	if (!isset(self::CIPHERS[$alg]))
	    throw new SamlException('encryption not supported: ' . $alg);
	//the key: in the data's KeyInfo, else next to the data
	$ek = null;
	foreach(self::children($data, self::DS, 'KeyInfo') as $ki)
	    $ek = $ek ?? (self::children($ki, self::XENC, 'EncryptedKey')[0] ?? null);
	$ek = $ek ?? (self::children($container, self::XENC, 'EncryptedKey')[0] ?? null);
	if (!$ek)
	    throw new SamlException('no encrypted key in the encrypted assertion');
	$key = self::decryptKey($ek, $keyPem);
	[ $cipher, $length, $gcm ] = self::CIPHERS[$alg];
	if (strlen($key) !== $length)
	    throw new SamlException('the encrypted key does not fit ' . $cipher);
	$value = self::base64(self::one(self::one($data, self::XENC, 'CipherData'), self::XENC, 'CipherValue')->textContent);
	if ($gcm) {
	    if (strlen($value) < 28)
		throw new SamlException('encrypted data too short');
	    $plain = openssl_decrypt(substr($value, 12, -16), $cipher, $key, OPENSSL_RAW_DATA, substr($value, 0, 12), substr($value, -16));
	} else {
	    if (strlen($value) < 32 || strlen($value) % 16)
		throw new SamlException('encrypted data of a wrong length');
	    $plain = openssl_decrypt(substr($value, 16), $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($value, 0, 16));
	    //XML Encryption's padding: the last byte is the length
	    $pad = $plain === false ? 0 : ord(substr($plain, -1));
	    $plain = $pad >= 1 && $pad <= 16 ? substr($plain, 0, -$pad) : false;
	}
	if ($plain === false)
	    throw new SamlException('the assertion could not be decrypted');
	return $plain;
    }

    protected static function decryptKey(\DOMElement $ek, $keyPem) {
	$method = self::one($ek, self::XENC, 'EncryptionMethod');
	$alg = $method->getAttribute('Algorithm');
	$digest = 'sha1';
	foreach(self::children($method, self::DS, 'DigestMethod') as $d)
	    $digest = self::DIGESTS[$d->getAttribute('Algorithm')] ?? throw new SamlException('key digest not supported: ' . $d->getAttribute('Algorithm'));
	$mgf = 'sha1';
	foreach(self::children($method, self::XENC11, 'MGF') as $m)
	    $mgf = self::MGF[$m->getAttribute('Algorithm')] ?? throw new SamlException('MGF not supported: ' . $m->getAttribute('Algorithm'));
	//RSA 1.5 (PKCS#1 v1.5) is refused: an oracle for the key (Bleichenbacher)
	if ($alg !== self::RSA_OAEP_MGF1P && $alg !== self::RSA_OAEP)
	    throw new SamlException('key encryption not supported: ' . $alg . ' (use RSA-OAEP)');
	if ($digest !== $mgf || ($alg === self::RSA_OAEP_MGF1P && $mgf !== 'sha1'))
	    throw new SamlException("RSA-OAEP with digest {$digest} and MGF1 {$mgf}: not supported");
	$value = self::base64(self::one(self::one($ek, self::XENC, 'CipherData'), self::XENC, 'CipherValue')->textContent);
	$pkey = openssl_pkey_get_private((string)$keyPem);
	if (!$pkey)
	    throw new SamlException('the service provider\'s key is not readable');
	$ok = $digest === 'sha1' ? openssl_private_decrypt($value, $key, $pkey, OPENSSL_PKCS1_OAEP_PADDING)
	    : openssl_private_decrypt($value, $key, $pkey, OPENSSL_PKCS1_OAEP_PADDING, $digest);
	if (!$ok)
	    throw new SamlException('the assertion\'s key could not be decrypted: does the IdP use this service provider\'s current certificate?');
	return $key;
    }

    //$element (e.g. an Assertion) replaced by saml:Encrypted<Name> (AES-256-GCM, the key with RSA-OAEP: SHA-1, or $digest for xmlenc11 rsa-oaep)
    public static function encrypt(\DOMElement $element, $certPem, $digest = 'sha1') {
	$doc = $element->ownerDocument;
	$plain = $doc->saveXML($element);
	$key = random_bytes(32);
	$iv = random_bytes(12);
	$ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
	$ok = $digest === 'sha1' ? openssl_public_encrypt($key, $ek, $certPem, OPENSSL_PKCS1_OAEP_PADDING)
	    : openssl_public_encrypt($key, $ek, $certPem, OPENSSL_PKCS1_OAEP_PADDING, $digest);
	if ($ct === false || !$ok)
	    throw new SamlException('could not encrypt: ' . openssl_error_string());
	$el = fn($ns, $name, $attrs = [], $text = null) => (function() use ($doc, $ns, $name, $attrs, $text) {
	    $e = $doc->createElementNS($ns, $name);
	    foreach($attrs as $k => $v)
		$e->setAttribute($k, $v);
	    if ($text !== null)
		$e->appendChild($doc->createTextNode($text));
	    return $e;
	})();
	$wrapper = $el(self::SAML, 'saml:Encrypted' . $element->localName);
	$data = $wrapper->appendChild($el(self::XENC, 'xenc:EncryptedData', [ 'Type' => 'http://www.w3.org/2001/04/xmlenc#Element' ]));
	$data->appendChild($el(self::XENC, 'xenc:EncryptionMethod', [ 'Algorithm' => self::AES256_GCM ]));
	$encKey = $data->appendChild($el(self::DS, 'ds:KeyInfo'))->appendChild($el(self::XENC, 'xenc:EncryptedKey'));
	$m = $encKey->appendChild($el(self::XENC, 'xenc:EncryptionMethod', [ 'Algorithm' => $digest === 'sha1' ? self::RSA_OAEP_MGF1P : self::RSA_OAEP ]));
	if ($digest !== 'sha1') {
	    $m->appendChild($el(self::DS, 'ds:DigestMethod', [ 'Algorithm' => array_search($digest, self::DIGESTS, true) ]));
	    $m->appendChild($el(self::XENC11, 'xenc11:MGF', [ 'Algorithm' => array_search($digest, self::MGF, true) ]));
	}
	$encKey->appendChild($el(self::XENC, 'xenc:CipherData'))->appendChild($el(self::XENC, 'xenc:CipherValue', [], base64_encode($ek)));
	$data->appendChild($el(self::XENC, 'xenc:CipherData'))->appendChild($el(self::XENC, 'xenc:CipherValue', [], base64_encode($iv . $ct . $tag)));
	$element->parentNode->replaceChild($wrapper, $element);
	return $wrapper;
    }
}
