<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: HubSpot record - starts the workflow when records of one type are created (or created or updated) that filter lines find. It searches on
    its schedule (models\wf\WfSchedule, every 5 minutes by default; util\Scheduler runs runScheduled) with the credential's token: no webhook (HubSpot's need a public app).
    The search asks for createdate (or the last modified date: lastmodifieddate of contacts, hs_lastmodifieddate of the others) >= since - LAG, the oldest first; what was
    started is kept in models\TriggerState, locked during a check: { floor, since (ms), seen: [ [ id|ms, ms ] ] } (as NodeTriggerJira). At most MAX_RUNS per check.
    The variable: the record (as HubSpot Records gives it, + event: created / updated), or { records, count } per check
*/

namespace models\node;

class NodeTriggerHubSpot extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'hubspot';
    public static $label = 'HubSpot record';
    public static $icon = 'bi-person-lines-fill';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'created' => 'Records created', 'updated' => 'Records created or updated' ];
    const RUNS = [ 'each' => 'One run per record', 'batch' => 'One run per check with records' ];
    const MAX_RUNS = 200;
    //ms looked back before the last change started (HubSpot's search index follows changes with a delay)
    const LAG = 120000;
    const TIMEOUT = 60;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'HubSpot credential',
		'choices' => NodeAppHubSpot::credentialChoices(),
		'required' => true,
	    ],
	    [ 'key' => 'objectType', 'type' => 'select', 'label' => 'Records', 'required' => true, 'default' => 'contacts', 'choices' => NodeAppHsRecords::TYPES, 'ui' => [ 'width' => 6, 'newRow' => true ] ],
	    [ 'key' => 'customType', 'type' => 'text', 'label' => 'Custom object', 'default' => '', 'help' => 'Its type ID (2-1234567) or name', 'requiredIf' => [ 'if' => 'objectType', 'in' => [ 'custom' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'objectType', 'operator' => 'eq', 'value' => 'custom' ] ] ],
	    [
		'key' => 'filters',
		'type' => 'textarea',
		'label' => 'Filters',
		'default' => '',
		'help' => 'Which records, one per line (at most 5), as HubSpot Records\' search: e.g. lifecyclestage = lead; empty: all',
		'ui' => [ 'rows' => 2, 'code' => true ],
	    ],
	    [ 'key' => 'event', 'type' => 'select', 'label' => 'Start on', 'required' => true, 'default' => 'created', 'choices' => self::EVENTS, 'ui' => [ 'width' => 6, 'newRow' => true ] ],
	    [ 'key' => 'runs', 'type' => 'select', 'label' => 'Runs', 'required' => true, 'default' => 'each', 'choices' => self::RUNS, 'ui' => [ 'width' => 6 ] ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Record variable',
		'required' => true,
		'default' => 'record',
		'help' => 'e.g. {{ record.email }}, {{ record.dealname }}, {{ record.url }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'hubspot')))) {
	    $this->errors('config', 'HubSpot record: choose a HubSpot credential of this project');
	    $valid = false;
	}
	try {
	    if (count(\util\HubSpot::filters((string)$this->cfg('filters', ''))) > 5)
		throw new \RuntimeException('Filters: at most 5 (the trigger adds the date)');
	    self::typeOf((array)$this->config);
	} catch (\RuntimeException $e) {
	    $this->errors('config', 'HubSpot record: ' . $e->getMessage());
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'record') : 'record';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$rec = [ 'id' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'the record in HubSpot' ], 'createdAt' => [ 'type' => 'string' ], 'updatedAt' => [ 'type' => 'string' ],
	    'properties' => [ 'type' => 'object', 'info' => 'its properties (also at the top: record.email, record.dealname, …)' ], 'event' => [ 'type' => 'string', 'info' => 'created or updated' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ records, count }', 'fields' => [ 'records' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $rec ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the record', 'fields' => $rec ] ];
    }

    //the API's name of the object type of the config
    public static function typeOf(array $cfg) {
	$t = (string)($cfg['objectType'] ?? 'contacts');
	if ($t !== 'custom')
	    return isset(NodeAppHsRecords::TYPES[$t]) ? $t : 'contacts';
	$c = strtolower(trim((string)($cfg['customType'] ?? '')));
	if (!preg_match(NodeAppHsRecords::TYPE_PATTERN, $c))
	    throw new \RuntimeException('Custom object: its type ID (2-1234567) or name');
	return $c;
    }

    //the property of the time of the event
    public static function timeProperty($type, $event) {
	return $event === 'updated' ? ($type === 'contacts' ? 'lastmodifieddate' : 'hs_lastmodifieddate') : 'createdate';
    }

    //a time of a record (ISO 8601, or ms) in ms; null when it cannot be read
    public static function ms($value) {
	try {
	    if (is_numeric($value))
		return (int)$value;
	    return $value ? (int)(new \DateTimeImmutable((string)$value))->format('Uv') : null;
	} catch (\Exception $e) {
	    return null;
	}
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'hubspot');
	if (!$cred)
	    throw new \Exception('HubSpot record: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("HubSpot record: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$type = self::typeOf($cfg);
	$event = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	$prop = self::timeProperty($type, $event);
	$floor = (int)$data['floor'];
	$since = (int)$data['since'];
	$seen = [];
	foreach((array)($data['seen'] ?? []) as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (int)$s[1];
	}
	$h = self::client($wf, $cfg, self::TIMEOUT);
	$filters = \util\HubSpot::filters((string)($cfg['filters'] ?? ''));
	$filters[] = [ 'propertyName' => $prop, 'operator' => 'GTE', 'value' => (string)max($floor + 1, $since - self::LAG) ];
	$props = array_values(array_unique(array_merge(NodeAppHsRecords::PROPERTIES[$type] ?? [], [ 'createdate', $prop ])));
	[ $raw ] = $h->search($type, $filters, $props, [ [ 'propertyName' => $prop, 'direction' => 'ASCENDING' ] ], self::MAX_RUNS + count($seen) + 50);
	$new = [];
	foreach($raw as $r) {
	    $ms = self::ms($r['properties'][$prop] ?? null);
	    $id = (string)($r['id'] ?? '') . '|' . $ms;
	    if ($ms === null || $ms <= $floor || isset($seen[$id]))
		continue;
	    $rec = \util\HubSpot::record((array)$r, $h->recordUrl($type, $r['id'] ?? ''));
	    $new[] = $rec + [ 'event' => $event === 'updated' && self::ms($r['properties']['createdate'] ?? null) !== $ms ? 'updated' : 'created' ];
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
	    $exe = \models\wf\WfExecution::startFor($wf, 'hubspot', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['records']) ? 'HubSpot: ' . \util\Format::plural($v['count'], 'record') : "HubSpot {$type} {$v['event']}: {$v['id']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($id, $ms) => [ (string)$id, $ms ], array_keys($seen), array_values($seen)) ], $started ];
    }

    //Run in the designer: one run with the newest record the filters find; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $type = self::typeOf($cfg);
	    $event = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	    $prop = self::timeProperty($type, $event);
	    $h = self::client($wf, $cfg, 30);
	    [ $raw ] = $h->search($type, \util\HubSpot::filters((string)($cfg['filters'] ?? '')), array_values(array_unique(array_merge(NodeAppHsRecords::PROPERTIES[$type] ?? [], [ $prop ]))),
		[ [ 'propertyName' => $prop, 'direction' => 'DESCENDING' ] ], 1);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'HubSpot record: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'HubSpot record: no record matches' ];
	$rec = \util\HubSpot::record((array)$raw[0], $h->recordUrl($type, $raw[0]['id'] ?? '')) + [ 'event' => $event ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'record';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'records' => [ $rec ], 'count' => 1 ] : $rec;
	$exe = \models\wf\WfExecution::startFor($wf, 'hubspot', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest {$type} record: {$rec['id']}" ];
    }
}
