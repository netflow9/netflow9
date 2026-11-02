<?php

//CLD: 2026-10-25T20:00:00+02:00: moved from the Database plugin to the core (the Database step and its credentials are core, for every account: no plan feature): models\node (was plugins\Database\models)

//CLD: 2026-10-25T17:40:00+02:00: new step: Database - runs SQL on a database through a database credential of the project (CredentialMysql, CredentialPostgres, CredentialMssql,
//CredentialOracle, CredentialFirebird, CredentialOdbc; provide `database`). Operations (setting `operation`):
//	query       a SELECT (or anything giving rows): { rows: [ { column: value } ], count, columns, truncated }, at most `maxRows` rows
//	execute     an INSERT / UPDATE / DELETE / DDL / procedure call: { affected, lastInsertId } (lastInsertId: null where the database has none)
//	insert      the rows of a list of objects (keys: the columns) into `table`, in one transaction: { affected }
//  A {{ }} in the SQL is sent as a parameter of the statement (WHERE id = {{ order.id }}), never as SQL text: it cannot be a table or column name (util\SqlDb::sql).
//  Values read: large objects as their content, binary as base64. One statement per step.
//  On error (the database's, no connection, the driver missing in this PHP): fail the step, or continue on the `error` output ({ error: the message } in the variable)

//CLD: 2026-10-31T23:30:00+02:00: split into one step per database type (NodeAppMysql, NodeAppPostgres, NodeAppMssql, NodeAppOracle, NodeAppFirebird, NodeAppOdbc): this class is
//their base (abstract, not registered). Each subclass names its util\SqlDb type (TYPE): its credentials are those providing `database:<TYPE>` (one credential class
//each), it is offered when this PHP has that type's PDO driver; settings, operations and running are the same for all (here)

namespace models\node;

use util\SqlDb;

abstract class NodeAppDatabase extends \models\wf\WfNode {
    //CLD: 2026-11-01T21:50:00+02:00: its operations can be an AI step's tools (util\AgentTools: varsForSettings, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-database';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: an advanced step, in Developer
    //CLD: 2026-10-31T23:30:00+02:00: in their own group, Databases
    public static $audience = 'advanced';
    public static $category = 'database';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [
	'query' => 'Query rows',
	'execute' => 'Execute a statement',
	'insert' => 'Insert rows',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something (a query too: its SQL may change data); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'query', 'execute', 'insert' ];
    const MAX_ROWS = 10000;
    const MAX_INSERT = 10000;
    const MAX_TIMEOUT = 600;

    //CLD: 2026-10-31T23:30:00+02:00: the key of util\SqlDb::TYPES; the subclass's
    const TYPE = '';

    //CLD: 2026-10-31T23:30:00+02:00: what the step's credentials provide (models\credential\CredentialDatabase::provides)
    public static function capability() {
	return 'database:' . static::TYPE;
    }

    //CLD: 2026-10-31T23:30:00+02:00: this PHP has the PDO driver of the step's type (was: of some type)
    public static function available() {
	return SqlDb::driver(static::TYPE) !== null;
    }

    public static function configSchema() {
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];
	$choices = [];
	foreach(self::credentialChoices() as $c)
	    $choices[$c['value']] = $c['label'];
	return [
	    [
		'key' => 'credential',
		'type' => 'select',
		//CLD: 2026-10-31T23:30:00+02:00: the credentials of the step's type
		'label' => static::$label . ' credential',
		'choices' => $choices,
		'help' => 'A ' . static::$label . ' credential of the project (menu: Credentials)',
		'required' => true,
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
		'key' => 'sql',
		'type' => 'textarea',
		'label' => 'SQL',
		'default' => '',
		'help' => 'One statement. A {{ }} is sent as a parameter: WHERE id = {{ order.id }} (not for table or column names)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'query', 'execute' ] ],
		'ui' => [ 'rows' => 6, 'expression' => 'text', 'code' => true, 'placeholder' => 'SELECT id, name FROM customers WHERE country = {{ country }}', 'showIf' => $op([ 'query', 'execute' ]) ],
	    ],
	    [
		'key' => 'table',
		'type' => 'text',
		'label' => 'Table',
		'default' => '',
		'help' => 'A table, or schema.table',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'insert' ] ],
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z_][A-Za-z0-9_$#]*(\.[A-Za-z_][A-Za-z0-9_$#]*){0,2}$', 'skipEmpty' => true, 'message' => 'A table name: letters, digits, _ $ #' ] ],
		'ui' => [ 'width' => 4, 'placeholder' => 'orders', 'showIf' => $op([ 'insert' ]) ],
	    ],
	    [
		'key' => 'rows',
		'type' => 'textarea',
		'label' => 'Rows',
		'default' => '',
		'help' => 'An expression giving a list of objects keyed by column, e.g. {{ response.body.items }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'insert' ] ],
		//CLD: 2026-11-01T21:50:00+02:00: + agent: as an AI step's tool, a list (util\AgentTools)
		'ui' => [ 'rows' => 3, 'width' => 8, 'expression' => true, 'showIf' => $op([ 'insert' ]), 'agent' => [ 'type' => 'list', 'help' => 'A list of objects keyed by column' ] ],
	    ],
	    [
		'key' => 'maxRows',
		'type' => 'number',
		'label' => 'At most (rows)',
		'required' => true,
		'default' => 1000,
		'help' => 'More are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ROWS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ROWS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => $op([ 'query' ]) ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 60,
		'help' => 'To connect, and of the statement where the database has a limit',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'db',
		'help' => '{ rows, count, ... } or { affected }',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'Database errors, or no connection',
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //the project's database credentials, for the designer
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor(static::capability(), \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    //the credential: of the workflow's project, a database
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	//CLD: 2026-10-31T23:30:00+02:00: a credential of the step's type
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::capability())))) {
	    $this->errors('config', static::$label . ': choose a ' . static::$label . ' credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'db') : 'db';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$error = [ 'error' => [ 'type' => 'string', 'info' => 'on error only' ] ];
	$out = match(is_array($config) ? ($config['operation'] ?? 'query') : 'query') {
	    'execute' => [ 'type' => 'object', 'info' => '{ affected, lastInsertId }', 'fields' => [ 'affected' => [ 'type' => 'number', 'info' => 'the rows changed' ],
		'lastInsertId' => [ 'type' => 'string', 'info' => 'the id of the row inserted, null where the database has none' ] ] ],
	    'insert' => [ 'type' => 'object', 'info' => '{ affected }', 'fields' => [ 'affected' => [ 'type' => 'number', 'info' => 'the rows inserted' ] ] ],
	    default => [ 'type' => 'object', 'info' => 'the rows: { rows, count, columns, truncated }', 'fields' => [
		'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a row: { column: value }' ] ], 'count' => [ 'type' => 'number' ],
		'columns' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the column names' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more rows than At most' ] ] ],
	};
	$out['fields'] += $error;
	return [ $name => $out ];
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$operation = (string)$this->cfg('operation', 'query');
	if (!isset(self::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	//CLD: 2026-10-31T23:30:00+02:00: a credential of the step's type; the messages and the log name the step's type
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::capability());
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');

	$name = (string)$this->cfg('variable', 'db');
	try {
	    $start = microtime(true);
	    $pdo = $cred->connect(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));
	    //CLD: 2026-11-01T21:50:00+02:00: varsForSettings() (none when the step runs as a tool: a {{ }} in the model's SQL reaches no variable)
	    [ $result, $summary ] = self::run($operation, $pdo, $cred->driver(), (array)$this->config, $this->varsForSettings());
	} catch (\RuntimeException $e) {
	    //PDOException is one; the message in the variable (for the `error` output)
	    $this->setResult($name, [ 'error' => $e->getMessage() ]);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	} finally {
	    $pdo = null;
	}
	//CLD: 2026-11-01T21:50:00+02:00: setResult() (a tool keeps it; was the variable only)
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation} on " . $cred->describe() . ": {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //$operation on $pdo ($driver: its PDO driver) with the step's settings $cfg: [ the result, a summary for the log ]; errors: RuntimeException (PDOException)
    public static function run($operation, \PDO $pdo, $driver, array $cfg, array $vars) {
	switch($operation) {
	    case 'query':
		[ $sql, $values ] = self::statement($cfg, $vars);
		$stmt = $pdo->prepare($sql);
		SqlDb::bind($stmt, $values, $driver);
		$stmt->execute();
		$max = max(1, min(self::MAX_ROWS, (int)($cfg['maxRows'] ?? 1000)));
		$rows = [];
		$truncated = false;
		while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
		    if (count($rows) >= $max) {
			$truncated = true;
			break;
		    }
		    $rows[] = array_map([ SqlDb::class, 'plain' ], $row);
		}
		$columns = [];
		for($i = 0; $i < $stmt->columnCount(); $i++) {
		    try {
			$meta = $stmt->getColumnMeta($i);
		    } catch (\PDOException | \Error $e) {
			$meta = false;
		    }
		    if (!is_array($meta) || !isset($meta['name'])) {
			$columns = $rows ? array_keys($rows[0]) : [];
			break;
		    }
		    $columns[] = (string)$meta['name'];
		}
		$stmt->closeCursor();
		return [ [ 'rows' => $rows, 'count' => count($rows), 'columns' => $columns, 'truncated' => $truncated ],
		    \util\Format::plural(count($rows), 'row') . ($truncated ? ' (more matched: truncated)' : '') ];
	    case 'execute':
		[ $sql, $values ] = self::statement($cfg, $vars);
		$stmt = $pdo->prepare($sql);
		SqlDb::bind($stmt, $values, $driver);
		$stmt->execute();
		$affected = $stmt->rowCount();
		$stmt->closeCursor();
		try {
		    $id = $pdo->lastInsertId();
		} catch (\PDOException | \Error $e) {
		    //PostgreSQL without a sequence used, Oracle, ...
		    $id = false;
		}
		$id = $id === false || $id === '' || $id === '0' ? null : (string)$id;
		return [ [ 'affected' => $affected, 'lastInsertId' => $id ], \util\Format::plural(max(0, $affected), 'row') . ' changed' . ($id !== null ? ", last id {$id}" : '') ];
	    case 'insert':
		$table = trim((string)($cfg['table'] ?? ''));
		if (!SqlDb::isIdentifier($table, 3))
		    throw new \RuntimeException("not a table name: `{$table}`");
		//CLD: 2026-11-01T21:50:00+02:00: a list as it is (an AI step's tool: the model's argument); the setting's text rendered (was always rendered)
		$list = is_array($cfg['rows'] ?? null) ? $cfg['rows'] : \util\Expression::render((string)($cfg['rows'] ?? ''), $vars);
		if ($list === null || $list === '' || $list === [])
		    return [ [ 'affected' => 0 ], 'no rows to insert' ];
		if (!is_array($list) || !array_is_list($list))
		    throw new \RuntimeException('Rows: give a list of objects');
		if (count($list) > self::MAX_INSERT)
		    throw new \RuntimeException('Rows: ' . count($list) . ' rows, at most ' . self::MAX_INSERT . ' in one step');
		//one statement per set of columns
		$statements = [];
		$pdo->beginTransaction();
		try {
		    foreach($list as $i => $row) {
			if (!is_array($row) || array_is_list($row) || !$row)
			    throw new \RuntimeException('Rows: row ' . ($i + 1) . ' is not an object of columns');
			foreach(array_keys($row) as $column) {
			    if (!SqlDb::isIdentifier($column))
				throw new \RuntimeException('Rows: row ' . ($i + 1) . ": not a column name: `{$column}`");
			}
			$key = implode(',', array_keys($row));
			$statements[$key] ??= $pdo->prepare("INSERT INTO {$table} ({$key}) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')');
			SqlDb::bind($statements[$key], array_values($row), $driver);
			$statements[$key]->execute();
		    }
		    $pdo->commit();
		} catch (\Throwable $e) {
		    if ($pdo->inTransaction())
			$pdo->rollBack();
		    throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage());
		}
		return [ [ 'affected' => count($list) ], \util\Format::plural(count($list), 'row') . " inserted into {$table}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the SQL setting: [ SQL with ?, the values of its {{ }} ]
    protected static function statement(array $cfg, array $vars) {
	[ $sql, $values ] = SqlDb::sql((string)($cfg['sql'] ?? ''), $vars);
	if ($sql === '')
	    throw new \RuntimeException('SQL: empty');
	return [ $sql, $values ];
    }
}
