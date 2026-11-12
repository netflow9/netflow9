<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune Apps - the apps Intune deploys (deviceAppManagement/mobileApps) and the apps found on the devices (deviceManagement/detectedApps).
    Operations (setting `operation`):
	apps                the managed apps: { id, name, type, publisher, assigned, created, modified }, a part of the name, assigned or not
	app                 one, with its assignments: { id, intent, target (group / allLicensedUsers / allDevices), groupId }
	assign              an assignment added: required, available, uninstall or available without enrollment, to a group (its Entra object id), all users or all devices;
	                    the same one already there: nothing done
	unassign            the app's assignments to that target removed (of an intent, or all)
	discovered          the apps found on the devices: { id, name, version, publisher, platform, devices (how many) }, a part of the name
	discoveredDevices   the devices one of those is on: { id, name }
    An app: its id, an app of a list, or its name (exactly one)
*/

namespace models\node;

class NodeAppIntuneApps extends NodeAppIntune {
    public static $name = 'intune-apps';
    public static $label = 'Intune Apps';
    public static $icon = 'bi-grid-3x3-gap';
    public static $defaultOperation = 'apps';
    public static $defaultVariable = 'apps';

    const OPERATIONS = [
	'apps' => 'List apps',
	'app' => 'Get an app',
	'assign' => 'Assign an app',
	'unassign' => 'Remove an assignment',
	'discovered' => 'List discovered apps',
	'discoveredDevices' => 'List the devices of a discovered app',
    ];
    const WRITES = [ 'assign', 'unassign' ];
    const LISTS = [ 'apps', 'discovered', 'discoveredDevices' ];
    const INTENTS = [ 'required' => 'Required', 'available' => 'Available', 'uninstall' => 'Uninstall', 'availableWithoutEnrollment' => 'Available without enrollment' ];
    const TARGETS = [ 'group' => 'A group', 'allUsers' => 'All users', 'allDevices' => 'All devices' ];
    //Graph's target types
    const TARGET_TYPES = [ 'group' => 'groupAssignmentTarget', 'allUsers' => 'allLicensedUsersAssignmentTarget', 'allDevices' => 'allDevicesAssignmentTarget' ];
    const APPS = '/deviceAppManagement/mobileApps';

    protected static function operationFields() {
	return [
	    self::field('search', 'Name contains', [ 'apps', 'discovered' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('assignedOnly', 'Assigned only', [ 'apps' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('app', 'App', [ 'app', 'assign', 'unassign', 'discoveredDevices' ], [ 'required' => true, 'help' => 'Its id, an app of a list, or its name (discovered: of a discovered app)',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('intent', 'Intent', [ 'assign', 'unassign' ], [ 'type' => 'select', 'default' => 'required', 'choices' => self::INTENTS, 'help' => 'Remove: only the assignments of this intent, unless All intents',
		'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	    self::field('target', 'To', [ 'assign', 'unassign' ], [ 'type' => 'select', 'required' => true, 'default' => 'group', 'choices' => self::TARGETS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('group', 'Group ID', [ 'assign', 'unassign' ], [ 'help' => 'The Entra group\'s object id (To: a group)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('allIntents', 'All intents', [ 'unassign' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function type($odata) {
	return $odata !== null ? preg_replace('/^#?microsoft\.graph\./', '', (string)$odata) : null;
    }

    public static function appOf(array $a) {
	return [ 'id' => $a['id'] ?? null, 'name' => $a['displayName'] ?? null, 'type' => self::type($a['@odata.type'] ?? null), 'publisher' => $a['publisher'] ?? null,
	    'assigned' => $a['isAssigned'] ?? null, 'created' => $a['createdDateTime'] ?? null, 'modified' => $a['lastModifiedDateTime'] ?? null ];
    }

    public static function assignmentOf(array $a) {
	$type = (string)self::type($a['target']['@odata.type'] ?? '');
	return [ 'id' => $a['id'] ?? null, 'intent' => $a['intent'] ?? null, 'target' => (string)(array_search($type, self::TARGET_TYPES, true) ?: $type),
	    'groupId' => $a['target']['groupId'] ?? null ];
    }

    //an app's id of $base (the managed apps, the discovered ones): its id, an app ({ id }), or its name (exactly one)
    protected function appId($base, $value) {
	if (is_array($value) && isset($value['id']))
	    return \util\Intune::id($value['id'], 'App');
	$t = trim(\util\Expression::toText($value));
	if (preg_match(\util\Intune::GUID, $t) || ($base !== self::APPS && preg_match('/^[0-9a-f]{40,}$/i', $t)))
	    return $t;
	[ $all ] = $this->intune->pages($base, [ '$select' => 'id,displayName' ], self::MAX_ITEMS);
	$found = array_values(array_filter($all, fn($a) => strcasecmp((string)($a['displayName'] ?? ''), $t) === 0));
	if (count($found) !== 1)
	    throw new \RuntimeException('App: ' . ($found ? count($found) . " apps are named `{$t}`: give the id" : "no app named `{$t}`"));
	return (string)$found[0]['id'];
    }

    //the target of assign / unassign: [ key, groupId or null ]
    protected function target(\Closure $text) {
	$target = (string)$this->cfg('target', 'group');
	if (!isset(self::TARGETS[$target]))
	    throw new \RuntimeException('To: not one of the choices');
	if ($target !== 'group')
	    return [ $target, null ];
	$g = $text('group');
	if (!preg_match(\util\Intune::GUID, $g))
	    throw new \RuntimeException('Group ID: the Entra group\'s object id (a GUID)');
	return [ $target, strtolower($g) ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->intune;
	$s = $text('search');
	switch($operation) {
	    case 'apps':
		[ $all ] = $G->pages(self::APPS, $this->cfg('assignedOnly', false) ? [ '$filter' => 'isAssigned eq true' ] : [], self::MAX_ITEMS);
		$list = array_values(array_filter(array_map(fn($a) => self::appOf((array)$a), $all), fn($a) => $s === '' || stripos((string)$a['name'], $s) !== false));
		usort($list, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
		return $this->listResult($list, 'app');
	    case 'discovered':
		[ $all ] = $G->pages('/deviceManagement/detectedApps', [], self::MAX_ITEMS);
		$list = array_values(array_filter(array_map(fn($a) => [ 'id' => $a['id'] ?? null, 'name' => $a['displayName'] ?? null, 'version' => $a['version'] ?? null,
		    'publisher' => $a['publisher'] ?? null, 'platform' => $a['platform'] ?? null, 'devices' => (int)($a['deviceCount'] ?? 0) ], $all), fn($a) => $s === '' || stripos((string)$a['name'], $s) !== false));
		usort($list, fn($a, $b) => $b['devices'] <=> $a['devices'] ?: strcasecmp((string)$a['name'], (string)$b['name']));
		return $this->listResult($list, 'discovered app');
	    case 'discoveredDevices':
		$id = $this->appId('/deviceManagement/detectedApps', $value('app'));
		[ $all, $truncated ] = $G->pages('/deviceManagement/detectedApps/' . rawurlencode($id) . '/managedDevices', [], $this->maxItems());
		$items = array_map(fn($d) => [ 'id' => $d['id'] ?? null, 'name' => $d['deviceName'] ?? null ], $all);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'device') . ($truncated ? ', more left out' : '') ];
	}
	$id = $this->appId(self::APPS, $value('app'));
	$path = self::APPS . '/' . rawurlencode($id);
	$assignments = fn() => array_map(fn($a) => self::assignmentOf((array)$a), $G->pages("{$path}/assignments", [], self::MAX_ITEMS)[0]);
	switch($operation) {
	    case 'app':
		$a = $G->request('GET', $path);
		$r = self::appOf($a) + [ 'assignments' => $assignments(), 'fields' => array_diff_key($a, [ '@odata.context' => 1, 'largeIcon' => 1 ]) ];
		return [ $r, "{$r['name']}: " . \util\Format::plural(count($r['assignments']), 'assignment') ];
	    case 'assign':
		[ $target, $group ] = $this->target($text);
		$intent = (string)$this->cfg('intent', 'required');
		if (!isset(self::INTENTS[$intent]))
		    throw new \RuntimeException('Intent: not one of the choices');
		foreach($assignments() as $a)
		    if ($a['target'] === $target && strtolower((string)$a['groupId']) === (string)$group && $a['intent'] === $intent)
			return [ $a + [ 'app' => $id, 'changed' => false ], "already {$intent} for " . ($group ?? self::TARGETS[$target]) ];
		$made = $G->request('POST', "{$path}/assignments", [], [ '@odata.type' => '#microsoft.graph.mobileAppAssignment', 'intent' => $intent,
		    'target' => [ '@odata.type' => '#microsoft.graph.' . self::TARGET_TYPES[$target] ] + ($group !== null ? [ 'groupId' => $group ] : []) ]);
		return [ self::assignmentOf($made) + [ 'app' => $id, 'changed' => true ], "{$intent} for " . ($group ?? self::TARGETS[$target]) ];
	    case 'unassign':
		[ $target, $group ] = $this->target($text);
		$intent = (string)$this->cfg('intent', 'required');
		$removed = [];
		foreach($assignments() as $a)
		    if ($a['target'] === $target && strtolower((string)$a['groupId']) === (string)$group && ($this->cfg('allIntents', false) || $a['intent'] === $intent)) {
			$G->request('DELETE', "{$path}/assignments/" . rawurlencode((string)$a['id']));
			$removed[] = $a;
		    }
		return [ [ 'app' => $id, 'removed' => $removed, 'count' => count($removed) ], \util\Format::plural(count($removed), 'assignment') . ' removed' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
