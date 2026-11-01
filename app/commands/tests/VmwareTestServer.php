<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new file: a fake vCenter Server 8.0 U3 (the REST API and VI/JSON 8.0.1.0) for the check suite (CheckCommand::suiteVmware), run by PHP's built-in server:
    VC_STATE=<a folder> php -S 127.0.0.1:<port> VmwareTestServer.php
	user automation@vsphere.local, password s3cret; the sessions kept in <state>/state.json (a test may drop them: a 401, then a new sign-in); each request appended to
	<state>/requests.jsonl (api, method, path, query as sent, body, session)
	REST: POST/DELETE /api/session; GET /api/vcenter/{datacenter,host,cluster,datastore,network,folder,resource-pool} (names filter); vm: list (names, power_states, hosts,
	    clusters, folders, datacenters), get, DELETE (on: 400), power (already: 400 ALREADY_IN_DESIRED_STATE), guest power (shutdown: off at once, unless the VM is `stuck`),
	    guest identity (no Tools: 503), interfaces, clone (?action=clone); GET /api/appliance/system/version
	VI/JSON: SessionManager Login (the session in a header) / Logout; ServiceInstance content; VirtualMachine snapshot (a tree), CreateSnapshot_Task, RevertToCurrentSnapshot_Task,
	    RemoveAllSnapshots_Task; VirtualMachineSnapshot RevertToSnapshot_Task (a snapshot named `broken`: the task fails), RemoveSnapshot_Task; Task info (each task done at once);
	    Folder group-d1 triggeredAlarmState (<state>/alarms.json); Alarm info; <type>/<id>/name; AlarmManager AcknowledgeAlarm; HostSystem runtime, Enter/ExitMaintenanceMode_Task
*/

$dir = getenv('VC_STATE') ?: sys_get_temp_dir();
$full = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$query = [];
foreach(explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
    if ($pair === '')
	continue;
    [ $k, $v ] = array_map('rawurldecode', explode('=', $pair, 2) + [ 1 => '' ]);
    $query[$k][] = $v;
}
$vimPrefix = '/sdk/vim25/8.0.1.0/';
$api = str_starts_with($full, $vimPrefix) ? 'vim' : (str_starts_with($full, '/api/') ? 'rest' : 'other');
file_put_contents("{$dir}/requests.jsonl", json_encode([ 'api' => $api, 'method' => $method, 'path' => $full, 'query' => $query, 'body' => $body,
    'session' => $headers['vmware-api-session-id'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = function($status, $data = null, array $more = []) {
    http_response_code($status);
    foreach($more as $k => $v)
	header("{$k}: {$v}");
    if ($data !== null) {
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$restError = fn($status, $type, $message) => $answer($status, [ 'error_type' => $type, 'messages' => [ [ 'args' => [], 'default_message' => $message, 'id' => 'vapi.fake' ] ] ]);
$fault = fn($status, $type, $message) => $answer($status, [ '_typeName' => $type, 'faultCause' => null, 'faultMessage' => [ [ '_typeName' => 'LocalizableMessage', 'key' => 'fake', 'message' => $message ] ] ]);

$default = [
    'sessions' => [],
    'n' => 100,
    'vms' => [
	'vm-42' => [ 'name' => 'web01', 'power_state' => 'POWERED_ON', 'cpu_count' => 2, 'memory_size_MiB' => 4096, 'host' => 'host-10', 'cluster' => 'domain-c8', 'folder' => 'group-v4', 'tools' => true ],
	'vm-43' => [ 'name' => 'web02', 'power_state' => 'POWERED_OFF', 'cpu_count' => 2, 'memory_size_MiB' => 4096, 'host' => 'host-11', 'cluster' => 'domain-c8', 'folder' => 'group-v4', 'tools' => false ],
	'vm-44' => [ 'name' => 'db01', 'power_state' => 'POWERED_ON', 'cpu_count' => 8, 'memory_size_MiB' => 32768, 'host' => 'host-11', 'cluster' => 'domain-c8', 'folder' => 'group-v5', 'tools' => true, 'stuck' => true ],
    ],
    'snapshots' => [ 'vm-42' => [
	[ 'id' => 'snapshot-1', 'name' => 'Base', 'parent' => null, 'createTime' => '2026-09-01T10:00:00Z' ],
	[ 'id' => 'snapshot-2', 'name' => 'Before patch', 'parent' => 'snapshot-1', 'createTime' => '2026-10-01T10:00:00Z' ],
	[ 'id' => 'snapshot-3', 'name' => 'broken', 'parent' => 'snapshot-2', 'createTime' => '2026-10-30T10:00:00Z' ],
    ] ],
    'current' => [ 'vm-42' => 'snapshot-3' ],
    'tasks' => [],
    'maintenance' => [ 'host-10' => false, 'host-11' => true ],
];
$state = is_file("{$dir}/state.json") ? json_decode(file_get_contents("{$dir}/state.json"), true) : $default;
$save = function() use (&$state, $dir) {
    file_put_contents("{$dir}/state.json", json_encode($state));
};
$alarms = is_file("{$dir}/alarms.json") ? json_decode(file_get_contents("{$dir}/alarms.json"), true) : [];
$session = (string)($headers['vmware-api-session-id'] ?? '');
$newId = function($prefix) use (&$state) {
    $state['n']++;
    return "{$prefix}-{$state['n']}";
};
$task = function($ok = true, $result = null, $message = '') use (&$state, $newId, $save) {
    $id = $newId('task');
    $state['tasks'][$id] = [ 'key' => $id, 'task' => [ '_typeName' => 'ManagedObjectReference', 'type' => 'Task', 'value' => $id ], 'state' => $ok ? 'success' : 'error', 'progress' => 100,
	'result' => $result, 'error' => $ok ? null : [ '_typeName' => 'LocalizedMethodFault', 'fault' => [ '_typeName' => 'GenericVmConfigFault', 'faultMessage' => [ [ 'message' => $message ] ] ],
	'localizedMessage' => $message ] ];
    $save();
    return [ '_typeName' => 'ManagedObjectReference', 'type' => 'Task', 'value' => $id ];
};

// ---------------------------------------------------------------------------------------- REST
if ($api === 'rest') {
    $path = $full;
    if ($path === '/api/session' && $method === 'POST') {
	if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode('automation@vsphere.local:s3cret'))
	    $restError(401, 'UNAUTHENTICATED', 'Authentication required.');
	$token = 'rest-' . bin2hex(random_bytes(6));
	$state['sessions'][] = $token;
	$save();
	$answer(201, $token);
    }
    if (!in_array($session, $state['sessions'], true))
	$restError(401, 'UNAUTHENTICATED', 'Authentication required.');
    if ($path === '/api/session' && $method === 'DELETE')
	$answer(204);
    $names = $query['names'] ?? [];
    $byName = fn(array $rows) => array_values(array_filter($rows, fn($r) => !$names || in_array($r['name'], $names, true)));
    $lists = [
	'datacenter' => [ [ 'datacenter' => 'datacenter-3', 'name' => 'DC1' ] ],
	'host' => [ [ 'host' => 'host-10', 'name' => 'esx01.lab', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON', '_cluster' => 'domain-c8' ],
	    [ 'host' => 'host-11', 'name' => 'esx02.lab', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON', '_cluster' => 'domain-c8' ] ],
	'cluster' => [ [ 'cluster' => 'domain-c8', 'name' => 'Prod', 'ha_enabled' => true, 'drs_enabled' => true ] ],
	'datastore' => [ [ 'datastore' => 'datastore-20', 'name' => 'ds-ssd', 'type' => 'VMFS', 'capacity' => 1000, 'free_space' => 50 ],
	    [ 'datastore' => 'datastore-21', 'name' => 'ds-nfs', 'type' => 'NFS', 'capacity' => 2000, 'free_space' => 1000 ] ],
	'network' => [ [ 'network' => 'network-30', 'name' => 'VM Network', 'type' => 'STANDARD_PORTGROUP' ] ],
	'folder' => [ [ 'folder' => 'group-v4', 'name' => 'Web', 'type' => 'VIRTUAL_MACHINE' ], [ 'folder' => 'group-v5', 'name' => 'Databases', 'type' => 'VIRTUAL_MACHINE' ],
	    [ 'folder' => 'group-h6', 'name' => 'host', 'type' => 'HOST' ] ],
	'resource-pool' => [ [ 'resource_pool' => 'resgroup-9', 'name' => 'Resources' ] ],
    ];
    if (preg_match('~^/api/vcenter/(datacenter|host|cluster|datastore|network|folder|resource-pool)$~', $path, $m) && $method === 'GET') {
	$rows = $byName($lists[$m[1]]);
	if ($m[1] === 'host' && isset($query['clusters']))
	    $rows = array_values(array_filter($rows, fn($r) => in_array($r['_cluster'], $query['clusters'], true)));
	if ($m[1] === 'folder' && isset($query['type']))
	    $rows = array_values(array_filter($rows, fn($r) => $r['type'] === $query['type'][0]));
	$answer(200, array_map(function($r) { unset($r['_cluster']); return $r; }, $rows));
    }
    if ($path === '/api/appliance/system/version')
	$answer(200, [ 'product' => 'VMware vCenter Server', 'version' => '8.0.3.00400', 'build' => '24322831', 'type' => 'vCenter Server with an embedded Platform Services Controller' ]);
    if ($path === '/api/vcenter/vm' && $method === 'GET') {
	$rows = [];
	foreach($state['vms'] as $id => $vm) {
	    if ($names && !in_array($vm['name'], $names, true))
		continue;
	    if (isset($query['power_states']) && !in_array($vm['power_state'], $query['power_states'], true))
		continue;
	    foreach([ 'hosts' => 'host', 'clusters' => 'cluster', 'folders' => 'folder' ] as $f => $k)
		if (isset($query[$f]) && !in_array($vm[$k], $query[$f], true))
		    continue 2;
	    if (isset($query['datacenters']) && $query['datacenters'] !== [ 'datacenter-3' ])
		continue;
	    $rows[] = [ 'vm' => $id, 'name' => $vm['name'], 'power_state' => $vm['power_state'], 'cpu_count' => $vm['cpu_count'], 'memory_size_MiB' => $vm['memory_size_MiB'] ];
	}
	$answer(200, $rows);
    }
    if ($path === '/api/vcenter/vm' && $method === 'POST' && ($query['action'][0] ?? '') === 'clone') {
	$src = $state['vms'][$body['source'] ?? ''] ?? null;
	if (!$src)
	    $restError(404, 'NOT_FOUND', 'The source VM was not found.');
	$id = $newId('vm');
	$state['vms'][$id] = [ 'name' => $body['name'], 'power_state' => !empty($body['power_on']) ? 'POWERED_ON' : 'POWERED_OFF', 'folder' => $body['placement']['folder'] ?? $src['folder'] ] + $src;
	$save();
	$answer(200, $id);
    }
    if (preg_match('~^/api/vcenter/vm/([a-z0-9-]+)(/.*)?$~', $path, $m)) {
	$id = $m[1];
	$rest = $m[2] ?? '';
	if (!isset($state['vms'][$id]))
	    $restError(404, 'NOT_FOUND', "The VM {$id} was not found.");
	$vm = &$state['vms'][$id];
	if ($rest === '' && $method === 'GET')
	    $answer(200, [ 'name' => $vm['name'], 'power_state' => $vm['power_state'], 'guest_OS' => 'UBUNTU_64', 'cpu' => [ 'count' => $vm['cpu_count'] ], 'memory' => [ 'size_MiB' => $vm['memory_size_MiB'] ] ]);
	if ($rest === '' && $method === 'DELETE') {
	    if ($vm['power_state'] !== 'POWERED_OFF')
		$restError(400, 'NOT_ALLOWED_IN_CURRENT_STATE', 'The operation is not allowed in the current state.');
	    unset($state['vms'][$id]);
	    $save();
	    $answer(204);
	}
	if ($rest === '/power' && $method === 'GET')
	    $answer(200, [ 'state' => $vm['power_state'] ]);
	if ($rest === '/power' && $method === 'POST') {
	    $to = [ 'start' => 'POWERED_ON', 'stop' => 'POWERED_OFF', 'suspend' => 'SUSPENDED', 'reset' => 'POWERED_ON' ][$query['action'][0] ?? ''] ?? null;
	    if ($to === null)
		$restError(400, 'INVALID_ARGUMENT', 'Unknown action.');
	    if ($vm['power_state'] === $to && ($query['action'][0] ?? '') !== 'reset')
		$restError(400, 'ALREADY_IN_DESIRED_STATE', 'Virtual machine is already in the desired state.');
	    $vm['power_state'] = $to;
	    $save();
	    $answer(204);
	}
	if ($rest === '/guest/power' && $method === 'POST') {
	    if (empty($vm['tools']) || $vm['power_state'] !== 'POWERED_ON')
		$restError(503, 'SERVICE_UNAVAILABLE', 'VMware Tools is not running in the guest.');
	    if (($query['action'][0] ?? '') === 'shutdown' && empty($vm['stuck'])) {
		$vm['power_state'] = 'POWERED_OFF';
		$save();
	    }
	    $answer(204);
	}
	if ($rest === '/guest/identity') {
	    if (empty($vm['tools']) || $vm['power_state'] !== 'POWERED_ON')
		$restError(503, 'SERVICE_UNAVAILABLE', 'VMware Tools is not running in the guest.');
	    $answer(200, [ 'name' => 'UBUNTU_64', 'family' => 'LINUX', 'full_name' => [ 'default_message' => 'Ubuntu Linux (64-bit)', 'id' => 'x', 'args' => [] ], 'host_name' => $vm['name'], 'ip_address' => '10.0.0.42' ]);
	}
	if ($rest === '/guest/networking/interfaces')
	    $answer(200, [ [ 'mac_address' => '00:50:56:aa:bb:cc', 'nic' => '4000', 'ip' => [ 'ip_addresses' => [ [ 'ip_address' => '10.0.0.42', 'prefix_length' => 24, 'state' => 'PREFERRED' ] ] ] ] ]);
    }
    $restError(404, 'NOT_FOUND', "No such endpoint: {$method} {$path}");
}

// ---------------------------------------------------------------------------------------- VI/JSON
if ($api === 'vim') {
    $path = substr($full, strlen($vimPrefix));
    if ($path === 'SessionManager/SessionManager/Login' && $method === 'POST') {
	if (($body['userName'] ?? '') !== 'automation@vsphere.local' || ($body['password'] ?? '') !== 's3cret')
	    $fault(400, 'InvalidLogin', 'Cannot complete login due to an incorrect user name or password.');
	$token = 'vim-' . bin2hex(random_bytes(6));
	$state['sessions'][] = $token;
	$save();
	$answer(200, [ '_typeName' => 'UserSession', 'userName' => 'VSPHERE.LOCAL\\automation' ], [ 'vmware-api-session-id' => $token ]);
    }
    if (!in_array($session, $state['sessions'], true))
	$fault(401, 'NotAuthenticated', 'The session is not authenticated.');
    if ($path === 'SessionManager/SessionManager/Logout')
	$answer(204);
    if ($path === 'ServiceInstance/ServiceInstance/content')
	$answer(200, [ '_typeName' => 'ServiceContent', 'rootFolder' => [ '_typeName' => 'ManagedObjectReference', 'type' => 'Folder', 'value' => 'group-d1' ],
	    'about' => [ '_typeName' => 'AboutInfo', 'fullName' => 'VMware vCenter Server 8.0.3 build-24322831', 'apiVersion' => '8.0.3.0' ] ]);
    if (preg_match('~^Task/(task-\d+)/info$~', $path, $m)) {
	if (!isset($state['tasks'][$m[1]]))
	    $fault(404, 'ManagedObjectNotFound', 'The object has already been deleted or has not been completely created');
	$answer(200, [ '_typeName' => 'TaskInfo' ] + $state['tasks'][$m[1]]);
    }
    $ref = fn($type, $value) => [ '_typeName' => 'ManagedObjectReference', 'type' => $type, 'value' => $value ];
    if (preg_match('~^VirtualMachine/(vm-\d+)/(\w+)$~', $path, $m)) {
	[ , $vm, $what ] = $m;
	if (!isset($state['vms'][$vm]))
	    $fault(404, 'ManagedObjectNotFound', 'The object has already been deleted or has not been completely created');
	$snaps = $state['snapshots'][$vm] ?? [];
	if ($what === 'name')
	    $answer(200, $state['vms'][$vm]['name']);
	if ($what === 'snapshot' && $method === 'GET') {
	    if (!$snaps)
		$answer(204);
	    $tree = function($parent) use (&$tree, $snaps, $vm, $ref) {
		$out = [];
		foreach($snaps as $s)
		    if ($s['parent'] === $parent)
			$out[] = [ '_typeName' => 'VirtualMachineSnapshotTree', 'snapshot' => $ref('VirtualMachineSnapshot', $s['id']), 'vm' => $ref('VirtualMachine', $vm), 'name' => $s['name'],
			    'description' => $s['description'] ?? '', 'id' => (int)substr($s['id'], 9), 'createTime' => $s['createTime'], 'state' => 'poweredOn', 'quiesced' => !empty($s['quiesced']),
			    'childSnapshotList' => $tree($s['id']) ];
		return $out;
	    };
	    $answer(200, [ '_typeName' => 'VirtualMachineSnapshotInfo', 'currentSnapshot' => isset($state['current'][$vm]) ? $ref('VirtualMachineSnapshot', $state['current'][$vm]) : null,
		'rootSnapshotList' => $tree(null) ]);
	}
	if ($what === 'CreateSnapshot_Task' && $method === 'POST') {
	    $id = $newId('snapshot');
	    $state['snapshots'][$vm][] = [ 'id' => $id, 'name' => $body['name'], 'description' => $body['description'] ?? '', 'parent' => $state['current'][$vm] ?? null,
		'createTime' => gmdate('Y-m-d\TH:i:s\Z'), 'quiesced' => !empty($body['quiesce']) ];
	    $state['current'][$vm] = $id;
	    $answer(200, $task(true, $ref('VirtualMachineSnapshot', $id)));
	}
	if ($what === 'RevertToCurrentSnapshot_Task' && $method === 'POST')
	    $answer(200, $task(isset($state['current'][$vm]), null, 'The virtual machine has no current snapshot.'));
	if ($what === 'RemoveAllSnapshots_Task' && $method === 'POST') {
	    $state['snapshots'][$vm] = [];
	    unset($state['current'][$vm]);
	    $answer(200, $task());
	}
    }
    if (preg_match('~^VirtualMachineSnapshot/(snapshot-\d+)/(\w+)$~', $path, $m) && $method === 'POST') {
	[ , $sid, $what ] = $m;
	foreach($state['snapshots'] as $vm => $snaps) {
	    foreach($snaps as $i => $s) {
		if ($s['id'] !== $sid)
		    continue;
		if ($what === 'RevertToSnapshot_Task') {
		    if ($s['name'] === 'broken')
			$answer(200, $task(false, null, 'The snapshot\'s disk is damaged.'));
		    $state['current'][$vm] = $sid;
		    $answer(200, $task());
		}
		if ($what === 'RemoveSnapshot_Task') {
		    $gone = [ $sid ];
		    if (!empty($body['removeChildren'])) {
			for ($grew = true; $grew; ) {
			    $grew = false;
			    foreach($snaps as $c)
				if (in_array($c['parent'], $gone, true) && !in_array($c['id'], $gone, true)) {
				    $gone[] = $c['id'];
				    $grew = true;
				}
			}
		    }
		    $kept = [];
		    foreach($snaps as $c) {
			if (in_array($c['id'], $gone, true))
			    continue;
			if ($c['parent'] === $sid)
			    $c['parent'] = $s['parent'];
			$kept[] = $c;
		    }
		    $state['snapshots'][$vm] = $kept;
		    if (in_array($state['current'][$vm] ?? null, $gone, true))
			$state['current'][$vm] = $s['parent'];
		    $answer(200, $task());
		}
	    }
	}
	$fault(404, 'ManagedObjectNotFound', 'The object has already been deleted or has not been completely created');
    }
    if ($path === 'Folder/group-d1/triggeredAlarmState')
	$answer(200, array_map(fn($a) => [ '_typeName' => 'AlarmState', 'key' => "{$a['alarm']}.{$a['entity']}", 'entity' => $ref($a['type'], $a['entity']),
	    'alarm' => $ref('Alarm', $a['alarm']), 'overallStatus' => $a['status'], 'time' => $a['time'] ?? '2026-11-01T08:00:00Z', 'acknowledged' => !empty($a['ack']) ], $alarms));
    if (preg_match('~^Alarm/(alarm-\d+)/info$~', $path, $m))
	$answer(200, [ '_typeName' => 'AlarmInfo', 'name' => [ 'alarm-7' => 'Virtual machine CPU usage', 'alarm-8' => 'Datastore usage on disk', 'alarm-9' => 'Host connection and power state' ][$m[1]] ?? "Alarm {$m[1]}" ]);
    if ($path === 'AlarmManager/AlarmManager/AcknowledgeAlarm' && $method === 'POST') {
	foreach($alarms as &$a)
	    if ($a['alarm'] === ($body['alarm']['value'] ?? '') && $a['entity'] === ($body['entity']['value'] ?? '') && $a['type'] === ($body['entity']['type'] ?? ''))
		$a['ack'] = true;
	unset($a);
	file_put_contents("{$dir}/alarms.json", json_encode($alarms));
	$answer(204);
    }
    if (preg_match('~^HostSystem/(host-\d+)/(\w+)$~', $path, $m)) {
	[ , $host, $what ] = $m;
	if (!array_key_exists($host, $state['maintenance']))
	    $fault(404, 'ManagedObjectNotFound', 'The object has already been deleted or has not been completely created');
	if ($what === 'name')
	    $answer(200, [ 'host-10' => 'esx01.lab', 'host-11' => 'esx02.lab' ][$host]);
	if ($what === 'runtime')
	    $answer(200, [ '_typeName' => 'HostRuntimeInfo', 'connectionState' => 'connected', 'inMaintenanceMode' => $state['maintenance'][$host] ]);
	if (in_array($what, [ 'EnterMaintenanceMode_Task', 'ExitMaintenanceMode_Task' ], true) && $method === 'POST') {
	    if ($state['maintenance'][$host] === ($what === 'EnterMaintenanceMode_Task'))
		$fault(500, 'InvalidState', 'The operation is not allowed in the current state.');
	    $state['maintenance'][$host] = $what === 'EnterMaintenanceMode_Task';
	    $answer(200, $task());
	}
    }
    if (preg_match('~^Datastore/(datastore-\d+)/name$~', $path, $m))
	$answer(200, [ 'datastore-20' => 'ds-ssd', 'datastore-21' => 'ds-nfs' ][$m[1]] ?? $m[1]);
    $fault(404, 'ManagedObjectNotFound', "No such object or request: {$method} {$path}");
}
$answer(404, null);
