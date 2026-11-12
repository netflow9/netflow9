<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune Devices - the tenant's managed devices (deviceManagement/managedDevices). Operations (setting `operation`):
	list         the devices: by operating system, compliance, ownership, user, a part of the name, the serial number, and an OData filter
	get          a device
	apps         the apps found on it (detectedApps)
	compliance   its compliance policies' states (deviceCompliancePolicyStates)
	sync, restart, lock, shutdown, locate, resetPasscode, defenderScan, defenderSignatures: remote actions (Intune sends them at the device's next check-in)
	retire       company data and settings removed, the device no longer managed (personal data kept)
	wipe         back to factory settings (keep the enrollment data / the user's data: Windows)
	delete       the device's record removed from Intune
    A device: its id, or its name / serial number (exactly one device). A device of the result: { id, url (Intune admin center), name, user, userName, email, os, osVersion,
    compliance, ownership, model, manufacturer, serialNumber, enrolled, lastSync, encrypted, azureADDeviceId, managementAgent, fields (all, as Graph gives them) }
*/

namespace models\node;

class NodeAppIntuneDevices extends NodeAppIntune {
    public static $name = 'intune-devices';
    public static $label = 'Intune Devices';
    public static $icon = 'bi-phone';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'devices';

    const OPERATIONS = [
	'list' => 'List devices',
	'get' => 'Get a device',
	'apps' => 'List its apps',
	'compliance' => 'List its compliance states',
	'sync' => 'Sync',
	'restart' => 'Restart',
	'lock' => 'Lock',
	'shutdown' => 'Shut down',
	'locate' => 'Locate',
	'resetPasscode' => 'Reset the passcode',
	'defenderScan' => 'Defender scan',
	'defenderSignatures' => 'Update Defender signatures',
	'retire' => 'Retire',
	'wipe' => 'Wipe',
	'delete' => 'Delete',
    ];
    const WRITES = [ 'sync', 'restart', 'lock', 'shutdown', 'locate', 'resetPasscode', 'defenderScan', 'defenderSignatures', 'retire', 'wipe', 'delete' ];
    const LISTS = [ 'list', 'apps', 'compliance' ];
    //the remote actions: operation => Graph's action
    const ACTIONS = [ 'sync' => 'syncDevice', 'restart' => 'rebootNow', 'lock' => 'remoteLock', 'shutdown' => 'shutDown', 'locate' => 'locateDevice', 'resetPasscode' => 'resetPasscode',
	'defenderScan' => 'windowsDefenderScan', 'defenderSignatures' => 'windowsDefenderUpdateSignatures', 'retire' => 'retire', 'wipe' => 'wipe' ];
    const COMPLIANCE = [ '' => 'Any', 'compliant' => 'Compliant', 'noncompliant' => 'Not compliant', 'inGracePeriod' => 'In grace period', 'unknown' => 'Unknown', 'conflict' => 'Conflict',
	'error' => 'Error', 'configManager' => 'Configuration Manager' ];
    const OWNERSHIP = [ '' => 'Any', 'company' => 'Corporate', 'personal' => 'Personal' ];
    const PATH = '/deviceManagement/managedDevices';

    protected static function operationFields() {
	$one = array_values(array_diff(array_keys(self::OPERATIONS), [ 'list' ]));
	return [
	    self::field('os', 'Operating system', [ 'list' ], [ 'help' => 'e.g. Windows, macOS, iOS, iPadOS, Android; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('compliance', 'Compliance', [ 'list' ], [ 'type' => 'select', 'choices' => self::COMPLIANCE, 'ui' => [ 'width' => 4 ] ]),
	    self::field('ownership', 'Ownership', [ 'list' ], [ 'type' => 'select', 'choices' => self::OWNERSHIP, 'ui' => [ 'width' => 4 ] ]),
	    self::field('user', 'User', [ 'list' ], [ 'help' => 'The user\'s principal name (UPN), e.g. ana@contoso.com', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('name', 'Name contains', [ 'list' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('serial', 'Serial number', [ 'list' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('odataFilter', 'Filter', [ 'list' ], [ 'help' => 'An OData $filter added to those above, e.g. lastSyncDateTime le 2026-10-01T00:00:00Z',
		'ui' => [ 'expression' => 'text', 'code' => true, 'advanced' => true ] ]),
	    self::field('device', 'Device', $one, [ 'required' => true, 'help' => 'Its id, a device of a list or a trigger, or its name or serial number (exactly one device)',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('fullScan', 'Full scan', [ 'defenderScan' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Off: a quick scan', 'ui' => [ 'width' => 6 ] ]),
	    self::field('keepEnrollment', 'Keep the enrollment data', [ 'wipe' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Windows: the device stays enrolled after the wipe', 'ui' => [ 'width' => 6 ] ]),
	    self::field('keepUserData', 'Keep the user\'s data', [ 'wipe' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Windows: the user\'s files kept', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    public static function adminUrl($id) {
	return 'https://intune.microsoft.com/#view/Microsoft_Intune_Devices/DeviceSettingsMenuBlade/~/overview/mdmDeviceId/' . rawurlencode((string)$id);
    }

    //a device of an answer, simplified (see the header); `fields` all of them
    public static function deviceOf(array $d) {
	unset($d['@odata.context'], $d['@odata.etag']);
	return [
	    'id' => $d['id'] ?? null,
	    'url' => isset($d['id']) ? self::adminUrl($d['id']) : null,
	    'name' => $d['deviceName'] ?? null,
	    'user' => ($d['userPrincipalName'] ?? '') !== '' ? $d['userPrincipalName'] : null,
	    'userName' => ($d['userDisplayName'] ?? '') !== '' ? $d['userDisplayName'] : null,
	    'email' => ($d['emailAddress'] ?? '') !== '' ? $d['emailAddress'] : null,
	    'os' => $d['operatingSystem'] ?? null,
	    'osVersion' => $d['osVersion'] ?? null,
	    'compliance' => $d['complianceState'] ?? null,
	    'ownership' => $d['managedDeviceOwnerType'] ?? null,
	    'model' => $d['model'] ?? null,
	    'manufacturer' => $d['manufacturer'] ?? null,
	    'serialNumber' => ($d['serialNumber'] ?? '') !== '' ? $d['serialNumber'] : null,
	    'enrolled' => $d['enrolledDateTime'] ?? null,
	    'lastSync' => $d['lastSyncDateTime'] ?? null,
	    'encrypted' => $d['isEncrypted'] ?? null,
	    'azureADDeviceId' => $d['azureADDeviceId'] ?? null,
	    'managementAgent' => $d['managementAgent'] ?? null,
	    'fields' => $d,
	];
    }

    //the list's $filter of the settings
    protected function listFilter(\Closure $text) {
	$q = [ \util\Intune::class, 'quote' ];
	return self::filter([
	    ($t = $text('os')) !== '' ? 'operatingSystem eq ' . $q($t) : '',
	    isset(self::COMPLIANCE[$c = (string)$this->cfg('compliance', '')]) && $c !== '' ? "complianceState eq '{$c}'" : '',
	    isset(self::OWNERSHIP[$o = (string)$this->cfg('ownership', '')]) && $o !== '' ? "managedDeviceOwnerType eq '{$o}'" : '',
	    ($t = $text('user')) !== '' ? 'userPrincipalName eq ' . $q($t) : '',
	    ($t = $text('name')) !== '' ? 'contains(deviceName,' . $q($t) . ')' : '',
	    ($t = $text('serial')) !== '' ? 'serialNumber eq ' . $q($t) : '',
	    $text('odataFilter'),
	]);
    }

    //a device's id: its id, a device ({ id }), or its name / serial number (exactly one device)
    protected function deviceId($value) {
	if (is_array($value) && isset($value['id']))
	    return \util\Intune::id($value['id']);
	$t = trim(\util\Expression::toText($value));
	if (preg_match(\util\Intune::GUID, $t))
	    return $t;
	if ($t === '')
	    throw new \RuntimeException('Device: empty');
	$q = \util\Intune::quote($t);
	[ $found ] = $this->intune->pages(self::PATH, [ '$filter' => "deviceName eq {$q} or serialNumber eq {$q}", '$select' => 'id,deviceName,serialNumber' ], 10);
	if (count($found) !== 1)
	    throw new \RuntimeException('Device: ' . ($found ? count($found) . " devices are named `{$t}`: give the id" : "no device named `{$t}` or with that serial number"));
	return (string)$found[0]['id'];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->intune;
	if ($operation === 'list') {
	    [ $all, $truncated ] = $G->pages(self::PATH, [ '$filter' => $this->listFilter($text), '$top' => min(1000, $this->maxItems() + 1) ], $this->maxItems());
	    $items = array_map(fn($d) => self::deviceOf((array)$d), $all);
	    return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'device') . ($truncated ? ', more left out' : '') ];
	}
	$id = $this->deviceId($value('device'));
	$path = self::PATH . '/' . rawurlencode($id);
	switch($operation) {
	    case 'get':
		$d = self::deviceOf($G->request('GET', $path));
		return [ $d, "{$d['name']}: {$d['os']} {$d['osVersion']}, {$d['compliance']}" ];
	    case 'apps':
		$apps = array_map(fn($a) => [ 'id' => $a['id'] ?? null, 'name' => $a['displayName'] ?? null, 'version' => $a['version'] ?? null, 'publisher' => $a['publisher'] ?? null,
		    'platform' => $a['platform'] ?? null, 'size' => $a['sizeInByte'] ?? null ], (array)($G->request('GET', $path, [ '$expand' => 'detectedApps' ])['detectedApps'] ?? []));
		usort($apps, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
		return $this->listResult($apps, 'app');
	    case 'compliance':
		[ $all ] = $G->pages("{$path}/deviceCompliancePolicyStates", [], self::MAX_ITEMS);
		$list = array_map(fn($s) => [ 'id' => $s['id'] ?? null, 'name' => $s['displayName'] ?? null, 'state' => $s['state'] ?? null, 'platform' => $s['platformType'] ?? null,
		    'settings' => array_map(fn($x) => [ 'setting' => $x['settingName'] ?? $x['setting'] ?? null, 'state' => $x['state'] ?? null, 'current' => $x['currentValue'] ?? null,
			'errorDescription' => $x['errorDescription'] ?? null ], (array)($s['settingStates'] ?? [])) ], $all);
		return $this->listResult($list, 'policy state');
	    case 'delete':
		$G->request('DELETE', $path);
		return [ [ 'id' => $id, 'deleted' => true ], "device {$id} deleted" ];
	}
	if (!isset(self::ACTIONS[$operation]))
	    throw new \RuntimeException("unknown operation `{$operation}`");
	$body = match($operation) {
	    'defenderScan' => [ 'quickScan' => !$this->cfg('fullScan', false) ],
	    'wipe' => [ 'keepEnrollmentData' => (bool)$this->cfg('keepEnrollment', false), 'keepUserData' => (bool)$this->cfg('keepUserData', false) ],
	    default => null,
	};
	$G->request('POST', "{$path}/" . self::ACTIONS[$operation], [], $body);
	return [ [ 'id' => $id, 'action' => $operation, 'sent' => true ], self::OPERATIONS[$operation] . " sent to {$id}" ];
    }
}
