<?php

//CLD: 2026-10-23T12:00:00+03:00: new: the assertions already used for a sign-in (their ID, by account), until they expire: an assertion is accepted once (util\SamlAuth::useOnce).
//MongoDB removes them when they expire (expires_at, a TTL index)

namespace models;

class SamlReplay extends \sys\BaseModel {
    protected static $collection = 'saml_replays';
    public static $indexes = [
	[ [ 'aid' => 1, 'assertion' => 1 ], [ 'unique' => true ] ],
	[ [ 'expires_at' => 1 ], [ 'expireAfterSeconds' => 0 ] ],
    ];

    public function getSchema() {
	return [
	    [ 'key' => 'assertion', 'type' => 'text', 'label' => 'Assertion ID', 'required' => true ],
	    [ 'key' => 'expires_at', 'type' => 'datetime', 'label' => 'Expires', 'required' => true ],
	];
    }
}
