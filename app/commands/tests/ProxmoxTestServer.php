<?php

/*
    a fake Proxmox VE 8.2 cluster `lab` (nodes pve1, pve2) for the check suite (CheckCommand::suiteProxmox), run by PHP's built-in server:
    PVE_STATE=<a folder> php -S 127.0.0.1:<port> ProxmoxTestServer.php
	Authorization: PVEAPIToken=automation@pve!rail22=s3cret; errors as Proxmox gives them: the message in the status line's reason phrase, parameters' errors in { errors };
	each request appended to <state>/requests.jsonl (method, path, params as sent, auth); the guests, snapshots, backups and tasks kept in <state>/state.json
	GET /version, /cluster/status, /cluster/resources (type vm / storage), /cluster/nextid, /cluster/tasks (<state>/tasks.json), /cluster/backup, /cluster/ha/status/current, /pools,
	    /nodes; a guest (/nodes/{node}/{qemu|lxc}/{vmid}: on another node, no configuration file): status/current, POST status/{action} (start when running: 500 already
	    running), config, agent/network-get-interfaces, interfaces, clone, migrate, DELETE (running: 500), snapshot (list with `current`, create, rollback, delete);
	    POST /nodes/{node}/vzdump (VMID 101: the task fails), storage content (backups), DELETE a backup; tasks/{upid}/status and log (every task stopped at once)
*/

$dir = getenv('PVE_STATE') ?: sys_get_temp_dir();
$full = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
parse_str((string)file_get_contents('php://input'), $form);
$params = $query + $form;
file_put_contents("{$dir}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $full, 'params' => $params, 'auth' => $headers['authorization'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = function($data, $status = 200, $reason = 'OK', $more = []) {
    header("HTTP/1.1 {$status} {$reason}");
    header('Content-Type: application/json;charset=UTF-8');
    echo json_encode([ 'data' => $data ] + $more, JSON_UNESCAPED_SLASHES);
    exit;
};
$fail = fn($status, $reason, $errors = null) => $answer(null, $status, $reason, $errors ? [ 'errors' => $errors ] : []);
$prefix = '/api2/json';
if (!str_starts_with($full, $prefix . '/'))
    $fail(501, 'Unexpected content');
if (($headers['authorization'] ?? '') !== 'PVEAPIToken=automation@pve!rail22=s3cret')
    $fail(401, 'authentication failure');
$path = substr($full, strlen($prefix));

$default = [
    'n' => 0,
    'guests' => [
	100 => [ 'name' => 'web01', 'type' => 'qemu', 'node' => 'pve1', 'status' => 'running', 'tags' => 'web;prod' ],
	101 => [ 'name' => 'web02', 'type' => 'qemu', 'node' => 'pve2', 'status' => 'stopped', 'tags' => 'web' ],
	200 => [ 'name' => 'dns01', 'type' => 'lxc', 'node' => 'pve1', 'status' => 'running', 'tags' => '' ],
	9000 => [ 'name' => 'tpl-debian', 'type' => 'qemu', 'node' => 'pve2', 'status' => 'stopped', 'tags' => '', 'template' => 1 ],
    ],
    'snapshots' => [ 100 => [
	[ 'name' => 'base', 'description' => 'Installed', 'snaptime' => 1756720800, 'parent' => null ],
	[ 'name' => 'before_patch', 'description' => '', 'snaptime' => 1759312800, 'parent' => 'base' ],
	[ 'name' => 'recent', 'description' => '', 'snaptime' => time() - 3600, 'parent' => 'before_patch' ],
    ] ],
    'current' => [ 100 => 'recent' ],
    'backups' => [ 'pbs' => [
	[ 'volid' => 'pbs:backup/vm/100/2026-10-30T01:00:00Z', 'vmid' => 100, 'ctime' => time() - 30 * 3600, 'size' => 5000000000, 'format' => 'pbs-vm' ],
	[ 'volid' => 'pbs:backup/vm/100/2026-10-31T01:00:00Z', 'vmid' => 100, 'ctime' => time() - 6 * 3600, 'size' => 5100000000, 'format' => 'pbs-vm' ],
	[ 'volid' => 'pbs:backup/ct/200/2026-10-31T01:00:00Z', 'vmid' => 200, 'ctime' => time() - 5 * 3600, 'size' => 400000000, 'format' => 'pbs-ct' ],
    ] ],
    'tasks' => [],
];
$state = is_file("{$dir}/state.json") ? json_decode(file_get_contents("{$dir}/state.json"), true) : $default;
$save = function() use (&$state, $dir) {
    file_put_contents("{$dir}/state.json", json_encode($state));
};
//a task, stopped at once: its UPID
$task = function($node, $type, $id, $exit = 'OK', array $log = []) use (&$state, $save) {
    $state['n']++;
    $upid = sprintf('UPID:%s:%08X:%08X:%08X:%s:%s:automation@pve!rail22:', $node, 1000 + $state['n'], 5000 + $state['n'], time(), $type, $id);
    $state['tasks'][$upid] = [ 'upid' => $upid, 'node' => $node, 'type' => $type, 'id' => (string)$id, 'status' => 'stopped', 'exitstatus' => $exit,
	'log' => array_merge($log, [ $exit === 'OK' ? 'TASK OK' : "TASK ERROR: {$exit}" ]) ];
    $save();
    return $upid;
};
$resource = fn($vmid, $g) => [ 'id' => "{$g['type']}/{$vmid}", 'type' => $g['type'], 'vmid' => (int)$vmid, 'name' => $g['name'], 'node' => $g['node'], 'status' => $g['status'],
    'template' => (int)($g['template'] ?? 0), 'tags' => $g['tags'], 'cpu' => 0.05, 'maxcpu' => 2, 'mem' => 1073741824, 'maxmem' => 4294967296, 'uptime' => $g['status'] === 'running' ? 3600 : 0 ];

if ($path === '/version')
    $answer([ 'version' => '8.2.4', 'release' => '8.2', 'repoid' => 'faa83925c9641325' ]);
if ($path === '/cluster/status')
    $answer([ [ 'type' => 'cluster', 'id' => 'cluster', 'name' => 'lab', 'quorate' => 1, 'nodes' => 2 ],
	[ 'type' => 'node', 'id' => 'node/pve1', 'name' => 'pve1', 'online' => 1, 'ip' => '10.0.0.11', 'local' => 1 ],
	[ 'type' => 'node', 'id' => 'node/pve2', 'name' => 'pve2', 'online' => 0, 'ip' => '10.0.0.12', 'local' => 0 ] ]);
if ($path === '/cluster/resources') {
    if (($params['type'] ?? '') === 'vm')
	$answer(array_values(array_map($resource, array_keys($state['guests']), $state['guests'])));
    if (($params['type'] ?? '') === 'storage')
	$answer([ [ 'id' => 'storage/pve1/local', 'storage' => 'local', 'node' => 'pve1', 'plugintype' => 'dir', 'status' => 'available', 'disk' => 95, 'maxdisk' => 100, 'shared' => 0 ],
	    [ 'id' => 'storage/pve1/pbs', 'storage' => 'pbs', 'node' => 'pve1', 'plugintype' => 'pbs', 'status' => 'available', 'disk' => 400, 'maxdisk' => 1000, 'shared' => 1 ],
	    [ 'id' => 'storage/pve2/pbs', 'storage' => 'pbs', 'node' => 'pve2', 'plugintype' => 'pbs', 'status' => 'available', 'disk' => 400, 'maxdisk' => 1000, 'shared' => 1 ] ]);
    $fail(400, 'Parameter verification failed.', [ 'type' => 'value not in enum' ]);
}
if ($path === '/cluster/nextid') {
    for ($id = 100; isset($state['guests'][$id]); $id++);
    $answer((string)$id);
}
if ($path === '/cluster/tasks')
    $answer(is_file("{$dir}/tasks.json") ? json_decode(file_get_contents("{$dir}/tasks.json"), true) : []);
if ($path === '/cluster/backup')
    $answer([ [ 'id' => 'backup-nightly', 'type' => 'vzdump', 'schedule' => '01:00', 'enabled' => 1, 'storage' => 'pbs', 'all' => 1, 'mode' => 'snapshot' ] ]);
if ($path === '/cluster/ha/status/current')
    $answer([ [ 'id' => 'quorum', 'type' => 'quorum', 'status' => 'OK', 'quorate' => 1 ] ]);
if ($path === '/pools')
    $answer([ [ 'poolid' => 'prod', 'comment' => 'Production' ] ]);
if ($path === '/nodes')
    $answer([ [ 'node' => 'pve1', 'status' => 'online', 'cpu' => 0.1, 'maxcpu' => 16, 'mem' => 32, 'maxmem' => 128, 'uptime' => 864000 ],
	[ 'node' => 'pve2', 'status' => 'offline' ] ]);

if (preg_match('~^/nodes/([a-z0-9-]+)/tasks/([^/]+)/(status|log)$~', $path, $m)) {
    $t = $state['tasks'][rawurldecode($m[2])] ?? null;
    if (!$t)
	$fail(500, 'no such task');
    if ($m[3] === 'status')
	$answer([ 'upid' => $t['upid'], 'node' => $t['node'], 'type' => $t['type'], 'id' => $t['id'], 'status' => 'stopped', 'exitstatus' => $t['exitstatus'] ]);
    $answer(array_map(fn($l, $i) => [ 'n' => $i + 1, 't' => $l ], $t['log'], array_keys($t['log'])));
}
if (preg_match('~^/nodes/([a-z0-9-]+)/vzdump$~', $path, $m) && $method === 'POST') {
    $vmid = (int)$params['vmid'];
    if ($vmid === 101)
	$answer($task($m[1], 'vzdump', $vmid, 'job errors', [ "INFO: starting new backup job: vzdump {$vmid}", 'ERROR: Backup of VM 101 failed - no space left on device' ]));
    $storage = $params['storage'] ?? 'local';
    $g = $state['guests'][$vmid];
    $state['backups'][$storage][] = [ 'volid' => "{$storage}:backup/" . ($g['type'] === 'lxc' ? 'ct' : 'vm') . "/{$vmid}/" . gmdate('Y-m-d\TH:i:s\Z'), 'vmid' => $vmid, 'ctime' => time(),
	'size' => 123, 'format' => 'pbs-vm', 'notes' => $params['notes-template'] ?? '' ];
    $answer($task($m[1], 'vzdump', $vmid));
}
if (preg_match('~^/nodes/([a-z0-9-]+)/storage/([A-Za-z0-9_.-]+)/content(?:/(.+))?$~', $path, $m)) {
    $list = $state['backups'][$m[2]] ?? [];
    if (isset($m[3]) && $method === 'DELETE') {
	$volid = rawurldecode($m[3]);
	$state['backups'][$m[2]] = array_values(array_filter($list, fn($b) => $b['volid'] !== $volid));
	if (count($state['backups'][$m[2]]) === count($list))
	    $fail(500, "volume '{$volid}' does not exist");
	$answer($task($m[1], 'imgdel', $volid));
    }
    if (isset($params['vmid']))
	$list = array_values(array_filter($list, fn($b) => (int)$b['vmid'] === (int)$params['vmid']));
    $answer($list);
}

if (preg_match('~^/nodes/([a-z0-9-]+)/(qemu|lxc)/(\d+)(/.*)?$~', $path, $m)) {
    [ , $node, $type, $vmid ] = $m;
    $rest = $m[4] ?? '';
    $g = $state['guests'][$vmid] ?? null;
    if (!$g || $g['node'] !== $node || $g['type'] !== $type)
	$fail(500, "Configuration file 'nodes/{$node}/" . ($type === 'qemu' ? 'qemu-server' : 'lxc') . "/{$vmid}.conf' does not exist");
    $G = &$state['guests'][$vmid];
    if ($rest === '/status/current')
	$answer([ 'vmid' => (int)$vmid, 'name' => $g['name'], 'status' => $g['status'], 'qmpstatus' => $g['status'], 'uptime' => 100 ]);
    if (preg_match('~^/status/(start|stop|shutdown|reboot|suspend|resume|reset)$~', $rest, $a) && $method === 'POST') {
	if ($a[1] === 'start' && $g['status'] === 'running')
	    $fail(500, "VM {$vmid} already running");
	if (in_array($a[1], [ 'shutdown', 'reboot', 'suspend', 'reset' ], true) && $g['status'] !== 'running')
	    $fail(500, "VM {$vmid} not running");
	$G['status'] = [ 'start' => 'running', 'stop' => 'stopped', 'shutdown' => 'stopped', 'reboot' => 'running', 'suspend' => 'paused', 'resume' => 'running', 'reset' => 'running' ][$a[1]];
	$answer($task($node, ($type === 'qemu' ? 'qm' : 'vz') . $a[1], $vmid));
    }
    if ($rest === '/config')
	$answer([ 'name' => $g['name'], 'memory' => '4096', 'cores' => 2, 'net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0' ]);
    if ($rest === '/agent/network-get-interfaces') {
	if ($g['status'] !== 'running')
	    $fail(500, "VM {$vmid} is not running");
	$answer([ 'result' => [ [ 'name' => 'lo', 'ip-addresses' => [ [ 'ip-address' => '127.0.0.1', 'prefix' => 8 ] ] ],
	    [ 'name' => 'eth0', 'hardware-address' => 'bc:24:11:00:00:01', 'ip-addresses' => [ [ 'ip-address' => '10.0.1.100', 'prefix' => 24 ] ] ] ] ]);
    }
    if ($rest === '/interfaces')
	$answer([ [ 'name' => 'eth0', 'hwaddr' => 'bc:24:11:00:00:02', 'inet' => '10.0.1.200/24' ] ]);
    if ($rest === '/clone' && $method === 'POST') {
	$new = (int)$params['newid'];
	if (isset($state['guests'][$new]))
	    $fail(500, "unable to create VM {$new}: config file already exists");
	$state['guests'][$new] = [ 'name' => $params['name'] ?? $params['hostname'] ?? "Copy-of-VM-{$g['name']}", 'type' => $type, 'node' => $params['target'] ?? $node,
	    'status' => 'stopped', 'tags' => $g['tags'] ];
	$answer($task($node, $type === 'qemu' ? 'qmclone' : 'vzclone', $vmid));
    }
    if ($rest === '/migrate' && $method === 'POST') {
	$G['node'] = $params['target'];
	$answer($task($node, $type === 'qemu' ? 'qmigrate' : 'vzmigrate', $vmid));
    }
    if ($rest === '' && $method === 'DELETE') {
	if ($g['status'] !== 'stopped')
	    $fail(500, "VM {$vmid} is running - destroy failed");
	unset($state['guests'][$vmid]);
	$answer($task($node, $type === 'qemu' ? 'qmdestroy' : 'vzdestroy', $vmid));
    }
    if ($rest === '/snapshot' && $method === 'GET') {
	$list = $state['snapshots'][$vmid] ?? [];
	$list[] = [ 'name' => 'current', 'description' => 'You are here!', 'parent' => $state['current'][$vmid] ?? null, 'running' => $g['status'] === 'running' ? 1 : 0 ];
	$answer($list);
    }
    if ($rest === '/snapshot' && $method === 'POST') {
	if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]+$/', (string)($params['snapname'] ?? '')))
	    $fail(400, 'Parameter verification failed.', [ 'snapname' => 'invalid format - invalid configuration ID' ]);
	$state['snapshots'][$vmid][] = [ 'name' => $params['snapname'], 'description' => $params['description'] ?? '', 'snaptime' => time(), 'parent' => $state['current'][$vmid] ?? null,
	    'vmstate' => (int)($params['vmstate'] ?? 0) ];
	$state['current'][$vmid] = $params['snapname'];
	$answer($task($node, $type === 'qemu' ? 'qmsnapshot' : 'vzsnapshot', $vmid));
    }
    if (preg_match('~^/snapshot/([A-Za-z0-9_-]+)(/rollback)?$~', $rest, $s)) {
	$list = $state['snapshots'][$vmid] ?? [];
	$found = array_values(array_filter($list, fn($x) => $x['name'] === $s[1]))[0] ?? null;
	if (!$found)
	    $fail(500, "snapshot '{$s[1]}' does not exist");
	if (($s[2] ?? '') === '/rollback' && $method === 'POST') {
	    $state['current'][$vmid] = $s[1];
	    $answer($task($node, $type === 'qemu' ? 'qmrollback' : 'vzrollback', $vmid));
	}
	if ($method === 'DELETE') {
	    $kept = [];
	    foreach($list as $x) {
		if ($x['name'] === $s[1])
		    continue;
		if ($x['parent'] === $s[1])
		    $x['parent'] = $found['parent'];
		$kept[] = $x;
	    }
	    $state['snapshots'][$vmid] = $kept;
	    if (($state['current'][$vmid] ?? null) === $s[1])
		$state['current'][$vmid] = $found['parent'];
	    $answer($task($node, $type === 'qemu' ? 'qmdelsnapshot' : 'vzdelsnapshot', $vmid));
	}
    }
}
$fail(501, "Method '{$method} {$path}' not implemented");
