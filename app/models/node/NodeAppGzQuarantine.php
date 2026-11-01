<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone Quarantine - the files GravityZone quarantined on endpoints (Quarantine API, computers). Operations:
	list      the quarantined items (getQuarantineItemsList; of one endpoint or all; all pages up to At most)
	restore   items restored to their place or to a folder (createRestoreQuarantineItemTask), with an exclusion added to the policy or not
	remove    items deleted (createRemoveQuarantineItemTask)
	empty     the quarantine emptied (createEmptyQuarantineTask)
	add       a file of endpoints quarantined (createAddFileToQuarantineTask)
*/

namespace models\node;

class NodeAppGzQuarantine extends NodeAppGravityZone {
    public static $name = 'gz-quarantine';
    public static $label = 'GravityZone Quarantine';
    public static $icon = 'bi-archive';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'quarantine';

    const OPERATIONS = [
	'list' => 'List quarantined items',
	'restore' => 'Restore items',
	'remove' => 'Delete items',
	'empty' => 'Empty the quarantine',
	'add' => 'Quarantine a file',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'restore', 'remove', 'empty', 'add' ];
    const LISTS = [ 'list' ];
    const AREA = 'quarantine/computers';

    protected static function operationFields() {
	return [
	    self::field('endpointId', 'Endpoint', [ 'list' ], [ 'help' => 'Empty: the items of every endpoint', 'ui' => [ 'expression' => true ] ]),
	    self::field('itemIds', 'Items', [ 'restore', 'remove' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Quarantined item ids: a list, or separated by commas',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('location', 'Restore to', [ 'restore' ], [ 'help' => 'A folder of the endpoint; empty: where the file was', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('exclusion', 'Add an exclusion to the policy', [ 'restore' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('endpointIds', 'Endpoints', [ 'add' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Endpoint ids: a list, or separated by commas',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('filePath', 'File', [ 'add' ], [ 'required' => true, 'help' => 'The file\'s path on the endpoints', 'ui' => [ 'expression' => 'text', 'placeholder' => 'C:\Users\Public\suspect.exe' ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf(self::AREA, 'getQuarantineItemsList', array_filter([ 'endpointId' => self::id($value('endpointId'), 'Endpoint', false) ]), 'item');
	    case 'restore':
		$ids = self::ids($value('itemIds'), 'Items');
		$p = [ 'quarantineItemsIds' => $ids, 'addExclusionInPolicy' => (bool)$this->cfg('exclusion', false) ];
		if ($text('location') !== '')
		    $p['locationToRestore'] = $text('location');
		return self::done($this->rpc(self::AREA, 'createRestoreQuarantineItemTask', $p), \util\Format::plural(count($ids), 'item') . ' to restore');
	    case 'remove':
		$ids = self::ids($value('itemIds'), 'Items');
		return self::done($this->rpc(self::AREA, 'createRemoveQuarantineItemTask', [ 'quarantineItemsIds' => $ids ]), \util\Format::plural(count($ids), 'item') . ' to delete');
	    case 'empty':
		return self::done($this->rpc(self::AREA, 'createEmptyQuarantineTask', []), 'the quarantine is being emptied');
	    case 'add':
		$ids = self::ids($value('endpointIds'), 'Endpoints');
		$path = $text('filePath');
		if ($path === '')
		    throw new \RuntimeException('File: empty');
		return self::done($this->rpc(self::AREA, 'createAddFileToQuarantineTask', [ 'endpointIds' => $ids, 'filePath' => $path ]), "{$path} to quarantine on " . \util\Format::plural(count($ids), 'endpoint'));
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
