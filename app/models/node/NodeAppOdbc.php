<?php

//CLD: 2026-10-31T23:30:00+02:00: new step: ODBC - queries and changes a database through an ODBC data source of the server through a ODBC credential of the project (models\credential\CredentialOdbc, provides `database:odbc`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppOdbc extends NodeAppDatabase {
    public static $name = 'odbc';
    public static $label = 'ODBC';
    public static $icon = 'bi-plug';

    const TYPE = 'odbc';
}
