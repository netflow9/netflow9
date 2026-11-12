<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: GLPI ticket - starts the workflow when tickets are created (or created or updated: a follow-up, a task, a status changed) that a status and
    GLPI's search criteria find. It searches on its schedule (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled) with the credential's user: nothing
    to set up in GLPI. No time zone is needed: GLPI's own values are compared, kept in models\TriggerState (locked during a check):
	created   { mode, last }: the largest ticket id seen (the first check: the newest of all); those above it, the oldest first
	updated   { mode, floor, since, seen: [ [ id|date_mod, date_mod ] ] }: floor the newest date_mod at the first check (older changes never start runs), since the newest
	          change started; the search looks back LAG seconds before it, those in `seen` skipped
    At most MAX_RUNS per check (the others: the next check). The variable: the ticket (as GLPI Tickets gives it, + event: created / updated), or { tickets, count } per check
*/

namespace models\node;

class NodeTriggerGlpi extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'glpi';
    public static $label = 'GLPI ticket';
    public static $icon = 'bi-headset';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'created' => 'Tickets created', 'updated' => 'Tickets created or updated' ];
    const RUNS = [ 'each' => 'One run per ticket', 'batch' => 'One run per check with tickets' ];
    const MAX_RUNS = 200;
    //rows of the search engine read at most per check
    const MAX_SCAN = 2000;
    //seconds looked back before the last change started (changes saved in the same second)
    const LAG = 60;
    const TIMEOUT = 60;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'GLPI credential',
		'choices' => NodeAppGlpi::credentialChoices(),
		'help' => 'Its user (profile, entity) must see the tickets',
		'required' => true,
	    ],
	    [
		'key' => 'statusFilter',
		'type' => 'select',
		'label' => 'Status',
		'default' => '',
		'choices' => NodeAppGlpiTickets::STATUS_FILTERS,
		'help' => 'Of the tickets when checked',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'event',
		'type' => 'select',
		'label' => 'Start on',
		'required' => true,
		'default' => 'created',
		'choices' => self::EVENTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'criteria',
		'type' => 'textarea',
		'label' => 'Criteria',
		'default' => '',
		'help' => 'GLPI\'s search criteria, a JSON list, e.g. [{"field": 7, "searchtype": "equals", "value": 3}] (category 3); the fields\' numbers: GLPI Setup, List search options',
		'ui' => [ 'rows' => 2, 'code' => true, 'advanced' => true ],
	    ],
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
		'label' => 'Ticket variable',
		'required' => true,
		'default' => 'ticket',
		'help' => 'e.g. {{ ticket.id }}, {{ ticket.name }}, {{ ticket.content }}, {{ ticket.url }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'glpi')))) {
	    $this->errors('config', 'GLPI ticket: choose a GLPI credential of this project');
	    $valid = false;
	}
	try {
	    self::criteria(is_array($this->config) ? $this->config : []);
	} catch (\RuntimeException $e) {
	    $this->errors('config', 'GLPI ticket: ' . $e->getMessage());
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'ticket') : 'ticket';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$ticket = [ 'id' => [ 'type' => 'number' ], 'url' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string', 'info' => 'the title' ], 'content' => [ 'type' => 'string', 'info' => 'plain text' ],
	    'status' => [ 'type' => 'string', 'info' => 'New, Processing (assigned), Pending, Solved, …' ], 'type' => [ 'type' => 'string', 'info' => 'Incident or Request' ],
	    'urgency' => [ 'type' => 'string' ], 'impact' => [ 'type' => 'string' ], 'priority' => [ 'type' => 'string' ], 'category' => [ 'type' => 'string' ], 'entity' => [ 'type' => 'string' ],
	    'date' => [ 'type' => 'string', 'info' => 'opened, the server\'s time' ], 'date_mod' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'object', 'info' => 'all, as GLPI gives them' ],
	    'event' => [ 'type' => 'string', 'info' => 'created or updated' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ tickets, count }', 'fields' => [ 'tickets' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $ticket ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the ticket', 'fields' => $ticket ] ];
    }

    //the search criteria of the settings: the status and GLPI's (JSON)
    public static function criteria(array $cfg) {
	$raw = trim((string)($cfg['criteria'] ?? ''));
	$more = $raw === '' ? [] : json_decode($raw, true);
	if (!is_array($more) || ($more && !array_is_list($more)))
	    throw new \RuntimeException('Criteria: not a JSON list');
	return NodeAppGlpiTickets::criteria((string)($cfg['statusFilter'] ?? ''), '', $more);
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'glpi');
	if (!$cred)
	    throw new \Exception('GLPI ticket: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    //GLPI's time (Y-m-d H:i:s, the server's zone) moved by $seconds, in the same zone
    public static function shifted($time, $seconds) {
	$d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr((string)$time, 0, 19), new \DateTimeZone('UTC'));
	return $d ? $d->modify(($seconds >= 0 ? '+' : '') . (int)$seconds . ' seconds')->format('Y-m-d H:i:s') : (string)$time;
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("GLPI ticket: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$mode = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	$criteria = self::criteria($cfg);
	$F = NodeAppGlpiTickets::class;
	$g = self::client($wf, $cfg, self::TIMEOUT);
	try {
	    $first = ($data['mode'] ?? null) !== $mode;
	    if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
		throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	    if ($mode === 'created') {
		if ($first) {
		    [ $rows ] = $g->search('Ticket', $F::criteria('', '', []), 1, [ 'forcedisplay' => [ $F::F_ID ], 'sort' => $F::F_ID, 'order' => 'DESC' ]);
		    return [ [ 'mode' => $mode, 'last' => (int)($rows[0][(string)$F::F_ID] ?? 0) ], [] ];
		}
		$last = (int)($data['last'] ?? 0);
		$ids = [];
		for ($from = 0; $from < self::MAX_SCAN; $from += \util\Glpi::PAGE) {
		    [ $rows, $total ] = $g->search('Ticket', $criteria, \util\Glpi::PAGE, [ 'forcedisplay' => [ $F::F_ID ], 'sort' => $F::F_ID, 'order' => 'DESC' ], $from);
		    $above = array_filter(array_map(fn($r) => (int)($r[(string)$F::F_ID] ?? 0), $rows), fn($id) => $id > $last);
		    $ids = array_merge($ids, $above);
		    if (count($above) < count($rows) || count($rows) < \util\Glpi::PAGE || $from + count($rows) >= $total)
			break;
		}
		sort($ids);
		$ids = array_slice($ids, 0, self::MAX_RUNS);
		$tickets = array_map(fn($t) => $F::ticketOf($t, $g) + [ 'event' => 'created' ], $g->many('Ticket', $ids, [ 'expand_dropdowns' => 'true' ]));
		$data = [ 'mode' => $mode, 'last' => $ids ? max($last, end($ids)) : $last ];
	    } else {
		$more = [ 'forcedisplay' => [ $F::F_ID, $F::F_DATE_MOD ], 'sort' => $F::F_DATE_MOD, 'order' => 'DESC' ];
		$col = (string)$F::F_DATE_MOD;
		if ($first) {
		    [ $rows ] = $g->search('Ticket', $F::criteria('', '', []), 50, $more);
		    $floor = (string)($rows[0][$col] ?? '');
		    $seen = [];
		    foreach($rows as $r)
			if ((string)($r[$col] ?? '') === $floor && $floor !== '')
			    $seen[] = [ (int)$r[(string)$F::F_ID] . "|{$floor}", $floor ];
		    return [ [ 'mode' => $mode, 'floor' => $floor, 'since' => $floor, 'seen' => $seen ], [] ];
		}
		$floor = (string)($data['floor'] ?? '');
		$since = (string)($data['since'] ?? $floor);
		$lower = $since !== '' ? self::shifted($since, -self::LAG) : '';
		$seen = [];
		foreach((array)($data['seen'] ?? []) as $s) {
		    $s = array_values((array)$s);
		    $seen[(string)$s[0]] = (string)$s[1];
		}
		$found = [];
		for ($from = 0; $from < self::MAX_SCAN; $from += \util\Glpi::PAGE) {
		    [ $rows, $total ] = $g->search('Ticket', $criteria, \util\Glpi::PAGE, $more, $from);
		    $older = false;
		    foreach($rows as $r) {
			$dm = (string)($r[$col] ?? '');
			if ($dm < $lower) {
			    $older = true;
			    break;
			}
			$key = (int)($r[(string)$F::F_ID] ?? 0) . "|{$dm}";
			if ($dm >= $floor && !isset($seen[$key]))
			    $found[$key] = [ (int)$r[(string)$F::F_ID], $dm ];
		    }
		    if ($older || count($rows) < \util\Glpi::PAGE || $from + count($rows) >= $total)
			break;
		}
		uasort($found, fn($a, $b) => strcmp($a[1], $b[1]) ?: $a[0] <=> $b[0]);
		$found = array_slice($found, 0, self::MAX_RUNS, true);
		$byId = [];
		foreach($g->many('Ticket', array_column($found, 0), [ 'expand_dropdowns' => 'true' ]) as $t)
		    $byId[(int)$t['id']] = $t;
		$tickets = [];
		foreach($found as $key => [ $id, $dm ]) {
		    $seen[$key] = $dm;
		    $since = max($since, $dm);
		    if (!isset($byId[$id]))
			continue;
		    $t = $F::ticketOf($byId[$id], $g);
		    $tickets[] = $t + [ 'event' => (string)($t['date_creation'] ?? $t['date'] ?? '') === $dm ? 'created' : 'updated' ];
		}
		$keep = self::shifted($since, -self::LAG);
		$seen = array_filter($seen, fn($dm) => $dm >= $keep);
		$data = [ 'mode' => $mode, 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($k, $dm) => [ (string)$k, $dm ], array_keys($seen), array_values($seen)) ];
	    }
	} finally {
	    $g->close();
	}
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'ticket';
	$values = !$tickets ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'tickets' => $tickets, 'count' => count($tickets) ] ] : $tickets);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'glpi', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['tickets']) ? 'GLPI: ' . \util\Format::plural($v['count'], 'ticket') : "GLPI ticket {$v['event']}: #{$v['id']} {$v['name']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ $data, $started ];
    }

    //Run in the designer: one run with the newest ticket the settings find; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$F = NodeAppGlpiTickets::class;
	$mode = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	try {
	    $g = self::client($wf, $cfg, 30);
	    try {
		[ $rows ] = $g->search('Ticket', self::criteria($cfg), 1, [ 'forcedisplay' => [ $F::F_ID ], 'sort' => $mode === 'updated' ? $F::F_DATE_MOD : $F::F_ID, 'order' => 'DESC' ]);
		$raw = $rows ? $g->many('Ticket', [ (int)$rows[0][(string)$F::F_ID] ], [ 'expand_dropdowns' => 'true' ]) : [];
	    } finally {
		$g->close();
	    }
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'GLPI ticket: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'GLPI ticket: no ticket matches' ];
	$ticket = $F::ticketOf($raw[0], $g) + [ 'event' => $mode ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'ticket';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'tickets' => [ $ticket ], 'count' => 1 ] : $ticket;
	$exe = \models\wf\WfExecution::startFor($wf, 'glpi', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest ticket: #{$ticket['id']} {$ticket['name']}" ];
    }
}
