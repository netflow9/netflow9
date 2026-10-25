<?php

/*
    CLD: 2026-10-25T17:40:00+02:00: new plugin: Database - the workflows read and change SQL databases. Enabled in app/plugins.php.
      - Credentials (group Database), one per type: MySQL / MariaDB, PostgreSQL, Microsoft SQL Server, Oracle, Firebird, ODBC data source (models/Credential*.php, on
	models/CredentialDatabase.php)
      - The Database step (models/NodeAppDatabase.php): query, execute a statement, insert rows; {{ }} in the SQL sent as parameters
      util/Db.php                         the types and their PDO drivers, the SQL's parameters, the values bound and read
    Each type needs its PDO driver in PHP (util\Db::TYPES: pdo_mysql, pdo_pgsql, pdo_sqlsrv or pdo_dblib, pdo_oci, pdo_firebird, pdo_odbc): without it, it is not offered for
    new credentials (Credential::available()), and the step is not in the designer's palette when no type has one (WfNode::available()). They stay registered: credentials and
    steps made where the driver is (the web PHP) say which extension is missing where it is not (the console PHP: the daemon). The initial setup lists the drivers (util\Setup)
    CLD: 2026-10-25T18:30:00+02:00: a feature of the accounts' tiers (util\Tiers: `database`), as LDAP: without it, no new database credentials, the step is not in the palette and
    does not run (the credentials and steps are kept)
*/

namespace plugins\Database;

class Plugin {

    const CREDENTIALS = [ 'CredentialMysql', 'CredentialPostgres', 'CredentialMssql', 'CredentialOracle', 'CredentialFirebird', 'CredentialOdbc' ];

    public function __construct() {
	//CLD: 2026-10-25T18:30:00+02:00: `feature`: of the accounts whose tier has it (util\Tiers: new credentials, the designer's palette, the step's runs)
	$objects = [
	    'NodeAppDatabase' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppDatabase',
		'wfNode' => true,
		'feature' => 'database',
	    ],
	];
	foreach(self::CREDENTIALS as $name) {
	    $objects[$name] = [
		'class' => __NAMESPACE__ . '\\models\\' . $name,
		'variant-of' => 'Credential',
		'feature' => 'database',
	    ];
	}
	\V::app()->om->registerObjects($objects);

	//CLD: 2026-10-25T18:30:00+02:00: a feature of the accounts' tiers (util\Tiers: `database`)
	\V::app()->hooks->addHook('tiers:features', function(\ArrayObject $features) {
	    $features['database'] = 'Databases (step, credentials)';
	});
    }
}
