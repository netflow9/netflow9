<?php

/*
    CLD: 2026-11-01T16:00:00+02:00: new step: Hyper-V Host - the Hyper-V host itself (PowerShell). Operations (setting `operation`):
	host         the host: { name, os, build, logicalProcessors, memoryTotal, memoryFree, memoryFreePercent, vmPath, vhdPath, vms, running, uptimeSeconds }
	volumes      its local volumes (Win32_LogicalDisk, fixed disks): { drive, label, size, free, freePercent, lowOnSpace }
	switches     its virtual switches (Get-VMSwitch): { name, id, type, adapter }
	replication  the replication of its VMs (Get-VMReplication): { vm, state, health, mode, primaryServer, replicaServer, lastReplication }
	events       the errors and warnings of the Hyper-V event logs (VMMS, Worker) of the last hours, newest first: { time, level, id, log, message }
*/

namespace models\node;

class NodeAppHyperVHost extends NodeAppHyperV {
    public static $name = 'hyperv-host';
    public static $label = 'Hyper-V Host';
    public static $icon = 'bi-server';
    public static $defaultOperation = 'host';
    public static $defaultVariable = 'host';

    const OPERATIONS = [
	'host' => 'Get the host',
	'volumes' => 'List volumes',
	'switches' => 'List virtual switches',
	'replication' => 'List VM replication',
	'events' => 'List Hyper-V errors and warnings',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [];
    const LISTS = [ 'volumes', 'switches', 'replication', 'events' ];
    const LEVELS = [ 'errors' => 'Errors', 'warnings' => 'Errors and warnings' ];

    protected static function operationFields() {
	return [
	    self::field('lowPercent', 'Low on space under (%)', [ 'volumes' ], [ 'type' => 'number', 'default' => 10, 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('hours', 'Of the last (hours)', [ 'events' ], [ 'type' => 'number', 'default' => 24, 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('level', 'Level', [ 'events' ], [ 'type' => 'select', 'default' => 'warnings', 'choices' => self::LEVELS, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return match($op) {
	    'volumes' => '{ drive, label, size, free, freePercent, lowOnSpace }',
	    'switches' => '{ name, id, type, adapter }',
	    'replication' => '{ vm, state, health, mode, primaryServer, replicaServer, lastReplication }',
	    'events' => '{ time, level, id, log, message }',
	    default => parent::itemInfo($op),
	};
    }

    protected static function resultInfo($op) {
	return [ 'type' => 'object', 'info' => '{ name, os, build, logicalProcessors, memoryTotal, memoryFree, memoryFreePercent, vms, running, uptimeSeconds }', 'fields' => [
	    'name' => [ 'type' => 'string' ], 'os' => [ 'type' => 'string' ], 'memoryFreePercent' => [ 'type' => 'number' ], 'vms' => [ 'type' => 'number' ], 'running' => [ 'type' => 'number' ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'host':
		$r = (array)$this->ps('host', <<<'PS'
$h = Get-VMHost
$os = Get-CimInstance Win32_OperatingSystem
$vms = @(Get-VM)
[ordered]@{ name = $h.ComputerName; os = $os.Caption; build = $os.Version; logicalProcessors = $h.LogicalProcessorCount
  memoryTotal = [int64]$os.TotalVisibleMemorySize * 1024; memoryFree = [int64]$os.FreePhysicalMemory * 1024
  vmPath = $h.VirtualMachinePath; vhdPath = $h.VirtualHardDiskPath; vms = $vms.Count
  running = @($vms | Where-Object { "$($_.State)" -eq 'Running' }).Count; uptimeSeconds = [int64]((Get-Date) - $os.LastBootUpTime).TotalSeconds }
PS);
		$r['memoryFreePercent'] = !empty($r['memoryTotal']) ? round((float)($r['memoryFree'] ?? 0) * 100 / (float)$r['memoryTotal'], 1) : null;
		return [ $r, ($r['name'] ?? 'host') . ': ' . (int)($r['running'] ?? 0) . ' of ' . \util\Format::plural((int)($r['vms'] ?? 0), 'VM') . ' running' ];
	    case 'volumes':
		$low = max(0, min(100, (float)$this->cfg('lowPercent', 10)));
		$all = $this->ps('volumes', ",@(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object { [ordered]@{ drive = \$_.DeviceID; label = \$_.VolumeName; size = [int64]\$_.Size; free = [int64]\$_.FreeSpace } })");
		return $this->listResult($all, 'volume', function($d) use ($low) {
		    $d = (array)$d;
		    $d['freePercent'] = !empty($d['size']) ? round((float)$d['free'] * 100 / (float)$d['size'], 1) : null;
		    $d['lowOnSpace'] = $d['freePercent'] !== null && $d['freePercent'] < $low;
		    return $d;
		});
	    case 'switches':
		return $this->listResult($this->ps('switches', ',@(Get-VMSwitch | ForEach-Object { [ordered]@{ name = $_.Name; id = "$($_.Id)"; type = "$($_.SwitchType)"; adapter = $_.NetAdapterInterfaceDescription } })'), 'switch');
	    case 'replication':
		return $this->listResult($this->ps('replication', <<<'PS'
,@(Get-VMReplication | ForEach-Object {
  $last = $null
  if ($_.LastReplicationTime) { $last = $_.LastReplicationTime.ToUniversalTime().ToString('o') }
  [ordered]@{ vm = $_.VMName; state = "$($_.State)"; health = "$($_.Health)"; mode = "$($_.Mode)"; primaryServer = $_.PrimaryServer; replicaServer = $_.ReplicaServer; lastReplication = $last }
})
PS), 'replicated VM');
	    case 'events':
		$hours = self::number($this->cfg('hours', 24), 'Of the last (hours)', 1, 8760, 24);
		$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
		return $this->listResult($this->ps('events', <<<'PS'
$levels = @(1, 2)
if ($P.level -eq 'warnings') { $levels = @(1, 2, 3) }
$f = @{ LogName = @('Microsoft-Windows-Hyper-V-VMMS-Admin', 'Microsoft-Windows-Hyper-V-Worker-Admin'); Level = $levels; StartTime = (Get-Date).AddHours(-[int]$P.hours) }
,@(Get-WinEvent -FilterHashtable $f -MaxEvents $P.max -ErrorAction SilentlyContinue | ForEach-Object {
  [ordered]@{ time = $_.TimeCreated.ToUniversalTime().ToString('o'); level = $_.LevelDisplayName; id = $_.Id; log = $_.LogName; message = $_.Message }
})
PS, [ 'hours' => $hours, 'level' => isset(self::LEVELS[(string)$this->cfg('level', 'warnings')]) ? (string)$this->cfg('level', 'warnings') : 'warnings', 'max' => $max + 1 ]), 'event');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
