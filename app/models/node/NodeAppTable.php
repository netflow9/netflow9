<?php
/*
    Data Table - works with a data table of the workflow's project:
    - query rows (filter, sort, limit, offset),
    - get / insert / update / delete a row
    - insert a list of rows
    - insert or update by a column (upsert)
    - update / delete the rows a filter matches
    - get the columns.

    Filter conditions:
	- column, operator, value (an expression), all or any of them;
	- row data: column, value (an expression; a single {{ }} keeps its type).
	Values are cast and checked by the table's row model (the column types and rules).

    The result goes into one variable (default `table`):
	query { rows, count, total },
	get / insert / update { row },
	upsert { row, operation },
	insertMany { inserted },
	updateMany { updated },
	delete / deleteMany { deleted },
	schema { columns };
    a row is { id, <columns>, created_at, updated_at }.

    On error: as the HTTP Request step (fail, or continue on the `error` output, the variable then { error }).
*/

namespace models\node;

class NodeAppTable extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'table';
    public static $label = 'Data Table';
    public static $icon = 'bi-table';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [
	'query' => 'Query rows',
	'get' => 'Get a row (by id)',
	'insert' => 'Insert a row',
	'insertMany' => 'Insert rows (a list)',
	'upsert' => 'Insert or update (by a column)',
	'update' => 'Update a row (by id)',
	'updateMany' => 'Update rows (filter)',
	'delete' => 'Delete a row (by id)',
	'deleteMany' => 'Delete rows (filter)',
	'schema' => 'Get the columns',
    ];

    //rows of a query / a list insert at most
    const MAX_LIMIT = 1000;
    const MAX_INSERT = 10000;

    //the operations using the filter / the row data (validateIf and showIf of `filter` / `data`, the column checks of validates())
    const FILTER_OPERATIONS = [ 'query', 'updateMany', 'deleteMany' ];
    //the operations that do not change rows: all a table shared at level `read` allows
    const READ_OPERATIONS = [ 'query', 'get', 'schema' ];
    const DATA_OPERATIONS = [ 'insert', 'upsert', 'update', 'updateMany' ];

    //columns every row has (besides the table's)
    const SYSTEM_COLUMNS = [ '_id', 'id', 'created_at', 'updated_at' ];

    public static function configSchema() {
	$tables = [];

	foreach(static::tableChoices() as $id => $name)
	    $tables[$id] = $name;

	return [
	    [
		//what the step works on (`resource`): the designer puts it on line 2, before the operation, 6 wide
		'key' => 'table',
		'type' => 'select',
		'label' => 'Table',
		'choices' => $tables,
		'help' => 'A data table of the project',
		'required' => true,
		'ui' => [ 'resource' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => 'query',
		'choices' => self::OPERATIONS,
	    ],
	    [
		//layout: 6 wide, on a new line
		'key' => 'rowId',
		'type' => 'text',
		'label' => 'Row id',
		'help' => '{{ ... }} for expressions, e.g. {{ table.row.id }}',
		//required in the browser from the requiredIf rule (util\WidgetsUtil; a field property: Field adds the rule)
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'get', 'update', 'delete' ] ],
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => [ 'get', 'update', 'delete' ] ] ],
	    ],
	    [
		//layout: 3 wide, on a new line
		'key' => 'match',
		'type' => 'select',
		'label' => 'Rows matching',
		'required' => true,
		'default' => 'all',
		'choices' => [ 'all' => 'All conditions', 'any' => 'Any condition' ],
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => [ 'query', 'updateMany', 'deleteMany' ] ] ],
	    ],
	    //a nested list (sys\fields\Field_nested): each condition cast by filterSchema(), validated
	    //when the operation uses it (validateIf), its expressions checked, errors as "Condition <n>: <field>: <message>"; the edit grid's columns come from filterSchema()
	    [
		'key' => 'filter',
		'type' => 'nested',
		'multiple' => true,
		'fields' => self::filterSchema(),
		'label' => 'Conditions',
		'itemLabel' => 'Condition',
		'default' => [],
		'help' => 'None: every row',
		'validateIf' => [ 'if' => 'operation', 'in' => self::FILTER_OPERATIONS ],
		'ui' => [ 'addLabel' => 'Add condition',
		    //a condition array (app.smartform evaluates it)
		    'compact' => true, 'maxRows' => 20, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => self::FILTER_OPERATIONS ] ],
	    ],
	    [
		//layout: 3 wide, on a new line
		'key' => 'sortBy',
		'type' => 'text',
		'label' => 'Sort by',
		'help' => 'A column; empty: in the order added',
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => 'created_at', 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'query' ] ],
	    ],
	    [
		//under "Advanced…" (the designer's closed section of the technical settings); 3 wide
		'key' => 'sortDir',
		'type' => 'select',
		'label' => 'Direction',
		'required' => true,
		'default' => 'asc',
		'choices' => [ 'asc' => 'Ascending', 'desc' => 'Descending' ],
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'query' ] ],
	    ],
	    [
		//layout: 3 wide
		'key' => 'limit',
		'type' => 'number',
		'label' => 'Limit',
		'required' => true,
		'default' => 100,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_LIMIT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_LIMIT ] ],
		//min / max from the validate rule (util\WidgetsUtil); a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 3, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'query' ] ],
	    ],
	    [
		//under "Advanced…" (the designer's closed section of the technical settings); 3 wide
		'key' => 'offset',
		'type' => 'number',
		'label' => 'Skip',
		'required' => true,
		'default' => 0,
		'validate' => [ [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => '0 or more' ] ],
		//min / max from the validate rule (util\WidgetsUtil); a condition array (app.smartform evaluates it)
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'query' ] ],
	    ],
	    //a nested list, as `filter` (dataSchema(); errors as "Value <n>: <field>: <message>")
	    [
		'key' => 'data',
		'type' => 'nested',
		'multiple' => true,
		'fields' => self::dataSchema(),
		'label' => 'Values',
		'itemLabel' => 'Value',
		'default' => [],
		'validateIf' => [ 'if' => 'operation', 'in' => self::DATA_OPERATIONS ],
		'ui' => [ 'addLabel' => 'Add value',
		    //a condition array (app.smartform evaluates it)
		    'compact' => true, 'maxRows' => 100, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => self::DATA_OPERATIONS ] ],
	    ],
	    [
		//layout: 6 wide, on a new line
		'key' => 'upsertKey',
		'type' => 'text',
		'label' => 'Match by column',
		'help' => 'Same value in this column: updated, else inserted',
		//required in the browser from the requiredIf rule (util\WidgetsUtil; a field property: Field adds the rule)
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'upsert' ] ],
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 6, 'newRow' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'upsert' ] ],
	    ],
	    [
		'key' => 'rows',
		'type' => 'textarea',
		'label' => 'Rows',
		'help' => 'An expression giving a list of objects keyed by column, e.g. {{ response.body.items }}',
		//required in the browser from the requiredIf rule (util\WidgetsUtil; a field property: Field adds the rule)
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'insertMany' ] ],
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'rows' => 3, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'insertMany' ] ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'table',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		//pattern from the validate rule (util\WidgetsUtil)
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'A missing row, a column rule, an unknown column',
	    ],
	];
    }

    //one condition of a filter
    public static function filterSchema() {
	return [
	    //trimmed by the field
	    [ 'key' => 'column', 'type' => 'text', 'label' => 'Column', 'required' => true, 'transform' => 'trim', 'ui' => [ 'width' => 4 ] ],
	    [ 'key' => 'operator', 'type' => 'select', 'label' => 'Operator', 'required' => true, 'default' => 'eq', 'choices' => \util\Conditions::operators(),
		'ui' => [ 'width' => 4 ] ],
	    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false,
		//a condition array (app.smartform evaluates it)
		'ui' => [ 'width' => 4, 'expression' => true, 'hideIf' => [ 'key' => 'operator', 'operator' => 'in', 'value' => [ 'empty', 'notEmpty' ], 'scope' => 'row' ] ] ],
	];
    }

    //one value of the row data
    public static function dataSchema() {
	return [
	    //trimmed by the field
	    [ 'key' => 'column', 'type' => 'text', 'label' => 'Column', 'required' => true, 'transform' => 'trim', 'ui' => [ 'width' => 5 ] ],
	    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false, 'ui' => [ 'width' => 7, 'expression' => true ] ],
	];
    }

    //the data tables of the current project and those shared with it (named with their project), for the designer: [ id => name ]
    protected static function tableChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	$choices = [];
	$project = \V::app()->user->crtProject;
	foreach(\V::app()->om->find('DataTableDef', [ 'aid' => \V::app()->config('rt.account')->_id ?? null ] + \models\datatable\DataTableDef::visibleInQuery($project), [ 'sort' => [ 'name' => 1 ] ]) as $t)
	    $choices[(string)$t->_id] = (string)$t->name . ($t->isHome($project) ? '' : ' (shared from ' . \V::app()->om->findById('Project', $t->project) . ')');
	return $choices;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'table') : 'table';
	$op = is_array($config) ? (string)($config['operation'] ?? 'query') : 'query';
	$shape = [ 'query' => '{ rows, count, total }', 'get' => '{ row }', 'insert' => '{ row }', 'update' => '{ row }', 'upsert' => '{ row, operation }', 'insertMany' => '{ inserted }',
	    'updateMany' => '{ updated }', 'delete' => '{ deleted }', 'deleteMany' => '{ deleted }', 'schema' => '{ columns }' ][$op] ?? '';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	//the fields and their types per operation; a row's fields: id, the table's columns (when the table is found: its account), created_at, updated_at
	$row = [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ] ] ];
	//for a user, only a table of their current project or shared with it
	$table = is_array($config) && \util\Validator::rule('oid', (string)($config['table'] ?? '')) && \V::app()->config('rt.account')
	    ? (\V::app()->config('app.mode') == 'web'
		? self::findTable((string)$config['table'], \V::app()->config('rt.account')->_id, \V::app()->user->crtProject)
		: \V::app()->om->findOne('DataTableDef', [ '_id' => (string)$config['table'], 'aid' => \V::app()->config('rt.account')->_id ])) : null;
	if ($table) {
	    foreach($table->columns() as $key => $f)
		$row['fields'][$key] = [ 'type' => [ 'number' => 'number', 'array' => 'array', 'object' => 'object', 'checkbox' => 'any', 'switch' => 'any' ][(string)$f->type] ?? 'string',
		    'info' => trim((string)$f->label . ' (' . (string)$f->type . ')') ];
	}
	$row['fields'] += [ 'created_at' => [ 'type' => 'string', 'info' => 'ISO 8601' ], 'updated_at' => [ 'type' => 'string', 'info' => 'ISO 8601' ] ];
	$number = [ 'type' => 'number' ];
	$fields = [
	    'query' => [ 'rows' => [ 'type' => 'array', 'items' => $row ], 'count' => $number, 'total' => $number ],
	    'get' => [ 'row' => $row ], 'insert' => [ 'row' => $row ], 'update' => [ 'row' => $row ], 'upsert' => [ 'row' => $row, 'operation' => [ 'type' => 'string', 'info' => 'insert or update' ] ],
	    'insertMany' => [ 'inserted' => $number ], 'updateMany' => [ 'updated' => $number ], 'delete' => [ 'deleted' => $number ], 'deleteMany' => [ 'deleted' => $number ],
	    'schema' => [ 'columns' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'key' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ],
		'type' => [ 'type' => 'string' ], 'required' => [ 'type' => 'boolean' ] ] ] ] ],
	][$op] ?? [];
	return [ $name => [ 'type' => 'object', 'info' => "the Data Table result: {$shape}", 'fields' => $fields + [ 'error' => [ 'type' => 'string', 'info' => 'on error only' ] ] ] ];
    }

    // ------------------------------------------------------------------------------------
    //checks

    //the table: one of the workflow's project; the rows of the lists through their forms; the columns named exist in the table
    public function validates($options = []) {
	$valid = parent::validates($options);
	$op = (string)$this->cfg('operation', 'query');

	$table = $this->workflow ? self::findTable((string)$this->cfg('table', ''), $this->workflow->aid, $this->workflow->project) : null;
	//an empty one is reported by the field's `required`
	if (!$table && (string)$this->cfg('table', '') !== '') {
	    $this->errors('config', 'Table: choose a data table of this project, or one shared with it');
	    $valid = false;
	}
	//a table shared for reading: no operation that changes rows
	if ($table && !in_array($op, self::READ_OPERATIONS, true) && !$table->shareAllows($this->workflow->project, 'DataTable::writeData')) {
	    $this->errors('config', "Table: {$table->name} is shared with this project for reading only");
	    $valid = false;
	}
	$columns = $table ? $table->columns() : null;

	//the rows (required fields, operator, expressions) are checked and normalized by parent::validates (nested fields, validated when the
	//operation uses them); here the columns they name
	$lists = [ 'filter' => [ 'Condition', self::FILTER_OPERATIONS ], 'data' => [ 'Value', self::DATA_OPERATIONS ] ];
	foreach($lists as $key => [ $label, $ops ]) {
	    if (!in_array($op, $ops, true))
		continue;
	    foreach(array_values(is_array($this->cfg($key)) ? $this->cfg($key) : []) as $i => $row) {
		$column = is_array($row) ? (string)($row['column'] ?? '') : '';
		if ($column !== '' && !self::knownColumn($columns, $column)) {
		    $this->errors('config', "{$label} " . ($i + 1) . ": the table has no column `{$column}`");
		    $valid = false;
		}
	    }
	}

	$checks = [ 'sortBy' => [ 'query' ], 'upsertKey' => [ 'upsert' ] ];
	foreach($checks as $key => $ops) {
	    $column = trim((string)$this->cfg($key, ''));
	    if (in_array($op, $ops, true) && $column !== '' && !self::knownColumn($columns, $column)) {
		$this->errors('config', (string)static::configForm()->schema[$key]->label . ": the table has no column `{$column}`");
		$valid = false;
	    }
	}
	return $valid;
    }

    //whether column $c is one of the table's ($columns; null: not known, any) or a system column
    private static function knownColumn($columns, $c) {
	return $columns === null || in_array($c, self::SYSTEM_COLUMNS, true) || isset($columns[$c]);
    }

    //data table $id of account $aid and project $project, or null
    //or shared with $project; $homeOnly: its own tables only
    public static function findTable($id, $aid, $project, $homeOnly = false) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	$scope = $homeOnly ? [ 'project' => $project ] : \models\datatable\DataTableDef::visibleInQuery($project);
	return \V::app()->om->findOne('DataTableDef', [ '_id' => (string)$id, 'aid' => $aid ] + $scope);
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', 'table');
	$op = (string)$this->cfg('operation', 'query');
	try {
	    $result = $this->run($op);
	} catch (\ErrorException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, [ 'error' => $e->getMessage() ]);
	    $this->exec->log($this->wfid, "table {$op}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('Data Table: ' . $e->getMessage());
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	return [ 0 ];
    }

    //the operation's result; \ErrorException with a message for the user
    protected function run($op) {
	$table = self::findTable((string)$this->cfg('table', ''), $this->exec->aid, $this->exec->project);
	if (!$table)
	    throw new \ErrorException('the table is not a data table of this project (deleted, or no longer shared?)');
	//checked again at each run: the level may have changed since the workflow was saved
	if (!in_array($op, self::READ_OPERATIONS, true) && !$table->shareAllows($this->exec->project, 'DataTable::writeData'))
	    throw new \ErrorException("the table {$table->name} is shared with this project for reading only");
	$columns = $table->columns();
	$iname = $table->iname;
	$aid = $this->exec->aid;
	//with the project variables (vars)
	$vars = $this->exec->expressionVariables();
	$this->renderVars = $vars;
	$label = $table->name;

	switch($op) {
	    case 'schema':
		$list = [];
		foreach($columns as $key => $f)
		    $list[] = [ 'key' => $key, 'label' => (string)$f->label, 'type' => (string)$f->type, 'required' => !empty($f->required) ];
		$this->exec->log($this->wfid, "table {$label}: " . count($list) . ' columns');
		return [ 'columns' => $list ];

	    case 'query':
		$query = \models\datatable\DataTableDef::conditionsQuery((array)$this->cfg('filter', []), $this->cfg('match', 'all'), $columns, $this->renderValue(...), $aid);
		$options = [ 'skip' => max(0, (int)$this->cfg('offset', 0)), 'limit' => max(1, min(self::MAX_LIMIT, (int)$this->cfg('limit', 100))) ];
		$sortBy = trim((string)$this->cfg('sortBy', ''));
		if ($sortBy !== '')
		    $options['sort'] = [ ($sortBy === 'id' ? '_id' : $sortBy) => $this->cfg('sortDir', 'asc') === 'desc' ? -1 : 1 ];
		$rows = [];
		foreach(\V::app()->om->find($iname, $query, $options) as $row)
		    $rows[] = \models\datatable\DataTableDef::rowValue($row, $columns);
		$total = \V::app()->om->count($iname, $query);
		$this->exec->log($this->wfid, "table {$label}: query -> " . count($rows) . " of {$total} rows");
		return [ 'rows' => $rows, 'count' => count($rows), 'total' => $total ];

	    case 'get':
		$row = $this->rowById($iname, $this->renderValue($this->cfg('rowId', '')), $aid, false);
		$this->exec->log($this->wfid, "table {$label}: get -> " . ($row ? 'found' : 'not found'));
		return [ 'row' => $row ? \models\datatable\DataTableDef::rowValue($row, $columns) : null ];

	    case 'insert':
		$row = \V::app()->om->create($iname, [ 'aid' => $aid ]);
		\models\datatable\DataTableDef::assignRow($row, $this->dataValues($columns));
		//the tier's limit of rows in a data table (util\Tiers; the step fails with the message)
		\util\Tiers::check($aid, 'datatable_records', 1, $iname);
		$row->save();
		$this->exec->log($this->wfid, "table {$label}: inserted row {$row->_id}");
		return [ 'row' => \models\datatable\DataTableDef::rowValue($row, $columns) ];

	    case 'insertMany':
		$list = $this->renderValue($this->cfg('rows', ''));
		if (!is_array($list) || !array_is_list($list))
		    throw new \ErrorException('Rows: the expression must give a list of objects');
		if (count($list) > self::MAX_INSERT)
		    throw new \ErrorException('Rows: at most ' . self::MAX_INSERT . ' rows at once');
		$models = [];
		foreach($list as $i => $item) {
		    if (!is_array($item) || array_is_list($item))
			throw new \ErrorException('Rows: item ' . ($i + 1) . ' is not an object (column: value)');
		    foreach(array_keys($item) as $c) {
			if (!isset($columns[$c]))
			    throw new \ErrorException('Rows: item ' . ($i + 1) . ": the table has no column `{$c}`");
		    }
		    $row = \V::app()->om->create($iname, [ 'aid' => $aid ]);
		    \models\datatable\DataTableDef::assignRow($row, $item, 'item ' . ($i + 1) . ': ');
		    $models[] = $row;
		}
		//the tier's limit of rows in a data table (util\Tiers): all of them, or none
		\util\Tiers::check($aid, 'datatable_records', count($models), $iname);
		$inserted = 0;
		foreach(array_chunk($models, 500) as $batch)
		    $inserted += \V::app()->om->insertMany($iname, $batch);
		$this->exec->log($this->wfid, "table {$label}: inserted {$inserted} rows");
		return [ 'inserted' => $inserted ];

	    case 'upsert':
		$values = $this->dataValues($columns);
		$key = trim((string)$this->cfg('upsertKey', ''));
		if (!isset($columns[$key]))
		    throw new \ErrorException("Match by column: the table has no column `{$key}`");
		if (!array_key_exists($key, $values))
		    throw new \ErrorException("Values: give a value for `{$key}` (the column rows are matched by)");
		$match = $columns[$key]->cast($values[$key]);
		$row = \V::app()->om->findOne($iname, [ 'aid' => $aid, $key => $match ]);
		$operation = $row ? 'update' : 'insert';
		$row = $row ?: \V::app()->om->create($iname, [ 'aid' => $aid ]);
		\models\datatable\DataTableDef::assignRow($row, $values);
		//a new row: the tier's limit of rows in a data table (util\Tiers)
		if ($operation === 'insert')
		    \util\Tiers::check($aid, 'datatable_records', 1, $iname);
		$row->save();
		$this->exec->log($this->wfid, "table {$label}: upsert by {$key} -> {$operation} row {$row->_id}");
		return [ 'row' => \models\datatable\DataTableDef::rowValue($row, $columns), 'operation' => $operation ];

	    case 'update':
		$row = $this->rowById($iname, $this->renderValue($this->cfg('rowId', '')), $aid, true);
		\models\datatable\DataTableDef::assignRow($row, $this->dataValues($columns));
		$row->save();
		$this->exec->log($this->wfid, "table {$label}: updated row {$row->_id}");
		return [ 'row' => \models\datatable\DataTableDef::rowValue($row, $columns) ];

	    case 'updateMany':
		$values = $this->dataValues($columns);
		$updated = 0;
		foreach(\V::app()->om->find($iname, \models\datatable\DataTableDef::conditionsQuery((array)$this->cfg('filter', []), $this->cfg('match', 'all'), $columns, $this->renderValue(...), $aid)) as $row) {
		    \models\datatable\DataTableDef::assignRow($row, $values, "row {$row->_id}: ");
		    $row->save();
		    $updated++;
		}
		$this->exec->log($this->wfid, "table {$label}: updated {$updated} rows");
		return [ 'updated' => $updated ];

	    case 'delete':
		$row = $this->rowById($iname, $this->renderValue($this->cfg('rowId', '')), $aid, false);
		if ($row)
		    $row->delete();
		$this->exec->log($this->wfid, "table {$label}: deleted " . ($row ? 1 : 0) . ' row');
		return [ 'deleted' => $row ? 1 : 0 ];

	    case 'deleteMany':
		$deleted = 0;
		foreach(\V::app()->om->find($iname, \models\datatable\DataTableDef::conditionsQuery((array)$this->cfg('filter', []), $this->cfg('match', 'all'), $columns, $this->renderValue(...), $aid)) as $row) {
		    $row->delete();
		    $deleted++;
		}
		$this->exec->log($this->wfid, "table {$label}: deleted {$deleted} rows");
		return [ 'deleted' => $deleted ];
	}
	throw new \ErrorException("unknown operation `{$op}`");
    }

    //the variables of renderValue(), set by run()
    private $renderVars = [];

    //value $v with its {{ }} rendered against the run's variables
    private function renderValue($v) {
	return \util\Expression::render((string)$v, $this->renderVars);
    }

    //row $id of the table (account $aid); null when missing, or \ErrorException when $required
    protected function rowById($iname, $id, $aid, $required) {
	$id = trim(\util\Expression::toText($id));
	$row = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne($iname, [ '_id' => $id, 'aid' => $aid ]) : null;
	if (!$row && $required)
	    throw new \ErrorException("Row id: no row `{$id}`");
	return $row;
    }

    //the row data: [ column => value ] (expressions evaluated; a single one keeps its type)
    protected function dataValues(array $columns) {
	$values = [];
	foreach((array)$this->cfg('data', []) as $item) {
	    $c = trim((string)($item['column'] ?? ''));
	    if ($c === '')
		continue;
	    if (!isset($columns[$c]))
		throw new \ErrorException("Values: the table has no column `{$c}`");
	    $values[$c] = $this->renderValue($item['value'] ?? '');
	}
	if (!$values)
	    throw new \ErrorException('Values: give at least one value');
	return $values;
    }
}
