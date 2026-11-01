<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Proxmox Snapshots - the snapshots of a VM or container of Proxmox VE. Operations (setting `operation`):
	list      its snapshots (GET .../snapshot, without Proxmox's `current` entry): { name, description, snaptime, time (ISO 8601), vmstate, parent, current }, oldest first
	create    a snapshot (POST .../snapshot: a name of letters, digits, _ -, starting with a letter; a description; a VM's RAM too when chosen)
	rollback  the guest rolled back to a snapshot (POST .../snapshot/{name}/rollback)
	delete    a snapshot deleted (DELETE .../snapshot/{name})
	prune     the snapshots older than N days (and named like a pattern) deleted, oldest first, one after the other (each task waited for): { removed, count }
    The guest: its VMID or name (NodeAppProxmox::guest). The tasks waited for unless Wait is off: { vmid, node, type, snapshot, task }
*/

namespace models\node;

class NodeAppProxmoxSnapshots extends NodeAppProxmox {
    public static $name = 'proxmox-snapshots';
    public static $label = 'Proxmox Snapshots';
    public static $icon = 'bi-camera';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'snapshots';

    const OPERATIONS = [
	'list' => 'List snapshots',
	'create' => 'Create a snapshot',
	'rollback' => 'Roll back to a snapshot',
	'delete' => 'Delete a snapshot',
	'prune' => 'Delete old snapshots',
    ];
    const LISTS = [ 'list' ];
    //Proxmox's snapshot names (pve-configid)
    const NAME = '/^[A-Za-z][A-Za-z0-9_-]{1,39}$/';

    protected static function operationFields() {
	return [
	    self::field('guest', 'VM or container', array_keys(self::OPERATIONS), [ 'required' => true, 'help' => 'Its VMID or its name (exactly)', 'ui' => [ 'expression' => true ] ]),
	    self::field('snapName', 'Name', [ 'create' ], [ 'required' => true, 'default' => 'before_workflow', 'help' => 'Letters, digits, _ -; starts with a letter',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('vmstate', 'Include the RAM (VMs)', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'help' => 'A running VM: rolling back resumes it', 'ui' => [ 'width' => 6 ] ]),
	    self::field('snapshot', 'Snapshot', [ 'rollback', 'delete' ], [ 'required' => true, 'help' => 'Its name', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('days', 'Older than (days)', [ 'prune' ], [ 'required' => true, 'default' => '7', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('pattern', 'Named', [ 'prune' ], [ 'help' => '* any text (e.g. before*); empty: any name', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('wait', 'Wait for the task', [ 'create', 'rollback', 'delete' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ name, description, snaptime, time, vmstate, parent, current }';
    }

    protected static function resultInfo($op) {
	if ($op === 'prune')
	    return [ 'type' => 'object', 'info' => '{ removed, count }', 'fields' => [ 'removed' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a snapshot deleted' ] ],
		'count' => [ 'type' => 'number' ] ] ];
	return [ 'type' => 'object', 'info' => '{ vmid, node, type, snapshot, task }', 'fields' => [ 'vmid' => [ 'type' => 'number' ], 'node' => [ 'type' => 'string' ],
	    'snapshot' => [ 'type' => 'string', 'info' => 'its name' ], 'task' => [ 'type' => 'string', 'info' => 'the task\'s UPID' ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$g = $this->guest($value('guest'), 'VM or container');
	$what = ($g['type'] === 'lxc' ? 'container ' : 'VM ') . $g['vmid'];
	$r = fn($name, $upid) => [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'type' => $g['type'], 'snapshot' => $name, 'task' => $upid ];
	switch($operation) {
	    case 'list':
		return $this->listResult($this->snapshots($g), 'snapshot');
	    case 'create':
		$name = self::snapName($text('snapName'));
		$p = [ 'snapname' => $name, 'description' => $text('description') ];
		if ($g['type'] === 'qemu' && $this->cfg('vmstate', false))
		    $p['vmstate'] = true;
		[ $upid, $done ] = $this->task($this->call('POST', "{$g['path']}/snapshot", $p), "the snapshot {$name} of {$what}");
		return [ $r($name, $upid), "{$what}: snapshot {$name} " . ($done ? 'created' : 'being created') ];
	    case 'rollback':
	    case 'delete':
		$name = self::snapName($text('snapshot'));
		$upid = $operation === 'rollback' ? $this->call('POST', "{$g['path']}/snapshot/{$name}/rollback") : $this->call('DELETE', "{$g['path']}/snapshot/{$name}");
		[ $upid, $done ] = $this->task($upid, ($operation === 'rollback' ? 'the rollback' : 'the deletion') . " of {$what} to snapshot {$name}");
		return [ $r($name, $upid), "{$what}: " . ($operation === 'rollback' ? ($done ? "rolled back to {$name}" : "rolling back to {$name}") : ($done ? "snapshot {$name} deleted" : "snapshot {$name} being deleted")) ];
	    case 'prune':
		$days = self::number($text('days'), 'Older than (days)', 0, 36500);
		$pattern = $text('pattern');
		$before = time() - $days * 86400;
		$old = array_values(array_filter($this->snapshots($g), fn($s) => (int)$s['snaptime'] > 0 && (int)$s['snaptime'] < $before
		    && ($pattern === '' || fnmatch($pattern, $s['name'], FNM_CASEFOLD))));
		$removed = [];
		foreach($old as $s) {
		    $this->pve->waitTask($this->call('DELETE', "{$g['path']}/snapshot/{$s['name']}"), "the deletion of snapshot {$s['name']}" . ($removed ? ' (' . count($removed) . ' deleted before it)' : ''));
		    $removed[] = $s;
		}
		return [ [ 'removed' => $removed, 'count' => count($removed) ], "{$what}: " . \util\Format::plural(count($removed), 'snapshot') . " older than {$days} days deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a snapshot's name, as Proxmox takes it
    public static function snapName($v) {
	$v = trim((string)$v);
	if (!preg_match(self::NAME, $v))
	    throw new \RuntimeException("Snapshot: not a snapshot name (letters, digits, _ -; starting with a letter; 2 to 40): `" . mb_substr($v, 0, 64) . '`');
	return $v;
    }

    //the guest's snapshots, oldest first, the one the guest runs from marked current
    protected function snapshots(array $g) {
	$list = (array)$this->pve->request('GET', "{$g['path']}/snapshot");
	$current = null;
	foreach($list as $s)
	    if (($s['name'] ?? '') === 'current')
		$current = $s['parent'] ?? null;
	$out = [];
	foreach($list as $s) {
	    if (($s['name'] ?? '') === 'current')
		continue;
	    $t = (int)($s['snaptime'] ?? 0);
	    $out[] = [ 'name' => (string)$s['name'], 'description' => trim((string)($s['description'] ?? '')), 'snaptime' => $t, 'time' => $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : null,
		'vmstate' => !empty($s['vmstate']), 'parent' => $s['parent'] ?? null, 'current' => $current !== null && $s['name'] === $current ];
	}
	usort($out, fn($a, $b) => $a['snaptime'] <=> $b['snaptime']);
	return $out;
    }
}
