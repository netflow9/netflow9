<?php

/*
    a sign-up waiting for its email to be confirmed (user's choices: the page asks the email only; the link, valid 24 hours and
    used once, opens the page that creates the account). Only a hash of the link's token is kept (the token is in the email); MongoDB removes it when it expires
//(expires_at, a TTL index). Not an object of an account (the account is made when the link is used)
//  [ $signup, $token ] = Signup::start($email, $ip);      a new one (the address's earlier one replaced)
//  $signup = Signup::findByToken($token);                 the valid one of a link, or null

*/

namespace plugins\MultiTenant\models;

class Signup extends \sys\BaseModel {
    protected static $collection = 'signups';
    public static $indexes = [
	[ [ 'token_hash' => 1 ], [ 'unique' => true ] ],
	[ [ 'email' => 1 ], [] ],
	[ [ 'expires_at' => 1 ], [ 'expireAfterSeconds' => 0 ] ],
    ];

    const VALID = 86400;

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [
		'key' => 'email',
		'type' => 'email',
		'label' => 'Email',
		'required' => true,
		'transform' => 'tolower'
	    ],
	    [
		'key' => 'token_hash',
		'type' => 'text',
		'label' => 'Token hash',
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'expires_at',
		'type' => 'datetime',
		'label' => 'Expires',
		'required' => true
	    ],
	    [
		'key' => 'ip',
		'type' => 'text',
		'label' => 'IP address'
	    ],
	];
    }

    public static function start($email, $ip = null) {
	foreach(\V::app()->om->find('Signup', [ 'email' => strtolower((string)$email) ]) as $old)
	    $old->delete();
	$token = bin2hex(random_bytes(24));
	$signup = \V::app()->om->create('Signup', [ 'email' => (string)$email, 'token_hash' => hash('sha256', $token), 'ip' => $ip,
	    'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VALID) * 1000) ]);
	if (!$signup->validates() || !$signup->save())
	    throw new \ErrorException('Signup: not saved: ' . json_encode($signup->errors()));
	return [ $signup, $token ];
    }

    public static function findByToken($token) {
	if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token))
	    return null;
	return \V::app()->om->findOne('Signup', [ 'token_hash' => hash('sha256', $token), 'expires_at' => [ '$gt' => new \MongoDB\BSON\UTCDateTime() ] ]);
    }
}
