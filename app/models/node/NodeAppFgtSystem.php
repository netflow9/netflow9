<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new step: FortiGate System - the firewall's state, and its configuration backed up into the project's Files. Operations (setting `operation`):
	status      hostname, model, serial, FortiOS version (monitor/system/status)
	resources   CPU, memory, sessions (monitor/system/resource/usage)
	interfaces  the interfaces and their state (monitor/system/interface)
	ha          the HA cluster's members (monitor/system/ha-peer)
	licenses    the licenses and FortiGuard contracts (monitor/license/status)
	backup      the configuration (global scope) saved in Files as <hostname>-<date>.txt: POST monitor/system/config/backup (FortiOS 7.0.2+), else GET (older):
	            { id, filename, size, contentType }. Its secrets are FortiOS-encrypted, not removed: keep it as you would the firewall's own backups
*/

namespace models\node;

class NodeAppFgtSystem extends NodeAppFortiGate {
    public static $name = 'fgt-system';
    public static $label = 'FortiGate System';
    public static $icon = 'bi-cpu';
    public static $defaultOperation = 'status';
    public static $defaultVariable = 'system';

    const OPERATIONS = [
	'status' => 'Get the status',
	'resources' => 'Get resource usage',
	'interfaces' => 'List interfaces',
	'ha' => 'List HA members',
	'licenses' => 'Get licenses',
	'backup' => 'Back up the configuration into Files',
    ];
    const MAX_BACKUP = 50 * 1024 * 1024;

    public static function outputVariables($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if ($op === 'backup' && \util\Validator::isIdentifier($name))
	    return [ $name => [ 'type' => 'object', 'info' => 'the file saved: { id, filename, size, contentType }', 'fields' => [
		'id' => [ 'type' => 'string', 'info' => 'the file\'s id in Files' ], 'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number' ], 'contentType' => [ 'type' => 'string' ] ] ] ];
	return parent::outputVariables($config);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'status':
		$r = $this->get('/api/v2/monitor/system/status');
		return [ $r, (string)($r['hostname'] ?? 'the firewall') . (isset($r['model_name']) ? " ({$r['model_name']})" : '') ];
	    case 'resources':
		return [ $this->get('/api/v2/monitor/system/resource/usage'), 'resource usage' ];
	    case 'interfaces':
		$r = $this->get('/api/v2/monitor/system/interface');
		return [ $r, \util\Format::plural(is_array($r) ? count($r) : 0, 'interface') ];
	    case 'ha':
		$r = $this->get('/api/v2/monitor/system/ha-peer');
		return [ $r, \util\Format::plural(is_array($r) && array_is_list($r) ? count($r) : 0, 'HA member') ];
	    case 'licenses':
		return [ $this->get('/api/v2/monitor/license/status'), 'licenses' ];
	    case 'backup':
		$status = $this->get('/api/v2/monitor/system/status');
		$host = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)($status['hostname'] ?? 'fortigate')) ?: 'fortigate';
		$filename = $host . '-' . gmdate('Ymd-His') . '.txt';
		$tmp = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		try {
		    $r = $this->fgt->raw('POST', '/api/v2/monitor/system/config/backup', [], array_replace([ 'scope' => 'global' ], $this->extraParams()), $tmp, self::MAX_BACKUP);
		    if (in_array($r['status'], [ 404, 405 ], true)) {
			ftruncate($tmp, 0);
			rewind($tmp);
			$r = $this->fgt->raw('GET', '/api/v2/monitor/system/config/backup', [ 'scope' => 'global' ], null, $tmp, self::MAX_BACKUP);
		    }
		    if ($r['status'] >= 400)
			throw new \RuntimeException("HTTP status {$r['status']}" . ($r['status'] === 403 ? ': the REST API administrator\'s profile may not back up' : ''));
		    rewind($tmp);
		    $head = (string)fread($tmp, 64);
		    if (!str_starts_with(ltrim($head), '#config-version') && !str_starts_with(ltrim($head), 'config '))
			throw new \RuntimeException('the answer is not a FortiOS configuration');
		    rewind($tmp);
		    $id = \models\File::createFromStream($filename, $tmp, [ 'metadata' => [
			'project' => $this->exec->project,
			'aid' => $this->exec->aid,
			'owner' => null,
			'source' => 'fortigate',
			'wf' => $this->exec->wf,
			'created_at' => new \MongoDB\BSON\UTCDateTime(),
		    ] ]);
		} finally {
		    fclose($tmp);
		}
		return [ [ 'id' => (string)$id, 'filename' => $filename, 'size' => $r['size'], 'contentType' => 'text/plain' ], "saved `{$filename}` ({$r['size']} bytes)" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
