<?php

/*
    an API key sent in a header (e.g. X-Api-Key) or in the query string; for HTTP nodes (httpAuth)
    httpAuth() is an instance method
*/

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
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'paramName',
		'type' => 'text',
		'label' => 'Name',
		'required' => true,
		'default' => '',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]+$', 'skipEmpty' => true, 'message' => 'Letters, digits, dot, dash or underscore' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'X-Api-Key' ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'Key',
		'required' => true,
		'encrypt' => true,
	    ],
	]);
    }

    public function httpAuth() {
	$pair = [ (string)$this->paramName => (string)$this->apiKey ];
	return $this->in === 'query' ? [ 'headers' => [], 'query' => $pair ] : [ 'headers' => $pair, 'query' => [] ];
    }
}
