<?php

/*
    the installation's services (Plugin::services): their status (systemd: `systemctl show`, which any user may read; a service not yet made a unit:
    its script's PID file) and start / stop / restart (`systemctl`, allowed to this app's user for these units only by the polkit rule of scripts/services-install.sh)
*/

namespace plugins\Admin\util;

class Services {
    const VERBS = [ 'start', 'stop', 'restart' ];

    //the status of service $s: [ unit (installed as a systemd unit), running (bool|null: unknown), state (text), pid, since (unix s|null), health: [ ok, text ]|null ]
    public static function status(array $s) {
	$r = [ 'unit' => false, 'running' => null, 'state' => 'unknown', 'pid' => null, 'since' => null, 'health' => null ];
	$unit = (string)($s['unit'] ?? '');
	if ($unit !== '') {
	    [ $code, $out ] = self::run([ 'systemctl', 'show', $unit, '--property=LoadState,ActiveState,SubState,MainPID,ActiveEnterTimestamp' ]);
	    $p = [];
	    foreach(explode("\n", $out) as $line) {
		if (!str_contains($line, '='))
		    continue;
		[ $k, $v ] = explode('=', $line, 2);
		$p[$k] = trim($v);
	    }
	    if ($code === 0 && ($p['LoadState'] ?? '') === 'loaded') {
		$r['unit'] = true;
		$r['running'] = ($p['ActiveState'] ?? '') === 'active';
		$r['state'] = trim(($p['ActiveState'] ?? '') . ' (' . ($p['SubState'] ?? '') . ')');
		$r['pid'] = (int)($p['MainPID'] ?? 0) ?: null;
		$ts = strtotime((string)($p['ActiveEnterTimestamp'] ?? ''));
		$r['since'] = $r['running'] && $ts ? $ts : null;
	    }
	}
	//before the unit: the script's PID file
	if (!$r['unit'] && !empty($s['pidfile'])) {
	    $pid = is_readable($s['pidfile']) ? (int)trim((string)file_get_contents($s['pidfile'])) : 0;
	    $r['pid'] = $pid ?: null;
	    $r['running'] = $pid > 0 && is_dir("/proc/{$pid}");
	    $r['state'] = $pid ? ($r['running'] ? 'running (started by its script)' : 'stopped (its PID file is stale)') : 'stopped';
	    $r['since'] = $r['running'] ? @filemtime("/proc/{$pid}") ?: null : null;
	}
	if (isset($s['health']) && is_callable($s['health'])) {
	    try {
		$r['health'] = ($s['health'])();
	    } catch (\Throwable $e) {
		$r['health'] = [ false, $e->getMessage() ];
	    }
	}
	return $r;
    }

    //$verb (start, stop, restart) service $s: [ ok, message ]
    public static function control(array $s, $verb) {
	if (!in_array($verb, self::VERBS, true))
	    return [ false, 'unknown action' ];
	$unit = (string)($s['unit'] ?? '');
	if ($unit === '' || !self::status([ 'unit' => $unit ])['unit'])
	    return [ false, 'not a system service yet: run scripts/services-install.sh (as root) first' ];
	[ $code, $out ] = self::run([ 'systemctl', '--no-ask-password', $verb, $unit ], 60);
	if ($code !== 0)
	    return [ false, trim($out) ?: "systemctl {$verb} failed ({$code})" ];
	return [ true, ucfirst($verb) . ($verb === 'stop' ? 'ped' : 'ed') . ": {$unit}" ];
    }

    //runs $cmd (an argument list, no shell): [ exit code, stdout + stderr ]
    private static function run(array $cmd, $timeout = 10) {
	$p = @proc_open($cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, [ 'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C' ]);
	if (!is_resource($p))
	    return [ -1, 'cannot run ' . $cmd[0] ];
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$out = '';
	$end = time() + $timeout;
	while(true) {
	    $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	    $st = proc_get_status($p);
	    if (!$st['running'])
		break;
	    if (time() > $end) {
		proc_terminate($p);
		return [ -1, $out . "\n(timed out)" ];
	    }
	    usleep(50000);
	}
	$out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($p);
	return [ (int)$st['exitcode'], $out ];
    }
}
