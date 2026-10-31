<?php

//CLD: 2026-10-31T23:30:00+02:00: new step: MySQL / MariaDB - queries and changes a MySQL or MariaDB database through a MySQL / MariaDB credential of the project (models\credential\CredentialMysql, provides `database:mysql`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppMysql extends NodeAppDatabase {
    public static $name = 'mysql';
    public static $label = 'MySQL / MariaDB';
    public static $icon = 'bi-database';

    const TYPE = 'mysql';
}
