<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune Autopilot - the Windows Autopilot devices (deviceManagement/windowsAutopilotDeviceIdentities). Operations (setting `operation`):
	list          the devices: a part of the serial number, a group tag
	get           a device
	import        a device added by its hardware hash (importedWindowsAutopilotDeviceIdentities), with a group tag and a user; Intune imports it in a few minutes
	importStatus  an import's state: unknown, pending, partial, complete, error (its device id when complete)
	update        its group tag, display name, user (updateDeviceProperties)
	delete        removed from Autopilot
    A device: its id, a device of a list, or its serial number. A device of the result: { id, serialNumber, model, manufacturer, groupTag, user, enrollmentState, lastContacted,
    managedDeviceId, azureADDeviceId, purchaseOrder, fields }
*/

namespace models\node;

class NodeAppIntuneAutopilot extends NodeAppIntune {
    public static $name = 'intune-autopilot';
    public static $label = 'Intune Autopilot';
    public static $icon = 'bi-windows';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'autopilot';

    const OPERATIONS = [
	'list' => 'List Autopilot devices',
	'get' => 'Get an Autopilot device',
	'import' => 'Import a device',
	'importStatus' => 'Get an import\'s state',
	'update' => 'Update a device',
	'delete' => 'Delete a device',
    ];
    const WRITES = [ 'import', 'update', 'delete' ];
    const LISTS = [ 'list' ];
    const PATH = '/deviceManagement/windowsAutopilotDeviceIdentities';
    const IMPORTS = '/deviceManagement/importedWindowsAutopilotDeviceIdentities';

    protected static function operationFields() {
	return [
	    self::field('serial', 'Serial number', [ 'list', 'import' ], [ 'help' => 'List: a part of it', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'import' ] ],
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('device', 'Device', [ 'get', 'update', 'delete' ], [ 'required' => true, 'help' => 'Its id, a device of a list, or its serial number', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('import', 'Import', [ 'importStatus' ], [ 'required' => true, 'help' => 'The id Import a device gave, or its result', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('hash', 'Hardware hash', [ 'import' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Base64, as Get-WindowsAutopilotInfo writes it (Hardware Hash column)',
		'ui' => [ 'rows' => 3, 'expression' => 'text', 'code' => true ] ]),
	    self::field('groupTag', 'Group tag', [ 'list', 'import', 'update' ], [ 'help' => 'List: exactly this tag; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('user', 'User', [ 'import', 'update' ], [ 'help' => 'The user\'s principal name (UPN); update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('displayName', 'Display name', [ 'update' ], [ 'help' => 'The name the device gets at its setup; empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	];
    }

    public static function deviceOf(array $d) {
	unset($d['@odata.context']);
	return [ 'id' => $d['id'] ?? null, 'serialNumber' => $d['serialNumber'] ?? null, 'model' => $d['model'] ?? null, 'manufacturer' => $d['manufacturer'] ?? null,
	    'groupTag' => ($d['groupTag'] ?? '') !== '' ? $d['groupTag'] : null, 'user' => ($d['userPrincipalName'] ?? '') !== '' ? $d['userPrincipalName'] : null,
	    'enrollmentState' => $d['enrollmentState'] ?? null, 'lastContacted' => $d['lastContactedDateTime'] ?? null,
	    'managedDeviceId' => in_array($d['managedDeviceId'] ?? '', [ '', '00000000-0000-0000-0000-000000000000' ], true) ? null : $d['managedDeviceId'],
	    'azureADDeviceId' => $d['azureActiveDirectoryDeviceId'] ?? null, 'purchaseOrder' => ($d['purchaseOrderIdentifier'] ?? '') !== '' ? $d['purchaseOrderIdentifier'] : null, 'fields' => $d ];
    }

    public static function importOf(array $i) {
	return [ 'id' => $i['id'] ?? null, 'serialNumber' => $i['serialNumber'] ?? null, 'groupTag' => $i['groupTag'] ?? null, 'user' => $i['assignedUserPrincipalName'] ?? null,
	    'status' => $i['state']['deviceImportStatus'] ?? null, 'deviceId' => ($i['state']['deviceRegistrationId'] ?? '') !== '' ? $i['state']['deviceRegistrationId'] : null,
	    'errorCode' => $i['state']['deviceErrorCode'] ?? null, 'error' => ($i['state']['deviceErrorName'] ?? '') !== '' ? $i['state']['deviceErrorName'] : null ];
    }

    //a device's id: its id, a device ({ id }), or its serial number
    protected function deviceId($value) {
	if (is_array($value) && isset($value['id']))
	    return \util\Intune::id($value['id']);
	$t = trim(\util\Expression::toText($value));
	if (preg_match(\util\Intune::GUID, $t))
	    return $t;
	if ($t === '')
	    throw new \RuntimeException('Device: empty');
	[ $found ] = $this->intune->pages(self::PATH, [ '$filter' => 'contains(serialNumber,' . \util\Intune::quote($t) . ')' ], 50);
	$found = array_values(array_filter($found, fn($d) => strcasecmp((string)($d['serialNumber'] ?? ''), $t) === 0));
	if (count($found) !== 1)
	    throw new \RuntimeException('Device: ' . ($found ? count($found) . " Autopilot devices have the serial number `{$t}`: give the id" : "no Autopilot device with the serial number `{$t}`"));
	return (string)$found[0]['id'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->intune;
	switch($operation) {
	    case 'list':
		$tag = $text('groupTag');
		$serial = $text('serial');
		[ $all, $truncated ] = $G->pages(self::PATH, [ '$filter' => $serial !== '' ? 'contains(serialNumber,' . \util\Intune::quote($serial) . ')' : '' ], $tag !== '' ? self::MAX_ITEMS : $this->maxItems());
		$all = array_map(fn($d) => self::deviceOf((array)$d), $all);
		if ($tag === '')
		    return [ [ 'items' => $all, 'count' => count($all), 'truncated' => $truncated ], \util\Format::plural(count($all), 'Autopilot device') . ($truncated ? ', more left out' : '') ];
		return $this->listResult(array_filter($all, fn($d) => strcasecmp((string)$d['groupTag'], $tag) === 0), 'Autopilot device');
	    case 'import':
		$hash = preg_replace('/\s+/', '', $text('hash'));
		if ($hash === '' || base64_decode($hash, true) === false)
		    throw new \RuntimeException('Hardware hash: not Base64');
		if (($serial = $text('serial')) === '')
		    throw new \RuntimeException('Serial number: required to import');
		$made = $G->request('POST', self::IMPORTS, [], array_filter([ '@odata.type' => '#microsoft.graph.importedWindowsAutopilotDeviceIdentity', 'serialNumber' => $serial,
		    'hardwareIdentifier' => $hash, 'groupTag' => $text('groupTag'), 'assignedUserPrincipalName' => $text('user'), 'productKey' => '' ], fn($v) => $v !== ''));
		$i = self::importOf($made);
		return [ $i, "{$serial} imported: " . ($i['status'] ?? '?') ];
	    case 'importStatus':
		$v = $value('import');
		$i = self::importOf($G->request('GET', self::IMPORTS . '/' . rawurlencode(\util\Intune::id($v, 'Import'))));
		return [ $i, "{$i['serialNumber']}: {$i['status']}" . ($i['error'] ? " ({$i['error']})" : '') ];
	}
	$id = $this->deviceId($value('device'));
	$path = self::PATH . '/' . rawurlencode($id);
	switch($operation) {
	    case 'get':
		$d = self::deviceOf($G->request('GET', $path));
		return [ $d, "{$d['serialNumber']}: " . ($d['groupTag'] ?? 'no group tag') . ", {$d['enrollmentState']}" ];
	    case 'update':
		$props = array_filter([ 'groupTag' => $text('groupTag'), 'displayName' => $text('displayName'), 'userPrincipalName' => $text('user') ], fn($v) => $v !== '');
		if (!$props)
		    throw new \RuntimeException('nothing to change');
		if (isset($props['userPrincipalName']))
		    $props['addressableUserName'] = $props['userPrincipalName'];
		$G->request('POST', "{$path}/updateDeviceProperties", [], $props);
		return [ self::deviceOf($G->request('GET', $path)), "{$id} updated: " . implode(', ', array_keys($props)) ];
	    case 'delete':
		$G->request('DELETE', $path);
		return [ [ 'id' => $id, 'deleted' => true ], "Autopilot device {$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
