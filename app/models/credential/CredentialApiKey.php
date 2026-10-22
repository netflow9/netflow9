<?php

//CLD: 2026-10-08T14:34:02+03:00: new credential type: an API key sent in a header (e.g. X-Api-Key) or in the query string; for HTTP nodes (httpAuth)
//CLD: 2026-10-09T17:21:14+03:00: a variant of models\Credential (was CredentialTypeApiKey, a CredentialType form model): its fields are the credential's,
//httpAuth() is an instance method (was static, given the credential)

namespace models\credential;

class CredentialApiKey extends \models\Credential {
    public static $label = 'API key';
    public static $icon = 'bi-key';
    public static $group = 'HTTP';
    public static $provides = [ 'http' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'in',
		'type' => 'select',
		'label' => 'Send in',
		'required' => true,
		'default' => 'header',
		'choices' => [ 'header' => 'A header', 'query' => 'The query string' ],
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'paramName',
		'type' => 'text',
		'label' => 'Name',
		'required' => true,
		//CLD: 2026-10-10T18:44:22+03:00: 'default' => '' (in every document; was set by Credential::_init)
		'default' => '',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]+$', 'skipEmpty' => true, 'message' => 'Letters, digits, dot, dash or underscore' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'X-Api-Key' ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'Key',
		'required' => true,
		//CLD: 2026-10-10T09:14:41+03:00: 'encrypt' => true (stored encrypted)
		'encrypt' => true,
	    ],
	]);
    }

    public function httpAuth() {
	$pair = [ (string)$this->paramName => (string)$this->apiKey ];
	return $this->in === 'query' ? [ 'headers' => [], 'query' => $pair ] : [ 'headers' => $pair, 'query' => [] ];
    }
}
