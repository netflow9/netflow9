<?php

/*
    CLD: 2026-11-01T16:00:00+02:00: new step: Hyper-V VMs - the virtual machines of a Hyper-V host (PowerShell: the Hyper-V module). Operations (setting `operation`):
	list       the VMs (Get-VM: names, * patterns; a state): NodeAppHyperV nf9vm { name, id, state, status, cpuUsage, memoryAssigned, uptimeSeconds, heartbeat, replicationHealth, ... }
	get        one VM
	start      Start-VM (running already: done); resume: Resume-VM of a paused VM; save: Save-VM; pause: Suspend-VM; turnOff: Stop-VM -TurnOff; so already: done (already: true)
	shutdown   the guest OS shut down (Stop-VM through the integration services, as a job): waited for up to the seconds given, then turned off when chosen (forced: true), else an
	           error; off already: done
	reboot     the guest OS restarted (Restart-VM -Type Reboot); reset: Restart-VM (a hard reset)
	network    its network adapters with their IP addresses (Get-VMNetworkAdapter; the guest's addresses through the integration services)
	delete     a VM removed (Remove-VM: it must be off); its virtual disks deleted too when chosen (not with checkpoints)
    An action's result: { name, id, action, state, already, forced }. The VM: its name (exactly) or its ID (nf9find)
*/

namespace models\node;

class NodeAppHyperVVms extends NodeAppHyperV {
    public static $name = 'hyperv-vms';
    public static $label = 'Hyper-V VMs';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'vms';

    const OPERATIONS = [
	'list' => 'List VMs',
	'get' => 'Get a VM',
	'start' => 'Start',
	'shutdown' => 'Shut down the guest OS',
	'reboot' => 'Restart the guest OS',
	'turnOff' => 'Turn off',
	'save' => 'Save',
	'pause' => 'Pause',
	'resume' => 'Resume',
	'reset' => 'Reset',
	'network' => 'Get its network adapters',
	'delete' => 'Delete',
    ];
    const LISTS = [ 'list' ];
    const STATES = [ 'any' => 'Any', 'Running' => 'Running', 'Off' => 'Off', 'Saved' => 'Saved', 'Paused' => 'Paused' ];
    //the simple actions: [ the cmdlet's line, the state in which it is done already (null: never) ]
    const SIMPLE = [
	'start' => [ 'Start-VM -VM $v', 'Running' ],
	'turnOff' => [ 'Stop-VM -VM $v -TurnOff -Force', 'Off' ],
	'save' => [ 'Save-VM -VM $v', 'Saved' ],
	'pause' => [ 'Suspend-VM -VM $v', 'Paused' ],
	'resume' => [ 'Resume-VM -VM $v', 'Running' ],
	'reset' => [ 'Restart-VM -VM $v -Force', null ],
	'reboot' => [ 'Restart-VM -VM $v -Type Reboot -Force', null ],
    ];

    protected static function operationFields() {
	$one = array_values(array_diff(array_keys(self::OPERATIONS), [ 'list' ]));
	return [
	    self::field('names', 'Names', [ 'list' ], [ 'help' => 'One per line or comma separated; * any text (web*); empty: every VM', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::STATES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('vm', 'VM', $one, [ 'required' => true, 'help' => 'Its name (exactly) or its ID', 'ui' => [ 'expression' => true ] ]),
	    self::field('wait', 'Wait for the shutdown (s)', [ 'shutdown' ], [ 'type' => 'number', 'default' => 90, 'help' => 'Within the step\'s Timeout', 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('turnOff', 'Then turn it off', [ 'shutdown' ], [ 'type' => 'switch', 'default' => false, 'help' => 'When still running after the wait', 'ui' => [ 'width' => 6 ] ]),
	    self::field('disks', 'Delete its virtual disks too', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Not for a VM with checkpoints', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ name, id, state, status, cpuUsage, memoryAssigned, uptimeSeconds, heartbeat, replicationHealth, ... }';
    }

    protected static function resultInfo($op) {
	if (isset(self::SIMPLE[$op]) || $op === 'shutdown')
	    return [ 'type' => 'object', 'info' => '{ name, id, action, state, already, forced }', 'fields' => [ 'name' => [ 'type' => 'string' ], 'id' => [ 'type' => 'string' ],
		'state' => [ 'type' => 'string', 'info' => 'Running, Off, Saved, Paused, ...' ], 'already' => [ 'type' => 'boolean', 'info' => 'it was so already' ],
		'forced' => [ 'type' => 'boolean', 'info' => 'turned off after the shutdown wait' ] ] ];
	return match($op) {
	    'get' => [ 'type' => 'object', 'info' => 'the VM', 'fields' => [ 'name' => [ 'type' => 'string' ], 'id' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ],
		'cpuUsage' => [ 'type' => 'number', 'info' => '%' ], 'memoryAssigned' => [ 'type' => 'number', 'info' => 'bytes' ], 'uptimeSeconds' => [ 'type' => 'number' ],
		'heartbeat' => [ 'type' => 'string' ], 'replicationHealth' => [ 'type' => 'string' ], 'checkpoint' => [ 'type' => 'string', 'info' => 'the current checkpoint' ] ] ],
	    'network' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ name, macAddress, switchName, ipAddresses, status }' ] ],
	    default => parent::resultInfo($op),
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $state = isset(self::STATES[(string)$this->cfg('state', 'any')]) ? (string)$this->cfg('state', 'any') : 'any';
	    $all = $this->ps('list', <<<'PS'
$out = foreach ($v in (Get-VM | Sort-Object Name)) {
  if ($P.state -ne 'any' -and "$($v.State)" -ne $P.state) { continue }
  if (@($P.names).Count -gt 0) {
    $hit = $false
    foreach ($n in @($P.names)) { if ($v.Name -like $n) { $hit = $true } }
    if (-not $hit) { continue }
  }
  nf9vm $v
}
,@($out)
PS, [ 'names' => self::names($value('names')), 'state' => $state ]);
	    return $this->listResult($all, 'VM');
	}
	$vm = self::vmRef($value('vm'));
	if (isset(self::SIMPLE[$operation])) {
	    [ $line, $doneIn ] = self::SIMPLE[$operation];
	    $r = (array)$this->ps($operation, "\$v = nf9find \$P.vm\n"
		. ($doneIn !== null ? "if (\"\$(\$v.State)\" -eq '{$doneIn}') { nf9done \$v '{$operation}' \$true \$false; return }\n" : '')
		. "{$line}\nnf9done \$v '{$operation}' \$false \$false\n", [ 'vm' => $vm ]);
	    return [ $r, "VM " . ($r['name'] ?? $vm) . ': ' . ($r['state'] ?? '?') . (!empty($r['already']) ? ' already' : '') ];
	}
	switch($operation) {
	    case 'get':
		$r = (array)$this->ps('get', 'nf9vm (nf9find $P.vm)', [ 'vm' => $vm ]);
		return [ $r, 'VM ' . ($r['name'] ?? $vm) . ': ' . ($r['state'] ?? '?') ];
	    case 'shutdown':
		$wait = self::number($this->cfg('wait', 90), 'Wait for the shutdown (s)', 1, 3600, 90);
		$wait = max(1, min($wait, $this->win->left() - 15));
		$r = (array)$this->ps('shutdown', <<<'PS'
$v = nf9find $P.vm
if ("$($v.State)" -eq 'Off') { nf9done $v 'shutdown' $true $false; return }
$j = Stop-VM -VM $v -Force -AsJob
$null = Wait-Job $j -Timeout $P.wait
$v = Get-VM -Id $v.Id
if ("$($v.State)" -ne 'Off') {
  $why = (@($j.ChildJobs | ForEach-Object { $_.JobStateInfo.Reason.Message }) | Where-Object { $_ }) -join ' '
  if (-not $P.turnOff) { throw "the guest OS did not shut down within $($P.wait) s $why".Trim() }
  Stop-Job $j
  Stop-VM -VM $v -TurnOff -Force
  nf9done $v 'shutdown' $false $true
  return
}
Remove-Job $j -Force
nf9done $v 'shutdown' $false $false
PS, [ 'vm' => $vm, 'wait' => $wait, 'turnOff' => (bool)$this->cfg('turnOff', false) ]);
		return [ $r, 'VM ' . ($r['name'] ?? $vm) . ': ' . (!empty($r['already']) ? 'Off already' : (!empty($r['forced']) ? "still running after {$wait} s, turned off" : 'shut down')) ];
	    case 'network':
		$r = $this->ps('network', <<<'PS'
$v = nf9find $P.vm
,@(Get-VMNetworkAdapter -VM $v | ForEach-Object { [ordered]@{ name = $_.Name; macAddress = $_.MacAddress; switchName = $_.SwitchName; ipAddresses = @($_.IPAddresses); status = "$($_.Status)" } })
PS, [ 'vm' => $vm ]);
		$r = is_array($r) ? array_values($r) : [];
		return [ $r, "VM {$vm}: " . \util\Format::plural(count($r), 'network adapter') ];
	    case 'delete':
		$r = (array)$this->ps('delete', <<<'PS'
$v = nf9find $P.vm
if ("$($v.State)" -ne 'Off') { throw "VM '$($v.Name)' is $($v.State): turn it off first" }
$disks = @()
if ($P.disks) {
  if (@(Get-VMSnapshot -VM $v).Count -gt 0) { throw "VM '$($v.Name)' has checkpoints: remove them first, or keep its disks" }
  $disks = @(Get-VMHardDiskDrive -VM $v | ForEach-Object { $_.Path } | Where-Object { $_ })
}
Remove-VM -VM $v -Force
foreach ($d in $disks) { Remove-Item -LiteralPath $d -Force }
[ordered]@{ name = $v.Name; id = "$($v.Id)"; deleted = $true; disksDeleted = $disks }
PS, [ 'vm' => $vm, 'disks' => (bool)$this->cfg('disks', false) ]);
		return [ $r, 'VM ' . ($r['name'] ?? $vm) . ' deleted' . (!empty($r['disksDeleted']) ? ', with ' . \util\Format::plural(count($r['disksDeleted']), 'disk') : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
