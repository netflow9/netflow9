<?php

//CLD: 2026-10-31T23:30:00+02:00: new step: PostgreSQL - queries and changes a PostgreSQL database through a PostgreSQL credential of the project (models\credential\CredentialPostgres, provides `database:pgsql`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppPostgres extends NodeAppDatabase {
    public static $name = 'postgres';
    public static $label = 'PostgreSQL';
    public static $icon = 'bi-database-fill';

    const TYPE = 'pgsql';
}
