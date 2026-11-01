<?php

/*
    CLD: 2026-11-01T04:00:00+02:00: new step: Veeam Restore - Instant Recovery of VMware vSphere machines (an advanced step: it starts machines). Operations (setting `operation`):
	instant   a machine started from a restore point (POST /api/v1/restore/instantRecovery/vSphere/vm: to its original place, powered on or not, with a reason): the mount;
	          to another place, network or name: Other parameters (the body's other fields, e.g. "type": "Customized", "destination": {...})
	mounts    the Instant Recovery mounts (GET .../vSphere/vm)
	unmount   a machine stopped and removed from the host (POST .../vSphere/vm/{mountId}/unmount)
    Other restores (entire machine, files, application items, other platforms): the Veeam API step
*/

namespace models\node;

class NodeAppVeeamRestore extends NodeAppVeeam {
    public static $name = 'veeam-restore';
    public static $label = 'Veeam Restore';
    public static $icon = 'bi-arrow-counterclockwise';
    public static $audience = 'advanced';
    public static $defaultOperation = 'instant';
    public static $defaultVariable = 'restore';

    const OPERATIONS = [
	'instant' => 'Instant Recovery (vSphere)',
	'mounts' => 'List Instant Recovery mounts',
	'unmount' => 'Stop an Instant Recovery',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'instant', 'unmount' ];
    const LISTS = [ 'mounts' ];
    const PATH = '/api/v1/restore/instantRecovery/vSphere/vm';

    protected static function operationFields() {
	return [
	    self::field('restorePoint', 'Restore point', [ 'instant' ], [ 'required' => true, 'help' => 'Its id (e.g. {{ backup.restorePoint.id }} from Check a machine\'s latest restore point)',
		'ui' => [ 'expression' => true ] ]),
	    self::field('powerUp', 'Power on', [ 'instant' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 4 ] ]),
	    self::field('reason', 'Reason', [ 'instant' ], [ 'help' => 'Kept in Veeam\'s history', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('mount', 'Mount', [ 'unmount' ], [ 'required' => true, 'help' => 'The mount\'s id (e.g. {{ restore.id }} after Instant Recovery)', 'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'instant':
		$point = $value('restorePoint');
		$id = self::uuid(is_array($point) ? ($point['id'] ?? '') : $point, 'Restore point');
		$r = $this->post(self::PATH, [ 'restorePointId' => $id, 'type' => 'OriginalLocation', 'powerUp' => (bool)$this->cfg('powerUp', true),
		    'reason' => mb_substr($text('reason') !== '' ? $text('reason') : 'NetFlow9 workflow', 0, 500) ]);
		return [ $r, "Instant Recovery from {$id} started" . (isset($r['id']) ? ", mount {$r['id']}" : '') ];
	    case 'mounts':
		return $this->listOf(self::PATH, [], 'mount');
	    case 'unmount':
		$mount = $value('mount');
		$id = self::uuid(is_array($mount) ? ($mount['id'] ?? '') : $mount, 'Mount');
		return [ $this->post(self::PATH . "/{$id}/unmount"), "mount {$id} stopping" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
