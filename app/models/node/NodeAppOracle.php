<?php

//CLD: 2026-10-31T23:30:00+02:00: new step: Oracle - queries and changes a Oracle database through a Oracle credential of the project (models\credential\CredentialOracle, provides `database:oracle`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppOracle extends NodeAppDatabase {
    public static $name = 'oracle';
    public static $label = 'Oracle';
    public static $icon = 'bi-database-gear';

    const TYPE = 'oracle';
}
