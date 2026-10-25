<?php

//CLD: 2026-10-25T17:40:00+02:00: new: an ODBC data source (pdo_odbc), for the Database step: a data source the server's administrator configured (odbc.ini / the system DSNs),
//by its name only: a connection string could name a driver that opens this machine's files. The username / password: when the data source does not hold them

namespace plugins\Database\models;

class CredentialOdbc extends CredentialDatabase {
    public static $label = 'ODBC data source';

    const TYPE = 'odbc';
    const TEST_SQL = '';
    const DSN_NAME = '^[A-Za-z0-9_][A-Za-z0-9_ .\-]*$';

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'dataSource',
		'type' => 'text',
		'label' => 'Data source name',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'A data source set up on the NetFlow9 server by its administrator (odbc.ini)',
		'validate' => [ [ 'regex', 'pattern' => self::DSN_NAME, 'skipEmpty' => true, 'message' => 'A data source name (letters, digits, space, _ . -), not a connection string' ] ],
		'ui' => [ 'placeholder' => 'SalesDB' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'default' => '',
		'help' => 'Empty: the data source\'s',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function describe() {
	return 'ODBC data source ' . $this->dataSource;
    }

    protected function dsn($driver, $timeout) {
	$name = trim((string)$this->dataSource);
	if (preg_match('/' . self::DSN_NAME . '/', $name) !== 1)
	    throw new \RuntimeException('the data source name is not valid (a name, not a connection string)');
	return [ "odbc:{$name}", [] ];
    }
}
