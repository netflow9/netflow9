<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip Account - the account and phone numbers. Operations (setting `operation`):
	balance   the account's balance (account/1/balance): { balance, currency }
	lookup    numbers checked on the mobile networks (number/1/query; charged per number): { to, valid, status, network, country, mccMnc, ported, portedNetwork, roaming, error }
	numbers   the account's own numbers (numbers/1/numbers): { id, number, country, type, capabilities }
*/

namespace models\node;

class NodeAppInfobipAccount extends NodeAppInfobip {
    public static $name = 'infobip-account';
    public static $label = 'Infobip Account';
    public static $icon = 'bi-wallet2';
    public static $defaultOperation = 'balance';
    public static $defaultVariable = 'infobip';

    const OPERATIONS = [
	'balance' => 'Get the balance',
	'lookup' => 'Look up numbers',
	'numbers' => 'List the account\'s numbers',
    ];
    //a lookup is charged: the AI step's tool asks for it to be allowed
    const WRITES = [ 'lookup' ];
    const LISTS = [ 'lookup', 'numbers' ];
    const MAX_LOOKUP = 100;

    protected static function operationFields() {
	return [
	    self::field('to', 'Numbers', [ 'lookup' ], [ 'required' => true, 'help' => 'In international format, separated by commas, or a list (at most ' . self::MAX_LOOKUP . '); each is charged',
		'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$I = $this->infobip;
	switch($operation) {
	    case 'balance':
		$b = $I->request('GET', '/account/1/balance');
		$r = [ 'balance' => isset($b['balance']) ? (float)$b['balance'] : null, 'currency' => $b['currency'] ?? null ];
		return [ $r, 'balance ' . number_format((float)$r['balance'], 2, '.', '') . " {$r['currency']}" ];
	    case 'lookup':
		$to = \util\Infobip::numbers($value('to'), 'Numbers');
		if (!$to)
		    throw new \RuntimeException('Numbers: none');
		if (count($to) > self::MAX_LOOKUP)
		    throw new \RuntimeException('Numbers: more than ' . self::MAX_LOOKUP);
		$d = $I->request('POST', '/number/1/query', [], [ 'to' => $to ]);
		return $this->listResult(array_map(fn($x) => [ 'to' => $x['to'] ?? null, 'valid' => ($x['status']['groupName'] ?? '') === 'DELIVERED' && ($x['error']['groupName'] ?? 'OK') === 'OK',
		    'status' => $x['status']['name'] ?? null, 'network' => $x['originalNetwork']['networkName'] ?? null, 'country' => $x['originalNetwork']['countryName'] ?? null,
		    'mccMnc' => $x['mccMnc'] ?? null, 'ported' => (bool)($x['ported'] ?? false), 'portedNetwork' => $x['portedNetwork']['networkName'] ?? null, 'roaming' => (bool)($x['roaming'] ?? false),
		    'error' => ($x['error']['groupName'] ?? 'OK') !== 'OK' ? ($x['error']['description'] ?? $x['error']['name'] ?? null) : null ], (array)($d['results'] ?? [])), 'number');
	    case 'numbers':
		$d = $I->request('GET', '/numbers/1/numbers', [ 'limit' => min(1000, $this->maxItems()) ]);
		return $this->listResult(array_map(fn($n) => [ 'id' => $n['numberKey'] ?? null, 'number' => $n['number'] ?? null, 'country' => $n['country'] ?? null, 'type' => $n['type'] ?? null,
		    'capabilities' => array_values((array)($n['capabilities'] ?? [])) ], (array)($d['numbers'] ?? [])), 'number');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
