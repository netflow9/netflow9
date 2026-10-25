<?php

//CLD: 2026-10-25T17:40:00+02:00: new: an Oracle database (pdo_oci, with Oracle's client libraries), for the Database step: //host:port/service name (Easy Connect), in UTF-8.
//No statement time limit of its own (the database's profile limits apply)

namespace models\credential;

class CredentialOracle extends CredentialDatabase {
    public static $label = 'Oracle';

    const TYPE = 'oracle';
    const PORT = 1521;
    const TEST_SQL = 'SELECT 1 FROM DUAL';

    public function getSchema() {
	return array_merge(parent::getSchema(), $this->serverSchema([ 'Service name', true, 'The database\'s service, e.g. ORCLPDB1 or XEPDB1', 'ORCLPDB1' ]));
    }

    protected function dsn($driver, $timeout) {
	return [ 'oci:dbname=//' . $this->dsnValue('host') . ':' . $this->port() . '/' . $this->dsnValue('database') . ';charset=AL32UTF8', [] ];
    }
}
