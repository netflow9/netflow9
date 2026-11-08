<?php

/*
    the sign-in throttle (SOC2.md gap 8).
    Failed attempts are counted per key in a window; at the key's limit, the key is locked for a while.

    MongoDB removes a record when its window and lock are over (expires_at, a TTL index; created by `v sys::indexes`)
    keys:
	name:<sign-in name>   the user or email typed (lower case; counted whether or not such a user exists: a lock tells nothing about the accounts)
        ip:<address>           the connection's address (REMOTE_ADDR: X-Forwarded-For is sent by the client); a success does not clear it
	tfa:<user id>          wrong 2FA codes; cleared only by a complete sign-in (the password alone does not give more code guesses)
	reset_ip:<address>,
	reset_email:<email>   "forgot password" requests (each one counts, not only failures)

    $seconds = LoginThrottle::lockedFor($key);       0, or the seconds the key stays locked
    $locked = LoginThrottle::fail($key);             counts a failure; true when this failure locked the key
    LoginThrottle::clear($key);                      after a success
*/
namespace models\user;

class LoginThrottle extends \sys\BaseModel {
    protected static $collection = 'login_throttle';
    public static $indexes = [
	[ [ 'expires_at' => 1 ], [ 'expireAfterSeconds' => 0 ] ],
    ];

    //[ failures allowed in the window, window (seconds), lock (seconds) ] by key kind
    const LIMITS = [
	'name' => [ 5, 900, 900 ],
	'ip' => [ 20, 900, 900 ],
	'tfa' => [ 5, 900, 900 ],
	'reset_ip' => [ 10, 3600, 3600 ],
	'reset_email' => [ 3, 3600, 3600 ],
	'chat_ip' => [ 60, 3600, 900 ],
	'chat_user' => [ 300, 3600, 300 ],
    ];

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return [
	    [
		'key' => 'failures',
		'type' => 'number',
		'label' => 'Failures',
	    ],
	    [
		'key' => 'window_start',
		'type' => 'datetime',
		'label' => 'Window start',
	    ],
	    [
		'key' => 'locked_until',
		'type' => 'datetime',
		'label' => 'Locked until',
	    ],
	    [
		'key' => 'expires_at',
		'type' => 'datetime',
		'label' => 'Expires',
	    ],
	];
    }

    public static function nameKey($login) {
	return 'name:' . mb_strtolower(trim((string)$login));
    }

    public static function ipKey() {
	return 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function tfaKey($userId) {
	return 'tfa:' . (string)$userId;
    }

    public static function resetIpKey() {
	return 'reset_ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function resetEmailKey($email) {
	return 'reset_email:' . mb_strtolower(trim((string)$email));
    }

    protected static function limits($key) {
	return self::LIMITS[explode(':', $key, 2)[0]];
    }

    protected static function date($ts) {
	return new \MongoDB\BSON\UTCDateTime($ts * 1000);
    }

    public static function lockedFor($key) {
	//counters are changed atomically, through ObjectManager's updates, not through save()
	$doc = \V::app()->om->findOne('LoginThrottle', [ '_id' => $key ], [ 'arrays' => true ]);
	if (empty($doc['locked_until']))
	    return 0;
	return max(0, $doc['locked_until']->toDateTime()->getTimestamp() - time());
    }

    public static function fail($key) {
	[ $max, $window, $lock ] = static::limits($key);
	$now = time();

	//in the current window: one more; else a new window
	$doc = \V::app()->om->findOneAndUpdate('LoginThrottle', [ '_id' => $key, 'window_start' => [ '$gte' => static::date($now - $window) ] ], [ '$inc' => [ 'failures' => 1 ] ],
	    [ 'arrays' => true ]);
	if (empty($doc)) {
	    \V::app()->om->replaceOne('LoginThrottle', [ '_id' => $key ], \V::app()->om->create('LoginThrottle', [ 'failures' => 1, 'window_start' => static::date($now), 'expires_at' => static::date($now + $window) ]), [ 'upsert' => true ]);
	    $failures = 1;
	} else {
	    $failures = (int)$doc['failures'];
	}

	if ($failures < $max)
	    return false;

	//the limit: locked; the count starts again after the lock
	\V::app()->om->updateOne('LoginThrottle', [ '_id' => $key ], [ '$set' => [
	    'failures' => 0,
	    'window_start' => static::date($now + $lock),
	    'locked_until' => static::date($now + $lock),
	    'expires_at' => static::date($now + $lock + $window),
	] ]);
	return true;
    }

    public static function clear($key) {
	\V::app()->om->deleteOne('LoginThrottle', [ '_id' => $key ]);
    }

    //"Try again in N minutes" for $seconds
    public static function message($seconds) {
	$minutes = max(1, (int)ceil($seconds / 60));
	return \V::t('Too many failed attempts. Try again in') . ' ' . $minutes . ' ' . \V::t($minutes == 1 ? 'minute' : 'minutes') . '.';
    }
}
