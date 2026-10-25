<?php

//CLD: 2026-10-25T20:00:00+02:00: moved from the Database plugin to the core (the Database step and its credentials are core, for every account: no plan feature): util\SqlDb (was plugins\Database\util\Db)

/*
    CLD: 2026-10-25T17:40:00+02:00: new: what the database credentials and the Database step share: the database types and their PDO drivers, the SQL of a step ({{ }} values sent
    as parameters), the values bound and read back
//  SqlDb::TYPES                       type => [ label, drivers: [ PDO driver => its PHP extension ] (the first loaded one is used) ]
//  SqlDb::driver($type)               the PDO driver of $type this PHP has, or null
//  SqlDb::missing($type)              the message when it has none ("needs the PHP extension pdo_pgsql")
//  SqlDb::sql($template, $vars)       [ SQL with ? in place of each {{ }} (or '{{ }}'), the values of the {{ }} in order ]
//  SqlDb::bind($stmt, $values, $driver)   the values bound to the ? (null, integers, booleans, text; lists / objects as JSON)
//  SqlDb::plain($value)               a value read from the database, as a workflow variable (large objects read, binary as base64)
//  SqlDb::isIdentifier($name, $parts) a table (schema.table: $parts 3) or column name: letters, digits, _ $ #, not starting with a digit; used in SQL as it is (not quoted)
*/

namespace util;

class SqlDb {

    const TYPES = [
	'mysql' => [ 'label' => 'MySQL / MariaDB', 'drivers' => [ 'mysql' => 'pdo_mysql' ] ],
	'pgsql' => [ 'label' => 'PostgreSQL', 'drivers' => [ 'pgsql' => 'pdo_pgsql' ] ],
	'mssql' => [ 'label' => 'Microsoft SQL Server', 'drivers' => [ 'sqlsrv' => 'pdo_sqlsrv', 'dblib' => 'pdo_dblib' ] ],
	'oracle' => [ 'label' => 'Oracle', 'drivers' => [ 'oci' => 'pdo_oci' ] ],
	'firebird' => [ 'label' => 'Firebird', 'drivers' => [ 'firebird' => 'pdo_firebird' ] ],
	'odbc' => [ 'label' => 'ODBC', 'drivers' => [ 'odbc' => 'pdo_odbc' ] ],
    ];

    //a {{ }} of a step's SQL (as util\Expression's templates); one in single quotes ('{{ name }}', as one would write a text) is the same parameter, quotes included
    const PLACEHOLDER = '/\'\{\{((?:(?!\}\}).)*)\}\}\'|\{\{(.*?)\}\}/s';

    public static function driver($type) {
	$loaded = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
	foreach(array_keys(self::TYPES[$type]['drivers'] ?? []) as $driver) {
	    if (in_array($driver, $loaded, true))
		return $driver;
	}
	return null;
    }

    public static function missing($type) {
	$def = self::TYPES[$type];
	return "{$def['label']} needs the PHP extension " . implode(' or ', $def['drivers']) . ' (not installed in this PHP' . (PHP_SAPI === 'cli' ? ': the console PHP, which runs the daemon' : '') . ')';
    }

    //the {{ }} of $template replaced by ? and their values computed: a value is never part of the SQL text
    public static function sql($template, array $vars) {
	$values = [];
	$sql = preg_replace_callback(self::PLACEHOLDER, function($m) use (&$values, $vars) {
	    //$m[1]: the one in quotes, $m[2]: a bare one
	    $values[] = \util\Expression::render('{{' . (isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1]) . '}}', $vars);
	    return '?';
	}, (string)$template);
	return [ trim($sql), $values ];
    }

    public static function bind(\PDOStatement $stmt, array $values, $driver) {
	foreach(array_values($values) as $i => $v) {
	    if ($v === null)
		$stmt->bindValue($i + 1, null, \PDO::PARAM_NULL);
	    elseif (is_bool($v))
		//PostgreSQL has booleans; the others take 1 / 0
		$driver === 'pgsql' ? $stmt->bindValue($i + 1, $v, \PDO::PARAM_BOOL) : $stmt->bindValue($i + 1, $v ? 1 : 0, \PDO::PARAM_INT);
	    elseif (is_int($v))
		$stmt->bindValue($i + 1, $v, \PDO::PARAM_INT);
	    elseif ($v instanceof \DateTimeInterface)
		$stmt->bindValue($i + 1, $v->format('Y-m-d H:i:s'));
	    else
		$stmt->bindValue($i + 1, \util\Expression::toText($v));
	}
    }

    public static function plain($v) {
	if (is_resource($v))
	    $v = stream_get_contents($v);
	if (is_string($v) && !mb_check_encoding($v, 'UTF-8'))
	    return base64_encode($v);
	return $v;
    }

    public static function isIdentifier($name, $parts = 1) {
	return preg_match('/^[A-Za-z_][A-Za-z0-9_$#]*(\.[A-Za-z_][A-Za-z0-9_$#]*){0,' . max(0, $parts - 1) . '}$/', (string)$name) === 1;
    }
}
