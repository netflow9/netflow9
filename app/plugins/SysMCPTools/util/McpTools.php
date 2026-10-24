<?php

/*
    the tools of NetFlow9's MCP server (controllers\McpController) - also meant for the AI steps, which call them directly.
    Every tool works on one project ($project) as the current user (\V::app()->user):
	each object is checked with the user's permissions (user->can), as the pages do.
    Data tables: list / query / insert / update / delete rows (rules and casts of the table's columns: models\DataTableDef);
	CLD: 2026-10-22T01:11:17+03:00: + their structure: create_table, update_table (rename), add_column, update_column, delete_column (the rules of the column designer: models\datatable\FieldForm);
    workflows: list, run (Manual trigger; the published version, else the draft),
	an execution's result; files: list, read (text);
    project variables: list.

    definitions() = the MCP tool list (name, title, description, inputSchema, annotations);
    call() = the result (plain array) or \ErrorException for the caller
    + building workflows (user's choice: create and update drafts, people publish): list_step_types, get_workflow, create_workflow, update_workflow (util\WorkflowSpec)
*/

namespace plugins\SysMCPTools\util;

class McpTools {
    const MAX_DEPTH = 3;
    private static $depth = 0;

    //most rows a query returns
    const MAX_ROWS = 200;
    //most rows inserted at once
    const MAX_INSERT = 100;
    //CLD: 2026-10-22T01:11:17+03:00: most columns create_table makes at once; column keys refused here besides the reserved ones (FieldForm): `id` is the row id in the rows these tools give
    const MAX_COLUMNS = 100;
    const KEYS_TAKEN = [ 'id' ];
    //largest file read_file returns (bytes)
    const MAX_FILE = 262144;
    //log lines get_execution returns (the last ones)
    const MAX_LOG = 50;
    //CLD: 2026-10-17T21:00:54+03:00: largest image read_image returns / an AI step sends (bytes; the providers' limit)
    //CLD: 2026-10-20T23:40:00+03:00: the largest image is the AI steps' (models\node\NodeAppAi::MAX_IMAGE, was here: the AI steps use it without this plugin)
    const MAX_IMAGE = \models\node\NodeAppAi::MAX_IMAGE;
    //CLD: 2026-10-17T21:00:54+03:00: the key of a result holding images: [ [ mimeType, data (base64) ] ]; sent as image content (McpController, the AI steps), not as text (images())
    const IMAGES = '_images';


    public static function definitions() {
	$table = [ 'type' => 'string', 'description' => 'The data table: its name or id (list_tables)' ];
	$conditions = [
	    'type' => 'array',
	    'description' => 'Row conditions; column `id` = the row id, also created_at / updated_at',
	    'items' => [
		'type' => 'object',
		'properties' => [
		    'column' => [ 'type' => 'string' ],
		    'operator' => [ 'type' => 'string', 'enum' => array_keys(\util\Conditions::operators()), 'description' => 'in: a list (or comma-separated text); empty / notEmpty: no value' ],
		    'value' => [ 'description' => 'The value to compare with (cast to the column type)' ],
		],
		'required' => [ 'column', 'operator' ],
	    ],
	];
	//CLD: 2026-10-22T01:11:17+03:00: a column of create_table / add_column (models\datatable\FieldForm's settings)
	$targets = self::linkTargets();
	$column = [
	    'type' => 'object',
	    'properties' => [
		'key' => [ 'type' => 'string', 'description' => 'Letters, digits or underscore, not starting with a digit; unique in the table; cannot be changed later (not id, aid, created_at, updated_at)' ],
		'label' => [ 'type' => 'string', 'description' => 'The column title; empty: the key' ],
		'type' => [ 'type' => 'string', 'enum' => array_keys(\V::app()->om->getFieldTypes()), 'default' => 'text', 'description' => 'Cannot be changed later' ],
		'required' => [ 'type' => 'boolean', 'default' => false ],
		'choices' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'select: the values it may hold (at least one)' ],
		'points_to' => [ 'type' => 'string', 'description' => 'object: what it points to - a data table of the project (name or id)'
		    . ($targets ? ', or one of: ' . implode(', ', array_keys($targets)) : '') . '; cannot be changed later' ],
	    ],
	    'required' => [ 'key' ],
	];
	$ro = [ 'readOnlyHint' => true, 'openWorldHint' => false ];
	return [
	    [ 'name' => 'list_tables', 'title' => 'List data tables',
		'description' => 'The data tables of the project, with their columns (key, label, type, required; choices of a select, points_to of an object).',
		'inputSchema' => [ 'type' => 'object', 'properties' => (object)[] ], 'annotations' => $ro ],
	    [ 'name' => 'query_table', 'title' => 'Query a data table',
		'description' => 'Rows of a data table matching the conditions (all of them, or any with match = any), sorted and paged. Each row: { id, <columns>, created_at, updated_at }.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'conditions' => $conditions,
		    'match' => [ 'type' => 'string', 'enum' => [ 'all', 'any' ], 'default' => 'all' ],
		    'sort_by' => [ 'type' => 'string', 'description' => 'A column, id, created_at or updated_at' ],
		    'sort_dir' => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ], 'default' => 'asc' ],
		    'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS, 'default' => 50 ],
		    'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
		], 'required' => [ 'table' ] ], 'annotations' => $ro ],
	    [ 'name' => 'insert_rows', 'title' => 'Insert rows',
		'description' => 'Adds rows to a data table (at most ' . self::MAX_INSERT . '); each row is an object { column: value }. All rows are checked first: nothing is inserted when one fails the column rules.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1, 'maxItems' => self::MAX_INSERT ],
		], 'required' => [ 'table', 'rows' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false ] ],
	    [ 'name' => 'update_row', 'title' => 'Update a row',
		'description' => 'Changes columns of one row of a data table (the other columns keep their values).',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'id' => [ 'type' => 'string', 'description' => 'The row id' ],
		    'values' => [ 'type' => 'object', 'description' => '{ column: new value }' ],
		], 'required' => [ 'table', 'id', 'values' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    [ 'name' => 'delete_row', 'title' => 'Delete a row',
		'description' => 'Deletes one row of a data table.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'id' => [ 'type' => 'string', 'description' => 'The row id' ],
		], 'required' => [ 'table', 'id' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    //CLD: 2026-10-22T01:11:17+03:00: the structure of the data tables (DataTable::create, DataTable::update)
	    [ 'name' => 'create_table', 'title' => 'Create a data table',
		'description' => 'Creates a data table in the project with its columns (at least one, at most ' . self::MAX_COLUMNS . '). The name: letters, digits or underscore. '
		    . 'Everything is checked first: nothing is created when the name or a column is refused. Answers the table as list_tables gives it.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'name' => [ 'type' => 'string', 'description' => 'Letters, digits or underscore, not starting with a digit; unique in the project' ],
		    'columns' => [ 'type' => 'array', 'items' => $column, 'minItems' => 1, 'maxItems' => self::MAX_COLUMNS ],
		], 'required' => [ 'name', 'columns' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ] ],
	    [ 'name' => 'update_table', 'title' => 'Rename a data table',
		'description' => 'Renames a data table (its rows and columns stay). Steps that name the table by its old name must be changed too.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'name' => [ 'type' => 'string', 'description' => 'The new name: letters, digits or underscore; unique in the project' ],
		], 'required' => [ 'table', 'name' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    [ 'name' => 'add_column', 'title' => 'Add a column',
		'description' => 'Adds a column to a data table; the existing rows have no value in it. Answers the table as list_tables gives it.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'column' => $column,
		], 'required' => [ 'table', 'column' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ] ],
	    [ 'name' => 'update_column', 'title' => 'Change a column',
		'description' => 'Changes the label, whether it is required, or the choices (select) of a column. Its key, type and points_to cannot be changed (add a new column instead). '
		    . 'Rows are not changed: a value no longer among the choices stays. Answers the table as list_tables gives it.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'key' => [ 'type' => 'string', 'description' => 'The column\'s key' ],
		    'label' => [ 'type' => 'string' ],
		    'required' => [ 'type' => 'boolean' ],
		    'choices' => $column['properties']['choices'],
		], 'required' => [ 'table', 'key' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    [ 'name' => 'delete_column', 'title' => 'Delete a column',
		'description' => 'Deletes a column of a data table and its values in every row (they cannot be brought back).',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'table' => $table,
		    'key' => [ 'type' => 'string', 'description' => 'The column\'s key' ],
		], 'required' => [ 'table', 'key' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    [ 'name' => 'list_workflows', 'title' => 'List workflows',
		'description' => 'The workflows of the project: id, name, status (draft / published), trigger, and whether run_workflow can start them (Manual trigger).',
		'inputSchema' => [ 'type' => 'object', 'properties' => (object)[] ], 'annotations' => $ro ],
	    [ 'name' => 'run_workflow', 'title' => 'Run a workflow',
		'description' => 'Runs a workflow whose trigger is a Manual trigger and waits for it: its published version, else its draft. The variables given are its first variables. Returns the execution id, its state (end, error, sleep), error and variables.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'workflow' => [ 'type' => 'string', 'description' => 'The workflow: its name or id (list_workflows)' ],
		    'variables' => [ 'type' => 'object', 'description' => '{ name: value }: variables the run starts with' ],
		], 'required' => [ 'workflow' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true ] ],
	    [ 'name' => 'get_execution', 'title' => 'Get an execution',
		'description' => 'A workflow execution: state, error, trigger, start / end time, variables and the last ' . self::MAX_LOG . ' log lines.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'id' => [ 'type' => 'string', 'description' => 'The execution id (run_workflow)' ],
		], 'required' => [ 'id' ] ], 'annotations' => $ro ],
	    [ 'name' => 'list_files', 'title' => 'List files',
		'description' => 'The files of the project (newest first): id, filename, size, type, created_at; optionally only names containing `search`.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'search' => [ 'type' => 'string' ],
		    'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ],
		] ], 'annotations' => $ro ],
	    [ 'name' => 'read_file', 'title' => 'Read a file',
		'description' => 'The content of a text file of the project (UTF-8, at most ' . (self::MAX_FILE / 1024) . ' KB).',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'id' => [ 'type' => 'string', 'description' => 'The file id (list_files)' ],
		], 'required' => [ 'id' ] ], 'annotations' => $ro ],
	    //CLD: 2026-10-17T21:00:54+03:00: an image file, to look at
	    [ 'name' => 'read_image', 'title' => 'Look at an image',
		'description' => 'An image file of the project (PNG, JPEG, GIF, WebP; at most ' . (self::MAX_IMAGE / 1048576) . ' MB), given as an image to look at.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'id' => [ 'type' => 'string', 'description' => 'The file id (list_files)' ],
		], 'required' => [ 'id' ] ], 'annotations' => $ro ],
	    //CLD: 2026-10-13T15:17:12+03:00: building workflows (drafts; publishing stays with people, in the designer)
	    [ 'name' => 'list_step_types', 'title' => 'List step types',
		'description' => 'The step types a workflow can use (type = e.g. app::set), with their settings (key, type, required, default, choices; syntax: text = text with {{ expression }} parts, '
		    . 'reference = only {{ variable }} or {{ variable.field }}, value = one {{ expression }}, script = J code (the Code step: no {{ }}; assigning a name sets that workflow variable)), their outputs (labels, for connections) and the variables they provide. '
		    . 'A workflow has one trigger (kind trigger); a loop (container) runs the steps inside it (parent = the loop) once per item, starting at its inner step without an incoming connection. '
		    //CLD: 2026-10-13T15:24:28+03:00: + the project variables
		    . 'Expressions also see the project variables as {{ vars.<name> }} (list_variables); no step may set a variable named vars.',
		'inputSchema' => [ 'type' => 'object', 'properties' => (object)[] ], 'annotations' => $ro ],
	    [ 'name' => 'get_workflow', 'title' => 'Get a workflow',
		'description' => 'The draft of a workflow as steps [ { id, type, title, config, parent } ] and connections [ { from, to, output } ], with its connection problems.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'workflow' => [ 'type' => 'string', 'description' => 'The workflow: its name or id (list_workflows)' ],
		], 'required' => [ 'workflow' ] ], 'annotations' => $ro ],
	    [ 'name' => 'create_workflow', 'title' => 'Create a workflow',
		'description' => 'Creates a draft workflow (not published: a person publishes it). Steps: { id (your name for it), type (list_step_types), title, config { setting: value }, parent (a loop\'s id) }; '
		    . 'connections: { from, to, output (an output label such as true / false / otherwise / error, or a number; default 0) }. Positions are laid out automatically. '
		    . 'Wrong settings and connection problems are saved and returned in problems (a draft with errors cannot be published or run: fix them). Test a Manual-trigger draft with run_workflow.',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'name' => [ 'type' => 'string', 'description' => 'The workflow name' ],
		] + \util\WorkflowSpec::schema(), 'required' => [ 'name', 'steps' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false ] ],
	    [ 'name' => 'update_workflow', 'title' => 'Update a workflow',
		'description' => 'Replaces the draft of a workflow: its steps and connections (both given: the whole new draft; get_workflow first), and/or its name. Steps keeping their id keep their place; '
		    . 'relayout = true lays out every step again. The published version runs unchanged until a person publishes. Wrong settings and connection problems are saved and returned in problems (a draft with errors cannot be published or run).',
		'inputSchema' => [ 'type' => 'object', 'properties' => [
		    'workflow' => [ 'type' => 'string', 'description' => 'The workflow: its name or id' ],
		    'name' => [ 'type' => 'string', 'description' => 'A new name' ],
		    'relayout' => [ 'type' => 'boolean', 'default' => false ],
		] + \util\WorkflowSpec::schema(), 'required' => [ 'workflow' ] ], 'annotations' => [ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ] ],
	    [ 'name' => 'list_variables', 'title' => 'List project variables',
		//CLD: 2026-10-22T00:28:13+03:00: they are the credentials of type Variable
		'description' => 'The variables of the project (credentials of type Variable): name, value, description.',
		'inputSchema' => [ 'type' => 'object', 'properties' => (object)[] ], 'annotations' => $ro ],
	];
    }


    //CLD: 2026-10-13T15:17:12+03:00: $wf with the steps / connections of $args (when given) as its draft, checked (wrong settings: \ErrorException with the reasons) and saved; the answer:
    //CLD: 2026-10-19T17:25:00+03:00: wrong step settings no longer refuse the save (they are in `problems`)
    //{ id, name, url (the designer), status, problems }
    private static function saveSpec($wf, array $args, $relayout) {
	if (array_key_exists('steps', $args) || array_key_exists('connections', $args)) {
	    $wf->nodes = \util\WorkflowSpec::nodesFrom(self::listArg($args, 'steps'), self::listArg($args, 'connections'), is_array($wf->nodes) ? array_values($wf->nodes) : [], $relayout);
	}
	//CLD: 2026-10-19T17:25:00+03:00: validatesDraft (was validates): saved even with errors in the steps' settings (as the designer); they are returned in `problems`
	//and stop publishing / running; only the workflow's own rules refuse the save
	if (!$wf->validatesDraft()) {
	    $messages = [];
	    foreach((array)$wf->errors() as $list)
		foreach((array)$list as $m)
		    $messages[] = (string)$m;
	    throw new \ErrorException("not saved:\n- " . implode("\n- ", $messages ?: [ 'invalid workflow' ]));
	}
	$wf->save();
	//CLD: 2026-10-19T17:25:00+03:00: + the errors in the steps' settings, first (WorkflowSpec::problems has the connections only)
	$stepProblems = array_map(fn($i) => array_filter([ 'level' => $i['level'], 'step' => $i['node'] ?? null, 'message' => $i['message'] ], fn($v) => $v !== null), $wf->stepIssues);
	//CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host
	return [ 'id' => (string)$wf->_id, 'name' => (string)$wf->name, 'url' => \V::app()->createAbsoluteUrl('/workflow/index', [ 'id' => (string)$wf->_id ]),
	    'status' => (string)($wf->status ?: \models\Workflow::DRAFT), 'problems' => array_merge($stepProblems, \util\WorkflowSpec::problems($wf)) ];
    }

    //runs tool $name with $args on project $project as the current user
    //CLD: 2026-10-12T22:16:44+03:00: $asUser = false: for the AI steps (a workflow's own step: no web user; the workflow decides, not a user's permissions): no permission checks,
    //runs started as trigger `ai`, no audit entry (the execution log records them)
    public static function call($name, array $args, $project, $asUser = true) {
	$aid = $project->aid;
	$user = $asUser ? \V::app()->user : null;
	$can = fn($permName, $o) => !$asUser || \V::app()->user->can($permName, [ 'object' => $o ]);
	$om = \V::app()->om;

	switch($name) {
	    case 'list_tables':
		$list = [];
		foreach($om->find('DataTableDef', [ 'aid' => $aid, 'project' => $project->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $t) {
		    if (!$can('DataTable::read', $t))
			continue;
		    //CLD: 2026-10-22T01:11:17+03:00: tableInfo (+ choices, points_to; shared with the structure tools)
		    $list[] = self::tableInfo($t);
		}
		return [ 'tables' => $list ];

	    case 'query_table':
		$t = self::table($args, $project, 'DataTable::readData', $can);
		$columns = $t->columns();
		$query = \models\DataTableDef::conditionsQuery(self::listArg($args, 'conditions'), (string)($args['match'] ?? 'all'), $columns, fn($v) => $v, $aid);
		$options = [ 'skip' => max(0, (int)($args['offset'] ?? 0)), 'limit' => max(1, min(self::MAX_ROWS, (int)($args['limit'] ?? 50))) ];
		$sortBy = trim((string)($args['sort_by'] ?? ''));
		if ($sortBy !== '') {
		    $key = $sortBy === 'id' ? '_id' : $sortBy;
		    if (!isset($columns[$sortBy]) && !in_array($key, [ '_id', 'created_at', 'updated_at' ], true))
			throw new \ErrorException("sort_by: the table has no column `{$sortBy}`");
		    $options['sort'] = [ $key => ($args['sort_dir'] ?? 'asc') === 'desc' ? -1 : 1 ];
		}
		$rows = [];
		foreach($om->find($t->iname, $query, $options) as $row)
		    $rows[] = \models\DataTableDef::rowValue($row, $columns);
		return [ 'rows' => $rows, 'count' => count($rows), 'total' => $om->count($t->iname, $query) ];

	    case 'insert_rows':
		$t = self::table($args, $project, 'DataTable::writeData', $can);
		$columns = $t->columns();
		$list = self::listArg($args, 'rows');
		if (!$list)
		    throw new \ErrorException('rows: give at least one row');
		if (count($list) > self::MAX_INSERT)
		    throw new \ErrorException('rows: at most ' . self::MAX_INSERT . ' rows at once');
		$models = [];
		foreach($list as $i => $item) {
		    $models[] = self::rowFrom($t, $columns, $item, null, 'row ' . ($i + 1) . ': ');
		}
		//CLD: 2026-10-24T09:45:36+03:00: the tier's limit of rows in a data table (util\Tiers): all of them, or none
		\util\Tiers::check($aid, 'datatable_records', count($models), $t->iname);
		$ids = [];
		foreach($models as $row) {
		    $row->save();
		    $ids[] = (string)$row->_id;
		}
		return [ 'inserted' => count($ids), 'ids' => $ids ];

	    case 'update_row':
		$t = self::table($args, $project, 'DataTable::writeData', $can);
		$columns = $t->columns();
		$row = self::rowFrom($t, $columns, $args['values'] ?? null, self::rowById($t, $args['id'] ?? ''), 'values: ');
		$row->save();
		return [ 'row' => \models\DataTableDef::rowValue($row, $columns) ];

	    case 'delete_row':
		$t = self::table($args, $project, 'DataTable::writeData', $can);
		self::rowById($t, $args['id'] ?? '')->delete();
		return [ 'deleted' => 1 ];

	    //CLD: 2026-10-22T01:11:17+03:00: the structure of the data tables
	    case 'create_table':
		$proto = $om->create('DataTableDef', [ 'aid' => $aid, 'project' => $project->_id ]);
		if (!$can('DataTable::create', $proto))
		    throw new \ErrorException('you may not create data tables in this project');
		$columns = self::listArg($args, 'columns');
		if (!$columns)
		    throw new \ErrorException('columns: give at least one column');
		if (count($columns) > self::MAX_COLUMNS)
		    throw new \ErrorException('columns: at most ' . self::MAX_COLUMNS . ' columns');
		//CLD: 2026-10-24T09:45:36+03:00: the tier's limit of data tables (util\Tiers)
		\util\Tiers::check($aid, 'datatables');
		//the next free internal name (as the data table pages)
		$n = $om->count('DataTableDef', [ 'aid' => $aid ]);
		do {
		    $iname = 'data-table-' . ++$n . '-' . $project->_id;
		} while ($om->count('DataTableDef', [ 'iname' => $iname ]) > 0);
		$t = $om->create('DataTableDef', [ 'aid' => $aid, 'name' => trim((string)($args['name'] ?? '')), 'iname' => $iname, 'project' => $project->_id ]);
		if (!$t->validates())
		    throw new \ErrorException('name: ' . self::messagesOf($t));
		$forms = [];
		foreach($columns as $i => $col) {
		    $form = self::columnForm($iname, $aid, $col, $project, 'columns[' . $i . ']: ');
		    foreach($forms as $other) {
			if ($other->key === $form->key)
			    throw new \ErrorException('columns[' . $i . "]: the key `{$form->key}` is used by another column");
		    }
		    $forms[] = $form;
		}
		$t->save();
		foreach($forms as $form)
		    \sys\fields\Field::create($form->data())->save();
		$om->registerDataTables();
		return [ 'table' => self::tableInfo($t) ];

	    case 'update_table':
		$t = self::table($args, $project, 'DataTable::update', $can);
		$t->name = trim((string)($args['name'] ?? ''));
		if (!$t->validates())
		    throw new \ErrorException('name: ' . self::messagesOf($t));
		$t->save();
		$om->registerDataTables();
		return [ 'table' => self::tableInfo($t) ];

	    case 'add_column':
		$t = self::table($args, $project, 'DataTable::update', $can);
		\sys\fields\Field::create(self::columnForm($t->iname, $aid, $args['column'] ?? null, $project, 'column: ')->data())->save();
		$om->registerDataTables();
		return [ 'table' => self::tableInfo($t) ];

	    case 'update_column':
		$t = self::table($args, $project, 'DataTable::update', $can);
		$field = self::columnOf($t, $args['key'] ?? '');
		foreach([ 'type', 'points_to', 'new_key' ] as $locked) {
		    if (array_key_exists($locked, $args))
			throw new \ErrorException("{$locked}: a column's key, type and points_to cannot be changed (add a new column instead)");
		}
		$changes = array_intersect_key($args, [ 'label' => 1, 'required' => 1, 'choices' => 1 ]);
		if (!$changes)
		    throw new \ErrorException('give what to change: label, required or choices');
		if (array_key_exists('choices', $changes))
		    $changes['choices'] = self::choicesText($changes['choices'], 'choices: ');
		if (array_key_exists('required', $changes))
		    $changes['required'] = (bool)$changes['required'];
		//an existing column: key, type and target locked (FieldForm::afterAttachSchema), as the column designer
		$form = \models\datatable\FieldForm::create($field->data(), [ 'exists' => true ]);
		$form->assign($changes, true);
		if (!$form->validates())
		    throw new \ErrorException(self::messagesOf($form, true));
		$field->attachSchema();
		$field->assign($form->data());
		$field->save();
		$om->registerDataTables();
		return [ 'table' => self::tableInfo($t) ];

	    case 'delete_column':
		$t = self::table($args, $project, 'DataTable::update', $can);
		$field = self::columnOf($t, $args['key'] ?? '');
		$key = (string)$field->key;
		$field->delete();
		//its values go from the rows (as the column designer)
		if ($om->isDefined($t->iname))
		    $om->updateMany($t->iname, [ 'aid' => $aid ], [ '$unset' => [ $key => '' ] ]);
		$om->registerDataTables();
		return [ 'deleted' => $key, 'table' => self::tableInfo($t) ];

	    case 'list_workflows':
		$list = [];
		foreach($om->find('Workflow', [ 'aid' => $aid, 'project' => $project->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $wf) {
		    if (!$can('Workflow::read', $wf))
			continue;
		    $live = $wf->liveCopy() ?: $wf;
		    $trigger = self::triggerOf($live);
		    $list[] = [ 'id' => (string)$wf->_id, 'name' => (string)$wf->name, 'status' => (string)($wf->status ?: \models\Workflow::DRAFT),
			'trigger' => $trigger['fqname'] ?? null, 'runnable' => ($trigger['fqname'] ?? '') === 'trigger::manual' ];
		}
		return [ 'workflows' => $list ];

	    case 'run_workflow':
		$wf = self::findIn('Workflow', $args['workflow'] ?? '', $project, 'workflow');
		if (!$can('Workflow::update', $wf))
		    throw new \ErrorException('you may not run this workflow');
		$live = $wf->liveCopy() ?: $wf;
		$trigger = self::triggerOf($live);
		if (($trigger['fqname'] ?? '') !== 'trigger::manual')
		    throw new \ErrorException('only workflows with a Manual trigger can be run (this one starts by ' . ($trigger['fqname'] ?? 'nothing') . ')');
		//CLD: 2026-10-19T17:10:00+03:00: issues() (was validateFlow): a draft may be saved with errors in the steps' settings
		if (array_filter($live->issues(), fn($i) => $i['level'] == 'error'))
		    throw new \ErrorException('the workflow has errors (steps\' settings or connections)');
		$vars = $args['variables'] ?? [];
		if (!is_array($vars) || ($vars && array_is_list($vars)))
		    throw new \ErrorException('variables: an object { name: value }');
		foreach(array_keys($vars) as $v) {
		    if (!\util\Validator::isIdentifier((string)$v))
			throw new \ErrorException("variables: `{$v}` is not a valid name");
		    //CLD: 2026-10-13T15:24:28+03:00: the project variables' name
		    //CLD: 2026-10-22T00:28:13+03:00: CredentialVariable::EXPRESSION_NAME (was models\Variable's)
		    if ((string)$v === \models\credential\CredentialVariable::EXPRESSION_NAME)
			throw new \ErrorException('variables: `' . \models\credential\CredentialVariable::EXPRESSION_NAME . '` is the name of the project variables');
		}
		if (self::$depth >= self::MAX_DEPTH)
		    throw new \ErrorException('workflows started from tools are nested too deep (at most ' . self::MAX_DEPTH . ')');
		//CLD: 2026-10-17T19:29:50+03:00: the caller waits for the result: a sync slot (the one held already when an AI step of a sync run calls this; none needed in the daemon)
		//CLD: 2026-10-18T16:05:00+03:00: ExecutionSlot (was WfExecution::acquireSlot / releaseSlot)
		if (!\models\ExecutionSlot::acquire($wf->aid))
		    throw new \ErrorException('too many executions are running at the same time in this account, try again later');
		try {
		    $exe = \models\WfExecution::startFor($live, $asUser ? 'mcp' : 'ai', [ 'user' => $user ? (string)$user->_id : null, 'vars' => $vars ], (string)$trigger['wfid']);
		    $exe->log(null, $asUser ? 'started by ' . (string)$user->name . ' over MCP' : 'started by an AI step');
		    $exe->save();
		    if ($asUser)
			\models\AuditLog::record('workflow.run', $wf, 'execution ' . (string)$exe->_id . ' (MCP)');
		    self::$depth++;
		    try {
			$exe->run();
		    } finally {
			self::$depth--;
		    }
		} finally {
		    //CLD: 2026-10-18T16:05:00+03:00: ExecutionSlot (was WfExecution::acquireSlot / releaseSlot)
		    \models\ExecutionSlot::release();
		}
		return [ 'execution' => (string)$exe->_id, 'state' => (string)$exe->state, 'error' => $exe->error ?: null,
		    'version' => $exe->version, 'variables' => \util\Expression::hostValue($exe->variables ?: []) ];

	    //CLD: 2026-10-13T15:17:12+03:00: building workflows (drafts)
	    case 'list_step_types':
		return [ 'step_types' => \util\WorkflowSpec::stepTypes() ];

	    case 'get_workflow':
		$wf = self::findIn('Workflow', $args['workflow'] ?? '', $project, 'workflow');
		if (!$can('Workflow::read', $wf))
		    throw new \ErrorException('you may not read this workflow');
		return \util\WorkflowSpec::toSpec($wf);

	    case 'create_workflow':
		$name = trim((string)($args['name'] ?? ''));
		if ($name === '')
		    throw new \ErrorException('name: give the workflow a name');
		$wf = $om->create('Workflow', [ 'aid' => $aid, 'project' => $project->_id, 'name' => mb_substr($name, 0, 100) ]);
		if (!$can('Workflow::create', $wf))
		    throw new \ErrorException('you may not create workflows in this project');
		if (!self::listArg($args, 'steps'))
		    throw new \ErrorException('steps: give at least the trigger');
		return self::saveSpec($wf, $args + [ 'connections' => [] ], false);

	    case 'update_workflow':
		$wf = self::findIn('Workflow', $args['workflow'] ?? '', $project, 'workflow');
		if (!$can('Workflow::update', $wf))
		    throw new \ErrorException('you may not change this workflow');
		if (array_key_exists('steps', $args) !== array_key_exists('connections', $args))
		    throw new \ErrorException('give both steps and connections (the whole new draft), or neither');
		if (isset($args['name'])) {
		    $name = trim((string)$args['name']);
		    if ($name === '')
			throw new \ErrorException('name: cannot be empty');
		    $wf->name = mb_substr($name, 0, 100);
		}
		return self::saveSpec($wf, $args, !empty($args['relayout']));

	    case 'get_execution':
		$id = (string)($args['id'] ?? '');
		$exe = \util\Validator::rule('oid', $id) ? $om->findOne('WfExecution', [ '_id' => $id, 'aid' => $aid, 'project' => $project->_id ]) : null;
		$wf = $exe ? $om->findOne('Workflow', [ '_id' => $exe->wf, 'aid' => $aid ]) : null;
		if (!$exe || !$wf || !$can('Workflow::read', $wf))
			throw new \ErrorException("no execution `{$id}` in this project");
		$log = [];
		foreach(array_slice((array)$exe->log, -self::MAX_LOG) as $line)
		    $log[] = [ 'ts' => \util\Expression::hostValue($line['ts'] ?? null), 'step' => $line['node'] ?? null, 'message' => (string)($line['message'] ?? '') ];
		return [ 'execution' => (string)$exe->_id, 'workflow' => (string)$wf->name, 'state' => (string)$exe->state, 'error' => $exe->error ?: null,
		    'trigger' => (string)$exe->trigger, 'version' => $exe->version, 'start_at' => \util\Expression::hostValue($exe->start_at),
		    'end_at' => \util\Expression::hostValue($exe->end_at), 'variables' => \util\Expression::hostValue($exe->variables ?: []), 'log' => $log ];

	    case 'list_files':
		$query = [ 'metadata.project' => $project->_id ];
		$search = trim((string)($args['search'] ?? ''));
		if ($search !== '')
		    $query['filename'] = new \MongoDB\BSON\Regex(preg_quote($search), 'i');
		$list = [];
		foreach($om->find('File', $query, [ 'sort' => [ '_id' => -1 ], 'limit' => max(1, min(200, (int)($args['limit'] ?? 50))) ]) as $f) {
		    if (($f->metadata['aid'] ?? $f->aid) != $aid || !$can('File::read', $f))
			continue;
		    $list[] = [ 'id' => (string)$f->_id, 'filename' => (string)$f->filename, 'size' => (int)$f->length, 'type' => (string)$f->type,
			'created_at' => \util\Expression::hostValue($f->created_at) ];
		}
		return [ 'files' => $list ];

	    case 'read_file':
		$id = (string)($args['id'] ?? '');
		$f = \util\Validator::rule('oid', $id) ? $om->findOne('File', [ '_id' => $id, 'metadata.project' => $project->_id ]) : null;
		if (!$f || ($f->metadata['aid'] ?? $f->aid) != $aid || !$can('File::read', $f))
		    throw new \ErrorException("no file `{$id}` in this project");
		if ((int)$f->length > self::MAX_FILE)
		    throw new \ErrorException("`{$f->filename}` is too large to read here (" . (int)$f->length . ' bytes, at most ' . self::MAX_FILE . ')');
		$content = $f->getContents();
		if (!mb_check_encoding($content, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $content))
		    throw new \ErrorException("`{$f->filename}` is not a text file");
		return [ 'id' => (string)$f->_id, 'filename' => (string)$f->filename, 'content' => $content ];

	    //CLD: 2026-10-17T21:00:54+03:00: the image itself goes as image content (IMAGES)
	    case 'read_image':
		$id = (string)($args['id'] ?? '');
		$f = \util\Validator::rule('oid', $id) ? $om->findOne('File', [ '_id' => $id, 'metadata.project' => $project->_id ]) : null;
		if (!$f || ($f->metadata['aid'] ?? $f->aid) != $aid || !$can('File::read', $f))
		    throw new \ErrorException("no file `{$id}` in this project");
		return [ 'id' => (string)$f->_id, 'filename' => (string)$f->filename, self::IMAGES => [ \models\node\NodeAppAi::fileImage($f) ] ];

	    case 'list_variables':
		$list = [];
		//CLD: 2026-10-22T00:28:13+03:00: the credentials of type Variable, with Credential::read (was models\Variable, Variable::read)
		foreach($om->find('CredentialVariable', [ 'aid' => $aid, 'project' => $project->_id ], [ 'sort' => [ 'name' => 1 ] ]) as $v) {
		    if ($can('Credential::read', $v))
			$list[] = [ 'name' => (string)$v->name, 'value' => $v->value, 'description' => (string)$v->description ];
		}
		return [ 'variables' => $list ];
	}
	throw new \ErrorException("unknown tool `{$name}`");
    }

    //the data table named / with the id in $args['table'], if the user has $permName on it ($can, see call())
    private static function table(array $args, $project, $permName, \Closure $can) {
	$t = self::findIn('DataTableDef', $args['table'] ?? '', $project, 'table');
	if (!$can($permName, $t))
	    //CLD: 2026-10-22T01:11:17+03:00: + DataTable::update: its structure
	    throw new \ErrorException('you may not ' . match($permName) { 'DataTable::readData' => "read the rows of table `{$t->name}`", 'DataTable::update' => "change table `{$t->name}`",
		default => "change the rows of table `{$t->name}`" });
	return $t;
    }

    //object $objectName of the project by id or name; \ErrorException naming $what when there is none
    private static function findIn($objectName, $ref, $project, $what) {
	$ref = trim((string)$ref);
	$query = [ 'aid' => $project->aid, 'project' => $project->_id ];
	$query += \util\Validator::rule('oid', $ref) ? [ '_id' => \V::app()->om->to_oid($ref) ] : [ 'name' => $ref ];
	$o = $ref === '' ? null : \V::app()->om->findOne($objectName, $query);
	if (!$o)
	    throw new \ErrorException("no {$what} `{$ref}` in this project");
	return $o;
    }

    //row $id of table $t
    private static function rowById($t, $id) {
	$id = trim((string)$id);
	$row = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne($t->iname, [ '_id' => $id, 'aid' => $t->aid ]) : null;
	if (!$row)
	    throw new \ErrorException("no row `{$id}` in table `{$t->name}`");
	return $row;
    }

    //$row (a new row when null) with the values of object $item, checked against the column rules
    private static function rowFrom($t, array $columns, $item, $row, $prefix) {
	if (!is_array($item) || ($item && array_is_list($item)))
	    throw new \ErrorException($prefix . 'an object { column: value }');
	foreach(array_keys($item) as $c) {
	    if (!isset($columns[$c]))
		throw new \ErrorException($prefix . "the table has no column `{$c}`");
	}
	$row = $row ?: \V::app()->om->create($t->iname, [ 'aid' => $t->aid ]);
	\models\DataTableDef::assignRow($row, $item, $prefix);
	return $row;
    }

    //CLD: 2026-10-22T01:11:17+03:00: a data table as list_tables gives it: { id, name, columns: [ { key, label, type, required, choices (select), points_to (object) } ] }
    private static function tableInfo($t) {
	$columns = [];
	foreach($t->columns() as $key => $f) {
	    $c = [ 'key' => $key, 'label' => (string)$f->label, 'type' => (string)$f->type, 'required' => !empty($f->required) ];
	    if ($f->type === 'select')
		$c['choices'] = array_values(array_map('strval', array_keys($f->choicesToArray())));
	    if ($f->type === 'object') {
		$target = \V::app()->om->findOne('DataTableDef', [ 'iname' => (string)$f->object, 'aid' => $t->aid ]);
		$c['points_to'] = $target ? (string)$target->name : (string)$f->object;
	    }
	    $columns[] = $c;
	}
	return [ 'id' => (string)$t->_id, 'name' => (string)$t->name, 'columns' => $columns ];
    }

    //CLD: 2026-10-22T01:11:17+03:00: the column `key` of table $t (its Field)
    private static function columnOf($t, $key) {
	$key = trim((string)$key);
	$field = $key === '' ? null : \V::app()->om->findOne('Field', [ 'objectName' => $t->iname, 'key' => $key ]);
	if (!$field)
	    throw new \ErrorException("table `{$t->name}` has no column `{$key}`");
	return $field;
    }

    //CLD: 2026-10-22T01:11:17+03:00: a new column of table $iname from $col ({ key, label, type, required, choices, points_to }), checked by the column designer's rules (models\datatable\FieldForm):
    //the form, ready to save (Field::create($form->data())); \ErrorException starting with $prefix when refused
    private static function columnForm($iname, $aid, $col, $project, $prefix) {
	if (!is_array($col) || ($col && array_is_list($col)))
	    throw new \ErrorException($prefix . 'an object { key, label, type, required, choices, points_to }');
	foreach(array_keys($col) as $k) {
	    if (!in_array($k, [ 'key', 'label', 'type', 'required', 'choices', 'points_to' ], true))
		throw new \ErrorException($prefix . "unknown setting `{$k}` (key, label, type, required, choices, points_to)");
	}
	$key = trim((string)($col['key'] ?? ''));
	if (in_array($key, self::KEYS_TAKEN, true))
	    throw new \ErrorException($prefix . "the key `{$key}` is taken (the row id)");
	$type = (string)($col['type'] ?? 'text');
	$values = [ 'key' => $key, 'label' => trim((string)($col['label'] ?? '')) ?: $key, 'type' => $type, 'required' => !empty($col['required']) ];
	if ($type === 'select' || array_key_exists('choices', $col))
	    $values['choices'] = self::choicesText($col['choices'] ?? [], $prefix . 'choices: ');
	if ($type === 'object' || array_key_exists('points_to', $col))
	    $values['object'] = self::linkTarget($col['points_to'] ?? '', $project, $prefix);
	$form = \models\datatable\FieldForm::create([ 'objectName' => $iname, 'aid' => $aid ]);
	$form->assign($values, true);
	if (!$form->validates())
	    throw new \ErrorException($prefix . self::messagesOf($form, true));
	return $form;
    }

    //CLD: 2026-10-22T01:11:17+03:00: choices as the column keeps them: one per line (a list, or text with one per line)
    private static function choicesText($choices, $prefix) {
	if (is_string($choices))
	    $choices = preg_split('/\R/', $choices);
	if (!is_array($choices))
	    throw new \ErrorException($prefix . 'a list of values');
	$list = [];
	foreach($choices as $c) {
	    if (is_array($c) || is_object($c))
		throw new \ErrorException($prefix . 'values as text');
	    $c = trim(str_replace([ "\r", "\n" ], ' ', (string)$c));
	    if ($c !== '' && !in_array($c, $list, true))
		$list[] = $c;
	}
	return implode("\n", $list);
    }

    //CLD: 2026-10-22T01:11:17+03:00: the objects (besides the data tables) a column may point to: [ objectName => label ]
    private static function linkTargets() {
	$om = \V::app()->om;
	$targets = [];
	foreach($om->getSelectableObjects() as $name => $label) {
	    $def = $om->getObjectDef($name);
	    if (empty($def['_id']))
		$targets[$name] = $label;
	}
	return $targets;
    }

    //CLD: 2026-10-22T01:11:17+03:00: what an object column points to: a data table of the project (name or id: its internal name), or one of linkTargets(); '' when not given (FieldForm refuses it)
    private static function linkTarget($ref, $project, $prefix) {
	$ref = trim((string)$ref);
	if ($ref === '')
	    return '';
	if (isset(self::linkTargets()[$ref]))
	    return $ref;
	$query = [ 'aid' => $project->aid, 'project' => $project->_id ];
	$query += \util\Validator::rule('oid', $ref) ? [ '_id' => \V::app()->om->to_oid($ref) ] : [ 'name' => $ref ];
	$t = \V::app()->om->findOne('DataTableDef', $query);
	if (!$t)
	    throw new \ErrorException($prefix . "points_to: no data table `{$ref}` in this project" . (self::linkTargets() ? ' (or one of: ' . implode(', ', array_keys(self::linkTargets())) . ')' : ''));
	return (string)$t->iname;
    }

    //CLD: 2026-10-22T01:11:17+03:00: the validation messages of $model in one line ($labels: "<field label>: <message>")
    private static function messagesOf($model, $labels = false) {
	$messages = [];
	foreach((array)$model->errors() as $key => $list) {
	    foreach((array)$list as $m)
		$messages[] = ($labels && isset($model->schema[$key]) ? (string)$model->schema[$key]->label . ': ' : '') . (string)$m;
	}
	return implode('; ', $messages ?: [ 'refused' ]);
    }

    //$args[$key] as a list (missing: empty)
    private static function listArg(array $args, $key) {
	$v = $args[$key] ?? [];
	if (!is_array($v) || ($v && !array_is_list($v)))
	    throw new \ErrorException("{$key}: a list");
	return $v;
    }

    //CLD: 2026-10-17T21:00:54+03:00: a tool result split: [ the result without its images, the images: [ [ mimeType, data ] ] ]
    public static function images(array $result) {
	$images = (array)($result[self::IMAGES] ?? []);
	unset($result[self::IMAGES]);
	return [ $result, $images ];
    }

    //the workflow's trigger step (a workflow has one), or null
    private static function triggerOf($wf) {
	foreach((is_array($wf->nodes) ? $wf->nodes : []) as $n) {
	    if (!empty($n['isNode']) && str_starts_with((string)($n['fqname'] ?? ''), 'trigger::'))
		return $n;
	}
	return null;
    }
}
