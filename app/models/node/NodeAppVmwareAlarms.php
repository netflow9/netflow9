<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new step: VMware Alarms - the triggered alarms of a vCenter Server (VI/JSON, vCenter 8.0 U1 and later). Operations (setting `operation`):
	list         the alarms triggered now on any object (the root folder's triggeredAlarmState), red or yellow and red, of VMs / hosts / ... or any, acknowledged ones left out
	             unless chosen; each named (the alarm's and the object's names): { key, alarm: { id, name }, entity: { type, id, name }, status, time, acknowledged,
	             acknowledgedBy, acknowledgedTime }, red first, newest first
	acknowledge  an alarm acknowledged (AlarmManager AcknowledgeAlarm): an alarm of the list or of the VMware alarm trigger, or its key (alarm-7|vm-42)
    alarms() is the VMware alarm trigger's reading too
*/

namespace models\node;

class NodeAppVmwareAlarms extends NodeAppVmware {
    public static $name = 'vmware-alarms';
    public static $label = 'VMware Alarms';
    public static $icon = 'bi-bell';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'alarms';

    const OPERATIONS = [
	'list' => 'List triggered alarms',
	'acknowledge' => 'Acknowledge an alarm',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'acknowledge' ];
    const LISTS = [ 'list' ];
    const STATUS = [ 'yellow' => 'Yellow (warning) and red', 'red' => 'Red (alert) only' ];
    const ENTITIES = [ 'any' => 'Any object', 'VirtualMachine' => 'VMs', 'HostSystem' => 'Hosts', 'Datastore' => 'Datastores', 'ClusterComputeResource' => 'Clusters',
	'Datacenter' => 'Datacenters', 'Network' => 'Networks' ];

    protected static function operationFields() {
	return [
	    self::field('status', 'Status', [ 'list' ], [ 'type' => 'select', 'default' => 'yellow', 'choices' => self::STATUS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('entityType', 'Of', [ 'list' ], [ 'type' => 'select', 'default' => 'any', 'choices' => self::ENTITIES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('acknowledged', 'Also acknowledged ones', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('alarm', 'Alarm', [ 'acknowledge' ], [ 'required' => true, 'help' => 'e.g. {{ alarm }} from the VMware alarm trigger, or its key (alarm-7|vm-42)', 'ui' => [ 'expression' => true ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ key, alarm: { id, name }, entity: { type, id, name }, status, time, acknowledged }';
    }

    protected static function resultInfo($op) {
	return [ 'type' => 'object', 'info' => '{ alarm, entity, acknowledged }', 'fields' => [ 'alarm' => [ 'type' => 'string' ], 'entity' => [ 'type' => 'string' ],
	    'acknowledged' => [ 'type' => 'boolean' ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		$all = self::alarms($this->vc, (string)$this->cfg('status', 'yellow'), (string)$this->cfg('entityType', 'any'), (bool)$this->cfg('acknowledged', false));
		return $this->listResult($all, 'alarm');
	    case 'acknowledge':
		[ $alarm, $entity, $type ] = self::keyOf($value('alarm'));
		$this->invoke('AlarmManager/AlarmManager/AcknowledgeAlarm', [ 'alarm' => \util\VSphere::moref($alarm, 'Alarm'), 'entity' => \util\VSphere::moref($entity, $type) ]);
		return [ [ 'alarm' => $alarm, 'entity' => $entity, 'acknowledged' => true ], "alarm {$alarm} on {$entity} acknowledged" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //[ alarm id, entity id, entity type ] of an alarm item, or of a key alarm-7|vm-42 (vCenter's own alarm-7.vm-42 too)
    public static function keyOf($value) {
	if (is_array($value) && isset($value['alarm']))
	    return [ (string)(is_array($value['alarm']) ? ($value['alarm']['id'] ?? '') : $value['alarm']), (string)($value['entity']['id'] ?? ''),
		($value['entity']['type'] ?? '') !== '' ? (string)$value['entity']['type'] : null ];
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['key'] ?? '') : $value));
	if (!preg_match('/^(alarm-\d+)[|.]([a-z]+-[a-z]?\d+)$/', $v, $m))
	    throw new \RuntimeException('Alarm: not an alarm of VMware Alarms or of the VMware alarm trigger, nor a key (alarm-7|vm-42): `' . mb_substr($v, 0, 64) . '`');
	return [ $m[1], $m[2], \util\VSphere::typeOf($m[2]) ];
    }

    //the alarms triggered now: $min yellow / red, of entities of $type ('any' or '': any), acknowledged ones too or not; named; red first, newest first
    public static function alarms(\util\VSphere $vc, $min = 'yellow', $type = '', $acknowledged = false) {
	$content = $vc->vim('GET', 'ServiceInstance/ServiceInstance/content');
	$root = (string)($content['rootFolder']['value'] ?? 'group-d1');
	$states = $vc->vim('GET', "Folder/{$root}/triggeredAlarmState");
	$names = [];
	$out = [];
	foreach(is_array($states) ? $states : [] as $s) {
	    $status = (string)($s['overallStatus'] ?? '');
	    if (!in_array($status, [ 'yellow', 'red' ], true) || ($min === 'red' && $status !== 'red'))
		continue;
	    if (!$acknowledged && !empty($s['acknowledged']))
		continue;
	    $etype = (string)($s['entity']['type'] ?? '');
	    if ($type !== '' && $type !== 'any' && $etype !== $type)
		continue;
	    $alarm = (string)($s['alarm']['value'] ?? '');
	    $entity = (string)($s['entity']['value'] ?? '');
	    $out[] = [ 'key' => "{$alarm}|{$entity}", 'alarm' => [ 'id' => $alarm, 'name' => self::nameOf($vc, $names, 'Alarm', $alarm, 'info') ],
		'entity' => [ 'type' => $etype, 'id' => $entity, 'name' => self::nameOf($vc, $names, $etype, $entity, 'name') ], 'status' => $status, 'time' => (string)($s['time'] ?? ''),
		'acknowledged' => (bool)($s['acknowledged'] ?? false), 'acknowledgedBy' => $s['acknowledgedByUser'] ?? null, 'acknowledgedTime' => $s['acknowledgedTime'] ?? null ];
	}
	usort($out, fn($a, $b) => [ $b['status'] === 'red', $b['time'] ] <=> [ $a['status'] === 'red', $a['time'] ]);
	return $out;
    }

    //CLD: 2026-11-07T01:10:30+02:00: the name of object $type/$id (read once: $names keeps them; property $path of it); its id when it is gone or not readable
    private static function nameOf(\util\VSphere $vc, array &$names, $type, $id, $path) {
	$k = "{$type}/{$id}";
	if (!array_key_exists($k, $names)) {
	    try {
		$r = $vc->vim('GET', "{$type}/{$id}/{$path}");
		$names[$k] = (string)($path === 'info' ? ($r['name'] ?? '') : $r);
	    } catch (\RuntimeException $e) {
		//removed meanwhile, or not readable: its id is shown
		$names[$k] = '';
	    }
	}
	return $names[$k] !== '' ? $names[$k] : $id;
    }

    //an alarm for the log: the alarm's name on the object, its status
    public static function describe(array $a) {
	return ($a['alarm']['name'] ?? '?') . ' on ' . ($a['entity']['name'] ?? '?') . ' (' . ($a['status'] ?? '?') . ')';
    }
}
