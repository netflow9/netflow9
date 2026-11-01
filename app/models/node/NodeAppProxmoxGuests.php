<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Proxmox Guests - the VMs (qemu) and containers (lxc) of a Proxmox VE cluster. Operations (setting `operation`):
	list        the guests (GET /cluster/resources?type=vm: names, * patterns; status, node, type; templates left out unless chosen): { vmid, name, node, type, status, cpu, maxcpu,
	            mem, maxmem, disk, maxdisk, uptime, tags, template }, by VMID
	status      its current status (GET .../status/current), with vmid, node, type
	config      its configuration (GET .../config)
	start / stop / shutdown / reboot / suspend / resume / reset (VMs)   POST .../status/<action>; so already (running for start, stopped for stop and shutdown): done
	            (already: true); shutdown: the guest's own shutdown, its timeout, then stopped when chosen (forceStop); { vmid, node, type, action, status, already, task }
	interfaces  its network interfaces and IP addresses (VMs: the QEMU guest agent's network-get-interfaces; containers: .../interfaces)
	clone       a guest cloned (POST .../clone: the new VMID or the next free one, its name, full or linked, to a node, a storage): { vmid, node, name, task }
	migrate     a guest moved to another node (POST .../migrate: online / restarted when running)
	delete      a guest deleted (DELETE, purged from jobs and its unreferenced disks too; it must be stopped)
    The guest: its VMID, its name (exactly) or an item of a list (NodeAppProxmox::guest); its node and type found by the step. The tasks waited for unless Wait is off
*/

namespace models\node;

class NodeAppProxmoxGuests extends NodeAppProxmox {
    public static $name = 'proxmox-guests';
    public static $label = 'Proxmox Guests';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'guests';

    const OPERATIONS = [
	'list' => 'List VMs and containers',
	'status' => 'Get its status',
	'config' => 'Get its configuration',
	'start' => 'Start',
	'shutdown' => 'Shut down',
	'reboot' => 'Reboot',
	'stop' => 'Stop (power off)',
	'suspend' => 'Suspend',
	'resume' => 'Resume',
	'reset' => 'Reset (VMs)',
	'interfaces' => 'Get its network interfaces',
	'clone' => 'Clone',
	'migrate' => 'Migrate to another node',
	'delete' => 'Delete',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'start', 'shutdown', 'reboot', 'stop', 'suspend', 'resume', 'reset', 'clone', 'migrate', 'delete' ];
    const LISTS = [ 'list' ];
    const POWER = [ 'start', 'shutdown', 'reboot', 'stop', 'suspend', 'resume', 'reset' ];
    //what each action leaves, and the status in which it is done already
    const AFTER = [ 'start' => [ 'running', 'running' ], 'shutdown' => [ 'stopped', 'stopped' ], 'stop' => [ 'stopped', 'stopped' ], 'reboot' => [ 'running', null ],
	'suspend' => [ 'paused', null ], 'resume' => [ 'running', null ], 'reset' => [ 'running', null ] ];
    const STATUS = [ 'any' => 'Any', 'running' => 'Running', 'stopped' => 'Stopped', 'paused' => 'Paused' ];
    const TYPES = [ 'any' => 'VMs and containers', 'qemu' => 'VMs', 'lxc' => 'Containers' ];
    const TASKS = [ 'start', 'shutdown', 'reboot', 'stop', 'suspend', 'resume', 'reset', 'clone', 'migrate', 'delete' ];

    protected static function operationFields() {
	$one = array_values(array_diff(array_keys(self::OPERATIONS), [ 'list' ]));
	return [
	    self::field('names', 'Names', [ 'list' ], [ 'help' => 'One per line or comma separated; * any text (web*); empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'list' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::STATUS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('kind', 'Type', [ 'list' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::TYPES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('node', 'Node', [ 'list' ], [ 'help' => 'Empty: every node', 'ui' => [ 'expression' => 'text', 'width' => 4, 'newRow' => true ] ]),
	    self::field('tag', 'Tag', [ 'list' ], [ 'help' => 'Empty: any', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('templates', 'Templates too', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('guest', 'VM or container', $one, [ 'required' => true, 'help' => 'Its VMID or its name (exactly); clone: the source', 'ui' => [ 'expression' => true ] ]),
	    self::field('shutdownTimeout', 'Shutdown timeout (s)', [ 'shutdown' ], [ 'type' => 'number', 'default' => 120, 'help' => 'Then it fails, or is stopped', 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('forceStop', 'Then stop it', [ 'shutdown' ], [ 'type' => 'switch', 'default' => false, 'help' => 'When still running after the shutdown timeout', 'ui' => [ 'width' => 6 ] ]),
	    self::field('newName', 'New name', [ 'clone' ], [ 'help' => 'Empty: Proxmox\'s own', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('newId', 'New VMID', [ 'clone' ], [ 'help' => 'Empty: the next free one', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('full', 'Full clone', [ 'clone' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Off: linked (from a template)', 'ui' => [ 'width' => 3 ] ]),
	    self::field('target', 'Target node', [ 'clone', 'migrate' ], [ 'help' => 'Migrate: required; clone, empty: the source\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('storage', 'Storage', [ 'clone' ], [ 'help' => 'Of a full clone; empty: the source\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('online', 'Online (live)', [ 'migrate' ], [ 'type' => 'switch', 'default' => true, 'help' => 'A running container is restarted instead', 'ui' => [ 'width' => 6 ] ]),
	    self::field('wait', 'Wait for the task', self::TASKS, [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ vmid, name, node, type, status, cpu, maxcpu, mem, maxmem, uptime, tags }';
    }

    protected static function resultInfo($op) {
	if (in_array($op, self::POWER, true))
	    return [ 'type' => 'object', 'info' => '{ vmid, node, type, action, status, already, task }', 'fields' => [ 'vmid' => [ 'type' => 'number' ], 'node' => [ 'type' => 'string' ],
		'type' => [ 'type' => 'string', 'info' => 'qemu or lxc' ], 'status' => [ 'type' => 'string', 'info' => 'running, stopped, paused' ],
		'already' => [ 'type' => 'boolean', 'info' => 'it was so already' ], 'task' => [ 'type' => 'string', 'info' => 'the task\'s UPID' ] ] ];
	return match($op) {
	    'status' => [ 'type' => 'object', 'info' => 'its status, as Proxmox gives it, with vmid, node, type', 'fields' => [ 'vmid' => [ 'type' => 'number' ], 'name' => [ 'type' => 'string' ],
		'status' => [ 'type' => 'string', 'info' => 'running, stopped' ], 'qmpstatus' => [ 'type' => 'string' ], 'uptime' => [ 'type' => 'number' ], 'node' => [ 'type' => 'string' ] ] ],
	    'clone' => [ 'type' => 'object', 'info' => '{ vmid, node, name, task }', 'fields' => [ 'vmid' => [ 'type' => 'number', 'info' => 'the new guest\'s' ], 'node' => [ 'type' => 'string' ],
		'name' => [ 'type' => 'string' ], 'task' => [ 'type' => 'string' ] ] ],
	    'interfaces' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ name, hardware-address, ip-addresses: [ { ip-address, prefix } ] } (containers: { name, hwaddr, inet })' ] ],
	    default => parent::resultInfo($op),
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list')
	    return $this->listGuests($text);
	$g = $this->guest($value('guest'), 'VM or container');
	$what = ($g['type'] === 'lxc' ? 'container ' : 'VM ') . $g['vmid'] . ($g['name'] !== '' ? " ({$g['name']})" : '');
	if (in_array($operation, self::POWER, true))
	    return $this->power($g, $operation, $what);
	switch($operation) {
	    case 'status':
		$r = (array)$this->call('GET', "{$g['path']}/status/current");
		return [ [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'type' => $g['type'] ] + $r, "{$what}: " . ($r['status'] ?? '?') ];
	    case 'config':
		$r = $this->call('GET', "{$g['path']}/config");
		return [ $r, "{$what}: its configuration" ];
	    case 'interfaces':
		$r = $g['type'] === 'lxc' ? $this->call('GET', "{$g['path']}/interfaces") : ($this->call('GET', "{$g['path']}/agent/network-get-interfaces")['result'] ?? []);
		return [ is_array($r) ? array_values($r) : [], "{$what}: " . \util\Format::plural(is_array($r) ? count($r) : 0, 'interface') ];
	    case 'clone':
		return $this->cloneGuest($g, $text, $what);
	    case 'migrate':
		$target = self::nodeName($value('target'), 'Target node');
		$p = [ 'target' => $target ];
		if ($g['status'] === 'running')
		    $p += $g['type'] === 'lxc' ? [ 'restart' => true ] : [ 'online' => (bool)$this->cfg('online', true) ];
		[ $upid, $done ] = $this->task($this->call('POST', "{$g['path']}/migrate", $p), "the migration of {$what}");
		return [ [ 'vmid' => $g['vmid'], 'node' => $target, 'type' => $g['type'], 'task' => $upid ], "{$what} " . ($done ? 'migrated' : 'migrating') . " to {$target}" ];
	    case 'delete':
		[ $upid, $done ] = $this->task($this->call('DELETE', $g['path'], [ 'purge' => true, 'destroy-unreferenced-disks' => true ]), "the deletion of {$what}");
		return [ [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'type' => $g['type'], 'deleted' => (bool)$done, 'task' => $upid ], "{$what} " . ($done ? 'deleted' : 'being deleted') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    protected function listGuests(\Closure $text) {
	$names = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text('names'))), fn($x) => $x !== ''));
	$status = isset(self::STATUS[(string)$this->cfg('status', 'any')]) ? (string)$this->cfg('status', 'any') : 'any';
	$type = isset(self::TYPES[(string)$this->cfg('kind', 'any')]) ? (string)$this->cfg('kind', 'any') : 'any';
	$node = $text('node');
	$tag = $text('tag');
	$templates = (bool)$this->cfg('templates', false);
	$all = array_values(array_filter((array)$this->call('GET', '/cluster/resources', [ 'type' => 'vm' ]), function($g) use ($names, $status, $type, $node, $tag, $templates) {
	    if (!isset($g['vmid']) || (!$templates && !empty($g['template'])))
		return false;
	    if ($status !== 'any' && ($g['status'] ?? '') !== $status)
		return false;
	    if ($type !== 'any' && ($g['type'] ?? '') !== $type)
		return false;
	    if ($node !== '' && strcasecmp((string)($g['node'] ?? ''), $node) !== 0)
		return false;
	    if ($tag !== '' && !in_array(strtolower($tag), array_map('strtolower', preg_split('/[;,\s]+/', (string)($g['tags'] ?? ''))), true))
		return false;
	    if ($names) {
		foreach($names as $n)
		    if (fnmatch($n, (string)($g['name'] ?? ''), FNM_CASEFOLD) || $n === (string)$g['vmid'])
			return true;
		return false;
	    }
	    return true;
	}));
	usort($all, fn($a, $b) => (int)$a['vmid'] <=> (int)$b['vmid']);
	return $this->listResult($all, 'guest');
    }

    //a power action; done already when the guest is so
    protected function power(array $g, $action, $what) {
	if ($action === 'reset' && $g['type'] === 'lxc')
	    throw new \RuntimeException('Reset: only for VMs (a container: Reboot)');
	[ $after, $doneWhen ] = self::AFTER[$action];
	$now = (string)($this->pve->request('GET', "{$g['path']}/status/current")['status'] ?? $g['status']);
	if ($doneWhen !== null && $now === $doneWhen)
	    return [ [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'type' => $g['type'], 'action' => $action, 'status' => $now, 'already' => true, 'task' => null ], "{$what}: {$now} already" ];
	$p = [];
	if ($action === 'shutdown')
	    $p = [ 'timeout' => self::number($this->cfg('shutdownTimeout', 120), 'Shutdown timeout (s)', 1, 3600, 120), 'forceStop' => (bool)$this->cfg('forceStop', false) ];
	[ $upid, $done ] = $this->task($this->call('POST', "{$g['path']}/status/{$action}", $p), "{$action} of {$what}");
	return [ [ 'vmid' => $g['vmid'], 'node' => $g['node'], 'type' => $g['type'], 'action' => $action, 'status' => $done ? $after : $now, 'already' => false, 'task' => $upid ],
	    "{$what}: " . ($done ? $after : "{$action} started") ];
    }

    protected function cloneGuest(array $g, \Closure $text, $what) {
	$id = $text('newId') !== '' ? self::number($text('newId'), 'New VMID', 100, 999999999) : (int)$this->pve->request('GET', '/cluster/nextid');
	$p = [ 'newid' => $id, 'full' => (bool)$this->cfg('full', true) ];
	if ($text('newName') !== '')
	    $p[$g['type'] === 'lxc' ? 'hostname' : 'name'] = $text('newName');
	if ($text('target') !== '')
	    $p['target'] = self::nodeName($text('target'), 'Target node');
	if ($text('storage') !== '' && $p['full'])
	    $p['storage'] = $text('storage');
	[ $upid, $done ] = $this->task($this->call('POST', "{$g['path']}/clone", $p), "the clone of {$what}");
	return [ [ 'vmid' => $id, 'node' => $p['target'] ?? $g['node'], 'name' => $text('newName'), 'task' => $upid ], "{$what} " . ($done ? 'cloned' : 'being cloned') . " to {$id}" ];
    }
}
