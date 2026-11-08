<?php

/*
    the installation's tool servers an account may use (util\ToolServers ids), one document per account (_id: the account's id).
    Set by the account's owners (Account settings > Tool servers), by the super-admins with the MultiTenant plugin (its account page), or ./v mcpserver::enable / disable
*/

namespace models;

class ToolServerAccess extends \sys\BaseModel {
    protected static $collection = 'toolserver_access';

    //global: set by super-admins for any account (the administration), always read by the account's id
    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	$fields = [
	    [
		'key' => 'servers',
		'type' => 'array',
		'label' => 'Servers'
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //the server ids account $aid may use
    public static function serversOf($aid) {
	if (empty($aid))
	    return [];
	$a = \V::app()->om->findOne('ToolServerAccess', [ '_id' => (string)$aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	return $a ? array_values(array_map('strval', (array)json_decode(\util\JSON::encode($a->servers ?? []), true))) : [];
    }

    //account $aid may ($on) or may not use server $id
    public static function set($aid, $id, $on) {
	$op = $on ? '$addToSet' : '$pull';
	\V::app()->om->updateOne('ToolServerAccess', [ '_id' => (string)$aid, 'aid' => \V::app()->om->to_oid($aid) ],
	    [ $op => [ 'servers' => (string)$id ], '$set' => [ 'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'upsert' => true ]);
    }

    //the accounts that may use server $id: [ aid ]
    public static function accountsOf($id) {
	$aids = [];
	foreach(\V::app()->om->acrossAccounts(fn() => \V::app()->om->find('ToolServerAccess', [ 'servers' => (string)$id ])) as $a)
	    $aids[] = (string)$a->_id;
	return $aids;
    }
}
