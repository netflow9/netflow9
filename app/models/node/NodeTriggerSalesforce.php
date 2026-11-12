<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: Salesforce record - starts the workflow when records of one object are created (or created or updated) that a SOQL condition finds. It queries
    on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled) as the app's run-as user: no streaming (Salesforce's Pub/Sub API is
    gRPC), no setup in Salesforce. The query asks for CreatedDate (or SystemModstamp) >= since - LAG (UTC literals: no time zone involved), the oldest first; what was started is
    kept in models\TriggerState, locked during a check: { floor, since (ms), seen: [ [ Id|ms, ms ] ] } (as NodeTriggerJira). At most MAX_RUNS per check.
    The variable: the record (as Salesforce Records gives it, + event: created / updated), or { records, count } per check
*/

namespace models\node;

class NodeTriggerSalesforce extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'salesforce';
    public static $label = 'Salesforce record';
    public static $icon = 'bi-cloud-fill';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'created' => 'Records created', 'updated' => 'Records created or updated' ];
    const RUNS = [ 'each' => 'One run per record', 'batch' => 'One run per check with records' ];
    const MAX_RUNS = 200;
    //ms looked back before the last change started (a transaction commits after its timestamp)
    const LAG = 60000;
    const TIMEOUT = 60;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Salesforce credential',
		'choices' => NodeAppSalesforce::credentialChoices(),
		'help' => 'Its run-as user must see the records',
		'required' => true,
	    ],
	    [ 'key' => 'object', 'type' => 'select', 'label' => 'Object', 'required' => true, 'default' => 'Lead', 'choices' => NodeAppSfRecords::OBJECTS, 'ui' => [ 'width' => 6, 'newRow' => true ] ],
	    [ 'key' => 'customObject', 'type' => 'text', 'label' => 'API name', 'default' => '', 'help' => 'e.g. Invoice__c', 'requiredIf' => [ 'if' => 'object', 'in' => [ 'custom' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'object', 'operator' => 'eq', 'value' => 'custom' ] ] ],
	    [
		'key' => 'where',
		'type' => 'textarea',
		'label' => 'Where',
		'default' => '',
		'help' => 'A SOQL condition: which records, e.g. LeadSource = \'Web\'; empty: all',
		'ui' => [ 'rows' => 2, 'code' => true ],
	    ],
	    [
		'key' => 'fields',
		'type' => 'text',
		'label' => 'Fields',
		'default' => '',
		'help' => 'More fields to read, separated by commas (the object\'s usual ones are read)',
		'ui' => [ 'advanced' => true ],
	    ],
	    [ 'key' => 'event', 'type' => 'select', 'label' => 'Start on', 'required' => true, 'default' => 'created', 'choices' => self::EVENTS, 'ui' => [ 'width' => 6, 'newRow' => true ] ],
	    [ 'key' => 'runs', 'type' => 'select', 'label' => 'Runs', 'required' => true, 'default' => 'each', 'choices' => self::RUNS, 'ui' => [ 'width' => 6 ] ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Record variable',
		'required' => true,
		'default' => 'record',
		'help' => 'e.g. {{ record.Id }}, {{ record.Name }}, {{ record.Email }}, {{ record.url }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'salesforce')))) {
	    $this->errors('config', 'Salesforce record: choose a Salesforce credential of this project');
	    $valid = false;
	}
	try {
	    self::objectOf((array)$this->config);
	    self::fieldsOf((array)$this->config);
	} catch (\RuntimeException $e) {
	    $this->errors('config', 'Salesforce record: ' . $e->getMessage());
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'record') : 'record';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$rec = [ 'Id' => [ 'type' => 'string' ], 'Name' => [ 'type' => 'string' ], 'CreatedDate' => [ 'type' => 'string' ], 'SystemModstamp' => [ 'type' => 'string' ],
	    'type' => [ 'type' => 'string', 'info' => 'the object' ], 'url' => [ 'type' => 'string', 'info' => 'the record in Lightning' ], 'event' => [ 'type' => 'string', 'info' => 'created or updated' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ records, count }', 'fields' => [ 'records' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $rec ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the record: its fields', 'fields' => $rec ] ];
    }

    public static function objectOf(array $cfg) {
	$o = (string)($cfg['object'] ?? 'Lead');
	return $o === 'custom' ? \util\Salesforce::name($cfg['customObject'] ?? '', 'API name') : (isset(NodeAppSfRecords::OBJECTS[$o]) ? $o : 'Lead');
    }

    public static function fieldsOf(array $cfg) {
	$o = self::objectOf($cfg);
	$more = array_map(fn($x) => \util\Salesforce::name($x, 'Fields', true), NodeAppSfRecords::names((string)($cfg['fields'] ?? '')));
	return array_values(array_unique(array_merge(NodeAppSfRecords::FIELDS[$o] ?? [ 'Id', 'Name', 'CreatedDate', 'LastModifiedDate' ], [ 'CreatedDate', 'SystemModstamp' ], $more)));
    }

    //the query of the records changed since $fromMs: the user's condition, the time field, the oldest first
    public static function soql(array $cfg, $field, $fromMs, $limit) {
	$where = trim((string)($cfg['where'] ?? ''));
	return 'SELECT ' . implode(', ', self::fieldsOf($cfg)) . ' FROM ' . self::objectOf($cfg) . ' WHERE ' . ($where !== '' ? "({$where}) AND " : '') . "{$field} >= " . \util\Salesforce::datetime($fromMs)
	    . " ORDER BY {$field} ASC, Id ASC LIMIT " . (int)$limit;
    }

    public static function ms($value) {
	try {
	    return $value ? (int)(new \DateTimeImmutable((string)$value))->format('Uv') : null;
	} catch (\Exception $e) {
	    return null;
	}
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'salesforce');
	if (!$cred)
	    throw new \Exception('Salesforce record: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Salesforce record: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$now = $now ?? (int)floor(microtime(true) * 1000);
	if (empty($data['since']))
	    return [ [ 'floor' => $now, 'since' => $now, 'seen' => [] ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$event = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	$field = $event === 'updated' ? 'SystemModstamp' : 'CreatedDate';
	$floor = (int)$data['floor'];
	$since = (int)$data['since'];
	$seen = [];
	foreach((array)($data['seen'] ?? []) as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (int)$s[1];
	}
	$s = self::client($wf, $cfg, self::TIMEOUT);
	$limit = self::MAX_RUNS + count($seen) + 50;
	[ $raw ] = $s->query(self::soql($cfg, $field, max($floor + 1, $since - self::LAG), $limit), $limit);
	$new = [];
	foreach($raw as $r) {
	    $ms = self::ms($r[$field] ?? null);
	    $id = (string)($r['Id'] ?? '') . '|' . $ms;
	    if ($ms === null || $ms <= $floor || isset($seen[$id]))
		continue;
	    $rec = \util\Salesforce::record((array)$r, $s->instance());
	    $new[] = $rec + [ 'event' => $event === 'updated' && self::ms($r['CreatedDate'] ?? null) !== $ms ? 'updated' : 'created' ];
	    $seen[$id] = $ms;
	    $since = max($since, $ms);
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	$seen = array_filter($seen, fn($ms) => $ms >= $since - self::LAG);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'record';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'records' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'salesforce', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['records']) ? 'Salesforce: ' . \util\Format::plural($v['count'], 'record') : "Salesforce {$v['type']} {$v['event']}: {$v['Id']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($id, $ms) => [ (string)$id, $ms ], array_keys($seen), array_values($seen)) ], $started ];
    }

    //Run in the designer: one run with the newest record the condition finds; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $event = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	    $field = $event === 'updated' ? 'SystemModstamp' : 'CreatedDate';
	    $where = trim((string)($cfg['where'] ?? ''));
	    $s = self::client($wf, $cfg, 30);
	    [ $raw ] = $s->query('SELECT ' . implode(', ', self::fieldsOf($cfg)) . ' FROM ' . self::objectOf($cfg) . ($where !== '' ? " WHERE {$where}" : '') . " ORDER BY {$field} DESC LIMIT 1", 1);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Salesforce record: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'Salesforce record: no record matches' ];
	$rec = \util\Salesforce::record((array)$raw[0], $s->instance()) + [ 'event' => $event ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'record';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'records' => [ $rec ], 'count' => 1 ] : $rec;
	$exe = \models\wf\WfExecution::startFor($wf, 'salesforce', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => 'Run with the newest record: ' . ($rec['Id'] ?? '') ];
    }
}
