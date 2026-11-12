<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: Zendesk ticket - starts the workflow when tickets are created (or created or updated) that a search filter finds. It searches on its schedule
    (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled) as the credential's agent: no webhook, no Zendesk admin needed.
    The search asks for `created` (or `updated`) > <UTC time> (Zendesk's search takes ISO 8601 times with their zone): since the last change started - LAG (the search's index
    lags a little), the oldest first; what was started is kept in models\TriggerState, locked during a check: { floor, since (ms), seen: [ [ id|ms, ms ] ] }:
	floor   the first check's time: older tickets never start runs
	since   the newest change started; those in `seen` (a ticket at the same time of change) are skipped
    At most MAX_RUNS per check (the others: the next check). The variable: the ticket (as Zendesk Tickets gives it, + event: created / updated), or { tickets, count } per check
*/

namespace models\node;

class NodeTriggerZendesk extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'zendesk';
    public static $label = 'Zendesk ticket';
    public static $icon = 'bi-life-preserver';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'created' => 'Tickets created', 'updated' => 'Tickets created or updated' ];
    const RUNS = [ 'each' => 'One run per ticket', 'batch' => 'One run per check with tickets' ];
    const MAX_RUNS = 200;
    //ms looked back before the last change started (Zendesk's search indexes changes with a delay of a few minutes)
    const LAG = 300000;
    const TIMEOUT = 60;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Zendesk credential',
		'choices' => NodeAppZendesk::credentialChoices(),
		'help' => 'Its agent must see the tickets',
		'required' => true,
	    ],
	    [
		'key' => 'query',
		'type' => 'textarea',
		'label' => 'Search filter',
		'default' => '',
		'help' => 'Which tickets, in Zendesk\'s search, e.g. priority:urgent group:"Tier 2" tags:vip; empty: all the agent sees',
		'ui' => [ 'rows' => 2, 'code' => true ],
	    ],
	    [
		'key' => 'event',
		'type' => 'select',
		'label' => 'Start on',
		'required' => true,
		'default' => 'created',
		'choices' => self::EVENTS,
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
		'label' => 'Ticket variable',
		'required' => true,
		'default' => 'ticket',
		'help' => 'e.g. {{ ticket.id }}, {{ ticket.subject }}, {{ ticket.priority }}, {{ ticket.url }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'zendesk')))) {
	    $this->errors('config', 'Zendesk ticket: choose a Zendesk credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'ticket') : 'ticket';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$ticket = [ 'id' => [ 'type' => 'number' ], 'url' => [ 'type' => 'string', 'info' => 'in the agent workspace' ], 'subject' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ],
	    'status' => [ 'type' => 'string', 'info' => 'new, open, pending, hold, solved, closed' ], 'priority' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ],
	    'requesterId' => [ 'type' => 'number' ], 'assigneeId' => [ 'type' => 'number' ], 'groupId' => [ 'type' => 'number' ], 'tags' => [ 'type' => 'array' ], 'channel' => [ 'type' => 'string' ],
	    'created' => [ 'type' => 'string' ], 'updated' => [ 'type' => 'string' ], 'customFields' => [ 'type' => 'object', 'info' => '{ field id: value }' ],
	    'fields' => [ 'type' => 'object', 'info' => 'all, as Zendesk gives them' ], 'event' => [ 'type' => 'string', 'info' => 'created or updated' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ tickets, count }', 'fields' => [ 'tickets' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $ticket ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the ticket', 'fields' => $ticket ] ];
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'zendesk');
	if (!$cred)
	    throw new \Exception('Zendesk ticket: the credential is no longer available');
	return $cred->client($timeout);
    }

    //the search of the tickets changed after $ms (UTC), the user's filter added
    public static function query($filter, $field, $ms) {
	return trim('type:ticket ' . trim((string)$filter) . " {$field}>" . gmdate('Y-m-d\TH:i:s\Z', (int)floor($ms / 1000)));
    }

    //a ticket's time of change (created_at / updated_at) in ms; null when it cannot be read
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
	    \V::app()->log->info("Zendesk ticket: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$field = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	$floor = (int)$data['floor'];
	$since = (int)$data['since'];
	$seen = [];
	foreach((array)($data['seen'] ?? []) as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (int)$s[1];
	}
	$z = self::client($wf, $cfg, self::TIMEOUT);
	[ $raw ] = $z->search(self::query($cfg['query'] ?? '', $field, max($floor, $since - self::LAG)), min(\util\Zendesk::MAX_SEARCH, self::MAX_RUNS + count($seen) + 100),
	    [ 'sort_by' => "{$field}_at", 'sort_order' => 'asc' ]);
	$new = [];
	foreach($raw as $t) {
	    if (($t['result_type'] ?? 'ticket') !== 'ticket')
		continue;
	    $ms = self::ms($t["{$field}_at"] ?? null);
	    $id = (string)($t['id'] ?? '') . '|' . $ms;
	    if ($ms === null || $ms <= $floor || isset($seen[$id]))
		continue;
	    $ticket = NodeAppZendeskTickets::ticketOf((array)$t, $z);
	    $new[] = $ticket + [ 'event' => $field === 'updated' && self::ms($ticket['created']) !== $ms ? 'updated' : 'created' ];
	    $seen[$id] = $ms;
	    $since = max($since, $ms);
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	$seen = array_filter($seen, fn($ms) => $ms >= $since - self::LAG);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'ticket';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'tickets' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'zendesk', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['tickets']) ? 'Zendesk: ' . \util\Format::plural($v['count'], 'ticket') : "Zendesk ticket {$v['event']}: #{$v['id']} {$v['subject']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($id, $ms) => [ (string)$id, $ms ], array_keys($seen), array_values($seen)) ], $started ];
    }

    //Run in the designer: one run with the newest ticket the filter finds; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$field = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	try {
	    $z = self::client($wf, $cfg, 30);
	    [ $raw ] = $z->search(trim('type:ticket ' . trim((string)($cfg['query'] ?? ''))), 1, [ 'sort_by' => "{$field}_at", 'sort_order' => 'desc' ]);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Zendesk ticket: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'Zendesk ticket: no ticket matches' ];
	$ticket = NodeAppZendeskTickets::ticketOf((array)$raw[0], $z) + [ 'event' => $field ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'ticket';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'tickets' => [ $ticket ], 'count' => 1 ] : $ticket;
	$exe = \models\wf\WfExecution::startFor($wf, 'zendesk', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest ticket: #{$ticket['id']} {$ticket['subject']}" ];
    }
}
