<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new step: VMware VMs - the virtual machines of a vCenter Server (REST API). Operations (setting `operation`):
	list        the VMs (GET /api/vcenter/vm: names, * patterns matched here; power state, host, cluster, folder, datacenter): { vm, name, power_state, cpu_count, memory_size_MiB }
	get         one VM (GET /api/vcenter/vm/{vm}: hardware, disks, NICs, ...), its id added as `vm`
	power       its power state (GET .../power): { vm, state }
	start / stop (power off) / suspend / reset   POST .../power?action=...; already so: done (already: true); { vm, action, state, already }
	shutdown    the guest OS shut down through VMware Tools (POST .../guest/power?action=shutdown); off already: done; optionally waited for until powered off, and powered off
	            when still on at the step's timeout
	reboot      the guest OS restarted through VMware Tools
	guest       the guest's identity (OS, host name, IP) and network interfaces (VMware Tools running): { identity, interfaces }
	clone       a VM cloned (POST /api/vcenter/vm?action=clone: the source, the new name, a folder, powered on or not; other placement: Other parameters): { vm, name }
	delete      a VM deleted from disk (it must be powered off)
    The VM: its id (vm-42) or its name (exactly; NodeAppVmware::resolve)
*/

namespace models\node;

class NodeAppVmwareVms extends NodeAppVmware {
    public static $name = 'vmware-vms';
    public static $label = 'VMware VMs';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'vms';

    const OPERATIONS = [
	'list' => 'List VMs',
	'get' => 'Get a VM',
	'power' => 'Get its power state',
	'start' => 'Power on',
	'shutdown' => 'Shut down the guest OS',
	'reboot' => 'Restart the guest OS',
	'stop' => 'Power off',
	'suspend' => 'Suspend',
	'reset' => 'Reset',
	'guest' => 'Get guest information',
	'clone' => 'Clone a VM',
	'delete' => 'Delete a VM',
    ];
    const LISTS = [ 'list' ];
    const POWER = [ 'start' => 'POWERED_ON', 'stop' => 'POWERED_OFF', 'suspend' => 'SUSPENDED', 'reset' => 'POWERED_ON' ];
    const STATES = [ 'any' => 'Any', 'POWERED_ON' => 'Powered on', 'POWERED_OFF' => 'Powered off', 'SUSPENDED' => 'Suspended' ];
    //seconds between two reads of the power state (shutdown, waiting)
    const POLL = 5;

    protected static function operationFields() {
	$one = [ 'get', 'power', 'start', 'stop', 'suspend', 'reset', 'shutdown', 'reboot', 'guest', 'delete' ];
	return [
	    self::field('names', 'Names', [ 'list' ], [ 'help' => 'One per line or comma separated; * any text (web*); empty: every VM', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'Power state', [ 'list' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::STATES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('host', 'Host', [ 'list' ], [ 'help' => 'Name or id; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 3, 'newRow' => true ] ]),
	    self::field('cluster', 'Cluster', [ 'list' ], [ 'help' => 'Name or id; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('folder', 'Folder', [ 'list' ], [ 'help' => 'Name or id; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('datacenter', 'Datacenter', [ 'list' ], [ 'help' => 'Name or id; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('vm', 'VM', array_merge($one, [ 'clone' ]), [ 'required' => true, 'help' => 'Its name (exactly) or its id (vm-42); clone: the source', 'ui' => [ 'expression' => true ] ]),
	    self::field('newName', 'New name', [ 'clone' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cloneFolder', 'Folder', [ 'clone' ], [ 'help' => 'Name or id; empty: the source\'s', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('powerOn', 'Power on', [ 'clone' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 3 ] ]),
	    self::field('wait', 'Wait until powered off', [ 'shutdown' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6 ] ]),
	    self::field('force', 'Then power off', [ 'shutdown' ], [ 'type' => 'switch', 'default' => false, 'help' => 'When still on near the timeout', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ vm, name, power_state, cpu_count, memory_size_MiB }';
    }

    protected static function resultInfo($op) {
	if (isset(self::POWER[$op]) || in_array($op, [ 'shutdown', 'reboot' ], true))
	    return [ 'type' => 'object', 'info' => '{ vm, action, state, already }', 'fields' => [ 'vm' => [ 'type' => 'string' ], 'action' => [ 'type' => 'string' ],
		'state' => [ 'type' => 'string', 'info' => 'POWERED_ON, POWERED_OFF, SUSPENDED' ], 'already' => [ 'type' => 'boolean', 'info' => 'it was so already' ] ] ];
	return match($op) {
	    'power' => [ 'type' => 'object', 'info' => '{ vm, state }', 'fields' => [ 'vm' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string', 'info' => 'POWERED_ON, POWERED_OFF, SUSPENDED' ] ] ],
	    'guest' => [ 'type' => 'object', 'info' => '{ identity, interfaces }', 'fields' => [ 'identity' => [ 'type' => 'object', 'info' => '{ name, family, full_name, host_name, ip_address }' ],
		'interfaces' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ mac_address, nic, ip: { ip_addresses } }' ] ] ] ],
	    'clone' => [ 'type' => 'object', 'info' => '{ vm, name }', 'fields' => [ 'vm' => [ 'type' => 'string', 'info' => 'the new VM\'s id' ], 'name' => [ 'type' => 'string' ] ] ],
	    'get' => [ 'type' => 'object', 'info' => 'the VM, as vCenter gives it, with `vm`', 'fields' => [ 'vm' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ],
		'power_state' => [ 'type' => 'string' ], 'guest_OS' => [ 'type' => 'string' ], 'cpu' => [ 'type' => 'object' ], 'memory' => [ 'type' => 'object' ] ] ],
	    default => parent::resultInfo($op),
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list')
	    return $this->listVms($text, $value);
	if ($operation === 'clone')
	    return $this->cloneVm($text, $value);
	$vm = $this->resolve('vm', $value('vm'));
	switch($operation) {
	    case 'get':
		$r = $this->get("/api/vcenter/vm/{$vm}");
		return [ [ 'vm' => $vm ] + (array)$r, "VM {$vm}" . (isset($r['name']) ? " ({$r['name']})" : '') . ': ' . ($r['power_state'] ?? '?') ];
	    case 'power':
		$state = (string)($this->get("/api/vcenter/vm/{$vm}/power")['state'] ?? '');
		return [ [ 'vm' => $vm, 'state' => $state ], "VM {$vm}: {$state}" ];
	    case 'start':
	    case 'stop':
	    case 'suspend':
	    case 'reset':
		$already = false;
		try {
		    $this->post("/api/vcenter/vm/{$vm}/power", [ 'action' => $operation ]);
		} catch (\util\VSphereError $e) {
		    if ($e->type !== 'ALREADY_IN_DESIRED_STATE')
			throw $e;
		    $already = true;
		}
		$state = self::POWER[$operation];
		return [ [ 'vm' => $vm, 'action' => $operation, 'state' => $state, 'already' => $already ], "VM {$vm}: {$state}" . ($already ? ' already' : '') ];
	    case 'shutdown':
		return $this->shutdown($vm);
	    case 'reboot':
		$this->post("/api/vcenter/vm/{$vm}/guest/power", [ 'action' => 'reboot' ]);
		return [ [ 'vm' => $vm, 'action' => 'reboot', 'state' => 'POWERED_ON', 'already' => false ], "VM {$vm}: guest OS restarting" ];
	    case 'guest':
		$identity = $this->get("/api/vcenter/vm/{$vm}/guest/identity");
		$interfaces = $this->vc->request('GET', "/api/vcenter/vm/{$vm}/guest/networking/interfaces");
		return [ [ 'identity' => $identity, 'interfaces' => is_array($interfaces) ? $interfaces : [] ],
		    "VM {$vm}: " . trim(($identity['full_name']['default_message'] ?? $identity['family'] ?? 'guest') . (isset($identity['ip_address']) ? ", {$identity['ip_address']}" : '')) ];
	    case 'delete':
		$this->vc->request('DELETE', "/api/vcenter/vm/{$vm}");
		return [ [ 'vm' => $vm, 'deleted' => true ], "VM {$vm} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    protected function listVms(\Closure $text, \Closure $value) {
	$q = [];
	$names = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text('names'))), fn($x) => $x !== ''));
	$patterns = array_values(array_filter($names, fn($x) => str_contains($x, '*')));
	//exact names: vCenter filters; any pattern: every VM read, matched here
	if ($names && !$patterns)
	    $q['names'] = $names;
	if (isset(self::STATES[(string)$this->cfg('state', 'any')]) && (string)$this->cfg('state', 'any') !== 'any')
	    $q['power_states'] = (string)$this->cfg('state');
	foreach([ 'host' => 'hosts', 'cluster' => 'clusters', 'folder' => 'folders', 'datacenter' => 'datacenters' ] as $key => $filter) {
	    if ($text($key) !== '')
		$q[$filter] = $this->resolve($key, $value($key));
	}
	$all = (array)$this->vc->request('GET', '/api/vcenter/vm', array_replace($q, $this->extraParams()));
	if ($patterns) {
	    $all = array_values(array_filter($all, function($vm) use ($names) {
		foreach($names as $n) {
		    if (fnmatch($n, (string)($vm['name'] ?? ''), FNM_CASEFOLD))
			return true;
		}
		return false;
	    }));
	}
	usort($all, fn($a, $b) => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
	return $this->listResult($all, 'VM');
    }

    protected function cloneVm(\Closure $text, \Closure $value) {
	$source = $this->resolve('vm', $value('vm'), 'VM (the source)');
	$name = $text('newName');
	if ($name === '')
	    throw new \RuntimeException('New name: empty');
	$body = [ 'source' => $source, 'name' => mb_substr($name, 0, 80), 'power_on' => (bool)$this->cfg('powerOn', false) ];
	if ($text('cloneFolder') !== '')
	    $body['placement'] = [ 'folder' => $this->resolve('folder', $value('cloneFolder')) ];
	$id = $this->post('/api/vcenter/vm', [ 'action' => 'clone' ], $body);
	return [ [ 'vm' => (string)$id, 'name' => $body['name'] ], "{$source} cloned to {$id} ({$body['name']})" ];
    }

    //the guest shut down; off already: done; waited for until off (and powered off at the timeout when chosen)
    protected function shutdown($vm) {
	$state = (string)($this->vc->request('GET', "/api/vcenter/vm/{$vm}/power")['state'] ?? '');
	if ($state === 'POWERED_OFF')
	    return [ [ 'vm' => $vm, 'action' => 'shutdown', 'state' => 'POWERED_OFF', 'already' => true ], "VM {$vm}: POWERED_OFF already" ];
	$this->post("/api/vcenter/vm/{$vm}/guest/power", [ 'action' => 'shutdown' ]);
	if (!$this->cfg('wait', true))
	    return [ [ 'vm' => $vm, 'action' => 'shutdown', 'state' => $state, 'already' => false ], "VM {$vm}: guest OS shutting down" ];
	$force = (bool)$this->cfg('force', false);
	while(true) {
	    $state = (string)($this->vc->request('GET', "/api/vcenter/vm/{$vm}/power")['state'] ?? '');
	    if ($state === 'POWERED_OFF')
		return [ [ 'vm' => $vm, 'action' => 'shutdown', 'state' => 'POWERED_OFF', 'already' => false ], "VM {$vm}: guest OS shut down" ];
	    //the last seconds kept for the power off
	    if ($this->vc->left() <= self::POLL + ($force ? 10 : 1))
		break;
	    sleep(self::POLL);
	}
	if (!$force)
	    throw new \RuntimeException("VM {$vm} is still {$state}: raise the step's Timeout, or choose Then power off");
	try {
	    $this->vc->request('POST', "/api/vcenter/vm/{$vm}/power", [ 'action' => 'stop' ]);
	} catch (\util\VSphereError $e) {
	    if ($e->type !== 'ALREADY_IN_DESIRED_STATE')
		throw $e;
	}
	return [ [ 'vm' => $vm, 'action' => 'shutdown', 'state' => 'POWERED_OFF', 'already' => false, 'forced' => true ], "VM {$vm}: still on at the timeout, powered off" ];
    }
}
