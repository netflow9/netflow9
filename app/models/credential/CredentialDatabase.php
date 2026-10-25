<?php

//CLD: 2026-10-25T20:00:00+02:00: moved from the Database plugin to the core (the Database step and its credentials are core, for every account: no plan feature): models\credential (was plugins\Database\models); CREDENTIALS: the types (were plugins\Database\Plugin::CREDENTIALS)

/*
    CLD: 2026-10-25T17:40:00+02:00: new: what the database credentials share (one per database type: CredentialMysql, CredentialPostgres, CredentialMssql, CredentialOracle,
    CredentialFirebird, CredentialOdbc; group Database, provide `database`): the connection through PDO, the connection test, the server's fields.
    A type is offered (available()) only when this PHP has its PDO driver (util\SqlDb::TYPES); without it the credential stays, its use says which extension is missing.
    The values of the fields become the DSN: they cannot hold ; { } or line breaks (they would add DSN settings)
//  $cred->connect($timeout)        a PDO (errors as exceptions, rows as arrays); $timeout: seconds to connect and, where the database has one, of each statement
//  $cred->driver()                 the PDO driver used (util\SqlDb::driver)
//  $cred->test()                   connects and runs TEST_SQL
*/

namespace models\credential;

use util\SqlDb;

abstract class CredentialDatabase extends \models\Credential {
    public static $icon = 'bi-database';
    public static $group = 'Database';
    public static $provides = [ 'database' ];
    public static $testable = true;

    //the database credentials: their object names (data\SysObjects)
    const CREDENTIALS = [ 'CredentialMysql', 'CredentialPostgres', 'CredentialMssql', 'CredentialOracle', 'CredentialFirebird', 'CredentialOdbc' ];

    //the key of util\SqlDb::TYPES
    const TYPE = '';
    //the port when none is given (null: the driver's own)
    const PORT = null;
    //what test() runs once connected ('': connecting is the test)
    const TEST_SQL = 'SELECT 1';
    //a DSN value: no ; { } (they would add or quote DSN settings), no control characters
    const DSN_VALUE = '^[^;{}\x00-\x1f]*$';
    const HOST = '^[A-Za-z0-9.\-:\[\]]+$';

    public static function available() {
	return SqlDb::driver(static::TYPE) !== null;
    }

    //[ dsn, PDO options ] for $driver
    abstract protected function dsn($driver, $timeout);

    //after connecting: the statement time limit, where the database has one
    protected function afterConnect(\PDO $pdo, $driver, $timeout) {
    }

    public function driver() {
	return SqlDb::driver(static::TYPE);
    }

    public function connect($timeout = 30) {
	$driver = $this->driver();
	if ($driver === null)
	    throw new \RuntimeException(SqlDb::missing(static::TYPE));
	[ $dsn, $options ] = $this->dsn($driver, max(1, (int)$timeout));
	$pdo = new \PDO($dsn, (string)$this->username !== '' ? (string)$this->username : null, (string)$this->password !== '' ? (string)$this->password : null, $options + [
	    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
	    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
	    \PDO::ATTR_STRINGIFY_FETCHES => false,
	]);
	$this->afterConnect($pdo, $driver, max(1, (int)$timeout));
	return $pdo;
    }

    //where it connects, for messages
    public function describe() {
	return trim((string)$this->host . ((string)$this->database !== '' ? '/' . $this->database : ''), '/');
    }

    public function test() {
	$pdo = $this->connect(20);
	if (static::TEST_SQL !== '')
	    $pdo->query(static::TEST_SQL)->fetchAll();
	try {
	    $version = (string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
	} catch (\PDOException $e) {
	    $version = '';
	}
	return 'connected to ' . $this->describe() . ' (' . static::$label . ($version !== '' ? ' ' . mb_substr($version, 0, 60) : '') . ', driver ' . $this->driver() . ')';
    }

    //the fields of a database server: host, port, database, username, password; $database: [ label, required, help, placeholder ]
    protected function serverSchema(array $database, $hostPattern = self::HOST) {
	return [
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => $hostPattern, 'skipEmpty' => true, 'message' => 'A host name or IP address' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'db.example.com' ],
	    ],
	    [
		'key' => 'port',
		'type' => 'number',
		'label' => 'Port',
		'default' => null,
		'help' => static::PORT ? 'Empty: ' . static::PORT : false,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ] ],
		'ui' => [ 'width' => 4, 'step' => 1 ],
	    ],
	    [
		'key' => 'database',
		'type' => 'text',
		'label' => $database[0],
		'required' => $database[1],
		'default' => '',
		'transform' => 'trim',
		'help' => $database[2],
		'validate' => [ [ 'regex', 'pattern' => self::DSN_VALUE, 'skipEmpty' => true, 'message' => 'Without ; { } or line breaks' ] ],
		'ui' => [ 'placeholder' => $database[3] ?? '' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    protected function port() {
	return (int)$this->port ?: static::PORT;
    }

    //a field's DSN value (validated by the form; checked again: a stored credential may predate a rule)
    protected function dsnValue($key) {
	$v = trim((string)$this->{$key});
	if (preg_match('/' . self::DSN_VALUE . '/', $v) !== 1)
	    throw new \RuntimeException("the credential's {$key} cannot hold ; { } or line breaks");
	return $v;
    }
}
