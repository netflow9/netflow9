<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone Blocklist - the file hashes GravityZone blocks on every endpoint (Incidents API). Operations:
	list      the blocklist (getBlocklistItems; all pages up to At most)
	add       hashes added (addToBlocklist): SHA-256 or MD5, a list or one per line / separated by commas, with a note (the source); each checked as a hash of its kind
	remove    an item removed (removeFromBlocklist: the item's id, from the list)
*/

namespace models\node;

class NodeAppGzBlocklist extends NodeAppGravityZone {
    public static $name = 'gz-blocklist';
    public static $label = 'GravityZone Blocklist';
    public static $icon = 'bi-slash-circle';
    public static $defaultOperation = 'add';
    public static $defaultVariable = 'blocklist';

    const OPERATIONS = [
	'list' => 'List blocked hashes',
	'add' => 'Block hashes',
	'remove' => 'Unblock an item',
    ];
    const LISTS = [ 'list' ];
    //GravityZone's hashType => [ label, length ]
    const HASH_TYPES = [ '1' => [ 'SHA-256', 64 ], '2' => [ 'MD5', 32 ] ];
    const MAX_HASHES = 1000;

    protected static function operationFields() {
	return [
	    self::field('hashType', 'Hash', [ 'add' ], [ 'type' => 'select', 'default' => '1', 'choices' => array_map(fn($h) => $h[0], self::HASH_TYPES), 'ui' => [ 'width' => 3 ] ]),
	    self::field('hashes', 'Hashes', [ 'add' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or one per line / separated by commas',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('source', 'Note', [ 'add' ], [ 'help' => 'Why they are blocked (shown in GravityZone)', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('itemId', 'Item', [ 'remove' ], [ 'required' => true, 'help' => 'The blocklist item\'s id (List blocked hashes)', 'ui' => [ 'expression' => true ] ]),
	];
    }

    //the hashes of $value, of the kind $type (lower case); \RuntimeException naming a wrong one
    public static function hashes($value, $type) {
	[ $label, $length ] = self::HASH_TYPES[$type] ?? self::HASH_TYPES['1'];
	$hashes = array_values(array_unique(array_map('strtolower', self::texts($value))));
	if (!$hashes)
	    throw new \RuntimeException('Hashes: empty');
	if (count($hashes) > self::MAX_HASHES)
	    throw new \RuntimeException('Hashes: ' . count($hashes) . ', at most ' . self::MAX_HASHES . ' in one step');
	foreach($hashes as $h) {
	    if (!preg_match('/^[0-9a-f]{' . $length . '}$/', $h))
		throw new \RuntimeException("Hashes: not a {$label} hash: `" . mb_substr($h, 0, 80) . '`');
	}
	return $hashes;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf('incidents', 'getBlocklistItems', [], 'item');
	    case 'add':
		$type = isset(self::HASH_TYPES[(string)$this->cfg('hashType', '1')]) ? (string)$this->cfg('hashType', '1') : '1';
		$hashes = self::hashes($value('hashes'), $type);
		$r = $this->rpc('incidents', 'addToBlocklist', [ 'hashType' => (int)$type, 'hashList' => $hashes, 'sourceInfo' => mb_substr($text('source'), 0, 500) ?: 'NetFlow9 workflow' ]);
		return self::done($r, \util\Format::plural(count($hashes), self::HASH_TYPES[$type][0] . ' hash', self::HASH_TYPES[$type][0] . ' hashes') . ' blocked');
	    case 'remove':
		return self::done($this->rpc('incidents', 'removeFromBlocklist', [ 'hashItemId' => self::id($value('itemId'), 'Item') ]), 'item ' . $text('itemId') . ' removed');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
