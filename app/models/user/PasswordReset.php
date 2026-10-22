<?php

//CLD: 2026-10-16T13:15:15+03:00: new model: a "forgot username or password" link (user's choices: its own model; valid 24 hours, used once). Only a hash of the link's token is kept (the token
//is in the email); MongoDB removes it when it expires (expires_at, a TTL index)
//  [ $reset, $token ] = PasswordReset::start($user, $ip);   a new one for user $user (the user's earlier ones replaced)
//  $reset = PasswordReset::findByToken($token);             the valid one of a link, or null

namespace models\user;

class PasswordReset extends \sys\BaseModel {
    protected static $collection = 'password_resets';
    public static $indexes = [
	[ [ 'token_hash' => 1 ], [ 'unique' => true ] ],
	[ [ 'user' => 1 ], [] ],
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
		'key' => 'user',
		'type' => 'object',
		'object' => 'User',
		'label' => 'User',
		'required' => true
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

    public static function start($user, $ip = null) {
	foreach(\V::app()->om->find('PasswordReset', [ 'user' => $user->_id ]) as $old)
	    $old->delete();
	$token = bin2hex(random_bytes(24));
	$reset = \V::app()->om->create('PasswordReset', [
	    'user' => $user->_id,
	    'aid' => $user->aid,
	    'token_hash' => hash('sha256', $token),
	    'ip' => $ip,
	    'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + self::VALID) * 1000)
	]);
	if (!$reset->validates() || !$reset->save())
	    throw new \ErrorException('PasswordReset: not saved: ' . json_encode($reset->errors()));
	return [ $reset, $token ];
    }

}
