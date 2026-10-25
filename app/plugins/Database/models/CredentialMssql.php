<?php

//CLD: 2026-10-25T17:40:00+02:00: new: a Microsoft SQL Server (pdo_sqlsrv, Microsoft's driver; else pdo_dblib, FreeTDS), for the Database step. The server may name an instance
//(sql.example.com\SQLEXPRESS). Encryption: sqlsrv's Encrypt / TrustServerCertificate (dblib: FreeTDS's own settings, freetds.conf). Statement time limit: sqlsrv's query timeout

namespace plugins\Database\models;

class CredentialMssql extends CredentialDatabase {
    public static $label = 'Microsoft SQL Server';

    const TYPE = 'mssql';
    const PORT = 1433;
    const HOST_INSTANCE = '^[A-Za-z0-9.\-:\[\]]+(\\\\[A-Za-z0-9_$#\-]+)?$';

    public function getSchema() {
	$fields = $this->serverSchema([ 'Database', false, 'Empty: the user\'s default database', 'Sales' ], self::HOST_INSTANCE);
	$fields[0]['ui']['placeholder'] = 'sql.example.com or sql.example.com\SQLEXPRESS';
	$fields[1]['help'] = 'Empty: 1433, or the named instance\'s';
	return array_merge(parent::getSchema(), $fields, [
	    [
		'key' => 'encrypt',
		'type' => 'switch',
		'label' => 'Encrypt the connection',
		'default' => true,
		'help' => 'pdo_sqlsrv; pdo_dblib: as set in freetds.conf',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'Only for a self-signed certificate',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    protected function dsn($driver, $timeout) {
	$host = $this->dsnValue('host');
	$named = str_contains($host, '\\');
	$db = $this->dsnValue('database');
	if ($driver === 'sqlsrv') {
	    $server = $named && !(int)$this->port ? $host : $host . ',' . $this->port();
	    return [ "sqlsrv:Server={$server}" . ($db !== '' ? ";Database={$db}" : '') . ';Encrypt=' . ($this->encrypt !== false ? 'yes' : 'no')
		. ';TrustServerCertificate=' . ($this->trustCertificate ? 'yes' : 'no') . ";LoginTimeout={$timeout}", [] ];
	}
	//dblib (FreeTDS): host:port, or the named instance
	return [ 'dblib:host=' . ($named && !(int)$this->port ? $host : $host . ':' . $this->port()) . ($db !== '' ? ";dbname={$db}" : '') . ';charset=UTF-8', [ \PDO::ATTR_TIMEOUT => $timeout ] ];
    }

    protected function afterConnect(\PDO $pdo, $driver, $timeout) {
	if ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'))
	    $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), (int)$timeout);
    }
}
