<?php

/*
    a fake Hyper-V host HV01 behind WinRM (WS-Management, the cmd shell) for the check suite (CheckCommand::suiteHyperV), run by PHP's built-in server:
    HV_STATE=<a folder> php -S 127.0.0.1:<port> HyperVTestServer.php
	POST /wsman only, Basic hv\automation : s3cret (curl's NTLM cannot be faked here); Create (a shell), Command (powershell.exe -EncodedCommand: the script decoded, kept), Receive (the
	first one: the TimedOut fault, as WinRM after OperationTimeout without output; then the output in two parts, the second with Done and the exit code), Signal, Delete.
	The script is not run: its first line (# r22:<name>) and its parameters ($P = ConvertFrom-Json '...') choose what a Hyper-V host would answer, from <state>/state.json (VMs,
	checkpoints), as ps()'s JSON { ok, data } or { ok: false, error }; hyperv-powershell:run with {"fail": true}: nothing on stdout, PowerShell's error on stderr (CLIXML).
	Each command appended to <state>/scripts.jsonl (name, params, the script's length, the shell's options)
*/

$dir = getenv('HV_STATE') ?: sys_get_temp_dir();
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
$soap = function($body, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/soap+xml;charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
	. ' xmlns:w="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd" xmlns:rsp="http://schemas.microsoft.com/wbem/wsman/1/windows/shell"'
	. ' xmlns:f="http://schemas.microsoft.com/wbem/wsman/1/wsmanfault"><s:Header/><s:Body>' . $body . '</s:Body></s:Envelope>';
    exit;
};
$fault = fn($code, $text, $status = 500) => $soap('<s:Fault><s:Code><s:Value>s:Receiver</s:Value></s:Code><s:Reason><s:Text xml:lang="en-US">' . htmlspecialchars($text) . '</s:Text></s:Reason>'
    . '<s:Detail><f:WSManFault Code="' . $code . '" Machine="hv01"><f:Message>' . htmlspecialchars($text) . '</f:Message></f:WSManFault></s:Detail></s:Fault>', $status);
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/wsman' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode('hv\automation:s3cret')) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="WSMAN"');
    exit;
}
$doc = new DOMDocument();
if (!@$doc->loadXML($raw))
    $fault('2150858817', 'The WS-Management service cannot process the request because the XML is invalid.', 400);
$xp = new DOMXPath($doc);
$action = (string)$xp->evaluate('string(//*[local-name()="Action"])');
$shell = (string)$xp->evaluate('string(//*[local-name()="Selector"][@Name="ShellId"])');
$options = [];
foreach($xp->query('//*[local-name()="Option"]') as $o)
    $options[$o->getAttribute('Name')] = $o->textContent;

$stateFile = "{$dir}/state.json";
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [ 'vms' => [
    [ 'name' => 'web01', 'id' => '11111111-1111-1111-1111-111111111111', 'state' => 'Running', 'health' => 'Normal', 'current' => 'cp-3',
	'checkpoints' => [ [ 'name' => 'Base', 'id' => 'cp-1', 'created' => '2026-09-01T10:00:00.0000000Z', 'parent' => null ],
	    [ 'name' => 'Before patch', 'id' => 'cp-2', 'created' => '2026-10-01T10:00:00.0000000Z', 'parent' => 'Base' ],
	    [ 'name' => 'Recent', 'id' => 'cp-3', 'created' => gmdate('Y-m-d\TH:i:s.0000000\Z', time() - 3600), 'parent' => 'Before patch' ] ] ],
    [ 'name' => 'web02', 'id' => '22222222-2222-2222-2222-222222222222', 'state' => 'Off', 'health' => 'NotApplicable', 'current' => null, 'checkpoints' => [] ],
    [ 'name' => 'db01', 'id' => '33333333-3333-3333-3333-333333333333', 'state' => 'Running', 'health' => 'Normal', 'current' => null, 'checkpoints' => [], 'stuck' => true ],
], 'commands' => [], 'n' => 0 ];
$save = function() use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state));
};

if (str_ends_with($action, '/transfer/Create')) {
    $id = sprintf('%08X-0000-4000-8000-%012X', random_int(0, 0xFFFFFFF), ++$state['n']);
    $state['shells'][$id] = $options;
    $save();
    $soap('<x:ResourceCreated xmlns:x="http://schemas.xmlsoap.org/ws/2004/09/transfer"><a:Address>http://127.0.0.1/wsman</a:Address><a:ReferenceParameters>'
	. '<w:ResourceURI>http://schemas.microsoft.com/wbem/wsman/1/windows/shell/cmd</w:ResourceURI><w:SelectorSet><w:Selector Name="ShellId">' . $id . '</w:Selector></w:SelectorSet>'
	. '</a:ReferenceParameters></x:ResourceCreated><rsp:Shell><rsp:ShellId>' . $id . '</rsp:ShellId></rsp:Shell>');
}
if (!isset($state['shells'][$shell]))
    $fault('2150858843', 'The request for the Windows Remote Shell with ShellId ' . $shell . ' failed because the shell was not found on the server.');
if (str_ends_with($action, '/transfer/Delete')) {
    unset($state['shells'][$shell]);
    $save();
    $soap('');
}
if (str_ends_with($action, '/shell/Signal'))
    $soap('<rsp:SignalResponse/>');

//the script's answer, as a Hyper-V host would give it: [ stdout, stderr ]
$answer = function($name, $P) use (&$state, $save) {
    $ok = fn($data) => [ json_encode([ 'ok' => true, 'data' => $data ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '' ];
    $err = fn($text) => [ json_encode([ 'ok' => false, 'error' => $text ]), '' ];
    $find = function($n) use (&$state) {
	$f = array_keys(array_filter($state['vms'], fn($v) => $v['name'] === $n || $v['id'] === $n));
	if (!$f)
	    return [ null, "VM: none named '{$n}'" ];
	return [ $f[0], null ];
    };
    $vm = fn($v) => [ 'name' => $v['name'], 'id' => $v['id'], 'state' => $v['state'], 'status' => 'Operating normally', 'cpuUsage' => 3, 'processorCount' => 2,
	'memoryAssigned' => 4294967296, 'uptimeSeconds' => $v['state'] === 'Running' ? 3600 : 0, 'heartbeat' => $v['state'] === 'Running' ? 'OkApplicationsUnknown' : '',
	'replicationHealth' => $v['health'], 'checkpoint' => $v['current'] ? array_values(array_filter($v['checkpoints'], fn($c) => $c['id'] === $v['current']))[0]['name'] ?? null : null, 'host' => 'HV01' ];
    $cp = fn($c, $v) => [ 'name' => $c['name'], 'id' => $c['id'], 'created' => $c['created'], 'parent' => $c['parent'], 'type' => 'Standard', 'current' => $c['id'] === $v['current'] ];
    [ $step, $op ] = explode(':', $name, 2) + [ 1 => '' ];
    if ($name === 'test')
	return $ok([ 'name' => 'HV01', 'os' => 'Microsoft Windows Server 2022 Datacenter', 'build' => '10.0.20348', 'vms' => count($state['vms']) ]);
    if ($name === 'hyperv-powershell:run') {
	if (!empty($P['fail']))
	    return [ '', "#< CLIXML\r\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><S S=\"Error\">The term 'Get-Thing' is not recognized_x000D__x000A_</S><S S=\"Error\">as the name of a cmdlet.</S></Objs>" ];
	return $ok([ 'echo' => $P ]);
    }
    if ($name === 'hyperv-trigger:states')
	return $ok(array_values(array_map(fn($v) => [ 'name' => $v['name'], 'id' => $v['id'], 'state' => $v['state'], 'health' => $v['health'] ],
	    array_filter($state['vms'], fn($v) => !$P['names'] || array_filter($P['names'], fn($n) => fnmatch($n, $v['name'], FNM_CASEFOLD))))));
    if ($step === 'hyperv-host') {
	return match($op) {
	    'host' => $ok([ 'name' => 'HV01', 'os' => 'Microsoft Windows Server 2022 Datacenter', 'build' => '10.0.20348', 'logicalProcessors' => 32, 'memoryTotal' => 400, 'memoryFree' => 100,
		'vmPath' => 'D:\\Hyper-V', 'vhdPath' => 'D:\\Hyper-V\\Disks', 'vms' => count($state['vms']), 'running' => count(array_filter($state['vms'], fn($v) => $v['state'] === 'Running')), 'uptimeSeconds' => 86400 ]),
	    'volumes' => $ok([ [ 'drive' => 'C:', 'label' => 'System', 'size' => 100, 'free' => 30 ], [ 'drive' => 'D:', 'label' => 'VMs', 'size' => 1000, 'free' => 50 ] ]),
	    'switches' => $ok([ [ 'name' => 'External', 'id' => 'sw-1', 'type' => 'External', 'adapter' => 'Intel(R) Ethernet' ] ]),
	    'replication' => $ok([ [ 'vm' => 'web01', 'state' => 'Replicating', 'health' => 'Warning', 'mode' => 'Primary', 'primaryServer' => 'HV01', 'replicaServer' => 'HV02', 'lastReplication' => '2026-11-01T08:00:00.0000000Z' ] ]),
	    'events' => $ok(array_slice([ [ 'time' => '2026-11-01T08:00:00.0000000Z', 'level' => 'Error', 'id' => 18560, 'log' => 'Microsoft-Windows-Hyper-V-Worker-Admin', 'message' => "'db01' was turned off as it could not be restarted." ],
		[ 'time' => '2026-11-01T07:00:00.0000000Z', 'level' => 'Warning', 'id' => 32022, 'log' => 'Microsoft-Windows-Hyper-V-VMMS-Admin', 'message' => 'Replication of web01 is delayed.' ] ], 0, (int)$P['max'])),
	    default => $err("unknown {$name}"),
	};
    }
    if ($name === 'hyperv-vms:list') {
	$out = [];
	foreach($state['vms'] as $v) {
	    if ($P['state'] !== 'any' && $v['state'] !== $P['state'])
		continue;
	    if ($P['names'] && !array_filter($P['names'], fn($n) => fnmatch($n, $v['name'], FNM_CASEFOLD)))
		continue;
	    $out[] = $vm($v);
	}
	usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
	return $ok($out);
    }
    [ $i, $e ] = $find($P['vm'] ?? '');
    if ($e !== null)
	return $err($e);
    $V = &$state['vms'][$i];
    $done = function($action, $already, $forced = false) use (&$V) {
	return [ 'name' => $V['name'], 'id' => $V['id'], 'action' => $action, 'state' => $V['state'], 'already' => $already, 'forced' => $forced ];
    };
    if ($step === 'hyperv-vms') {
	$simple = [ 'start' => 'Running', 'turnOff' => 'Off', 'save' => 'Saved', 'pause' => 'Paused', 'resume' => 'Running', 'reset' => null, 'reboot' => null ];
	if (array_key_exists($op, $simple)) {
	    if ($simple[$op] !== null && $V['state'] === $simple[$op])
		return $ok($done($op, true));
	    if (in_array($op, [ 'reset', 'reboot' ], true) && $V['state'] !== 'Running')
		return $err("'{$V['name']}' failed to restart. The operation cannot be performed while the virtual machine is in its current state.");
	    $V['state'] = $simple[$op] ?? 'Running';
	    $save();
	    return $ok($done($op, false));
	}
	switch($op) {
	    case 'get':
		return $ok($vm($V));
	    case 'shutdown':
		if ($V['state'] === 'Off')
		    return $ok($done('shutdown', true));
		if (!empty($V['stuck'])) {
		    if (empty($P['turnOff']))
			return $err("the guest OS did not shut down within {$P['wait']} s");
		    $V['state'] = 'Off';
		    $save();
		    return $ok($done('shutdown', false, true));
		}
		$V['state'] = 'Off';
		$save();
		return $ok($done('shutdown', false));
	    case 'network':
		return $ok([ [ 'name' => 'Network Adapter', 'macAddress' => '00155D010203', 'switchName' => 'External', 'ipAddresses' => [ '10.0.2.10', 'fe80::1' ], 'status' => 'Ok' ] ]);
	    case 'delete':
		if ($V['state'] !== 'Off')
		    return $err("VM '{$V['name']}' is {$V['state']}: turn it off first");
		$gone = $V;
		array_splice($state['vms'], $i, 1);
		$save();
		return $ok([ 'name' => $gone['name'], 'id' => $gone['id'], 'deleted' => true, 'disksDeleted' => !empty($P['disks']) ? [ "D:\\Hyper-V\\Disks\\{$gone['name']}.vhdx" ] : [] ]);
	}
    }
    if ($step === 'hyperv-checkpoints') {
	$snap = function($n) use (&$V) {
	    $f = array_keys(array_filter($V['checkpoints'], fn($c) => $c['name'] === $n || $c['id'] === $n));
	    return $f ? [ $f[0], null ] : [ null, "Checkpoint: VM '{$V['name']}' has none named '{$n}'" ];
	};
	$remove = function($k) use (&$V) {
	    $c = $V['checkpoints'][$k];
	    foreach($V['checkpoints'] as &$x)
		if ($x['parent'] === $c['name'])
		    $x['parent'] = $c['parent'];
	    unset($x);
	    array_splice($V['checkpoints'], $k, 1);
	    if ($V['current'] === $c['id']) {
		$p = array_values(array_filter($V['checkpoints'], fn($x) => $x['name'] === $c['parent']))[0] ?? null;
		$V['current'] = $p['id'] ?? null;
	    }
	};
	switch($op) {
	    case 'list':
		return $ok(array_map(fn($c) => $cp($c, $V), $V['checkpoints']));
	    case 'create':
		$parent = array_values(array_filter($V['checkpoints'], fn($c) => $c['id'] === $V['current']))[0]['name'] ?? null;
		$c = [ 'name' => $P['name'], 'id' => 'cp-' . (count($V['checkpoints']) + 10), 'created' => gmdate('Y-m-d\TH:i:s.0000000\Z'), 'parent' => $parent ];
		$V['checkpoints'][] = $c;
		$V['current'] = $c['id'];
		$save();
		return $ok($cp($c, $V));
	    case 'restore':
		[ $k, $e ] = $snap($P['checkpoint']);
		if ($e)
		    return $err($e);
		$V['current'] = $V['checkpoints'][$k]['id'];
		$save();
		return $ok($cp($V['checkpoints'][$k], $V));
	    case 'remove':
		[ $k, $e ] = $snap($P['checkpoint']);
		if ($e)
		    return $err($e);
		$r = $cp($V['checkpoints'][$k], $V);
		$remove($k);
		$save();
		return $ok($r);
	    case 'removeAll':
	    case 'prune':
		$out = [];
		$before = $P['days'] !== null ? time() - (int)$P['days'] * 86400 : null;
		foreach($V['checkpoints'] as $c) {
		    if ($before !== null && !(strtotime($c['created']) < $before && ((string)$P['pattern'] === '' || fnmatch($P['pattern'], $c['name'], FNM_CASEFOLD))))
			continue;
		    $out[] = $cp($c, $V);
		}
		foreach($out as $o)
		    $remove(array_keys(array_filter($V['checkpoints'], fn($c) => $c['id'] === $o['id']))[0]);
		$save();
		return $ok($out);
	}
    }
    return $err("The term '{$name}' is not recognized");
};

if (str_ends_with($action, '/shell/Command')) {
    $args = (string)$xp->evaluate('string(//*[local-name()="Arguments"])');
    $command = (string)$xp->evaluate('string(//*[local-name()="Command"])');
    if (!preg_match('/-EncodedCommand (\S+)/', $args, $m))
	$fault('2147942402', 'The system cannot find the file specified.');
    $script = mb_convert_encoding(base64_decode($m[1]), 'UTF-8', 'UTF-16LE');
    $name = preg_match('/^# r22:(\S+)/', $script, $n) ? $n[1] : '';
    $params = preg_match("/^\\\$P = ConvertFrom-Json '((?:[^']|'')*)'\$/m", $script, $p) ? json_decode(str_replace("''", "'", $p[1]), true) : null;
    [ $out, $err ] = $answer($name, (array)$params);
    $id = sprintf('%08X-1111-4000-8000-%012X', random_int(0, 0xFFFFFFF), ++$state['n']);
    $state['commands'][$id] = [ 'out' => $out, 'err' => $err, 'receives' => 0 ];
    $save();
    file_put_contents("{$dir}/scripts.jsonl", json_encode([ 'name' => $name, 'params' => $params, 'command' => $command, 'length' => mb_strlen($script), 'shell' => $state['shells'][$shell],
	'options' => $options, 'helpers' => str_contains($script, 'function r22find') ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $soap('<rsp:CommandResponse><rsp:CommandId>' . $id . '</rsp:CommandId></rsp:CommandResponse>');
}
if (str_ends_with($action, '/shell/Receive')) {
    $id = (string)$xp->evaluate('string(//*[local-name()="DesiredStream"]/@CommandId)');
    $c = &$state['commands'][$id];
    if (!$c)
	$fault('2150858843', 'The command was not found.');
    $c['receives']++;
    $save();
    if ($c['receives'] === 1)
	$fault('2150858793', 'The WS-Management service cannot complete the operation within the time specified in OperationTimeout.');
    $half = intdiv(strlen($c['out']), 2);
    $part = $c['receives'] === 2 ? substr($c['out'], 0, $half) : substr($c['out'], $half);
    $streams = '<rsp:Stream Name="stdout" CommandId="' . $id . '">' . base64_encode($part) . '</rsp:Stream>';
    if ($c['receives'] === 2) {
	$soap('<rsp:ReceiveResponse>' . $streams . '<rsp:CommandState CommandId="' . $id . '" State="http://schemas.microsoft.com/wbem/wsman/1/windows/shell/CommandState/Running"/></rsp:ReceiveResponse>');
    }
    if ($c['err'] !== '')
	$streams .= '<rsp:Stream Name="stderr" CommandId="' . $id . '">' . base64_encode($c['err']) . '</rsp:Stream>';
    $soap('<rsp:ReceiveResponse>' . $streams . '<rsp:Stream Name="stdout" CommandId="' . $id . '" End="true"></rsp:Stream><rsp:CommandState CommandId="' . $id
	. '" State="http://schemas.microsoft.com/wbem/wsman/1/windows/shell/CommandState/Done"><rsp:ExitCode>' . ($c['err'] !== '' ? 1 : 0) . '</rsp:ExitCode></rsp:CommandState></rsp:ReceiveResponse>');
}
$fault('2150858817', "Unknown action {$action}");
