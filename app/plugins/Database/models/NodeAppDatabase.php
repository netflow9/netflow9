<?php

//CLD: 2026-10-25T17:40:00+02:00: new step: Database - runs SQL on a database through a database credential of the project (CredentialMysql, CredentialPostgres, CredentialMssql,
//CredentialOracle, CredentialFirebird, CredentialOdbc; provide `database`). Operations (setting `operation`):
//	query       a SELECT (or anything giving rows): { rows: [ { column: value } ], count, columns, truncated }, at most `maxRows` rows
//	execute     an INSERT / UPDATE / DELETE / DDL / procedure call: { affected, lastInsertId } (lastInsertId: null where the database has none)
//	insert      the rows of a list of objects (keys: the columns) into `table`, in one transaction: { affected }
//  A {{ }} in the SQL is sent as a parameter of the statement (WHERE id = {{ order.id }}), never as SQL text: it cannot be a table or column name (util\Db::sql).
//  Values read: large objects as their content, binary as base64. One statement per step.
//  On error (the database's, no connection, the driver missing in this PHP): fail the step, or continue on the `error` output ({ error: the message } in the variable)

namespace plugins\Database\models;

use plugins\Database\util\Db;

class NodeAppDatabase extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'database';
    public static $label = 'Database';
    public static $icon = 'bi-database';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [
	'query' => 'Query rows',
	'execute' => 'Execute a statement',
	'insert' => 'Insert rows',
    ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const MAX_ROWS = 10000;
    const MAX_INSERT = 10000;
    const MAX_TIMEOUT = 600;

    //some database type of the plugin has its PDO driver in this PHP
    public static function available() {
	foreach(array_keys(Db::TYPES) as $type) {
	    if (Db::driver($type) !== null)
		return true;
	}
	return false;
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
		'label' => 'Database',
		'choices' => $choices,
		'help' => 'A database credential of the project (menu: Credentials)',
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
		'ui' => [ 'rows' => 3, 'width' => 8, 'expression' => true, 'showIf' => $op([ 'insert' ]) ],
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
	return \models\Credential::choicesFor('database', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    //the credential: of the workflow's project, a database
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'database')))) {
	    $this->errors('config', 'Database: choose a database credential of this project');
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
	    throw new \ErrorException("Database: unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'database');
	if (empty($cred))
	    throw new \ErrorException('Database: the credential is no longer available');

	$name = (string)$this->cfg('variable', 'db');
	try {
	    $start = microtime(true);
	    $pdo = $cred->connect(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));
	    [ $result, $summary ] = self::run($operation, $pdo, $cred->driver(), (array)$this->config, $this->exec->expressionVariables());
	} catch (\RuntimeException $e) {
	    //PDOException is one; the message in the variable (for the `error` output)
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, [ 'error' => $e->getMessage() ]);
	    $this->exec->log($this->wfid, "Database {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException("Database: {$operation}: " . $e->getMessage());
	} finally {
	    $pdo = null;
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$this->exec->log($this->wfid, "Database {$operation} on " . $cred->describe() . ": {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //$operation on $pdo ($driver: its PDO driver) with the step's settings $cfg: [ the result, a summary for the log ]; errors: RuntimeException (PDOException)
    public static function run($operation, \PDO $pdo, $driver, array $cfg, array $vars) {
	switch($operation) {
	    case 'query':
		[ $sql, $values ] = self::statement($cfg, $vars);
		$stmt = $pdo->prepare($sql);
		Db::bind($stmt, $values, $driver);
		$stmt->execute();
		$max = max(1, min(self::MAX_ROWS, (int)($cfg['maxRows'] ?? 1000)));
		$rows = [];
		$truncated = false;
		while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
		    if (count($rows) >= $max) {
			$truncated = true;
			break;
		    }
		    $rows[] = array_map([ Db::class, 'plain' ], $row);
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
		Db::bind($stmt, $values, $driver);
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
		if (!Db::isIdentifier($table, 3))
		    throw new \RuntimeException("not a table name: `{$table}`");
		$list = \util\Expression::render((string)($cfg['rows'] ?? ''), $vars);
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
			    if (!Db::isIdentifier($column))
				throw new \RuntimeException('Rows: row ' . ($i + 1) . ": not a column name: `{$column}`");
			}
			$key = implode(',', array_keys($row));
			$statements[$key] ??= $pdo->prepare("INSERT INTO {$table} ({$key}) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')');
			Db::bind($statements[$key], array_values($row), $driver);
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
	[ $sql, $values ] = Db::sql((string)($cfg['sql'] ?? ''), $vars);
	if ($sql === '')
	    throw new \RuntimeException('SQL: empty');
	return [ $sql, $values ];
    }
}
