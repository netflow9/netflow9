<?php

//CLD: 2026-11-07T10:37:49+02:00: new: the audit log's seal of an account (models\AuditLog::SEALED): { _id: the account's id, seq: the last number given, pruned: the
//highest number removed by the retention }; written through ObjectManager's updates (atomic counters), never in the logs. Keyed by the account's id, not by aid (global)

namespace models;

class AuditSeal extends \sys\BaseModel {
    protected static $collection = 'audit_seal';

    public function _init() {
	$this->_config['global'] = true;
	parent::_init();
    }

    //no fields of their own: what is stored is the handler's / the counters'
    public function getSchema() {
	return $this->getSystemSchema();
    }
}
