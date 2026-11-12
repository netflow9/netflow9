<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: Jira Issues - the issues of the site. Operations (setting `operation`):
	search       the issues a JQL search finds (with the fields named in Fields too)
	get          an issue with all its fields
	create       an issue: project, type, summary, description, priority, assignee, labels, components, due date, parent; custom fields in Other fields
	update       what is not empty of those (labels: replaced); Other fields merged
	transition   moved by a transition, given by its name or by the status it goes to (In Progress, Done); a resolution and a comment with it; already there: done
	transitions  the transitions the issue has now
	assign       assigned to a user (an email, a name or an account id; `-`: unassigned)
	comment      a comment added
	comments     its comments, the newest first
	addLabels    labels added / removed (the others kept)
	removeLabels
	link         linked to another issue (Blocks, Relates, Duplicates, Clones, …): this issue <outward description> the other
	attach       a project file attached
	delete       deleted (its subtasks too, when allowed)
    An issue: { key, id, url, summary, status, statusCategory (new / indeterminate / done), issueType, priority, project, assignee, reporter, labels, components, created, updated,
    duedate, resolution, parent, description (plain text), fields (all, as Jira gives them: custom fields by id) }. Texts: plain text (Jira Cloud: sent as paragraphs of ADF)
*/

namespace models\node;

class NodeAppJiraIssues extends NodeAppJira {
    public static $name = 'jira-issues';
    public static $label = 'Jira Issues';
    public static $icon = 'bi-kanban';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'issues';

    const OPERATIONS = [
	'search' => 'Search issues (JQL)',
	'get' => 'Get an issue',
	'create' => 'Create an issue',
	'update' => 'Update an issue',
	'transition' => 'Change the status',
	'transitions' => 'List the transitions',
	'assign' => 'Assign an issue',
	'comment' => 'Add a comment',
	'comments' => 'List comments',
	'addLabels' => 'Add labels',
	'removeLabels' => 'Remove labels',
	'link' => 'Link issues',
	'attach' => 'Attach a file',
	'delete' => 'Delete an issue',
    ];
    const WRITES = [ 'create', 'update', 'transition', 'assign', 'comment', 'addLabels', 'removeLabels', 'link', 'attach', 'delete' ];
    const LISTS = [ 'search', 'transitions', 'comments' ];
    const ONE = [ 'get', 'update', 'transition', 'transitions', 'assign', 'comment', 'comments', 'addLabels', 'removeLabels', 'link', 'attach', 'delete' ];
    const FIELDS = [ 'summary', 'status', 'issuetype', 'priority', 'project', 'assignee', 'reporter', 'labels', 'components', 'created', 'updated', 'duedate', 'resolution', 'parent', 'description' ];
    const MAX_ATTACH_MB = 100;

    protected static function operationFields() {
	$edit = [ 'create', 'update' ];
	return [
	    self::field('jql', 'JQL', [ 'search' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'e.g. project = OPS AND status != Done ORDER BY created DESC',
		'ui' => [ 'rows' => 2, 'expression' => 'text', 'code' => true ] ]),
	    self::field('extraFields', 'Fields', [ 'search' ], [ 'help' => 'More fields to read, e.g. customfield_10016, timetracking (Get an issue reads them all)', 'ui' => [ 'expression' => 'text', 'advanced' => true ] ]),
	    self::field('issue', 'Issue', self::ONE, [ 'required' => true, 'help' => 'Its key (OPS-123), its address, or an issue of a search or a trigger', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('project', 'Project', [ 'create' ], [ 'required' => true, 'help' => 'Its key, e.g. OPS', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('issueType', 'Type', [ 'create' ], [ 'required' => true, 'default' => 'Task', 'help' => 'Its name, e.g. Task, Bug, Story, Sub-task', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('summary', 'Summary', $edit, [ 'help' => 'Required to create; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', $edit, [ 'type' => 'textarea', 'help' => 'Plain text: paragraphs at blank lines', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('priority', 'Priority', $edit, [ 'help' => 'Its name: Highest, High, Medium, Low, Lowest', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('assignee', 'Assignee', array_merge($edit, [ 'assign' ]), [ 'help' => 'An email, a name or an account ID; - : unassigned', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('labels', 'Labels', array_merge($edit, [ 'addLabels', 'removeLabels' ]), [ 'help' => 'Separated by commas, or a list (no spaces in a label); update: replaces them all',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('components', 'Components', $edit, [ 'help' => 'Their names, separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('duedate', 'Due date', $edit, [ 'help' => 'A date (2026-12-31) or relative (+7 days)', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('parent', 'Parent', [ 'create' ], [ 'help' => 'For a sub-task, or an epic\'s child: the parent\'s key', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('fields', 'Other fields', $edit, [ 'type' => 'textarea', 'help' => 'A JSON object merged into the fields, as Jira names them, e.g. {"customfield_10016": 5}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('to', 'To', [ 'transition' ], [ 'required' => true, 'help' => 'The transition\'s name or the status it leads to, e.g. In Progress, Done', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('resolution', 'Resolution', [ 'transition' ], [ 'help' => 'e.g. Done, Won\'t Do; only when the transition\'s screen has it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('comment', 'Comment', [ 'comment', 'transition' ], [ 'type' => 'textarea', 'help' => 'Plain text. Transition: optional, added with it',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'comment' ] ], 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('linkType', 'Link type', [ 'link' ], [ 'required' => true, 'default' => 'Relates', 'help' => 'Its name: Blocks, Relates, Duplicate, Cloners, …', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('other', 'Other issue', [ 'link' ], [ 'required' => true, 'help' => 'This issue <outward description> the other, e.g. OPS-1 blocks OPS-2', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('sourceFile', 'Project file', [ 'attach' ], [ 'required' => true, 'help' => 'The id of a file of the project, e.g. {{ file.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'attach' ], [ 'type' => 'number', 'required' => true, 'default' => 10,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ATTACH_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ATTACH_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	    self::field('deleteSubtasks', 'With its subtasks', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'An issue with subtasks cannot be deleted without', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    //a user of an answer: { displayName, accountId | name, emailAddress, active }, or null
    public static function userOf($u) {
	if (!is_array($u) || !$u)
	    return null;
	return array_filter([ 'displayName' => $u['displayName'] ?? null, 'accountId' => $u['accountId'] ?? null, 'name' => $u['name'] ?? null, 'emailAddress' => $u['emailAddress'] ?? null,
	    'active' => $u['active'] ?? null ], fn($v) => $v !== null);
    }

    //an issue of an answer, simplified (see the header); `fields` all of them
    public static function issueOf(array $raw, \util\Jira $j) {
	$f = (array)($raw['fields'] ?? []);
	return [
	    'key' => $raw['key'] ?? null,
	    'id' => $raw['id'] ?? null,
	    'url' => isset($raw['key']) ? $j->browseUrl($raw['key']) : null,
	    'summary' => $f['summary'] ?? null,
	    'status' => $f['status']['name'] ?? null,
	    'statusCategory' => $f['status']['statusCategory']['key'] ?? null,
	    'issueType' => $f['issuetype']['name'] ?? null,
	    'priority' => $f['priority']['name'] ?? null,
	    'project' => $f['project']['key'] ?? null,
	    'assignee' => self::userOf($f['assignee'] ?? null),
	    'reporter' => self::userOf($f['reporter'] ?? null),
	    'labels' => array_values((array)($f['labels'] ?? [])),
	    'components' => array_values(array_map(fn($c) => (string)($c['name'] ?? ''), (array)($f['components'] ?? []))),
	    'created' => $f['created'] ?? null,
	    'updated' => $f['updated'] ?? null,
	    'duedate' => $f['duedate'] ?? null,
	    'resolution' => $f['resolution']['name'] ?? null,
	    'parent' => $f['parent']['key'] ?? null,
	    'description' => isset($f['description']) ? \util\Jira::plain($f['description']) : null,
	    'fields' => $f,
	];
    }

    //YYYY-MM-DD of a date or a relative date (+7 days); '' stays ''
    public static function date($v, $label = 'Due date') {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return '';
	$ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? strtotime("{$t} 00:00:00 UTC") : strtotime($t);
	if ($ts === false)
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($t, 0, 40) . '`');
	return gmdate('Y-m-d', $ts);
    }

    //labels: no spaces in one (Jira refuses them)
    public static function labels($value) {
	$labels = self::names($value, [ 'name', 'label' ]);
	foreach($labels as $l)
	    if (preg_match('/\s/', $l))
		throw new \RuntimeException("Labels: no spaces in a label: `{$l}`");
	return $labels;
    }

    //a user as the site takes it ({ accountId } / { name }); '-' / none: null (unassigned). An email or a name is looked up
    protected function userRef($text, $label = 'Assignee') {
	$t = trim((string)$text);
	if (in_array(strtolower($t), [ '-', 'none', 'unassigned' ], true))
	    return null;
	$cloud = $this->jira->isCloud();
	if ($cloud && preg_match('/^[0-9a-f]{24}$|^\d+:[0-9a-f-]{36}$|^[a-zA-Z0-9:_-]{20,128}$/', $t) && !str_contains($t, '@'))
	    return [ 'accountId' => $t ];
	$found = (array)$this->jira->request('GET', $this->jira->api() . '/user/search', $cloud ? [ 'query' => $t, 'maxResults' => 20 ] : [ 'username' => $t, 'maxResults' => 20 ]);
	$found = array_values(array_filter($found, fn($u) => ($u['accountType'] ?? 'atlassian') === 'atlassian' && ($u['active'] ?? true)));
	$exact = array_values(array_filter($found, fn($u) => strcasecmp((string)($u['emailAddress'] ?? ''), $t) === 0 || strcasecmp((string)($u['displayName'] ?? ''), $t) === 0
	    || strcasecmp((string)($u['name'] ?? ''), $t) === 0));
	$u = $exact[0] ?? (count($found) === 1 ? $found[0] : null);
	if (!$u)
	    throw new \RuntimeException("{$label}: " . ($found ? \util\Format::plural(count($found), 'user') . " match `{$t}`: give the email" : "no user `{$t}` (or the email is hidden: give the account ID)"));
	return $cloud ? [ 'accountId' => (string)$u['accountId'] ] : [ 'name' => (string)$u['name'] ];
    }

    //the fields of create / update that are not empty; Other fields merged
    protected function issueFields(\Closure $text, \Closure $value) {
	$f = [];
	if (($t = $text('summary')) !== '')
	    $f['summary'] = $t;
	$d = $value('description');
	if (is_array($d) || trim(\util\Expression::toText($d)) !== '')
	    $f['description'] = $this->jira->text($d);
	if (($t = $text('priority')) !== '')
	    $f['priority'] = [ 'name' => $t ];
	if (($t = $text('assignee')) !== '')
	    $f['assignee'] = $this->userRef($t);
	if (($l = self::labels($value('labels'))) !== [])
	    $f['labels'] = $l;
	if (($c = self::names($text('components'))) !== [])
	    $f['components'] = array_map(fn($n) => [ 'name' => $n ], $c);
	if (($t = self::date($text('duedate'))) !== '')
	    $f['duedate'] = $t;
	$raw = trim((string)$this->cfg('fields', ''));
	if ($raw !== '') {
	    $more = $this->settingValue($raw, $this->settingVars);
	    if (is_string($more))
		$more = json_decode($more, true);
	    if (!is_array($more) || ($more && array_is_list($more)))
		throw new \RuntimeException('Other fields: not a JSON object');
	    $f = array_replace($f, $more);
	}
	return $f;
    }

    protected function getIssue($key, $fields = '*all') {
	return self::issueOf((array)$this->jira->request('GET', $this->jira->api() . '/issue/' . rawurlencode($key), [ 'fields' => $fields ]), $this->jira);
    }

    protected function searchFields($text) {
	return array_values(array_unique(array_merge(self::FIELDS, self::names($text('extraFields')))));
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$J = $this->jira;
	$api = $J->api();
	switch($operation) {
	    case 'search':
		$jql = $text('jql');
		if ($jql === '')
		    throw new \RuntimeException('JQL: empty');
		[ $raw, $truncated ] = $J->search($jql, $this->searchFields($text), $this->maxItems());
		$items = array_map(fn($i) => self::issueOf((array)$i, $J), $raw);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'issue') . ($truncated ? ' (more left out)' : '') ];
	    case 'create':
		$f = $this->issueFields($text, $value);
		if (trim((string)($f['summary'] ?? '')) === '')
		    throw new \RuntimeException('Summary: required to create an issue');
		$type = $text('issueType') ?: 'Task';
		$f = [ 'project' => [ 'key' => strtoupper($text('project')) ], 'issuetype' => preg_match('/^\d+$/', $type) ? [ 'id' => $type ] : [ 'name' => $type ] ] + $f;
		if (($p = $text('parent')) !== '')
		    $f['parent'] = [ 'key' => \util\Jira::issueKey($p, 'Parent') ];
		$made = (array)$J->request('POST', "{$api}/issue", [], [ 'fields' => $f ]);
		$issue = $this->getIssue((string)($made['key'] ?? $made['id'] ?? ''));
		return [ $issue, "{$issue['key']} created: {$issue['summary']}" ];
	}
	$key = \util\Jira::issueKey($value('issue'));
	$path = "{$api}/issue/" . rawurlencode($key);
	switch($operation) {
	    case 'get':
		$i = $this->getIssue($key);
		return [ $i, "{$i['key']}: {$i['status']}, {$i['summary']}" ];
	    case 'update':
		$f = $this->issueFields($text, $value);
		if (!$f)
		    throw new \RuntimeException('nothing to change');
		$J->request('PUT', $path, [], [ 'fields' => $f ]);
		return [ $this->getIssue($key), "{$key} updated: " . implode(', ', array_keys($f)) ];
	    case 'addLabels':
	    case 'removeLabels':
		$labels = self::labels($value('labels'));
		if (!$labels)
		    throw new \RuntimeException('Labels: empty');
		$verb = $operation === 'addLabels' ? 'add' : 'remove';
		$J->request('PUT', $path, [], [ 'update' => [ 'labels' => array_map(fn($l) => [ $verb => $l ], $labels) ] ]);
		return [ [ 'key' => $key, 'labels' => $labels ], \util\Format::plural(count($labels), 'label') . ($verb === 'add' ? " added to {$key}" : " removed from {$key}") ];
	    case 'assign':
		$who = $this->userRef($text('assignee'));
		$J->request('PUT', "{$path}/assignee", [], $who ?? ($J->isCloud() ? [ 'accountId' => null ] : [ 'name' => null ]));
		return [ [ 'key' => $key, 'assignee' => $who ], $who ? "{$key} assigned to " . ($who['accountId'] ?? $who['name']) : "{$key} unassigned" ];
	    case 'transitions':
		$list = array_map(fn($t) => [ 'id' => (string)$t['id'], 'name' => (string)($t['name'] ?? ''), 'to' => (string)($t['to']['name'] ?? '') ],
		    (array)($J->request('GET', "{$path}/transitions")['transitions'] ?? []));
		return $this->listResult($list, 'transition');
	    case 'transition':
		return $this->transition($key, $path, $text, $value);
	    case 'comment':
		$c = (array)$J->request('POST', "{$path}/comment", [], [ 'body' => $J->text($value('comment')) ]);
		return [ [ 'id' => $c['id'] ?? null, 'key' => $key, 'author' => self::userOf($c['author'] ?? null), 'body' => \util\Jira::plain($c['body'] ?? ''), 'created' => $c['created'] ?? null ],
		    "comment added to {$key}" ];
	    case 'comments':
		$d = (array)$J->request('GET', "{$path}/comment", [ 'orderBy' => '-created', 'maxResults' => min(100, $this->maxItems()) ]);
		$list = array_map(fn($c) => [ 'id' => $c['id'] ?? null, 'author' => self::userOf($c['author'] ?? null), 'body' => \util\Jira::plain($c['body'] ?? ''), 'created' => $c['created'] ?? null,
		    'updated' => $c['updated'] ?? null ], (array)($d['comments'] ?? []));
		return $this->listResult($list, 'comment');
	    case 'link':
		$other = \util\Jira::issueKey($value('other'), 'Other issue');
		$type = $text('linkType') ?: 'Relates';
		$J->request('POST', "{$api}/issueLink", [], [ 'type' => [ 'name' => $type ], 'outwardIssue' => [ 'key' => $key ], 'inwardIssue' => [ 'key' => $other ] ]);
		return [ [ 'key' => $key, 'type' => $type, 'other' => $other ], "{$key} → {$other} ({$type})" ];
	    case 'attach':
		return $this->attach($key, $path, $text);
	    case 'delete':
		$J->request('DELETE', $path, [ 'deleteSubtasks' => $this->cfg('deleteSubtasks', false) ? 'true' : null ]);
		return [ [ 'key' => $key, 'deleted' => true ], "{$key} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the transition named $to (or leading to the status $to); the issue already in that status: nothing done
    protected function transition($key, $path, \Closure $text, \Closure $value) {
	$J = $this->jira;
	$to = $text('to');
	if ($to === '')
	    throw new \RuntimeException('To: empty');
	$now = (string)($J->request('GET', $path, [ 'fields' => 'status' ])['fields']['status']['name'] ?? '');
	if (strcasecmp($now, $to) === 0)
	    return [ [ 'key' => $key, 'status' => $now, 'changed' => false ], "{$key} is {$now} already" ];
	$all = (array)($J->request('GET', "{$path}/transitions")['transitions'] ?? []);
	$pick = null;
	foreach($all as $t)
	    if (strcasecmp((string)($t['name'] ?? ''), $to) === 0)
		$pick = $t;
	foreach($pick ? [] : $all as $t)
	    if (!$pick && strcasecmp((string)($t['to']['name'] ?? ''), $to) === 0)
		$pick = $t;
	if (!$pick)
	    throw new \RuntimeException("no transition to `{$to}` from {$now} (it has: " . (implode(', ', array_map(fn($t) => "{$t['name']} → " . ($t['to']['name'] ?? '?'), $all)) ?: 'none') . ')');
	$body = [ 'transition' => [ 'id' => (string)$pick['id'] ] ];
	if (($r = $text('resolution')) !== '')
	    $body['fields'] = [ 'resolution' => [ 'name' => $r ] ];
	$c = $value('comment');
	if (is_array($c) || trim(\util\Expression::toText($c)) !== '')
	    $body['update'] = [ 'comment' => [ [ 'add' => [ 'body' => $J->text($c) ] ] ] ];
	$J->request('POST', "{$path}/transitions", [], $body);
	$status = (string)($pick['to']['name'] ?? $to);
	return [ [ 'key' => $key, 'status' => $status, 'from' => $now, 'transition' => (string)$pick['name'], 'changed' => true ], "{$key}: {$now} → {$status}" ];
    }

    //a project file attached (multipart, X-Atlassian-Token: no-check): the attachment { id, filename, size, mimeType, content }
    protected function attach($key, $path, \Closure $text) {
	$fileId = $text('sourceFile');
	$file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	$max = max(1, min(self::MAX_ATTACH_MB, (int)$this->cfg('maxSize', 10))) * 1024 * 1024;
	if ((int)$file->length > $max)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . ($max >> 20) . ' MB');
	$boundary = 'r22-' . bin2hex(random_bytes(12));
	$name = str_replace([ '"', "\r", "\n" ], '_', (string)$file->filename);
	$body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n"
	    . $file->getContents() . "\r\n--{$boundary}--\r\n";
	$list = (array)$this->jira->request('POST', "{$path}/attachments", [], null, [ 'X-Atlassian-Token' => 'no-check' ], [ $body, "multipart/form-data; boundary={$boundary}" ]);
	$a = (array)($list[0] ?? []);
	return [ array_intersect_key($a, array_flip([ 'id', 'filename', 'size', 'mimeType', 'content', 'created' ])), "`{$name}` attached to {$key}" ];
    }
}
