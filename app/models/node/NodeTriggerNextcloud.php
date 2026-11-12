<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud file - starts the workflow when files appear (or change) in a Nextcloud folder: it reads the folder (and its subfolders to a depth)
    on its schedule (models\wf\WfSchedule, every 5 minutes by default; util\Scheduler runs runScheduled), through WebDAV with the credential's user. Nothing has to be set up in
    Nextcloud and no administrator is needed. What it has seen is kept in models\TriggerState, locked during a check: { seen: [ [ md5(path), etag ] ] }:
	the first check only notes the files there; later checks start runs for the files not seen before (and, with "New or changed", those whose etag changed)
	at most MAX_RUNS per check (the others: the next check); at most MAX_FILES are read per check (more: the others are not watched, logged)
    The variable: the file (as Nextcloud Files lists it: { name, path, folder, size, modified, contentType, etag, fileId, ... } + change: new | changed), or { files, count } per check
*/

namespace models\node;

class NodeTriggerNextcloud extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'nextcloud';
    public static $label = 'Nextcloud file';
    public static $icon = 'bi-cloud-arrow-up';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const CHANGES = [ 'new' => 'New files', 'changed' => 'New or changed files' ];
    const RUNS = [ 'each' => 'One run per file', 'batch' => 'One run per check with files' ];
    const MAX_RUNS = 200;
    const MAX_FILES = 10000;
    const TIMEOUT = 120;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Nextcloud credential',
		'choices' => NodeAppNextcloud::credentialChoices(),
		'help' => 'Its user\'s files are watched',
		'required' => true,
	    ],
	    [
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'default' => '',
		'help' => 'A path in the files, e.g. /Scans; empty: the top',
		'ui' => [ 'width' => 6, 'placeholder' => '/Scans' ],
	    ],
	    [
		'key' => 'depth',
		'type' => 'number',
		'label' => 'Subfolders',
		'default' => 0,
		'help' => 'Levels below the folder (0: only it)',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => NodeAppNxFiles::MAX_DEPTH, 'skipEmpty' => true, 'message' => 'from 0 to ' . NodeAppNxFiles::MAX_DEPTH ] ],
		'ui' => [ 'step' => 1, 'width' => 3 ],
	    ],
	    [
		'key' => 'pattern',
		'type' => 'text',
		'label' => 'Names',
		'default' => '',
		'help' => 'Patterns, e.g. *.pdf, invoice-*; empty: all',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'changes',
		'type' => 'select',
		'label' => 'Start on',
		'required' => true,
		'default' => 'new',
		'choices' => self::CHANGES,
		'ui' => [ 'width' => 6, 'newRow' => true ],
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
		'key' => 'variable',
		'type' => 'text',
		'label' => 'File variable',
		'required' => true,
		'default' => 'file',
		'help' => 'e.g. {{ file.path }}, {{ file.name }}, {{ file.size }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'nextcloud')))) {
	    $this->errors('config', 'Nextcloud file: choose a Nextcloud credential of this project');
	    $valid = false;
	}
	try {
	    \util\Nextcloud::path($this->cfg('folder', ''), 'Folder');
	} catch (\RuntimeException $e) {
	    $this->errors('config', 'Nextcloud file: ' . $e->getMessage());
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'file') : 'file';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$file = [ 'name' => [ 'type' => 'string' ], 'path' => [ 'type' => 'string', 'info' => 'in the user\'s files, e.g. /Scans/a.pdf' ], 'folder' => [ 'type' => 'string' ],
	    'size' => [ 'type' => 'number', 'info' => 'bytes' ], 'modified' => [ 'type' => 'string', 'info' => 'ISO 8601' ], 'contentType' => [ 'type' => 'string' ], 'etag' => [ 'type' => 'string' ],
	    'fileId' => [ 'type' => 'number' ], 'change' => [ 'type' => 'string', 'info' => 'new or changed' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ files, count }', 'fields' => [ 'files' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $file ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the file', 'fields' => $file ] ];
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'nextcloud');
	if (!$cred)
	    throw new \Exception('Nextcloud file: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    //the files of the folder now: [ md5(path) => item ], more than MAX_FILES or not
    protected static function files($wf, array $cfg) {
	[ $items, $more ] = NodeAppNxFiles::walk(self::client($wf, $cfg, self::TIMEOUT), \util\Nextcloud::path($cfg['folder'] ?? '', 'Folder'), (int)($cfg['depth'] ?? 0), 'files',
	    (string)($cfg['pattern'] ?? ''), self::MAX_FILES);
	$byKey = [];
	foreach($items as $it)
	    $byKey[md5($it['path'])] = $it;
	return [ $byKey, $more ];
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Nextcloud file: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data, the executions started ]
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	[ $now, $more ] = self::files($wf, $cfg);
	if ($more)
	    \V::app()->log->info("Nextcloud file: workflow {$wf->_id}, step {$node['wfid']}: more than " . self::MAX_FILES . ' files, the others are not watched');
	if (!isset($data['seen']))
	    return [ [ 'seen' => array_map(fn($k, $it) => [ $k, $it['etag'] ], array_keys($now), array_values($now)) ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");

	$seen = [];
	foreach((array)$data['seen'] as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (string)($s[1] ?? '');
	}
	$changed = ($cfg['changes'] ?? 'new') === 'changed';
	$new = [];
	$kept = [];
	foreach($now as $k => $it) {
	    $was = $seen[$k] ?? null;
	    $isNew = $was === null;
	    if (($isNew || ($changed && $was !== $it['etag'])) && count($new) < self::MAX_RUNS) {
		$new[] = $it + [ 'change' => $isNew ? 'new' : 'changed' ];
		$kept[$k] = $it['etag'];
	    } elseif (!$isNew)
		//not started yet (over MAX_RUNS): the old etag kept, so the next check takes it
		$kept[$k] = $changed ? $was : $it['etag'];
	}
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'file';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'files' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'nextcloud', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['files']) ? 'Nextcloud: ' . \util\Format::plural($v['count'], 'file') : "Nextcloud file {$v['change']}: {$v['path']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'seen' => array_map(fn($k, $e) => [ $k, $e ], array_keys($kept), array_values($kept)) ], $started ];
    }

    //Run in the designer: one run with the newest file of the folder; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    [ $now ] = self::files($wf, $cfg);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Nextcloud file: ' . $e->getMessage() ];
	}
	if (!$now)
	    return [ 'executions' => [], 'message' => 'Nextcloud file: no file in the folder matches' ];
	$files = array_values($now);
	usort($files, fn($a, $b) => strcmp((string)$b['modified'], (string)$a['modified']));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'file';
	$file = $files[0] + [ 'change' => 'new' ];
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'files' => [ $file ], 'count' => 1 ] : $file;
	$exe = \models\wf\WfExecution::startFor($wf, 'nextcloud', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest file: {$file['path']}" ];
    }
}
