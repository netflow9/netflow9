<?php

/*
    VMware Snapshots - the snapshots of a VM (VI/JSON, vCenter 8.0 U1 and later; the REST API has none). Operations (setting `operation`):
	list        its snapshots, the tree flattened (VirtualMachine snapshot): { id, name, description, createTime, state, quiesced, current, parent, depth }
	create      a snapshot (CreateSnapshot_Task: name, description, the memory, the guest's file systems quiesced (VMware Tools))
	revert      the VM reverted to a snapshot (RevertToSnapshot_Task), or to the current one when none is given (RevertToCurrentSnapshot_Task)
	remove      a snapshot removed (RemoveSnapshot_Task, consolidated; its children too when chosen)
	removeAll   every snapshot removed (RemoveAllSnapshots_Task, consolidated)
	prune       the snapshots older than N days (and named like a pattern) removed, oldest first, one after the other (each task waited for): { removed, count }
    The tasks are waited for (within the step's Timeout) unless Wait is off: { vm, task, snapshot (create), ... }. The VM: its id or name; a snapshot: its id (snapshot-12)
    or its name (exactly; several of that name: an error)
*/

namespace models\node;

class NodeAppVmwareSnapshots extends NodeAppVmware {
    public static $name = 'vmware-snapshots';
    public static $label = 'VMware Snapshots';
    public static $icon = 'bi-camera';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'snapshots';

    const OPERATIONS = [
	'list' => 'List snapshots',
	'create' => 'Create a snapshot',
	'revert' => 'Revert to a snapshot',
	'remove' => 'Remove a snapshot',
	'removeAll' => 'Remove all snapshots',
	'prune' => 'Remove old snapshots',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'create', 'revert', 'remove', 'removeAll', 'prune' ];
    const LISTS = [ 'list' ];
    const WAITED = [ 'create', 'revert', 'remove', 'removeAll' ];

    protected static function operationFields() {
	return [
	    self::field('vm', 'VM', array_keys(self::OPERATIONS), [ 'required' => true, 'help' => 'Its name (exactly) or its id (vm-42)', 'ui' => [ 'expression' => true ] ]),
	    self::field('snapName', 'Name', [ 'create' ], [ 'required' => true, 'default' => 'Before the workflow', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('memory', 'Include the memory', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Of a running VM: reverting resumes it', 'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	    self::field('quiesce', 'Quiesce the guest file systems', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Needs VMware Tools', 'ui' => [ 'width' => 6 ] ]),
	    self::field('snapshot', 'Snapshot', [ 'revert', 'remove' ], [ 'help' => 'Its name (exactly) or id; revert, empty: the current one', 'ui' => [ 'expression' => true, 'width' => 8 ] ]),
	    self::field('children', 'Its children too', [ 'remove' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('days', 'Older than (days)', [ 'prune' ], [ 'required' => true, 'default' => '7', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('pattern', 'Named', [ 'prune' ], [ 'help' => '* any text (e.g. Before*); empty: any name', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('wait', 'Wait for the task', self::WAITED, [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ id, name, description, createTime, state, quiesced, current, parent, depth }';
    }

    protected static function resultInfo($op) {
	if ($op === 'prune')
	    return [ 'type' => 'object', 'info' => '{ removed, count }', 'fields' => [ 'removed' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a snapshot removed' ] ],
		'count' => [ 'type' => 'number' ] ] ];
	return [ 'type' => 'object', 'info' => '{ vm, task, snapshot, name }', 'fields' => [ 'vm' => [ 'type' => 'string' ], 'task' => [ 'type' => 'string', 'info' => 'the vCenter task' ],
	    'snapshot' => [ 'type' => 'string', 'info' => 'the snapshot\'s id (create: when waited for)' ], 'name' => [ 'type' => 'string' ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$vm = $this->resolve('vm', $value('vm'));
	$wait = (bool)$this->cfg('wait', true);
	switch($operation) {
	    case 'list':
		return $this->listResult(self::flatten($this->vc->vim('GET', "VirtualMachine/{$vm}/snapshot")), 'snapshot');
	    case 'create':
		$name = mb_substr($text('snapName'), 0, 80);
		if ($name === '')
		    throw new \RuntimeException('Name: empty');
		$task = $this->invoke("VirtualMachine/{$vm}/CreateSnapshot_Task", [ 'name' => $name, 'description' => $text('description'),
		    'memory' => (bool)$this->cfg('memory', false), 'quiesce' => (bool)$this->cfg('quiesce', false) ]);
		$r = [ 'vm' => $vm, 'task' => (string)($task['value'] ?? ''), 'snapshot' => null, 'name' => $name ];
		if (!$wait)
		    return [ $r, "VM {$vm}: snapshot {$name} being created ({$r['task']})" ];
		$info = $this->vc->waitTask($task, "the snapshot of {$vm}");
		$r['snapshot'] = (string)($info['result']['value'] ?? '');
		return [ $r, "VM {$vm}: snapshot {$name} created ({$r['snapshot']})" ];
	    case 'revert':
		if ($text('snapshot') === '') {
		    $task = $this->invoke("VirtualMachine/{$vm}/RevertToCurrentSnapshot_Task");
		    $snap = null;
		} else {
		    $snap = $this->snapshotOf($vm, $value('snapshot'));
		    $task = $this->invoke("VirtualMachineSnapshot/{$snap['id']}/RevertToSnapshot_Task");
		}
		$r = [ 'vm' => $vm, 'task' => (string)($task['value'] ?? ''), 'snapshot' => $snap['id'] ?? null, 'name' => $snap['name'] ?? null ];
		$what = $snap ? "snapshot {$snap['name']}" : 'the current snapshot';
		if ($wait)
		    $this->vc->waitTask($task, "the revert of {$vm}");
		return [ $r, "VM {$vm}: " . ($wait ? "reverted to {$what}" : "reverting to {$what} ({$r['task']})") ];
	    case 'remove':
		$snap = $this->snapshotOf($vm, $value('snapshot'));
		$task = $this->invoke("VirtualMachineSnapshot/{$snap['id']}/RemoveSnapshot_Task", [ 'removeChildren' => (bool)$this->cfg('children', false), 'consolidate' => true ]);
		$r = [ 'vm' => $vm, 'task' => (string)($task['value'] ?? ''), 'snapshot' => $snap['id'], 'name' => $snap['name'] ];
		if ($wait)
		    $this->vc->waitTask($task, "the removal of snapshot {$snap['name']}");
		return [ $r, "VM {$vm}: snapshot {$snap['name']} " . ($wait ? 'removed' : "being removed ({$r['task']})") ];
	    case 'removeAll':
		$task = $this->invoke("VirtualMachine/{$vm}/RemoveAllSnapshots_Task", [ 'consolidate' => true ]);
		$r = [ 'vm' => $vm, 'task' => (string)($task['value'] ?? ''), 'snapshot' => null, 'name' => null ];
		if ($wait)
		    $this->vc->waitTask($task, "the removal of the snapshots of {$vm}");
		return [ $r, "VM {$vm}: every snapshot " . ($wait ? 'removed' : "being removed ({$r['task']})") ];
	    case 'prune':
		return $this->prune($vm, $text);
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the snapshots older than the days (named like the pattern) removed, oldest first
    protected function prune($vm, \Closure $text) {
	$days = self::number($text('days'), 'Older than (days)', 0, 36500);
	$pattern = $text('pattern');
	$before = time() - $days * 86400;
	$old = array_values(array_filter(self::flatten($this->vc->vim('GET', "VirtualMachine/{$vm}/snapshot")),
	    fn($s) => ($t = strtotime((string)$s['createTime'])) !== false && $t < $before && ($pattern === '' || fnmatch($pattern, (string)$s['name'], FNM_CASEFOLD))));
	usort($old, fn($a, $b) => strcmp((string)$a['createTime'], (string)$b['createTime']));
	$removed = [];
	foreach($old as $s) {
	    $task = $this->invoke("VirtualMachineSnapshot/{$s['id']}/RemoveSnapshot_Task", [ 'removeChildren' => false, 'consolidate' => true ]);
	    $this->vc->waitTask($task, "the removal of snapshot {$s['name']}" . ($removed ? ' (' . count($removed) . ' removed before it)' : ''));
	    $removed[] = $s;
	}
	return [ [ 'removed' => $removed, 'count' => count($removed) ], "VM {$vm}: " . \util\Format::plural(count($removed), 'snapshot') . " older than {$days} days removed" ];
    }

    //a snapshot of the VM by id or name (exactly): its item
    protected function snapshotOf($vm, $value) {
	if (is_array($value))
	    $value = $value['id'] ?? $value['snapshot'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException('Snapshot: empty');
	$all = self::flatten($this->vc->vim('GET', "VirtualMachine/{$vm}/snapshot"));
	$found = array_values(array_filter($all, fn($s) => $s['id'] === $v || $s['name'] === $v));
	if (!$found)
	    throw new \RuntimeException("Snapshot: VM {$vm} has none " . (preg_match('/^snapshot-\d+$/', $v) ? "with id {$v}" : "named `{$v}`"));
	if (count($found) > 1)
	    throw new \RuntimeException("Snapshot: several are named `{$v}`: give its id");
	return $found[0];
    }

    //the snapshot tree (VirtualMachineSnapshotInfo) as a list, parents before their children
    public static function flatten($info) {
	$current = (string)($info['currentSnapshot']['value'] ?? '');
	$out = [];
	self::walk(is_array($info) ? (array)($info['rootSnapshotList'] ?? []) : [], null, 0, $current, $out);
	return $out;
    }

    //the snapshots of tree $list (children of $parent, at $depth) added to $out, depth first; $current: the current snapshot's id
    private static function walk(array $list, $parent, $depth, $current, array &$out) {
	foreach($list as $t) {
	    if (!is_array($t))
		continue;
	    $id = (string)($t['snapshot']['value'] ?? '');
	    $out[] = [ 'id' => $id, 'name' => (string)($t['name'] ?? ''), 'description' => (string)($t['description'] ?? ''), 'createTime' => (string)($t['createTime'] ?? ''),
		'state' => (string)($t['state'] ?? ''), 'quiesced' => (bool)($t['quiesced'] ?? false), 'current' => $id !== '' && $id === $current, 'parent' => $parent, 'depth' => $depth ];
	    self::walk((array)($t['childSnapshotList'] ?? []), $id, $depth + 1, $current, $out);
	}
    }
}
