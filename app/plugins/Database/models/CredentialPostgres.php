<?php

//CLD: 2026-10-25T17:40:00+02:00: new: a PostgreSQL server (pdo_pgsql), for the Database step. TLS: libpq's sslmode. Statement time limit: statement_timeout

namespace plugins\Database\models;

class CredentialPostgres extends CredentialDatabase {
    public static $label = 'PostgreSQL';

    const TYPE = 'pgsql';
    const PORT = 5432;
    const SSLMODES = [ 'disable' => 'None', 'prefer' => 'Encrypted when the server offers it', 'require' => 'Encrypted (certificate not checked)', 'verify-full' => 'Encrypted, certificate and name checked' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), $this->serverSchema([ 'Database', true, false, 'sales' ]), [
	    [
		'key' => 'sslmode',
		'type' => 'select',
		'label' => 'TLS',
		'required' => true,
		'default' => 'prefer',
		'choices' => self::SSLMODES,
	    ],
	]);
    }

    protected function dsn($driver, $timeout) {
	$mode = isset(self::SSLMODES[$this->sslmode]) ? $this->sslmode : 'prefer';
	return [ 'pgsql:host=' . $this->dsnValue('host') . ';port=' . $this->port() . ';dbname=' . $this->dsnValue('database') . ";sslmode={$mode};connect_timeout={$timeout}", [] ];
    }

    protected function afterConnect(\PDO $pdo, $driver, $timeout) {
	$pdo->exec('SET statement_timeout = ' . ((int)$timeout * 1000));
    }
}
