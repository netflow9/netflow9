<?php

/*
    the installation's tool servers an account may use (Plugin::servers ids), one document per account (_id: the account's id).
    Set by the administrator: ./v LocalMCP::servers::enable / disable
*/

namespace plugins\LocalMCP\models;

class LocalMcpAccess extends \sys\BaseModel {
    protected static $collection = 'localmcp_access';

    //CLD: 2026-10-22T23:00:00+03:00: global: set by super-admins for any account (the administration), always read by the account's id
    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'servers', 'type' => 'array', 'label' => 'Servers' ],
	], $this->getSystemSchema());
    }

    //the server ids account $aid may use
    public static function serversOf($aid) {
	if (empty($aid))
	    return [];
	$a = \V::app()->om->findOne('LocalMcpAccess', [ '_id' => (string)$aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	return $a ? array_values(array_map('strval', (array)json_decode(\util\JSON::encode($a->servers ?? []), true))) : [];
    }

    //account $aid may ($on) or may not use server $id
    public static function set($aid, $id, $on) {
	$op = $on ? '$addToSet' : '$pull';
	\V::app()->om->updateOne('LocalMcpAccess', [ '_id' => (string)$aid, 'aid' => \V::app()->om->to_oid($aid) ],
	    [ $op => [ 'servers' => (string)$id ], '$set' => [ 'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'upsert' => true ]);
    }

    //the accounts that may use server $id: [ aid ]
    public static function accountsOf($id) {
	$aids = [];
	foreach(\V::app()->om->acrossAccounts(fn() => \V::app()->om->find('LocalMcpAccess', [ 'servers' => (string)$id ])) as $a)
	    $aids[] = (string)$a->_id;
	return $aids;
    }
}
