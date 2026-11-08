<?php

/*
    a web session's record (sys\Session, PHP's session handler: $_SESSION as stored, _id the session id, u_ts its last use, aid the
    request's account, user { id, ... }); written through ObjectManager (replaceOne, no hooks), never in the audit or execution logs. Not an account's object (global)
*/

namespace models\sys;

class WebSession extends \sys\BaseModel {
    protected static $collection = 'sessions';

    public function _init() {
	$this->_config['global'] = true;
	parent::_init();
    }

    //no fields of their own: what is stored is the handler's / the counters'
    public function getSchema() {
	return $this->getSystemSchema();
    }
}
