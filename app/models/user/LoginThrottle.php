<?php

//CLD: 2026-10-21T18:00:00+03:00: new model: the sign-in throttle (SOC2.md gap 8). Failed attempts are counted per key in a window; at the key's limit, the key is locked for a while.
//MongoDB removes a record when its window and lock are over (expires_at, a TTL index; created by `v setup::indexes`)
//  keys:   name:<sign-in name>   the user or email typed (lower case; counted whether or not such a user exists: a lock tells nothing about the accounts)
//          ip:<address>           the connection's address (REMOTE_ADDR: X-Forwarded-For is sent by the client); a success does not clear it
//          tfa:<user id>          wrong 2FA codes; cleared only by a complete sign-in (the password alone does not give more code guesses)
//          CLD: 2026-10-24T00:20:00+03:00: reset_ip:<address>, reset_email:<email>   "forgot password" requests (each one counts, not only failures)
//  $seconds = LoginThrottle::lockedFor($key);       0, or the seconds the key stays locked
//  $locked = LoginThrottle::fail($key);             counts a failure; true when this failure locked the key
//  LoginThrottle::clear($key);                      after a success

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
	//CLD: 2026-10-24T00:20:00+03:00: "forgot password" requests (SiteController::forgot; SOC2.md gap 12): per address and per email, in an hour; then an hour's lock
	'reset_ip' => [ 10, 3600, 3600 ],
	'reset_email' => [ 3, 3600, 3600 ],
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

    //CLD: 2026-10-24T00:20:00+03:00: "forgot password" requests: from the connection's address, and for an email (lower case)
    public static function resetIpKey() {
	return 'reset_ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function resetEmailKey($email) {
	return 'reset_email:' . mb_strtolower(trim((string)$email));
    }

    //the record's collection (counters are changed atomically, not through save())
    protected static function coll() {
	return \V::app()->mongo->selectCollection(\V::app()->mongo->database, static::$collection);
    }

    protected static function limits($key) {
	return self::LIMITS[explode(':', $key, 2)[0]];
    }

    protected static function date($ts) {
	return new \MongoDB\BSON\UTCDateTime($ts * 1000);
    }

    public static function lockedFor($key) {
	$doc = static::coll()->findOne([ '_id' => $key ]);
	if (empty($doc['locked_until']))
	    return 0;
	return max(0, $doc['locked_until']->toDateTime()->getTimestamp() - time());
    }

    public static function fail($key) {
	[ $max, $window, $lock ] = static::limits($key);
	$now = time();
	$coll = static::coll();

	//in the current window: one more; else a new window
	$doc = $coll->findOneAndUpdate(
	    [ '_id' => $key, 'window_start' => [ '$gte' => static::date($now - $window) ] ],
	    [ '$inc' => [ 'failures' => 1 ] ],
	    [ 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ]
	);
	if (empty($doc)) {
	    $coll->replaceOne([ '_id' => $key ], [ 'failures' => 1, 'window_start' => static::date($now), 'expires_at' => static::date($now + $window) ], [ 'upsert' => true ]);
	    $failures = 1;
	} else {
	    $failures = (int)$doc['failures'];
	}

	if ($failures < $max)
	    return false;

	//the limit: locked; the count starts again after the lock
	$coll->updateOne([ '_id' => $key ], [ '$set' => [
	    'failures' => 0,
	    'window_start' => static::date($now + $lock),
	    'locked_until' => static::date($now + $lock),
	    'expires_at' => static::date($now + $lock + $window),
	] ]);
	return true;
    }

    public static function clear($key) {
	static::coll()->deleteOne([ '_id' => $key ]);
    }

    //"Try again in N minutes" for $seconds
    public static function message($seconds) {
	$minutes = max(1, (int)ceil($seconds / 60));
	return \V::t('Too many failed attempts. Try again in') . ' ' . $minutes . ' ' . \V::t($minutes == 1 ? 'minute' : 'minutes') . '.';
    }
}
