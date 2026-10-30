<?php

//CLD: 2026-10-30T17:18:20+02:00: new: a saved view of a data table: a named filter (the conditions of the table's Filter: [ { field, op, value } ]), shared by
//every user who can open the table (controllers\DatatableController views / create_view / delete_view; DataTable::readData on the table). Deleted with
//its table (DataTableDef::beforeDelete)
namespace models;

class DataTableView extends \sys\BaseModel {
    protected static $collection = 'data-tables-views';

    //the views of a table (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'aid' => 1, 'table' => 1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'required' => true,
	    ],
	    //the table the view filters
	    [
		'key' => 'table',
		'type' => 'object',
		'label' => 'Data table',
		'object' => 'DataTableDef',
		'help' => false,
		'required' => true,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //the conditions: [ { field, op, value } ]
	    [
		'key' => 'filter',
		'type' => 'array',
		'label' => 'Filter',
		'help' => false,
		'showInForms' => false,
	    ],
	    //who saved it
	    [
		'key' => 'user',
		'type' => 'object',
		'label' => 'Saved by',
		'object' => 'User',
		'help' => false,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //as its table: DataTable::readData there (the views are the table's, shared by the users who can open it)
    public function checkAccess($userId, $permName, $params = []) {
	$dt = \V::app()->om->findOne('DataTableDef', [ '_id' => $this->table, 'aid' => $this->aid ]);
	return !empty($dt) && $dt->checkAccess($userId, 'DataTable::readData', $params);
    }

    public function __toString() {
	return (string)$this->name;
    }
}
