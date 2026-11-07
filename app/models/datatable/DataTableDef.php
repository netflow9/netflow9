<?php

namespace models\datatable;

class DataTableDef extends \sys\BaseModel {
    //CLD: 2026-11-03T14:00:00+02:00: a table can be shared with other projects of the account: rows only; its columns stay with its own project
    use \models\share\ShareableTrait;
    //CLD: 2026-11-04T12:00:00+02:00: and moved to another project
    use \models\share\MovableTrait;

    const MOVE_PERMS = [ 'from' => 'DataTable::delete', 'to' => 'DataTable::create' ];
    const MOVE_ACTION = 'datatable.move';

    const SHARE_PERM = 'DataTable::share';
    const SHARE_WHAT = 'Data table';
    const SHARE_LEVELS = [ 'read' => 'Read rows', 'write' => 'Read and change rows' ];
    const SHARE_PERMS = [
	'read' => [ 'DataTable::read', 'DataTable::readData' ],
	'write' => [ 'DataTable::read', 'DataTable::readData', 'DataTable::writeData' ],
    ];

    protected static $collection = 'data-tables-defs';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1 ], [] ],
	[ [ 'aid' => 1, 'shares.project' => 1 ], [] ],
	[ [ 'iname' => 1 ], [] ],
    ];


    public function getSchema($overrides = []) {
	$fields = [
	    //iname
	    [
		'key' => 'iname',
		'type' => 'text',
		'label' => 'Internal Name',
		'help' => false,
		'required' => true,
		'showInForms' => false,
	    ],
	    
	    //name
	    [
		'key' => 'name',
		'type' => 'text',
		'validate' => [
		    [
			'uniqueInProject',
			'message' => 'This name already exists',
			'collection' => self::_collection(),
		    ],
		    [
			'identifier',
			'message' => 'Only letters and numbers',
		    ],
		],
		'label' => 'Name',
		'help' => false,
		'required' => true,
	    ],

	    //project
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
		'showInForms' => false,
	    ],

	    //CLD: 2026-11-03T14:00:00+02:00: the projects it is shared with
	    [
		'key' => 'shares',
		'type' => 'array',
		'label' => 'Shared with',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function afterAttachSchema() {
	if ($this->exists())
	    $this->schema['name']->{'perm:edit'} = false;
    }

    //CLD: 2026-10-26T07:17:30+02:00: a table saved: the ObjectManager's cached data tables are forgotten (ensureDataTables loads them again)
    public function afterSave() {
	parent::afterSave();
	\V::app()->om->resetCache();
    }

    //a deleted table takes its columns (Field records with objectName = iname) and its rows (the collection named iname, see
    //ObjectManager::registerDataTables) with it. Removed directly: the audit log keeps the table's own delete entry, not one per column.
    //The columns are matched by iname only (it holds the project id, so it is unique)
    public function beforeDelete() {
	parent::beforeDelete();
	//CLD: 2026-10-26T07:17:30+02:00: the cached data tables / columns forgotten
	\V::app()->om->resetCache();
	if (empty($this->iname))
	    return;
	//CLD: 2026-10-12T12:21:36+03:00: through ObjectManager: the columns deleted one by one, the rows with om->drop()
	foreach(\V::app()->om->find('Field', [ 'objectName' => $this->iname ]) as $field)
	    $field->delete();
	//CLD: 2026-10-30T17:18:20+02:00: and its saved views
	foreach(\V::app()->om->find('DataTableView', [ 'table' => $this->_id, 'aid' => $this->aid ]) as $view)
	    $view->delete();
	if (!\V::app()->om->isDefined($this->iname))
	    \V::app()->om->registerDataTables();
	if (\V::app()->om->isDefined($this->iname))
	    \V::app()->om->drop($this->iname);
    }

    //CLD: 2026-10-11T21:16:48+03:00: access to a table (BaseModel's default always refuses): global scope = the
    //account's tables, project scope = the tables of the projects the user is a member of. The permissions are named DataTable::* (not DataTableDef::*)
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = 'DataTable::' . $permName;

	//the user's role in the table's project decides
	//or a share with a project where their role allows it
	return \V::app()->om->findById('Project', $this->project)?->checkAccess($userId, $permName) || $this->sharedCan($userId, $permName);
    }

    //CLD: 2026-11-03T14:00:00+02:00: what the Share dialog warns of: object columns pointing to tables that are not shared (their rows' labels are shown to who reads this one)
    public function shareWarnings() {
	$names = [];
	foreach($this->columns() as $f) {
	    if ($f->type !== 'object')
		continue;
	    $target = \V::app()->om->findOne('DataTableDef', [ 'iname' => (string)$f->object, 'aid' => $this->aid ]);
	    if ($target && (string)$target->_id !== (string)$this->_id)
		$names[(string)$target->name] = true;
	}
	return $names ? [ 'Columns of this table point to ' . implode(', ', array_keys($names)) . ': who reads this table also sees the labels of the rows they point to.' ] : [];
    }

    //CLD: 2026-11-04T12:00:00+02:00: what the Move dialog warns of: object columns in both directions (they point by iname, which stays, so they keep working)
    public function moveWarnings() {
	$warnings = $this->shareWarnings();
	$names = [];
	foreach(\V::app()->om->find('Field', [ 'aid' => $this->aid, 'object' => $this->iname ]) as $f) {
	    if ($f->objectName !== $this->iname) {
		$table = \V::app()->om->findOne('DataTableDef', [ 'iname' => (string)$f->objectName, 'aid' => $this->aid ]);
		if ($table)
		    $names[(string)$table->name] = true;
	    }
	}
	if ($names)
	    $warnings[] = 'Columns of ' . implode(', ', array_keys($names)) . ' point to this table: they keep their values, but choosing a new row there needs access to the new project.';
	return $warnings;
    }

    // ------------------------------------------------------------------------------------
    //CLD: 2026-10-12T21:51:51+03:00: rows, shared by the Data Table step (models\node\NodeAppTable) and the MCP server's data table tools (util\McpServerTools);
    //messages are \ErrorException for the user

    //the table's columns: [ key => Field ] (its row model's schema, without the system fields)
    public function columns() {
	if (!\V::app()->om->isDefined($this->iname))
	    \V::app()->om->registerDataTables();
	if (!\V::app()->om->isDefined($this->iname))
	    return [];
	$columns = [];
	foreach(\V::app()->om->create($this->iname, [])->schema as $key => $f) {
	    if (!in_array($key, [ '_id', 'aid', 'created_at', 'updated_at' ], true))
		$columns[$key] = $f;
	}
	return $columns;
    }

    //sets $values on row $row (cast by the columns) and checks the column rules
    public static function assignRow($row, array $values, $prefix = '') {
	$row->assign($values);
	if (!$row->validates()) {
	    $messages = [];
	    foreach((array)$row->errors() as $key => $list)
		$messages[] = "{$key}: " . implode(', ', (array)$list);
	    throw new \ErrorException($prefix . 'the values do not pass the column rules (' . implode('; ', $messages) . ')');
	}
    }

    //the MongoDB query of $conditions ([ { column, operator, value } ], values through $render), $match all | any, within account $aid
    //CLD: 2026-10-14T20:17:14+03:00: the query is util\Conditions::query with the columns' fields (their operators, their casts); id is the row's _id (an oid field), created_at /
    //updated_at dates
    public static function conditionsQuery(array $conditions, $match, array $columns, \Closure $render, $aid) {
	$list = [];
	foreach($conditions as $cond) {
	    $c = trim((string)($cond['column'] ?? ''));
	    if ($c === '')
		continue;
	    $key = $c === 'id' ? '_id' : $c;
	    if (!isset($columns[$c]) && !in_array($key, [ '_id', 'created_at', 'updated_at' ], true))
		throw new \ErrorException("Conditions: the table has no column `{$c}`");
	    $list[] = [ 'key' => $key, 'operator' => (string)($cond['operator'] ?? 'eq'), 'value' => $render($cond['value'] ?? '') ];
	}
	return [ 'aid' => $aid ] + \util\Conditions::query($list, $match, fn($key) => $columns[$key] ?? \sys\fields\Field::create([ 'type' => $key === '_id' ? 'oid' : 'datetime', 'key' => $key ]));
    }

    //a row as a plain value: { id, <columns>, created_at, updated_at } (ids as text, dates as ISO 8601)
    public static function rowValue($row, array $columns) {
	$value = [ 'id' => (string)$row->_id ];
	foreach(array_keys($columns) as $c)
	    $value[$c] = \util\Expression::hostValue($row->$c);
	$value['created_at'] = \util\Expression::hostValue($row->created_at);
	$value['updated_at'] = \util\Expression::hostValue($row->updated_at);
	return $value;
    }

}