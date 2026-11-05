<?php

/*
    CLD: 2026-11-05T13:00:00+02:00: new step: Namecheap API - any command of the Namecheap API (what the other Namecheap steps do not have: registering a domain, child name
    servers, transfers, WhoisGuard, SSL activation, ...): the command and its parameters (a JSON object, or an expression giving one; Other parameters is not used here).
    Its result: the answer's CommandResponse as an array (util\Namecheap::toArray: attributes, children by name, repeated ones a list)
*/

namespace models\node;

class NodeAppNcApi extends NodeAppNamecheap {
    public static $name = 'namecheap-api';
    public static $label = 'Namecheap API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'command';
    public static $defaultVariable = 'namecheap';

    const OPERATIONS = [ 'command' => 'Run a command' ];
    const WRITES = [ 'command' ];

    protected static function operationFields() {
	return [
	    self::field('command', 'Command', [ 'command' ], [ 'required' => true, 'help' => 'e.g. namecheap.domains.ns.getInfo', 'ui' => [ 'expression' => 'text', 'placeholder' => 'namecheap.domains.…' ] ]),
	    self::field('parameters', 'Parameters', [ 'command' ], [ 'type' => 'textarea', 'help' => 'A JSON object, as Namecheap names them (empty: none), e.g. {"SLD": "example", "TLD": "com", "Nameserver": "ns1.example.com"}',
		'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$command = $text('command');
	$p = $value('parameters');
	if (is_string($p)) {
	    $p = trim($p) === '' ? [] : json_decode($p, true);
	    if (!is_array($p))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($p) || ($p && array_is_list($p)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	foreach($p as $k => $x) {
	    if (is_array($x) && !array_is_list($x))
		throw new \RuntimeException("Parameters: `{$k}`: a text, a number, true / false or a list");
	}
	$r = \util\Namecheap::toArray($this->nc->call($command, $p));
	return [ $r, $command ];
    }
}
