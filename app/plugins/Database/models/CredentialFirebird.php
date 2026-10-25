<?php

//CLD: 2026-10-25T17:40:00+02:00: new: a Firebird server (pdo_firebird), for the Database step: the database's path or alias on the server, in UTF-8. The server is required: the
//client library would otherwise open a database file of this machine itself. No statement time limit of its own

namespace plugins\Database\models;

class CredentialFirebird extends CredentialDatabase {
    public static $label = 'Firebird';

    const TYPE = 'firebird';
    const PORT = 3050;
    const TEST_SQL = 'SELECT 1 FROM RDB$DATABASE';

    public function getSchema() {
	return array_merge(parent::getSchema(), $this->serverSchema([ 'Database', true, 'Its path or alias on the server', '/data/sales.fdb' ]));
    }

    protected function dsn($driver, $timeout) {
	$host = $this->dsnValue('host');
	if ($host === '')
	    throw new \RuntimeException('the server is needed');
	return [ "firebird:dbname={$host}/" . $this->port() . ':' . $this->dsnValue('database') . ';charset=UTF8', [] ];
    }
}
