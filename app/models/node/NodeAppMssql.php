<?php

//CLD: 2026-10-31T23:30:00+02:00: new step: SQL Server - queries and changes a Microsoft SQL Server database through a SQL Server credential of the project (models\credential\CredentialMssql, provides `database:mssql`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppMssql extends NodeAppDatabase {
    public static $name = 'mssql';
    public static $label = 'SQL Server';
    public static $icon = 'bi-microsoft';

    const TYPE = 'mssql';
}
