<?php

//CLD: 2026-10-25T20:00:00+02:00: moved from the Database plugin to the core (the Database step and its credentials are core, for every account: no plan feature): models\credential (was plugins\Database\models)

//CLD: 2026-10-25T17:40:00+02:00: new: a MySQL or MariaDB server (pdo_mysql), for the Database step. TLS: none, encrypted (the certificate not checked) or checked against the
//system's CAs. LOAD DATA LOCAL is off (it would read this server's files). Statement time limit: max_execution_time (MySQL, SELECT only) / max_statement_time (MariaDB)

namespace models\credential;

class CredentialMysql extends CredentialDatabase {
    public static $label = 'MySQL / MariaDB';

    const TYPE = 'mysql';
    const PORT = 3306;
    const TLS = [ 'none' => 'None', 'encrypt' => 'Encrypted (certificate not checked)', 'verify' => 'Encrypted, certificate checked' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), $this->serverSchema([ 'Database', false, 'Empty: none (name the tables as database.table)', 'sales' ]), [
	    [
		'key' => 'tls',
		'type' => 'select',
		'label' => 'TLS',
		'required' => true,
		'default' => 'none',
		'choices' => self::TLS,
	    ],
	]);
    }

    //a pdo_mysql attribute: Pdo\Mysql::ATTR_* (PHP 8.4+; the PDO::MYSQL_ATTR_* are deprecated in 8.5), else PDO::MYSQL_ATTR_*
    public static function attr($name) {
	return class_exists('Pdo\Mysql') ? constant("Pdo\\Mysql::ATTR_{$name}") : constant("PDO::MYSQL_ATTR_{$name}");
    }

    //the system's CA bundle
    public static function caFile() {
	$default = function_exists('openssl_get_cert_locations') ? (string)(openssl_get_cert_locations()['default_cert_file'] ?? '') : '';
	foreach([ $default, '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem' ] as $f) {
	    if ($f !== '' && is_file($f))
		return $f;
	}
	return null;
    }

    protected function dsn($driver, $timeout) {
	$dsn = 'mysql:host=' . $this->dsnValue('host') . ';port=' . $this->port() . ';charset=utf8mb4';
	if ($this->dsnValue('database') !== '')
	    $dsn .= ';dbname=' . $this->dsnValue('database');
	$options = [ \PDO::ATTR_TIMEOUT => $timeout, \PDO::ATTR_EMULATE_PREPARES => false, self::attr('LOCAL_INFILE') => false ];
	if ($this->tls === 'encrypt' || $this->tls === 'verify') {
	    $ca = self::caFile();
	    if ($ca === null && $this->tls === 'verify')
		throw new \RuntimeException('no CA bundle found on this server to check the certificate');
	    if ($ca !== null)
		$options[self::attr('SSL_CA')] = $ca;
	    $options[self::attr('SSL_VERIFY_SERVER_CERT')] = $this->tls === 'verify';
	}
	return [ $dsn, $options ];
    }

    protected function afterConnect(\PDO $pdo, $driver, $timeout) {
	$mariadb = stripos((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
	try {
	    $pdo->exec($mariadb ? 'SET SESSION max_statement_time = ' . (int)$timeout : 'SET SESSION max_execution_time = ' . ((int)$timeout * 1000));
	} catch (\PDOException $e) {
	    //an older server without it: no time limit of its own
	}
    }
}
