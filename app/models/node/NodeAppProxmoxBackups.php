<?php

/*
    Proxmox Backups - the backups of Proxmox VE (vzdump; a storage of type Proxmox Backup Server too). Operations (setting `operation`):
	backup   a guest backed up now (POST /nodes/{node}/vzdump: to a storage or the node's default, mode snapshot / suspend / stop, compression, notes): { vmid, node, storage, task,
	         backup } (backup: its newest backup on the storage, when waited for and a storage is given)
	list     the backups on a storage (GET /nodes/{node}/storage/{storage}/content?content=backup: of a guest or all): { volid, vmid, ctime, time, size, format, notes, protected,
	         verification }, newest first
	latest   a guest's newest backup on a storage, and how old it is: { vmid, backup (null when none), ageHours }
	jobs     the cluster's backup jobs (GET /cluster/backup)
	delete   a backup deleted (DELETE /nodes/{node}/storage/{storage}/content/{volid})
    The guest: its VMID or name (NodeAppProxmox::guest); a storage's node: the guest's, or the Node given
*/

namespace models\node;

class NodeAppProxmoxBackups extends NodeAppProxmox {
    public static $name = 'proxmox-backups';
    public static $label = 'Proxmox Backups';
    public static $icon = 'bi-archive';
    public static $defaultOperation = 'backup';
    public static $defaultVariable = 'backup';

    const OPERATIONS = [
	'backup' => 'Back up a guest now',
	'list' => 'List backups on a storage',
	'latest' => 'Get a guest\'s latest backup',
	'jobs' => 'List backup jobs',
	'delete' => 'Delete a backup',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'backup', 'delete' ];
    const LISTS = [ 'list', 'jobs' ];
    const MODES = [ 'snapshot' => 'Snapshot (no downtime)', 'suspend' => 'Suspend', 'stop' => 'Stop' ];
    const COMPRESS = [ 'zstd' => 'ZSTD', 'lzo' => 'LZO', 'gzip' => 'GZIP', '0' => 'None' ];

    protected static function operationFields() {
	return [
	    self::field('guest', 'VM or container', [ 'backup', 'list', 'latest' ], [ 'help' => 'Its VMID or its name (exactly); list, empty: every guest\'s', 'ui' => [ 'expression' => true ] ]),
	    self::field('storage', 'Storage', [ 'backup', 'list', 'latest', 'delete' ], [ 'help' => 'Its ID, e.g. pbs; back up, empty: the node\'s default', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('node', 'Node', [ 'list', 'delete' ], [ 'help' => 'The storage\'s; empty: the guest\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('mode', 'Mode', [ 'backup' ], [ 'type' => 'select', 'default' => 'snapshot', 'choices' => self::MODES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('compress', 'Compression', [ 'backup' ], [ 'type' => 'select', 'default' => 'zstd', 'choices' => self::COMPRESS, 'help' => 'Not for Proxmox Backup Server', 'ui' => [ 'width' => 3 ] ]),
	    self::field('notes', 'Notes', [ 'backup' ], [ 'help' => 'Shown with the backup, e.g. Before the upgrade', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true ] ]),
	    self::field('volid', 'Backup', [ 'delete' ], [ 'required' => true, 'help' => 'Its volume ID, e.g. {{ backup.backup.volid }}', 'ui' => [ 'expression' => true ] ]),
	    self::field('wait', 'Wait for the task', [ 'backup', 'delete' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return $op === 'jobs' ? '{ id, schedule, enabled, storage, vmid, all, mode, ... }' : '{ volid, vmid, ctime, time, size, format, notes, protected, verification }';
    }

    protected static function resultInfo($op) {
	return match($op) {
	    'backup' => [ 'type' => 'object', 'info' => '{ vmid, node, storage, task, backup }', 'fields' => [ 'vmid' => [ 'type' => 'number' ], 'node' => [ 'type' => 'string' ],
		'storage' => [ 'type' => 'string' ], 'task' => [ 'type' => 'string', 'info' => 'the task\'s UPID' ], 'backup' => [ 'type' => 'object', 'info' => 'the backup made: { volid, size, time, ... }' ] ] ],
	    'latest' => [ 'type' => 'object', 'info' => '{ vmid, backup, ageHours }', 'fields' => [ 'vmid' => [ 'type' => 'number' ], 'backup' => [ 'type' => 'object', 'info' => '{ volid, size, time, ... }; null: none' ],
		'ageHours' => [ 'type' => 'number', 'info' => 'null: none' ] ] ],
	    default => parent::resultInfo($op),
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'jobs':
		return $this->listOf('/cluster/backup', [], 'backup job');
	    case 'backup':
		$g = $this->guest($value('guest'), 'VM or container');
		$storage = $text('storage');
		$p = [ 'vmid' => $g['vmid'], 'mode' => isset(self::MODES[(string)$this->cfg('mode', 'snapshot')]) ? (string)$this->cfg('mode', 'snapshot') : 'snapshot',
		    'storage' => $storage !== '' ? self::storageId($storage) : null, 'notes-template' => $text('notes') ];
		$compress = (string)$this->cfg('compress', 'zstd');
		if (isset(self::COMPRESS[$compress]))
		    $p['compress'] = $compress;
		[ $upid, $done ] = $this->task($this->call('POST', "/nodes/{$g['node']}/vzdump", $p), "the backup of {$g['vmid']}");
		$made = $done && $storage !== '' ? ($this->backups($g['node'], self::storageId($storage), $g['vmid'])[0] ?? null) : null;
		return [ [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'storage' => $storage !== '' ? $storage : null, 'task' => $upid, 'backup' => $made ],
		    "guest {$g['vmid']}: " . ($done ? 'backed up' . ($made ? " ({$made['volid']})" : '') : 'backup started') ];
	    case 'list':
		$storage = self::storageId($text('storage'));
		$g = $text('guest') !== '' ? $this->guest($value('guest'), 'VM or container') : null;
		$node = $text('node') !== '' ? self::nodeName($text('node')) : ($g['node'] ?? null);
		if ($node === null)
		    throw new \RuntimeException('Node: empty (give the node, or a guest)');
		return $this->listResult($this->backups($node, $storage, $g['vmid'] ?? null), 'backup');
	    case 'latest':
		$g = $this->guest($value('guest'), 'VM or container');
		$b = $this->backups($g['node'], self::storageId($text('storage')), $g['vmid'])[0] ?? null;
		$age = $b ? round((time() - (int)$b['ctime']) / 3600, 1) : null;
		return [ [ 'vmid' => $g['vmid'], 'backup' => $b, 'ageHours' => $age ], "guest {$g['vmid']}: " . ($b ? "latest backup {$b['time']} ({$age} h old)" : 'no backup') ];
	    case 'delete':
		$volid = $text('volid');
		if (!preg_match('~^[A-Za-z][A-Za-z0-9_.-]*:[^\s]+$~', $volid))
		    throw new \RuntimeException("Backup: not a volume ID (storage:backup/...): `" . mb_substr($volid, 0, 80) . '`');
		$storage = $text('storage') !== '' ? self::storageId($text('storage')) : explode(':', $volid)[0];
		$node = $text('node') !== '' ? self::nodeName($text('node')) : null;
		if ($node === null)
		    throw new \RuntimeException('Node: empty (the node the storage is read from)');
		[ $upid, $done ] = $this->task($this->call('DELETE', "/nodes/{$node}/storage/{$storage}/content/" . rawurlencode($volid)), "the deletion of {$volid}");
		return [ [ 'volid' => $volid, 'deleted' => $done !== null || $upid === null, 'task' => $upid ], "backup {$volid} " . ($done !== null || $upid === null ? 'deleted' : 'being deleted') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a storage's ID
    public static function storageId($v) {
	$v = trim((string)$v);
	if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $v))
	    throw new \RuntimeException('Storage: ' . ($v === '' ? 'empty' : 'not a storage ID: `' . mb_substr($v, 0, 64) . '`'));
	return $v;
    }

    //the backups on a storage (of a guest), newest first, with time (ISO 8601)
    protected function backups($node, $storage, $vmid = null) {
	$list = (array)$this->pve->request('GET', "/nodes/{$node}/storage/{$storage}/content", [ 'content' => 'backup', 'vmid' => $vmid ]);
	$list = array_values(array_filter($list, fn($b) => $vmid === null || (int)($b['vmid'] ?? 0) === (int)$vmid));
	foreach($list as &$b)
	    $b['time'] = isset($b['ctime']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$b['ctime']) : null;
	unset($b);
	usort($list, fn($a, $b) => (int)($b['ctime'] ?? 0) <=> (int)($a['ctime'] ?? 0));
	return $list;
    }
}
