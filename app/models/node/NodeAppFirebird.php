<?php

//Firebird - queries and changes a Firebird database through a Firebird credential of the project (models\credential\CredentialFirebird, provides `database:firebird`).
//Settings, operations (query, execute, insert), the result and the errors are NodeAppDatabase's

namespace models\node;

class NodeAppFirebird extends NodeAppDatabase {
    public static $name = 'firebird';
    public static $label = 'Firebird';
    public static $icon = 'bi-fire';

    const TYPE = 'firebird';
}
