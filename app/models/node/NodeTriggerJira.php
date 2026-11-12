<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: Jira issue - starts the workflow when issues are created (or created or updated) that a JQL filter finds. It searches on its schedule
    (models\wf\WfSchedule, every 2 minutes by default; util\Scheduler runs runScheduled) with the credential's user: no webhook, no Jira administrator needed.
    The search asks for `created` (or `updated`) >= -<n>m (relative minutes: JQL's absolute dates are in the user's time zone), n covering the time since the last check + LAG,
    the oldest first; what was started is kept in models\TriggerState, locked during a check: { floor, since (ms), seen: [ [ key|ms, ms ] ] }:
	floor   the first check's time: older issues never start runs
	since   the newest change started; those in `seen` (an issue at the same time of change) are skipped
    At most MAX_RUNS per check (the others: the next check). The variable: the issue (as Jira Issues gives it, + event: created / updated), or { issues, count } per check
*/

namespace models\node;

class NodeTriggerJira extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'jira';
    public static $label = 'Jira issue';
    public static $icon = 'bi-kanban';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [ 'created' => 'Issues created', 'updated' => 'Issues created or updated' ];
    const RUNS = [ 'each' => 'One run per issue', 'batch' => 'One run per check with issues' ];
    const MAX_RUNS = 200;
    //ms looked back before the last change started (Jira indexes changes with a short delay)
    const LAG = 120000;
    const TIMEOUT = 60;

    public static function configSchema() {
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Jira credential',
		'choices' => NodeAppJira::credentialChoices(),
		'help' => 'Its user must see the issues',
		'required' => true,
	    ],
	    [
		'key' => 'jql',
		'type' => 'textarea',
		'label' => 'JQL filter',
		'default' => '',
		'help' => 'Which issues, e.g. project = OPS AND priority in (High, Highest); empty: all the user sees. Its ORDER BY is not used',
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
		'label' => 'Issue variable',
		'required' => true,
		'default' => 'issue',
		'help' => 'e.g. {{ issue.key }}, {{ issue.summary }}, {{ issue.priority }}, {{ issue.url }}',
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
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'jira')))) {
	    $this->errors('config', 'Jira issue: choose a Jira credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'issue') : 'issue';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$issue = [ 'key' => [ 'type' => 'string', 'info' => 'e.g. OPS-123' ], 'url' => [ 'type' => 'string' ], 'summary' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string' ],
	    'issueType' => [ 'type' => 'string' ], 'priority' => [ 'type' => 'string' ], 'project' => [ 'type' => 'string' ], 'assignee' => [ 'type' => 'object', 'info' => '{ displayName, accountId, emailAddress }' ],
	    'reporter' => [ 'type' => 'object' ], 'labels' => [ 'type' => 'array' ], 'created' => [ 'type' => 'string' ], 'updated' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string', 'info' => 'plain text' ],
	    'fields' => [ 'type' => 'object', 'info' => 'all the fields read, as Jira gives them' ], 'event' => [ 'type' => 'string', 'info' => 'created or updated' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ issues, count }', 'fields' => [ 'issues' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $issue ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the issue', 'fields' => $issue ] ];
    }

    protected static function client($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'jira');
	if (!$cred)
	    throw new \Exception('Jira issue: the credential is no longer available');
	return $cred->client($wf->aid, $timeout);
    }

    //the user's JQL without its ORDER BY, and the search of the issues changed in the last $minutes, the oldest first
    public static function jql($filter, $field, $minutes) {
	$f = trim(preg_replace('/\border\s+by\b.*$/is', '', (string)$filter));
	return ($f !== '' ? "({$f}) AND " : '') . "{$field} >= -{$minutes}m ORDER BY {$field} ASC";
    }

    //an issue's time of change (created / updated) in ms; null when it cannot be read
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
	    \V::app()->log->info("Jira issue: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$minutes = (int)ceil(max(0, $now - $since + self::LAG) / 60000) + 1;
	$j = self::client($wf, $cfg, self::TIMEOUT);
	[ $raw ] = $j->search(self::jql($cfg['jql'] ?? '', $field, $minutes), NodeAppJiraIssues::FIELDS, self::MAX_RUNS + count($seen) + 50);
	$new = [];
	foreach($raw as $i) {
	    $ms = self::ms($i['fields'][$field] ?? null);
	    $id = (string)($i['key'] ?? '') . '|' . $ms;
	    if ($ms === null || $ms <= $floor || isset($seen[$id]))
		continue;
	    $issue = NodeAppJiraIssues::issueOf((array)$i, $j);
	    $new[] = $issue + [ 'event' => $field === 'updated' && self::ms($issue['created']) !== $ms ? 'updated' : 'created' ];
	    $seen[$id] = $ms;
	    $since = max($since, $ms);
	    if (count($new) >= self::MAX_RUNS)
		break;
	}
	$seen = array_filter($seen, fn($ms) => $ms >= $since - self::LAG);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'issue';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'issues' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'jira', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['issues']) ? 'Jira: ' . \util\Format::plural($v['count'], 'issue') : "Jira issue {$v['event']}: {$v['key']} {$v['summary']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'floor' => $floor, 'since' => $since, 'seen' => array_map(fn($id, $ms) => [ (string)$id, $ms ], array_keys($seen), array_values($seen)) ], $started ];
    }

    //Run in the designer: one run with the newest issue the filter finds; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$field = ($cfg['event'] ?? 'created') === 'updated' ? 'updated' : 'created';
	try {
	    $j = self::client($wf, $cfg, 30);
	    $f = trim(preg_replace('/\border\s+by\b.*$/is', '', (string)($cfg['jql'] ?? '')));
	    [ $raw ] = $j->search(($f !== '' ? "{$f} " : '') . "ORDER BY {$field} DESC", NodeAppJiraIssues::FIELDS, 1);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Jira issue: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'Jira issue: no issue matches' ];
	$issue = NodeAppJiraIssues::issueOf((array)$raw[0], $j) + [ 'event' => $field ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'issue';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'issues' => [ $issue ], 'count' => 1 ] : $issue;
	$exe = \models\wf\WfExecution::startFor($wf, 'jira', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest issue: {$issue['key']} {$issue['summary']}" ];
    }
}
