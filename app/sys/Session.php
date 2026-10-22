<?php

namespace sys;

class Session extends BaseObject implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface {

    public $coll = null;

    public $noSessionPaths = [];

    //requests whose path starts with $prefix get no session (no record, no cookie); call before the session opens (on:init)
    public function noSessionFor($prefix) {
	if (!in_array($prefix, $this->noSessionPaths, true))
	    $this->noSessionPaths[] = $prefix;
    }

    const KEY_PERSISTENT = '__persistent';
    const KEY_COOKIE_TS = '__cookie_ts';
    //CLD: 2026-10-21T23:00:00+03:00: when the user signed in and when the session was last used (the account's session limits, sys\Boot::after_user_login)
    const KEY_LOGIN_TS = '__login_ts';
    const KEY_ACTIVE_TS = '__active_ts';

    public function __construct($config = []) {
	//`lifetime` = idle time for persistent ("remember me") sessions,
	//`transient_lifetime` = idle time for the others;
	$defaults  = [
	    'lifetime' => 60*60*24*30,
	    'transient_lifetime' => 60*60*24,
	    'cookie_refresh' => 60*60*24, //how often the expiry of a persistent cookie is pushed forward
	    'name' => 'NFNSID',
	];
	$config += $defaults;
	parent::__construct($config);

	if (session_status() == PHP_SESSION_NONE) {
    	    session_set_save_handler($this, true);
	    ini_set('session.serialize_handler', 'php_serialize');
	    ini_set('session.use_strict_mode', true);
    	    \V::app()->hooks->addHook('on:init', [ $this, 'open_session' ]);
    	    \V::app()->hooks->addHook('on:end', [ $this, 'close_session' ]);
    	}

	$this->coll = \V::app()->mongo->selectCollection(\V::app()->mongo->database, 'sessions');
    }

    //session specific stuff
    public function open_session(?string $path = null, ?string $name = null): bool {
	if (\V::app()->config('app.mode') == 'web') {
	    $path = (string)(\V::app()->request->uri['path'] ?? '');
	    foreach($this->noSessionPaths as $prefix) {
		if (strpos($path, $prefix) === 0)
		    return true;
	    }
	}

	$sess_config = [
	    'gc_maxlifetime' => $this->_config['lifetime'],
	    'name' => $this->_config['name'],
	];

	//set cookie domain
	if (\V::app()->config('app.mode') == 'web') {
	    $sess_config['cookie_secure'] = 1;
	    $sess_config['use_only_cookies'] = 1;
	    $sess_config['cookie_lifetime'] = 0;
	    $sess_config['cookie_httponly'] = 1;
	    $sess_config['cookie_samesite'] = 'Lax';
	}

        $res = session_start($sess_config);

	if ($res && \V::app()->config('app.mode') == 'web' && !empty($_SESSION[self::KEY_PERSISTENT])) {
	    $cookie_ts = $_SESSION[self::KEY_COOKIE_TS] ?? 0;
	    if (time() - $cookie_ts > $this->_config['cookie_refresh'])
		$this->sendCookie();
	}

	return $res;
    }

    public function close_session() {
        session_write_close();
    }

    //CLD: 2026-10-05T20:02:30+03:00: make the current session survive browser restarts (or not); call after session_regenerate_id()
    public function setPersistent($persistent) {
	if ($persistent) {
	    $_SESSION[self::KEY_PERSISTENT] = true;
	    $this->sendCookie();
	} else {
	    unset($_SESSION[self::KEY_PERSISTENT], $_SESSION[self::KEY_COOKIE_TS]);
	}
    }

    //CLD: 2026-10-21T23:00:00+03:00: the session starts now (sign-in); a session without it (signed in before the limits existed) is signed out
    public function markLogin() {
	$_SESSION[self::KEY_LOGIN_TS] = time();
	$_SESSION[self::KEY_ACTIVE_TS] = time();
    }

    //CLD: 2026-10-05T20:02:30+03:00: (re)send the session cookie with an expiry date; same attributes as in open_session
    protected function sendCookie() {
	if (\V::app()->config('app.mode') != 'web' || headers_sent())
	    return;

	setcookie(session_name(), session_id(), [
	    'expires' => time() + $this->_config['lifetime'],
	    'path' => '/',
	    'secure' => true,
	    'httponly' => true,
	    'samesite' => 'Lax',
	]);
	$_SESSION[self::KEY_COOKIE_TS] = time();
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
	return true;
    }

    public function getData($id = null) {
	if (empty($id))
	    $id = session_id();
        return $this->coll->findOne([ '_id' => $id ]);
    }

    //a session is alive if it was used within its lifetime; gc only runs on ~1/1000 requests, so check here too
    protected function isAlive($data) {
	if (empty($data) || empty($data['u_ts']))
	    return false;

	$lifetime = !empty($data[self::KEY_PERSISTENT]) ? $this->_config['lifetime'] : $this->_config['transient_lifetime'];
	$u_ts = $data['u_ts']->toDateTime()->getTimestamp();
	return (time() - $u_ts) <= $lifetime;
    }

    public function read($id): string|false {
	$data = $this->getData($id);
	if (!$this->isAlive($data))
	    return '';
    	return serialize($data);
    }

    //extract aid so that we can query by aid
    public function write($id, $data): bool {
	$__data = unserialize($data);
	$__data['_id'] = $id;
	$__data['u_ts'] = new \MongoDB\BSON\UTCDateTime();

	$account = \V::app()->config('rt.account');
	if ($account)
	    $__data['aid'] = $account->_id;

	$this->coll->replaceOne(
	    [ '_id' => $id ],
	    $__data,
	    [ 'upsert' => true, ]
	);
	return true;
    }

    public function validateId($id): bool {
	return $this->isAlive($this->getData($id));
    }

    public function updateTimestamp($id, $data): bool {
	$this->coll->updateOne(
	    [ '_id' => $id ],
	    [ '$set' => [ 'u_ts' => new \MongoDB\BSON\UTCDateTime() ] ]
	);
	return true;
    }

    public function endFor($userId) {
	$this->coll->deleteMany([ 'user.id' => (string)$userId, '_id' => [ '$ne' => session_id() ] ]);
    }

    public function destroy($id): bool {
    	$this->coll->deleteOne([ '_id' => $id ]);
    	return true;
    }

    public function gc($maxlifetime): int|false {
	$now = time();
    	$res = $this->coll->deleteMany([
	    '$or' => [
		[ 'u_ts' => [ '$lt' => new \MongoDB\BSON\UTCDateTime(($now - $this->_config['lifetime']) * 1000) ] ],
		[
		    self::KEY_PERSISTENT => [ '$ne' => true ],
		    'u_ts' => [ '$lt' => new \MongoDB\BSON\UTCDateTime(($now - $this->_config['transient_lifetime']) * 1000) ],
		],
	    ],
    	]);
    	return $res->getDeletedCount();
    }


    //state management
    public function setState($name, $value = null) {
	if ($value === null)
	    unset($_SESSION[$name]);
	else
	    $_SESSION[$name] = $value;
    }

    public function getState($name) {
	if (!isset($_SESSION[$name]))
	    $_SESSION[$name] = null;

	return $_SESSION[$name];
    }

    public function hasState($name) {
	return isset($_SESSION[$name]);
    }
}
