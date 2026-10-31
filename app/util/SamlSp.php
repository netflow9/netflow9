<?php

//CLD: 2026-10-23T12:00:00+03:00: new: the SAML 2.0 Web Browser SSO profile of an account's service provider (models\SamlSettings):
//  requestUrl($s, $id)                 the IdP's URL with an AuthnRequest of ID $id (HTTP-Redirect binding; signed with the SP's key when signRequests)
//  metadata($s)                        the SP's metadata (XML): entity ID, ACS (HTTP-POST), its certificate for signing and encryption, the NameID format
//  inResponseTo($b64)                  the request a SAMLResponse (as posted) says it answers, before it is checked (to find the request); '' for none
//  response($s, $b64, $expected, $now) the claims of a SAMLResponse, checked (else SamlException): XML, status, the IdP's signature (the response's, or the assertion's),
//                                      one assertion (decrypted with the SP's key), issuer, audience, recipient, destination, times (CLOCK_SKEW), InResponseTo = $expected
//                                      (null: an unsolicited response, none refused). Claims: nameId, format, sessionIndex, attributes [ name => [ values ] ], assertionId,
//                                      expires (the last moment the assertion is valid: its replay is refused until then), encrypted, signedBy
//  idpMetadata($xml)                   the IdP's settings in its metadata: idpEntityId, idpSsoUrl, idpCerts (PEM), nameIdFormats; else SamlException
//  fetch($url)                         the text at $url (https; not this machine's network: a public address only), for the metadata

namespace util;

use models\SamlSettings;

class SamlSp {
    const BINDING_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';
    const BINDING_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';
    const SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';
    const BEARER = 'urn:oasis:names:tc:SAML:2.0:cm:bearer';
    const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    //seconds the clocks of the IdP and of this machine may differ
    const CLOCK_SKEW = 180;
    const MAX_METADATA = 2097152;

    // ------------------------------------------------------------------------------------
    //the request, the metadata

    public static function requestUrl(SamlSettings $s, $id, $relayState = '') {
	$doc = new \DOMDocument('1.0', 'UTF-8');
	$req = $doc->appendChild($doc->createElementNS(SamlXml::SAMLP, 'samlp:AuthnRequest'));
	$req->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:saml', SamlXml::SAML);
	$req->setAttribute('ID', $id);
	$req->setAttribute('Version', '2.0');
	$req->setAttribute('IssueInstant', gmdate('Y-m-d\TH:i:s\Z'));
	$req->setAttribute('Destination', (string)$s->idpSsoUrl);
	$req->setAttribute('AssertionConsumerServiceURL', $s->acsUrl());
	$req->setAttribute('ProtocolBinding', self::BINDING_POST);
	if ($s->forceAuthn)
	    $req->setAttribute('ForceAuthn', 'true');
	$req->appendChild($doc->createElementNS(SamlXml::SAML, 'saml:Issuer'))->appendChild($doc->createTextNode($s->spEntityId()));
	$policy = $req->appendChild($doc->createElementNS(SamlXml::SAMLP, 'samlp:NameIDPolicy'));
	$policy->setAttribute('Format', (string)$s->nameIdFormat);
	$policy->setAttribute('AllowCreate', 'true');

	//HTTP-Redirect: deflated, base64; the signature over the query (SAMLRequest, RelayState, SigAlg in this order)
	$query = 'SAMLRequest=' . rawurlencode(base64_encode(gzdeflate($doc->saveXML($req))));
	if ((string)$relayState !== '')
	    $query .= '&RelayState=' . rawurlencode((string)$relayState);
	if ($s->signRequests) {
	    $query .= '&SigAlg=' . rawurlencode(self::RSA_SHA256);
	    if (!openssl_sign($query, $sig, (string)$s->spKey, 'sha256'))
		throw new SamlException('the request could not be signed: ' . openssl_error_string());
	    $query .= '&Signature=' . rawurlencode(base64_encode($sig));
	}
	$url = (string)$s->idpSsoUrl;
	return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    public static function metadata(SamlSettings $s) {
	$doc = new \DOMDocument('1.0', 'UTF-8');
	$doc->formatOutput = true;
	$md = fn($name) => $doc->createElementNS(SamlXml::MD, 'md:' . $name);
	$ed = $doc->appendChild($md('EntityDescriptor'));
	$ed->setAttribute('entityID', $s->spEntityId());
	$sp = $ed->appendChild($md('SPSSODescriptor'));
	$sp->setAttribute('AuthnRequestsSigned', $s->signRequests ? 'true' : 'false');
	$sp->setAttribute('WantAssertionsSigned', 'true');
	$sp->setAttribute('protocolSupportEnumeration', SamlXml::SAMLP);
	foreach([ 'signing', 'encryption' ] as $use) {
	    $kd = $sp->appendChild($md('KeyDescriptor'));
	    $kd->setAttribute('use', $use);
	    $kd->appendChild($doc->createElementNS(SamlXml::DS, 'ds:KeyInfo'))->appendChild($doc->createElementNS(SamlXml::DS, 'ds:X509Data'))
		->appendChild($doc->createElementNS(SamlXml::DS, 'ds:X509Certificate'))->appendChild($doc->createTextNode(SamlXml::certBody($s->spCert)));
	    if ($use === 'encryption') {
		foreach([ 'http://www.w3.org/2009/xmlenc11#aes256-gcm', 'http://www.w3.org/2009/xmlenc11#aes128-gcm', 'http://www.w3.org/2001/04/xmlenc#aes256-cbc',
		    'http://www.w3.org/2001/04/xmlenc#aes128-cbc', SamlXml::RSA_OAEP_MGF1P ] as $alg)
		    $kd->appendChild($md('EncryptionMethod'))->setAttribute('Algorithm', $alg);
	    }
	}
	$sp->appendChild($md('NameIDFormat'))->appendChild($doc->createTextNode((string)$s->nameIdFormat));
	$acs = $sp->appendChild($md('AssertionConsumerService'));
	$acs->setAttribute('Binding', self::BINDING_POST);
	$acs->setAttribute('Location', $s->acsUrl());
	$acs->setAttribute('index', '0');
	$acs->setAttribute('isDefault', 'true');
	return $doc->saveXML();
    }

    // ------------------------------------------------------------------------------------
    //the response

    protected static function decode($b64) {
	$xml = base64_decode(preg_replace('/\s+/', '', (string)$b64), true);
	if ($xml === false || $xml === '')
	    throw new SamlException('the SAMLResponse is not base64');
	return SamlXml::load($xml);
    }

    public static function inResponseTo($b64) {
	try {
	    return (string)self::decode($b64)->documentElement->getAttribute('InResponseTo');
	} catch (SamlException $e) {
	    return '';
	}
    }

    //a time of the message (xs:dateTime, UTC) as a timestamp
    protected static function time($text, $what) {
	$t = preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:\d\d)$/', (string)$text) ? strtotime((string)$text) : false;
	if ($t === false)
	    throw new SamlException("{$what}: not a time: " . $text);
	return $t;
    }

    public static function response(SamlSettings $s, $b64, $expected, $now = null) {
	$now = $now ?? time();
	$certs = $s->idpCertList();
	if (!$certs)
	    throw new SamlException('no IdP certificate in the settings');
	$doc = self::decode($b64);
	$root = $doc->documentElement;
	if ($root->namespaceURI !== SamlXml::SAMLP || $root->localName !== 'Response')
	    throw new SamlException('not a SAML Response');
	if ($root->getAttribute('Version') !== '2.0')
	    throw new SamlException('not SAML 2.0');
	$acs = $s->acsUrl();
	if ($root->hasAttribute('Destination') && $root->getAttribute('Destination') !== $acs)
	    throw new SamlException('sent to another address (Destination ' . $root->getAttribute('Destination') . ')');
	$irt = $root->getAttribute('InResponseTo');
	if ($expected === null && $irt !== '')
	    throw new SamlException('the answer to a request that is not waiting (InResponseTo ' . $irt . ')');
	if ($expected !== null && $irt !== $expected)
	    throw new SamlException('not the answer to the request sent');
	foreach(SamlXml::children($root, SamlXml::SAML, 'Issuer') as $issuer) {
	    if (trim($issuer->textContent) !== (string)$s->idpEntityId)
		throw new SamlException('from another IdP (Issuer ' . trim($issuer->textContent) . ')');
	}

	//the status: refusals of the IdP say why
	$status = SamlXml::one($root, SamlXml::SAMLP, 'Status');
	$code = SamlXml::one($status, SamlXml::SAMLP, 'StatusCode');
	if ($code->getAttribute('Value') !== self::SUCCESS) {
	    $sub = SamlXml::children($code, SamlXml::SAMLP, 'StatusCode')[0] ?? null;
	    $msg = SamlXml::children($status, SamlXml::SAMLP, 'StatusMessage')[0] ?? null;
	    $short = fn($v) => preg_replace('/^urn:oasis:names:tc:SAML:2.0:status:/', '', (string)$v);
	    throw new SamlException('the IdP refused the sign-in: ' . $short($code->getAttribute('Value')) . ($sub ? ' / ' . $short($sub->getAttribute('Value')) : '')
		. ($msg ? ': ' . trim($msg->textContent) : ''));
	}

	//the response's signature (it covers the assertion, also an encrypted one)
	$signedBy = [];
	if (SamlXml::children($root, SamlXml::DS, 'Signature')) {
	    SamlXml::verify($root, $certs);
	    $signedBy[] = 'response';
	}

	//one assertion, plain or encrypted, a child of the response
	$plain = SamlXml::children($root, SamlXml::SAML, 'Assertion');
	$encrypted = SamlXml::children($root, SamlXml::SAML, 'EncryptedAssertion');
	if (count($plain) + count($encrypted) !== 1)
	    throw new SamlException(count($plain) + count($encrypted) ? 'several assertions in the response' : 'no assertion in the response');
	if (SamlXml::xpath($doc)->query('//saml:Assertion | //saml:EncryptedAssertion')->length !== 1)
	    throw new SamlException('assertions elsewhere in the response');
	if ($encrypted) {
	    $assertion = self::decryptAssertion($encrypted[0], (string)$s->spKey);
	} else {
	    if ($s->requireEncryption)
		throw new SamlException('the assertion is not encrypted (the settings require it)');
	    $assertion = $plain[0];
	}
	if (SamlXml::children($assertion, SamlXml::DS, 'Signature')) {
	    SamlXml::verify($assertion, $certs);
	    $signedBy[] = 'assertion';
	}
	if (!$signedBy)
	    throw new SamlException('neither the response nor the assertion is signed');

	return self::assertion($s, $assertion, $expected, $now) + [ 'encrypted' => (bool)$encrypted, 'signedBy' => $signedBy ];
    }

    //the assertion of an EncryptedAssertion, in a document of its own (with the namespaces in scope where it was: some IdPs declare them on the response)
    protected static function decryptAssertion(\DOMElement $encrypted, $keyPem) {
	if (trim($keyPem) === '')
	    throw new SamlException('an encrypted assertion, and this service provider has no key');
	$xml = SamlXml::decrypt($encrypted, $keyPem);
	$decls = '';
	foreach((new \DOMXPath($encrypted->ownerDocument))->query('namespace::*', $encrypted) as $ns) {
	    if ($ns->localName !== 'xml')
		$decls .= ' ' . ($ns->prefix !== '' ? 'xmlns:' . $ns->prefix : 'xmlns') . '="' . htmlspecialchars((string)$ns->namespaceURI, ENT_XML1 | ENT_QUOTES) . '"';
	}
	$doc = SamlXml::load('<wrapper' . $decls . '>' . preg_replace('/^\s*<\?xml[^>]*\?>/', '', $xml) . '</wrapper>');
	$found = [];
	foreach($doc->documentElement->childNodes as $c) {
	    if ($c instanceof \DOMElement)
		$found[] = $c;
	}
	if (count($found) !== 1 || $found[0]->namespaceURI !== SamlXml::SAML || $found[0]->localName !== 'Assertion')
	    throw new SamlException('the encrypted assertion is not an assertion');
	return $found[0];
    }

    protected static function assertion(SamlSettings $s, \DOMElement $a, $expected, $now) {
	if ($a->getAttribute('Version') !== '2.0' || $a->getAttribute('ID') === '')
	    throw new SamlException('not a SAML 2.0 assertion');
	if (trim(SamlXml::one($a, SamlXml::SAML, 'Issuer')->textContent) !== (string)$s->idpEntityId)
	    throw new SamlException('an assertion of another IdP (Issuer ' . trim(SamlXml::one($a, SamlXml::SAML, 'Issuer')->textContent) . ')');
	$acs = $s->acsUrl();
	$expires = [];

	//the subject: its NameID, and a bearer confirmation for this ACS
	$subject = SamlXml::one($a, SamlXml::SAML, 'Subject');
	if (SamlXml::children($subject, SamlXml::SAML, 'EncryptedID'))
	    throw new SamlException('an encrypted NameID: not supported');
	$nameId = SamlXml::one($subject, SamlXml::SAML, 'NameID');
	$confirmed = null;
	$why = 'no bearer SubjectConfirmation';
	foreach(SamlXml::children($subject, SamlXml::SAML, 'SubjectConfirmation') as $sc) {
	    if ($sc->getAttribute('Method') !== self::BEARER)
		continue;
	    $data = SamlXml::children($sc, SamlXml::SAML, 'SubjectConfirmationData')[0] ?? null;
	    if (!$data || !$data->hasAttribute('NotOnOrAfter')) {
		$why = 'the SubjectConfirmation has no NotOnOrAfter';
		continue;
	    }
	    if ($data->getAttribute('Recipient') !== $acs) {
		$why = 'for another address (Recipient ' . $data->getAttribute('Recipient') . ')';
		continue;
	    }
	    if (self::time($data->getAttribute('NotOnOrAfter'), 'SubjectConfirmationData') <= $now - self::CLOCK_SKEW) {
		$why = 'expired (SubjectConfirmationData NotOnOrAfter ' . $data->getAttribute('NotOnOrAfter') . ')';
		continue;
	    }
	    if ($data->hasAttribute('NotBefore') && self::time($data->getAttribute('NotBefore'), 'SubjectConfirmationData') > $now + self::CLOCK_SKEW) {
		$why = 'not valid yet (SubjectConfirmationData NotBefore)';
		continue;
	    }
	    $irt = $data->getAttribute('InResponseTo');
	    if ($irt !== '' && $irt !== $expected) {
		$why = 'the answer to another request (SubjectConfirmationData InResponseTo)';
		continue;
	    }
	    $confirmed = $data;
	    break;
	}
	if (!$confirmed)
	    throw new SamlException('the subject is not confirmed: ' . $why);
	$expires[] = self::time($confirmed->getAttribute('NotOnOrAfter'), 'SubjectConfirmationData');

	//the conditions: the times, the audience (this SP)
	$conditions = SamlXml::one($a, SamlXml::SAML, 'Conditions');
	if ($conditions->hasAttribute('NotBefore') && self::time($conditions->getAttribute('NotBefore'), 'Conditions') > $now + self::CLOCK_SKEW)
	    throw new SamlException('the assertion is not valid yet (NotBefore ' . $conditions->getAttribute('NotBefore') . ': are the clocks right?)');
	if ($conditions->hasAttribute('NotOnOrAfter')) {
	    $t = self::time($conditions->getAttribute('NotOnOrAfter'), 'Conditions');
	    if ($t <= $now - self::CLOCK_SKEW)
		throw new SamlException('the assertion expired (NotOnOrAfter ' . $conditions->getAttribute('NotOnOrAfter') . ')');
	    $expires[] = $t;
	}
	$restrictions = SamlXml::children($conditions, SamlXml::SAML, 'AudienceRestriction');
	if (!$restrictions)
	    throw new SamlException('the assertion has no audience');
	foreach($restrictions as $r) {
	    $audiences = array_map(fn($e) => trim($e->textContent), SamlXml::children($r, SamlXml::SAML, 'Audience'));
	    if (!in_array($s->spEntityId(), $audiences, true))
		throw new SamlException('for another service provider (Audience ' . implode(', ', $audiences) . '; this one is ' . $s->spEntityId() . ')');
	}

	//the authentication
	$authn = SamlXml::children($a, SamlXml::SAML, 'AuthnStatement');
	if (!$authn)
	    throw new SamlException('the assertion has no AuthnStatement');
	if ($authn[0]->hasAttribute('SessionNotOnOrAfter') && self::time($authn[0]->getAttribute('SessionNotOnOrAfter'), 'AuthnStatement') <= $now - self::CLOCK_SKEW)
	    throw new SamlException('the IdP session expired (SessionNotOnOrAfter)');

	//the attributes, by Name (and FriendlyName)
	$attributes = [];
	foreach(SamlXml::children($a, SamlXml::SAML, 'AttributeStatement') as $st) {
	    foreach(SamlXml::children($st, SamlXml::SAML, 'Attribute') as $attr) {
		$values = array_values(array_filter(array_map(fn($v) => trim($v->textContent), SamlXml::children($attr, SamlXml::SAML, 'AttributeValue')), fn($v) => $v !== ''));
		foreach(array_unique(array_filter([ $attr->getAttribute('Name'), $attr->getAttribute('FriendlyName') ], fn($n) => $n !== '')) as $name)
		    $attributes[$name] = array_values(array_unique(array_merge($attributes[$name] ?? [], $values)));
	    }
	}

	return [
	    'nameId' => trim($nameId->textContent),
	    'format' => $nameId->getAttribute('Format'),
	    'sessionIndex' => $authn[0]->getAttribute('SessionIndex'),
	    'attributes' => $attributes,
	    'assertionId' => $a->getAttribute('ID'),
	    'expires' => max($expires),
	];
    }

    // ------------------------------------------------------------------------------------
    //the IdP's metadata

    public static function idpMetadata($xml) {
	$doc = SamlXml::load($xml);
	$xp = SamlXml::xpath($doc);
	//an EntityDescriptor, or the first one with an IdP in an EntitiesDescriptor
	$entity = $xp->query('/md:EntityDescriptor[md:IDPSSODescriptor] | /md:EntitiesDescriptor//md:EntityDescriptor[md:IDPSSODescriptor]')->item(0);
	if (!$entity)
	    throw new SamlException('no identity provider (IDPSSODescriptor) in the metadata');
	$idp = $xp->query('md:IDPSSODescriptor', $entity)->item(0);
	$sso = '';
	$bindings = [];
	foreach($xp->query('md:SingleSignOnService', $idp) as $svc) {
	    $bindings[] = $svc->getAttribute('Binding');
	    if ($sso === '' && $svc->getAttribute('Binding') === self::BINDING_REDIRECT)
		$sso = $svc->getAttribute('Location');
	}
	if ($sso === '')
	    throw new SamlException('the IdP has no HTTP-Redirect single sign-on service (' . (implode(', ', $bindings) ?: 'none') . ')');
	$certs = [];
	foreach($xp->query('md:KeyDescriptor[not(@use) or @use="signing"]//ds:X509Certificate', $idp) as $c) {
	    $pem = SamlXml::certPem($c->textContent);
	    if (@openssl_pkey_get_public($pem) && !in_array($pem, $certs, true))
		$certs[] = $pem;
	}
	if (!$certs)
	    throw new SamlException('no signing certificate in the metadata');
	return [
	    'idpEntityId' => $entity->getAttribute('entityID'),
	    'idpSsoUrl' => $sso,
	    'idpCerts' => implode('', $certs),
	    'nameIdFormats' => array_map(fn($n) => trim($n->textContent), iterator_to_array($xp->query('md:NameIDFormat', $idp))),
	];
    }

    public static function fetch($url) {
	$url = trim((string)$url);
	$parts = parse_url($url);
	if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']))
	    throw new SamlException('not an https:// address');
	//a public address only (not this machine, nor its network), the one connected to
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (its `public` guard; was curl and the address check here)
	$r = \util\Http::request('GET', $url, [ 'headers' => [ 'Accept: application/samlmetadata+xml, application/xml, text/xml' ], 'timeout' => 15, 'maxBytes' => self::MAX_METADATA, 'https' => true, 'public' => true ]);
	if ($r['tooLarge'])
	    throw new SamlException('the metadata is too large');
	if ($r['error'] !== null)
	    throw new SamlException(str_starts_with($r['error'], 'the host ') ? $r['error'] : 'the metadata was not read: ' . $r['error']);
	if ($r['status'] !== 200)
	    throw new SamlException('the metadata was not read: HTTP ' . $r['status']);
	return $r['body'];
    }
}
