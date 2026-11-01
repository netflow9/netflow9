<?php

/*
    CLD: 2026-10-31T11:05:39+02:00: moved to the core from the Admin plugin (was plugins\MultiTenant\util\Services): the Services page of the Settings (controllers\ServicesController);
    the list (services(): hook app:services, was admin:services) and who may see / manage them (allowed())
    CLD: 2026-11-01T19:00:00+02:00: in Docker (docker()): read-only, the status from each service's check, start / stop / restart with docker compose on the host
    the installation's services: their status (systemd: `systemctl show`, which any user may read; a service not yet made a unit:
    its script's PID file) and start / stop / restart (`systemctl`, allowed to this app's user for these units only by the polkit rule of scripts/daemon.sh)
*/

namespace util;

class Services {
    const VERBS = [ 'start', 'stop', 'restart' ];

    //CLD: 2026-10-31T11:05:39+02:00: the services of the installation (hook app:services ($services: \ArrayObject): id => [ label, description, unit (systemd), pidfile (before the unit:
    //a script's), health: fn() => [ ok (bool|null), text ] ]); was plugins\MultiTenant\Plugin::services
    public static function services() {
	$services = new \ArrayObject();
	\V::app()->hooks->applyHook('app:services', [ $services ]);
	return $services->getArrayCopy();
    }

    //CLD: 2026-10-31T19:30:00+02:00: the services, each with its `status` (status()): what the page shows (was ServicesController::index's own loop)
    public static function withStatus() {
	$r = [];
	foreach(self::services() as $id => $s)
	    $r[$id] = $s + [ 'status' => self::status($s) ];
	return $r;
    }

    //CLD: 2026-10-31T19:30:00+02:00: where the services are managed: with the Admin plugin its super-admins' page (/services/index), without it a tab of the account's settings
    public static function pageUrl() {
	return self::adminPlugin() ? \V::app()->createUrl('/services/index') : \V::app()->createUrl('/account/settings', [ '__tab' => 'services' ]);
    }

    //CLD: 2026-10-31T11:05:39+02:00: whether the signed-in user may see and manage the services: with the Admin plugin, a super-admin (as before: plugins\MultiTenant\Plugin::isAdmin; its
    //2FA is required by the page); without it, the account's owners (ownerSignedIn)
    public static function allowed() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest)
	    return false;
	//CLD: 2026-10-31T22:00:00+02:00: the administration's own check (hook services:admin; was plugins\MultiTenant\Plugin::isAdmin)
	if (($admin = self::admin()) !== null)
	    return (bool)($admin['allowed'])();
	return self::ownerSignedIn();
    }

    //the signed-in user's role is the account's Owner role (Role `iname` owner)
    public static function ownerSignedIn() {
	if (\V::app()->user->isGuest || empty(\V::app()->user->role))
	    return false;
	$role = \V::app()->om->findById('Role', (string)\V::app()->user->role);
	return $role && (string)$role->iname === 'owner';
    }

    //the administration is there: the MultiTenant plugin is enabled (plugins.json)
    //CLD: 2026-10-31T21:00:00+02:00: the MultiTenant plugin (was the Admin plugin)
    //CLD: 2026-10-31T22:00:00+02:00: a plugin manages the services (hook services:admin), so the core runs without the MultiTenant plugin (was plugins\MultiTenant\Plugin::enabled)
    public static function adminPlugin() {
	return self::admin() !== null;
    }

    //CLD: 2026-10-31T22:00:00+02:00: new: the administration that manages the services, as a plugin offers it (hook services:admin ($box: \ArrayObject): `allowed` => fn() => whether the
    //signed-in user may see them, `require` => fn() => refuses the request otherwise); null: none (the account's owners manage them)
    public static function admin() {
	$box = new \ArrayObject();
	\V::app()->hooks->applyHook('services:admin', [ $box ]);
	return isset($box['allowed'], $box['require']) ? $box->getArrayCopy() : null;
    }

    //CLD: 2026-10-31T18:00:00+02:00: the systemd unit of this installation's service $name (daemon, playwright): nfn-<name>-<the app's user> (the owner of app/, as
    //scripts/daemon.sh and scripts/playwright-mcp.sh name them), so that several installations on one server (live, development) each have theirs; was nfn-<name>.service
    public static function unit($name) {
	$owner = @fileowner(BASE . '/app');
	$user = $owner !== false && function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? '') : '';
	return "nfn-{$name}-" . ($user !== '' ? $user : basename(BASE)) . '.service';
    }

    //unit $unit was written by one of this installation's scripts (its first line: #written by <app>/scripts/...), or is not there yet: a server's Haraka is one installation's
    //CLD: 2026-11-01T21:30:00+02:00: any script of the installation (Haraka's unit: scripts/haraka.sh; was only 4_services-install.sh, now daemon.sh)
    public static function ownUnit($unit) {
	$file = '/etc/systemd/system/' . $unit;
	if (!is_file($file))
	    return true;
	$f = @fopen($file, 'r');
	$first = $f ? trim((string)fgets($f, 4096)) : '';
	if ($f)
	    fclose($f);
	return str_starts_with($first, '#written by ' . BASE . '/scripts/');
    }

    //CLD: 2026-11-01T19:00:00+02:00: the app runs in Docker (NFN_RUNTIME=docker, compose.yaml): no systemd; the services are compose's, shown from their checks only (read-only)
    public static function docker() {
	return (string)\V::env('NFN_RUNTIME', '') === 'docker';
    }

    //the status of service $s: [ unit (installed as a systemd unit), running (bool|null: unknown), state (text), pid, since (unix s|null), health: [ ok, text ]|null ]
    //CLD: 2026-11-01T19:00:00+02:00: + docker (bool): in Docker running is the check's result (no systemctl, no PID files: another container's)
    public static function status(array $s) {
	$r = [ 'unit' => false, 'running' => null, 'state' => 'unknown', 'pid' => null, 'since' => null, 'health' => null, 'docker' => self::docker() ];
	$unit = $r['docker'] ? '' : (string)($s['unit'] ?? '');
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
	if (!$r['docker'] && !$r['unit'] && !empty($s['pidfile'])) {
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
	if ($r['docker'] && $r['health'] !== null) {
	    $r['running'] = (bool)$r['health'][0];
	    $r['state'] = $r['running'] ? 'answers (Docker)' : 'does not answer (Docker)';
	}
	return $r;
    }

    //$verb (start, stop, restart) service $s: [ ok, message ]
    public static function control(array $s, $verb) {
	if (!in_array($verb, self::VERBS, true))
	    return [ false, 'unknown action' ];
	//CLD: 2026-11-01T19:00:00+02:00: Docker's services are managed by compose, on the host
	if (self::docker())
	    return [ false, 'managed by Docker: docker compose ' . $verb . ' <service>, on the host' ];
	$unit = (string)($s['unit'] ?? '');
	if ($unit === '' || !self::status([ 'unit' => $unit ])['unit'])
	    //CLD: 2026-11-01T22:00:00+02:00: the service's own script (was scripts/4_services-install.sh for all)
	    return [ false, 'not a system service yet: run its script as root first (scripts/daemon.sh, haraka.sh or playwright-mcp.sh install)' ];
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
