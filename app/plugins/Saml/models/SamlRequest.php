<?php

//CLD: 2026-10-23T12:00:00+03:00: new: a sign-in request sent to an account's IdP, waiting for its response (SsoController::start, ::acs): its ID (the response's InResponseTo),
//a hash of the token of the browser that sent it (a cookie: the session cookie does not come with the IdP's cross-site POST), a test of the settings page (who tests:
//the result is shown, nobody is signed in). Used once; MongoDB removes it when it expires (expires_at, a TTL index)

namespace plugins\Saml\models;

class SamlRequest extends \sys\BaseModel {
    protected static $collection = 'saml_requests';
    public static $indexes = [
	[ [ 'request_id' => 1 ], [ 'unique' => true ] ],
	[ [ 'expires_at' => 1 ], [ 'expireAfterSeconds' => 0 ] ],
    ];

    //seconds to sign in at the IdP
    const VALID = 900;

    public function getSchema() {
	return [
	    [ 'key' => 'request_id', 'type' => 'text', 'label' => 'Request ID', 'required' => true ],
	    [ 'key' => 'token_hash', 'type' => 'text', 'label' => 'Token hash', 'required' => true, 'perm:read' => 'nobody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'test', 'type' => 'switch', 'label' => 'Test', 'default' => false ],
	    [ 'key' => 'tester', 'type' => 'oid', 'label' => 'Tested by' ],
	    [ 'key' => 'expires_at', 'type' => 'datetime', 'label' => 'Expires', 'required' => true ],
	];
    }

    //a new request of account $aid: [ request, the browser's token ]
    public static function start($aid, $test = false, $tester = null) {
	$token = bin2hex(random_bytes(32));
	$r = \V::app()->om->create('SamlRequest', [ 'aid' => $aid, 'request_id' => '_' . bin2hex(random_bytes(20)), 'token_hash' => hash('sha256', $token), 'test' => (bool)$test,
	    'tester' => $tester, 'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VALID) * 1000) ]);
	if (!$r->save())
	    throw new \ErrorException('SamlRequest: not saved: ' . json_encode($r->errors()));
	return [ $r, $token ];
    }

    //the cookie of request $id's token (one per request: several sign-ins may wait)
    public static function cookieName($id) {
	return 'saml_' . substr(hash('sha256', (string)$id), 0, 16);
    }

    //the waiting request $id of account $aid when $token is its browser's (used up: removed), else null
    public static function take($aid, $id, $token) {
	if (!is_string($token) || $token === '')
	    return null;
	//the hash in the query (token_hash is not readable)
	$r = \V::app()->om->findOne('SamlRequest', [ 'aid' => \V::app()->om->to_oid($aid), 'request_id' => (string)$id, 'token_hash' => hash('sha256', $token),
	    'expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ] ]);
	if (!$r)
	    return null;
	//a copy: delete() empties the model
	$taken = \V::app()->om->create('SamlRequest', [ 'aid' => $r->aid, 'request_id' => $r->request_id, 'test' => (bool)$r->test, 'tester' => $r->tester ]);
	$r->delete();
	return $taken;
    }
}
