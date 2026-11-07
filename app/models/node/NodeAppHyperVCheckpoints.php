<?php

/*
    CLD: 2026-11-01T16:00:00+02:00: new step: Hyper-V Checkpoints - the checkpoints (snapshots) of a Hyper-V VM (PowerShell: the Hyper-V module). Operations (setting `operation`):
	list       its checkpoints, oldest first (Get-VMSnapshot): { name, id, created (ISO 8601), parent, type, current }; current: the one the VM runs from
	create     a checkpoint (Checkpoint-VM; Production or Standard as the VM is set): the checkpoint
	restore    the VM restored to a checkpoint (Restore-VMSnapshot)
	remove     a checkpoint removed (Remove-VMSnapshot; its children too when chosen); Hyper-V merges its disk in the background
	removeAll  every checkpoint removed
	prune      the checkpoints older than N days (and named like a pattern) removed, oldest first: { removed, count }
    The VM: its name or ID (nf9find); a checkpoint: its name (exactly) or its ID (several of that name: an error)
*/

namespace models\node;

class NodeAppHyperVCheckpoints extends NodeAppHyperV {
    public static $name = 'hyperv-checkpoints';
    public static $label = 'Hyper-V Checkpoints';
    public static $icon = 'bi-camera';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'checkpoints';

    const OPERATIONS = [
	'list' => 'List checkpoints',
	'create' => 'Create a checkpoint',
	'restore' => 'Restore a checkpoint',
	'remove' => 'Remove a checkpoint',
	'removeAll' => 'Remove all checkpoints',
	'prune' => 'Remove old checkpoints',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'create', 'restore', 'remove', 'removeAll', 'prune' ];
    const LISTS = [ 'list' ];

    //nf9cp <checkpoint> <the VM's current checkpoint ID>: a checkpoint as JSON; nf9snap <VM> <name or ID>: a checkpoint of the VM
    const HELPERS = <<<'PS'
function nf9cp($s, $cur) {
  [ordered]@{ name = $s.Name; id = "$($s.Id)"; created = $s.CreationTime.ToUniversalTime().ToString('o'); parent = $s.ParentSnapshotName; type = "$($s.SnapshotType)"; current = ("$($s.Id)" -eq "$cur") }
}
function nf9snap($v, $n) {
  $n = "$n".Trim()
  if ($n -eq '') { throw 'Checkpoint: empty' }
  $f = @(Get-VMSnapshot -VM $v | Where-Object { $_.Name -eq $n -or "$($_.Id)" -eq $n })
  if ($f.Count -eq 0) { throw "Checkpoint: VM '$($v.Name)' has none named '$n'" }
  if ($f.Count -gt 1) { throw "Checkpoint: several are named '$n': give its ID" }
  $f[0]
}
$v = nf9find $P.vm

PS;

    protected static function operationFields() {
	return [
	    self::field('vm', 'VM', array_keys(self::OPERATIONS), [ 'required' => true, 'help' => 'Its name (exactly) or its ID', 'ui' => [ 'expression' => true ] ]),
	    self::field('cpName', 'Name', [ 'create' ], [ 'required' => true, 'default' => 'Before the workflow', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('checkpoint', 'Checkpoint', [ 'restore', 'remove' ], [ 'required' => true, 'help' => 'Its name (exactly) or its ID', 'ui' => [ 'expression' => true, 'width' => 8 ] ]),
	    self::field('children', 'Its children too', [ 'remove' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('days', 'Older than (days)', [ 'prune' ], [ 'required' => true, 'default' => '7', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('pattern', 'Named', [ 'prune' ], [ 'help' => '* any text (e.g. Before*); empty: any name', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ name, id, created, parent, type, current }';
    }

    protected static function resultInfo($op) {
	return match($op) {
	    'prune', 'removeAll' => [ 'type' => 'object', 'info' => '{ removed, count }', 'fields' => [ 'removed' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a checkpoint removed' ] ],
		'count' => [ 'type' => 'number' ] ] ],
	    default => [ 'type' => 'object', 'info' => '{ name, id, created, parent, type, current }', 'fields' => [ 'name' => [ 'type' => 'string' ], 'id' => [ 'type' => 'string' ],
		'created' => [ 'type' => 'string', 'info' => 'ISO 8601' ] ] ],
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$vm = self::vmRef($value('vm'));
	switch($operation) {
	    case 'list':
		return $this->listResult($this->psOn($operation, $vm, ',@(Get-VMSnapshot -VM $v | Sort-Object CreationTime | ForEach-Object { nf9cp $_ $v.ParentSnapshotId })'), 'checkpoint');
	    case 'create':
		$name = mb_substr($text('cpName'), 0, 100);
		if ($name === '')
		    throw new \RuntimeException('Name: empty');
		$r = (array)$this->psOn($operation, $vm, <<<'PS'
Checkpoint-VM -VM $v -SnapshotName $P.name
$v = Get-VM -Id $v.Id
nf9cp (Get-VMSnapshot -VM $v | Where-Object { $_.Name -eq $P.name } | Sort-Object CreationTime | Select-Object -Last 1) $v.ParentSnapshotId
PS, [ 'name' => $name ]);
		return [ $r, "VM {$vm}: checkpoint {$name} created" ];
	    case 'restore':
		$r = (array)$this->psOn($operation, $vm, "\$s = nf9snap \$v \$P.checkpoint\nRestore-VMSnapshot -VMSnapshot \$s -Confirm:\$false\nnf9cp \$s \$s.Id\n", [ 'checkpoint' => $text('checkpoint') ]);
		return [ $r, "VM {$vm}: restored to " . ($r['name'] ?? $text('checkpoint')) ];
	    case 'remove':
		$r = (array)$this->psOn($operation, $vm, "\$s = nf9snap \$v \$P.checkpoint\n\$r = nf9cp \$s \$v.ParentSnapshotId\n"
		    . "if (\$P.children) { Remove-VMSnapshot -VMSnapshot \$s -IncludeAllChildSnapshots -Confirm:\$false } else { Remove-VMSnapshot -VMSnapshot \$s -Confirm:\$false }\n\$r\n",
		    [ 'checkpoint' => $text('checkpoint'), 'children' => (bool)$this->cfg('children', false) ]);
		return [ $r, "VM {$vm}: checkpoint " . ($r['name'] ?? $text('checkpoint')) . ' removed' ];
	    case 'removeAll':
	    case 'prune':
		$days = $operation === 'prune' ? self::number($text('days'), 'Older than (days)', 0, 36500) : null;
		$removed = $this->psOn($operation, $vm, <<<'PS'
$all = @(Get-VMSnapshot -VM $v | Sort-Object CreationTime)
if ($P.days -ne $null) {
  $before = (Get-Date).AddDays(-[int]$P.days)
  $all = @($all | Where-Object { $_.CreationTime -lt $before -and ("$($P.pattern)" -eq '' -or $_.Name -like $P.pattern) })
}
$out = foreach ($s in $all) { $r = nf9cp $s $v.ParentSnapshotId; Remove-VMSnapshot -VMSnapshot $s -Confirm:$false; $r }
,@($out)
PS, [ 'days' => $days, 'pattern' => $text('pattern') ]);
		$removed = is_array($removed) ? array_values($removed) : [];
		return [ [ 'removed' => $removed, 'count' => count($removed) ], "VM {$vm}: " . \util\Format::plural(count($removed), 'checkpoint') . ' removed' . ($days !== null ? " (older than {$days} days)" : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //CLD: 2026-11-07T01:09:01+02:00: PowerShell $script (after HELPERS) for operation $operation on VM $vm, with parameters $p
    private function psOn($operation, $vm, $script, array $p = []) {
	return $this->ps($operation, self::HELPERS . $script, [ 'vm' => $vm ] + $p);
    }
}
