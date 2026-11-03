<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new trigger: Wazuh alert - starts the workflow on new alerts of a rule level and worse, of agents, rules, groups, MITRE techniques, a query. It reads
    the Wazuh indexer on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled), oldest first; nothing has to be set up in Wazuh.
    What it has read is kept in models\TriggerState, locked during a check: { floor, since (ms), seen: [ [ _id, ms ] ] }:
	floor   the first check's time: the newest alert of the indexer (Wazuh's own clock), else now. Older alerts never start runs
	since   the newest alert started. Each check reads from LAG before it, as alerts reach the indexer late and out of order (Filebeat's batches); those in `seen` are skipped
    An alert that reaches the indexer more than LAG late is missed. The variable: the alert (with _id, _index), or { alerts, count } per check; at most MAX_RUNS per check (the others:
    the next check)
*/

namespace models\node;

class NodeTriggerWazuh extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'wazuh';
    public static $label = 'Wazuh alert';
    public static $icon = 'bi-shield-exclamation';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RUNS = [ 'each' => 'One run per alert', 'batch' => 'One run per check with alerts' ];
    const MAX_RUNS = 200;
    const LAG = 120000;
    const TIMEOUT = 60;

    public static function configSchema() {
	$filters = array_map(function($f) {
	    unset($f['ui']['expression']);
	    return array_replace($f, $f['key'] === 'minLevel' ? [ 'default' => 10 ] : []);
	}, NodeAppWzAlerts::filterFields());
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Wazuh credential',
		'choices' => NodeAppWazuh::credentialChoices(),
		'help' => 'With the indexer: the alerts are read from it',
		'required' => true,
	    ],
	], $filters, [
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Alert variable',
		'required' => true,
		'default' => 'alert',
		'help' => 'e.g. {{ alert.rule.description }}, {{ alert.rule.level }}, {{ alert.agent.name }}, {{ alert.data.srcip }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]));
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
	if ($id !== '') {
	    $cred = $this->workflow ? \models\Credential::findFor($this->workflow, $id, 'wazuh') : null;
	    if (empty($cred)) {
		$this->errors('config', 'Wazuh alert: choose a Wazuh credential of this project');
		$valid = false;
	    } elseif (trim((string)$cred->indexerUrl) === '') {
		$this->errors('config', 'Wazuh alert: the credential has no indexer, where the alerts are');
		$valid = false;
	    }
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'alert') : 'alert';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ alerts, count }', 'fields' => [
		'alerts' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an alert, as Wazuh wrote it' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the alert, as Wazuh wrote it', 'fields' => [
	    '_id' => [ 'type' => 'string', 'info' => 'its id in the indexer' ], 'timestamp' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    'rule' => [ 'type' => 'object', 'info' => '{ id, level, description, groups, mitre, ... }' ], 'agent' => [ 'type' => 'object', 'info' => '{ id, name, ip }' ],
	    'data' => [ 'type' => 'object', 'info' => 'what the decoder found, e.g. srcip, dstuser' ], 'full_log' => [ 'type' => 'string', 'info' => 'the log line' ] ] ] ];
    }

    //an alert's time, in ms; null when it has none that can be read
    public static function ms($alert) {
	if (empty($alert['timestamp']))
	    return null;
	try {
	    return (int)(new \DateTimeImmutable((string)$alert['timestamp']))->format('Uv');
	} catch (\Exception $e) {
	    return null;
	}
    }

    protected static function iso($ms) {
	return gmdate('Y-m-d\TH:i:s', (int)floor($ms / 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'wazuh');
	if (!$cred)
	    throw new \Exception('Wazuh alert: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Wazuh alert: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$w = self::client($wf, $cfg, self::TIMEOUT);
	if (empty($data['since'])) {
	    [ $newest ] = $w->search(\util\Wazuh::ALERTS, [ 'size' => 1, 'sort' => [ [ 'timestamp' => [ 'order' => 'desc' ] ] ], '_source' => [ 'timestamp' ] ]);
	    $t = ($newest ? self::ms($newest[0]) : null) ?? (int)floor(microtime(true) * 1000);
	    return [ [ 'floor' => $t, 'since' => $t, 'seen' => [] ], [] ];
	}
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");

	$floor = (int)$data['floor'];
	$since = (int)$data['since'];
	$seen = [];
	foreach((array)($data['seen'] ?? []) as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (int)$s[1];
	}
	$filters = NodeAppWzAlerts::filters($cfg);
	$filters[] = NodeAppWzAlerts::timeRange($since - self::LAG > $floor ? [ 'gte' => self::iso($since - self::LAG) ] : [ 'gt' => self::iso($floor) ]);
	[ $hits ] = $w->search(\util\Wazuh::ALERTS, [ 'size' => min(\util\Wazuh::MAX_HITS, self::MAX_RUNS + count($seen)), 'sort' => [ [ 'timestamp' => [ 'order' => 'asc' ] ] ],
	    'query' => [ 'bool' => [ 'filter' => $filters ] ] ]);

	$new = [];
	foreach($hits as $a) {
	    $ms = self::ms($a);
	    if ($a['_id'] === '' || $ms === null || isset($seen[$a['_id']]))
		continue;
	    $new[] = $a;
	    $seen[$a['_id']] = $ms;
	    $since = max($since, $ms);
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	$seen = array_filter($seen, fn($ms) => $ms >= $since - self::LAG);

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'alert';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'alerts' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'wazuh', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['alerts']) ? 'Wazuh: ' . \util\Format::plural($v['count'], 'alert') : 'Wazuh alert: ' . NodeAppWzAlerts::describe($v));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($id, $ms) => [ (string)$id, $ms ], array_keys($seen), array_values($seen)) ], $started ];
    }

    //Run in the designer: one run with the newest matching alert; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    [ $hits ] = self::client($wf, $cfg, 30)->search(\util\Wazuh::ALERTS, [ 'size' => 1, 'sort' => [ [ 'timestamp' => [ 'order' => 'desc' ] ] ], 'query' => [ 'bool' => [ 'filter' => NodeAppWzAlerts::filters($cfg) ] ] ]);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Wazuh alert: ' . $e->getMessage() ];
	}
	if (!$hits)
	    return [ 'executions' => [], 'message' => 'Wazuh alert: no alert matches' ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'alert';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'alerts' => $hits, 'count' => 1 ] : $hits[0];
	$exe = \models\wf\WfExecution::startFor($wf, 'wazuh', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => 'Run with the newest matching alert: ' . NodeAppWzAlerts::describe($hits[0]) ];
    }
}
