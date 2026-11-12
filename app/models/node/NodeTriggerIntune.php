<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune device - starts the workflow when devices are enrolled, or become noncompliant, of an operating system and an ownership. It reads Intune
    on its schedule (models\wf\WfSchedule, every 5 minutes by default; util\Scheduler runs runScheduled) as the credential's app; kept in models\TriggerState (locked during a check):
	enrolled      { mode, floor, since (ms), seen: [ [ id|ms, ms ] ] }: the devices whose enrolledDateTime (UTC) is after floor (the first check's time), since - LAG looked back,
	              those in `seen` skipped
	noncompliant  { mode, ids }: the devices noncompliant at the last check; one noncompliant now and not then starts a run (the first check only notes them). A device
	              compliant again and noncompliant later starts one again
    At most MAX_RUNS per check (the others: the next check). The variable: the device (as Intune Devices gives it, + event: enrolled / noncompliant), or { devices, count } per check
*/

namespace models\node;

class NodeTriggerIntune extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'intune';
    public static $label = 'Intune device';
    public static $icon = 'bi-phone';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'enrolled' => 'Devices enrolled', 'noncompliant' => 'Devices becoming noncompliant' ];
    const RUNS = [ 'each' => 'One run per device', 'batch' => 'One run per check with devices' ];
    const MAX_RUNS = 200;
    //the noncompliant devices read at most per check
    const MAX_SCAN = 20000;
    //ms looked back before the last enrollment started (Intune shows a device a little after its enrollment)
    const LAG = 600000;
    const TIMEOUT = 120;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Intune credential',
		'choices' => NodeAppIntune::credentialChoices(),
		'help' => 'Its app needs DeviceManagementManagedDevices.Read.All',
		'required' => true,
	    ],
	    [
		'key' => 'event',
		'type' => 'select',
		'label' => 'Start on',
		'required' => true,
		'default' => 'enrolled',
		'choices' => self::EVENTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'os',
		'type' => 'text',
		'label' => 'Operating system',
		'default' => '',
		'help' => 'e.g. Windows, macOS, iOS, Android; empty: any',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'ownership',
		'type' => 'select',
		'label' => 'Ownership',
		'default' => '',
		'choices' => NodeAppIntuneDevices::OWNERSHIP,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Device variable',
		'required' => true,
		'default' => 'device',
		'help' => 'e.g. {{ device.name }}, {{ device.user }}, {{ device.os }}, {{ device.url }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	$id = (string)$this->cfg('credential', '');
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'intune')))) {
	    $this->errors('config', 'Intune device: choose an Intune credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'device') : 'device';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$device = [ 'id' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'in the Intune admin center' ], 'name' => [ 'type' => 'string' ],
	    'user' => [ 'type' => 'string', 'info' => 'the user\'s principal name' ], 'userName' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ], 'os' => [ 'type' => 'string' ],
	    'osVersion' => [ 'type' => 'string' ], 'compliance' => [ 'type' => 'string' ], 'ownership' => [ 'type' => 'string', 'info' => 'company or personal' ], 'model' => [ 'type' => 'string' ],
	    'serialNumber' => [ 'type' => 'string' ], 'enrolled' => [ 'type' => 'string' ], 'lastSync' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'object', 'info' => 'all, as Graph gives them' ],
	    'event' => [ 'type' => 'string', 'info' => 'enrolled or noncompliant' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ devices, count }', 'fields' => [ 'devices' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $device ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the device', 'fields' => $device ] ];
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'intune');
	if (!$cred)
	    throw new \Exception('Intune device: the credential is no longer available');
	return $cred->client($timeout);
    }

    //the $filter of the settings (operating system, ownership) and $more
    public static function filter(array $cfg, $more = '') {
	$os = trim((string)($cfg['os'] ?? ''));
	$own = (string)($cfg['ownership'] ?? '');
	return NodeAppIntune::filter([ $os !== '' ? 'operatingSystem eq ' . \util\Intune::quote($os) : '', $own !== '' && isset(NodeAppIntuneDevices::OWNERSHIP[$own]) ? "managedDeviceOwnerType eq '{$own}'" : '', $more ]);
    }

    public static function iso($ms) {
	return gmdate('Y-m-d\TH:i:s\Z', (int)floor($ms / 1000));
    }

    public static function ms($value) {
	try {
	    return $value ? (int)(new \DateTimeImmutable((string)$value))->format('Uv') : null;
	} catch (\Exception $e) {
	    return null;
	}
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Intune device: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //one check: [ the new state data, the executions started ]; $now: the time (ms; the checks)
    public static function check($wf, array $node, array $data, $now = null) {
	$cfg = (array)($node['config'] ?? []);
	$mode = ($cfg['event'] ?? 'enrolled') === 'noncompliant' ? 'noncompliant' : 'enrolled';
	$now = $now ?? (int)floor(microtime(true) * 1000);
	$first = ($data['mode'] ?? null) !== $mode;
	if ($first && $mode === 'enrolled')
	    return [ [ 'mode' => $mode, 'floor' => $now, 'since' => $now, 'seen' => [] ], [] ];
	$g = self::client($wf, $cfg, self::TIMEOUT);
	$path = NodeAppIntuneDevices::PATH;
	if ($mode === 'noncompliant') {
	    [ $rows, $truncated ] = $g->pages($path, [ '$filter' => self::filter($cfg, "complianceState eq 'noncompliant'"), '$select' => 'id', '$top' => 1000 ], self::MAX_SCAN);
	    $ids = array_values(array_unique(array_map(fn($r) => (string)($r['id'] ?? ''), $rows)));
	    if ($first)
		return [ [ 'mode' => $mode, 'ids' => $ids ], [] ];
	    if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
		throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	    $before = array_flip(array_map('strval', (array)($data['ids'] ?? [])));
	    $new = array_slice(array_values(array_filter($ids, fn($id) => $id !== '' && !isset($before[$id]))), 0, self::MAX_RUNS);
	    $devices = [];
	    foreach($new as $id) {
		try {
		    $devices[] = NodeAppIntuneDevices::deviceOf($g->request('GET', "{$path}/" . rawurlencode($id))) + [ 'event' => 'noncompliant' ];
		} catch (\RuntimeException $e) {
		    //gone since: no run
		}
	    }
	    //those left for the next check are not kept: they start then
	    $later = array_flip(array_slice(array_values(array_filter($ids, fn($id) => !isset($before[$id]))), self::MAX_RUNS));
	    $keep = array_values(array_filter($ids, fn($id) => !isset($later[$id])));
	    $data = [ 'mode' => $mode, 'ids' => $truncated ? array_values(array_unique(array_merge($keep, array_keys($before)))) : $keep ];
	} else {
	    if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
		throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	    $floor = (int)$data['floor'];
	    $since = (int)$data['since'];
	    $seen = [];
	    foreach((array)($data['seen'] ?? []) as $s) {
		$s = array_values((array)$s);
		$seen[(string)$s[0]] = (int)$s[1];
	    }
	    [ $rows ] = $g->pages($path, [ '$filter' => self::filter($cfg, 'enrolledDateTime ge ' . self::iso(max($floor, $since - self::LAG))), '$top' => 1000 ], self::MAX_SCAN);
	    usort($rows, fn($a, $b) => (self::ms($a['enrolledDateTime'] ?? null) ?? 0) <=> (self::ms($b['enrolledDateTime'] ?? null) ?? 0));
	    $devices = [];
	    foreach($rows as $r) {
		$ms = self::ms($r['enrolledDateTime'] ?? null);
		$key = (string)($r['id'] ?? '') . '|' . $ms;
		if ($ms === null || $ms <= $floor || isset($seen[$key]))
		    continue;
		$devices[] = NodeAppIntuneDevices::deviceOf((array)$r) + [ 'event' => 'enrolled' ];
		$seen[$key] = $ms;
		$since = max($since, $ms);
		if (count($devices) >= self::MAX_RUNS)
		    break;
	    }
	    $seen = array_filter($seen, fn($ms) => $ms >= $since - self::LAG);
	    $data = [ 'mode' => $mode, 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($k, $ms) => [ (string)$k, $ms ], array_keys($seen), array_values($seen)) ];
	}
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'device';
	$values = !$devices ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'devices' => $devices, 'count' => count($devices) ] ] : $devices);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'intune', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['devices']) ? 'Intune: ' . \util\Format::plural($v['count'], 'device') : "Intune device {$v['event']}: {$v['name']} ({$v['user']})");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ $data, $started ];
    }

    //Run in the designer: one run with a device the settings find (the newest enrolled of the last 30 days, or a noncompliant one); the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$mode = ($cfg['event'] ?? 'enrolled') === 'noncompliant' ? 'noncompliant' : 'enrolled';
	try {
	    $g = self::client($wf, $cfg, 30);
	    $more = $mode === 'noncompliant' ? "complianceState eq 'noncompliant'" : 'enrolledDateTime ge ' . self::iso((time() - 30 * 86400) * 1000);
	    [ $rows ] = $g->pages(NodeAppIntuneDevices::PATH, [ '$filter' => self::filter($cfg, $more), '$top' => 1000 ], 5000);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Intune device: ' . $e->getMessage() ];
	}
	if (!$rows)
	    return [ 'executions' => [], 'message' => 'Intune device: no device matches' ];
	usort($rows, fn($a, $b) => (self::ms($b['enrolledDateTime'] ?? null) ?? 0) <=> (self::ms($a['enrolledDateTime'] ?? null) ?? 0));
	$device = NodeAppIntuneDevices::deviceOf((array)$rows[0]) + [ 'event' => $mode ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'device';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'devices' => [ $device ], 'count' => 1 ] : $device;
	$exe = \models\wf\WfExecution::startFor($wf, 'intune', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the device {$device['name']} ({$device['user']})" ];
    }
}
