<?php

/*
    CLD: 2026-11-05T16:00:00+02:00: new: a migration of the stored data done in this database (util\Migrations, run by `../v sys::migrate`): its id (`name`), the app's version that
    needed it, what it changed (`summary`), the app's version that ran it and when. Global: the installation's, not an account's
*/

namespace models;

class Migration extends \sys\BaseModel {
    protected static $collection = 'migrations';

    public static $indexes = [
	[ [ 'name' => 1 ], [ 'unique' => true ] ],
    ];

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'name', 'type' => 'text', 'label' => 'Migration', 'required' => true ],
	    [ 'key' => 'version', 'type' => 'text', 'label' => 'Needed by version' ],
	    [ 'key' => 'label', 'type' => 'text', 'label' => 'What' ],
	    [ 'key' => 'summary', 'type' => 'text', 'label' => 'Changed' ],
	    [ 'key' => 'app_version', 'type' => 'text', 'label' => 'Run by version' ],
	    [ 'key' => 'applied_at', 'type' => 'datetime', 'label' => 'Run at' ],
	], $this->getSystemSchema());
    }
}
