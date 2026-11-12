<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: Zendesk Tickets - the support tickets. Operations (setting `operation`):
	search       the tickets of a status and Zendesk's search (priority:urgent, requester:ana@x.com, tags:vip, …), the newest updated first (or by creation)
	get          a ticket, with its requester, assignee, group and organization named
	create       a ticket: subject, the first comment (its description), requester (an email: Zendesk adds an end user when it has none), priority, type, status, assignee,
	             group, tags, due date (tasks), custom fields ({ field id: value }); other fields in Other fields
	update       what is not empty of those (tags: replaced); Other fields merged
	comment      a comment added, public or internal (a note); comments: its comments, the newest first
	status       its status changed (new, open, pending, on hold, solved, closed), a comment with it
	assign       its assignee and / or group changed
	addTags / removeTags   tags added / removed (the others kept)
	attach       a project file added in a comment
	merge        other tickets merged into this one (Zendesk's job: done in a moment)
	delete       deleted (recoverable for 30 days in Zendesk)
    A ticket: { id, url (agent workspace), subject, description, status, priority, type, requesterId, assigneeId, groupId, organizationId, requester / assignee / group / organization
    (named: get), tags, channel, created, updated, due, externalId, customFields { id: value }, fields (all, as Zendesk gives them) }. Texts: plain text (a text with HTML tags: HTML)
*/

namespace models\node;

class NodeAppZendeskTickets extends NodeAppZendesk {
    public static $name = 'zendesk-tickets';
    public static $label = 'Zendesk Tickets';
    public static $icon = 'bi-life-preserver';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'tickets';

    const OPERATIONS = [
	'search' => 'Search tickets',
	'get' => 'Get a ticket',
	'create' => 'Create a ticket',
	'update' => 'Update a ticket',
	'comment' => 'Add a comment',
	'comments' => 'List comments',
	'status' => 'Change the status',
	'assign' => 'Assign a ticket',
	'addTags' => 'Add tags',
	'removeTags' => 'Remove tags',
	'attach' => 'Attach a file',
	'merge' => 'Merge tickets',
	'delete' => 'Delete a ticket',
    ];
    const WRITES = [ 'create', 'update', 'comment', 'status', 'assign', 'addTags', 'removeTags', 'attach', 'merge', 'delete' ];
    const LISTS = [ 'search', 'comments' ];
    const ONE = [ 'get', 'update', 'comment', 'comments', 'status', 'assign', 'addTags', 'removeTags', 'attach', 'merge', 'delete' ];
    const STATUSES = [ 'new' => 'New', 'open' => 'Open', 'pending' => 'Pending', 'hold' => 'On hold', 'solved' => 'Solved', 'closed' => 'Closed' ];
    const STATUS_FILTERS = [ '' => 'Any', 'unsolved' => 'Not solved (new, open, pending, on hold)', 'new' => 'New', 'open' => 'Open', 'pending' => 'Pending', 'hold' => 'On hold',
	'solved' => 'Solved', 'closed' => 'Closed' ];
    const PRIORITIES = [ 'urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low' ];
    const TYPES = [ 'question' => 'Question', 'incident' => 'Incident', 'problem' => 'Problem', 'task' => 'Task' ];
    const ORDERS = [ 'updated' => 'The newest updated first', 'newest' => 'The newest first', 'oldest' => 'The oldest first' ];
    const MAX_ATTACH_MB = 50;

    protected static function operationFields() {
	$edit = [ 'create', 'update' ];
	$keep = [ '' => '(unchanged / Zendesk\'s default)' ];
	return [
	    self::field('statusFilter', 'Status', [ 'search' ], [ 'type' => 'select', 'default' => 'unsolved', 'choices' => self::STATUS_FILTERS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('order', 'Order', [ 'search' ], [ 'type' => 'select', 'default' => 'updated', 'choices' => self::ORDERS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('query', 'Search', [ 'search' ], [ 'help' => 'Zendesk\'s search, added to the status, e.g. priority:urgent tags:vip requester:ana@example.com "printer"',
		'ui' => [ 'expression' => 'text', 'code' => true ] ]),
	    self::field('ticket', 'Ticket', self::ONE, [ 'required' => true, 'help' => 'Its id, its address, or a ticket of a search or a trigger', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('subject', 'Subject', $edit, [ 'help' => 'Required to create; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'The first comment: plain text, or HTML', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('requester', 'Requester', [ 'create' ], [ 'help' => 'An email (a new end user when Zendesk has none), a user id, or a name; empty: the credential\'s agent',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('requesterName', 'Requester\'s name', [ 'create' ], [ 'help' => 'For a new end user', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('priority', 'Priority', $edit, [ 'type' => 'select', 'choices' => $keep + self::PRIORITIES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('type', 'Type', $edit, [ 'type' => 'select', 'choices' => $keep + self::TYPES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('status', 'Status', $edit, [ 'type' => 'select', 'choices' => $keep + self::STATUSES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('assignee', 'Assignee', array_merge($edit, [ 'assign' ]), [ 'help' => 'An agent\'s email, id or name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('group', 'Group', array_merge($edit, [ 'assign' ]), [ 'help' => 'Its name or id', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('tags', 'Tags', array_merge($edit, [ 'addTags', 'removeTags' ]), [ 'help' => 'Separated by commas or spaces, or a list; update: replaces them all',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('due', 'Due date', $edit, [ 'help' => 'A task\'s: a date (2026-12-31) or relative (+3 days)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('customFields', 'Custom fields', $edit, [ 'type' => 'textarea', 'help' => 'A JSON object { field id: value }, e.g. {"360001234567": "gold"} (the ids: Zendesk Setup, List ticket fields)',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('fields', 'Other fields', $edit, [ 'type' => 'textarea', 'help' => 'A JSON object merged into the ticket, as Zendesk names the fields, e.g. {"external_id": "ord-12"}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('newStatus', 'New status', [ 'status' ], [ 'type' => 'select', 'required' => true, 'default' => 'solved', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('message', 'Comment', [ 'comment', 'status', 'attach', 'update' ], [ 'type' => 'textarea', 'help' => 'Plain text or HTML; change the status, attach, update: optional',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'comment' ] ], 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('public', 'Public', [ 'comment', 'status', 'attach', 'update' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Off: an internal note, not seen by the requester',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('sourceFile', 'Project file', [ 'attach' ], [ 'required' => true, 'help' => 'The id of a file of the project, e.g. {{ file.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'attach' ], [ 'type' => 'number', 'required' => true, 'default' => 10,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ATTACH_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ATTACH_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	    self::field('sources', 'Tickets to merge', [ 'merge' ], [ 'required' => true, 'help' => 'Their ids, separated by commas, or a list: merged into Ticket and closed', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('targetComment', 'Comment on this ticket', [ 'merge' ], [ 'help' => 'Empty: Zendesk\'s ("Requests #… were closed and merged into this request")', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    //a ticket of an answer, simplified (see the header); $side: the sideloaded users / groups / organizations by id
    public static function ticketOf(array $t, \util\Zendesk $z, array $side = []) {
	$custom = [];
	foreach((array)($t['custom_fields'] ?? []) as $f)
	    if (isset($f['id']))
		$custom[(string)$f['id']] = $f['value'] ?? null;
	$person = fn($id) => $id !== null && isset($side['users'][$id]) ? [ 'id' => (int)$id, 'name' => $side['users'][$id]['name'] ?? null, 'email' => $side['users'][$id]['email'] ?? null ] : null;
	$r = [
	    'id' => isset($t['id']) ? (int)$t['id'] : null,
	    'url' => isset($t['id']) ? $z->agentUrl('ticket', $t['id']) : null,
	    'subject' => $t['subject'] ?? null,
	    'description' => $t['description'] ?? null,
	    'status' => $t['status'] ?? null,
	    'priority' => $t['priority'] ?? null,
	    'type' => $t['type'] ?? null,
	    'requesterId' => $t['requester_id'] ?? null,
	    'assigneeId' => $t['assignee_id'] ?? null,
	    'groupId' => $t['group_id'] ?? null,
	    'organizationId' => $t['organization_id'] ?? null,
	    'tags' => array_values((array)($t['tags'] ?? [])),
	    'channel' => $t['via']['channel'] ?? null,
	    'created' => $t['created_at'] ?? null,
	    'updated' => $t['updated_at'] ?? null,
	    'due' => $t['due_at'] ?? null,
	    'externalId' => $t['external_id'] ?? null,
	    'customFields' => $custom,
	];
	if ($side)
	    $r += [ 'requester' => $person($t['requester_id'] ?? null), 'assignee' => $person($t['assignee_id'] ?? null),
		'group' => $side['groups'][$t['group_id'] ?? '']['name'] ?? null, 'organization' => $side['organizations'][$t['organization_id'] ?? '']['name'] ?? null ];
	return $r + [ 'fields' => $t ];
    }

    //a comment of an answer: { id, author, public, body (plain text), html, created, attachments }
    public static function commentOf(array $c) {
	return [ 'id' => $c['id'] ?? null, 'author' => $c['author_id'] ?? null, 'public' => (bool)($c['public'] ?? true), 'body' => $c['plain_body'] ?? $c['body'] ?? null,
	    'html' => $c['html_body'] ?? null, 'created' => $c['created_at'] ?? null,
	    'attachments' => array_map(fn($a) => [ 'id' => $a['id'] ?? null, 'name' => $a['file_name'] ?? null, 'size' => $a['size'] ?? null, 'url' => $a['content_url'] ?? null ], (array)($c['attachments'] ?? [])) ];
    }

    //a comment of a setting: { body | html_body, public }, or null when empty
    protected function commentInput($value) {
	$t = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : \util\Expression::toText($value);
	if (trim($t) === '')
	    return null;
	$html = (bool)preg_match('~</?(p|div|br|ul|ol|li|table|strong|b|i|em|a|h[1-6]|span|pre|code|img)\b[^>]*>~i', $t);
	return [ $html ? 'html_body' : 'body' => $t, 'public' => (bool)$this->cfg('public', true) ];
    }

    //YYYY-MM-DD of a date or a relative date (+3 days); '' stays ''
    public static function date($v, $label = 'Due date') {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return '';
	$ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? strtotime("{$t} 00:00:00 UTC") : strtotime($t);
	if ($ts === false)
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($t, 0, 40) . '`');
	return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    //the ticket of create / update: what is not empty; custom fields; Other fields merged
    protected function ticketInput(\Closure $text, \Closure $value, $create) {
	$in = [];
	if (($t = $text('subject')) !== '')
	    $in['subject'] = $t;
	foreach([ 'priority' => self::PRIORITIES, 'type' => self::TYPES, 'status' => self::STATUSES ] as $k => $choices)
	    if (isset($choices[$v = (string)$this->cfg($k, '')]))
		$in[$k] = $v;
	if (($id = $this->userId($value('assignee'), 'Assignee')) !== null)
	    $in['assignee_id'] = $id;
	if (($id = $this->groupId($value('group'))) !== null)
	    $in['group_id'] = $id;
	if (($tags = self::tags($value('tags'))) !== [])
	    $in['tags'] = $tags;
	if (($d = self::date($text('due'))) !== '')
	    $in['due_at'] = $d;
	$custom = $this->jsonOf('customFields', 'Custom fields');
	if ($custom)
	    $in['custom_fields'] = array_map(fn($id, $v) => [ 'id' => is_numeric($id) ? (int)$id : $id, 'value' => $v ], array_keys($custom), array_values($custom));
	if ($create) {
	    $r = $text('requester');
	    if (str_contains($r, '@'))
		$in['requester'] = array_filter([ 'email' => $r, 'name' => $text('requesterName') ?: null ]);
	    elseif ($r !== '')
		$in['requester_id'] = $this->userId($r, 'Requester');
	    $c = $this->commentInput($value('description'));
	    if ($c === null)
		throw new \RuntimeException('Description: required to create a ticket');
	    $in['comment'] = $c;
	} elseif (($c = $this->commentInput($value('message'))) !== null)
	    $in['comment'] = $c;
	return array_replace($in, $this->jsonOf('fields', 'Other fields'));
    }

    protected function getTicket($id) {
	$d = $this->zendesk->request('GET', "/tickets/{$id}", [ 'include' => 'users,groups,organizations' ]);
	$side = [];
	foreach([ 'users', 'groups', 'organizations' ] as $k)
	    foreach((array)($d[$k] ?? []) as $x)
		$side[$k][$x['id']] = $x;
	return self::ticketOf((array)($d['ticket'] ?? []), $this->zendesk, $side + [ 'users' => [] ]);
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$Z = $this->zendesk;
	switch($operation) {
	    case 'search':
		$status = (string)$this->cfg('statusFilter', 'unsolved');
		$q = trim('type:ticket ' . ($status === 'unsolved' ? 'status<solved ' : (isset(self::STATUSES[$status]) ? "status:{$status} " : '')) . $text('query'));
		$order = (string)$this->cfg('order', 'updated');
		[ $raw, $count, $truncated ] = $Z->search($q, $this->maxItems(), [ 'sort_by' => $order === 'updated' ? 'updated_at' : 'created_at', 'sort_order' => $order === 'oldest' ? 'asc' : 'desc' ]);
		$items = array_map(fn($t) => self::ticketOf((array)$t, $Z), array_values(array_filter($raw, fn($r) => ($r['result_type'] ?? 'ticket') === 'ticket')));
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'ticket') . ($truncated ? " of {$count}" : '') ];
	    case 'create':
		$in = $this->ticketInput($text, $value, true);
		if (trim((string)($in['subject'] ?? '')) === '')
		    throw new \RuntimeException('Subject: required to create a ticket');
		$made = (array)($Z->request('POST', '/tickets', [], [ 'ticket' => $in ])['ticket'] ?? []);
		$t = $this->getTicket(\util\Zendesk::ticketId($made));
		return [ $t, "#{$t['id']} created: {$t['subject']}" ];
	}
	$id = \util\Zendesk::ticketId($value('ticket'));
	switch($operation) {
	    case 'get':
		$t = $this->getTicket($id);
		return [ $t, "#{$id}: {$t['status']}, {$t['subject']}" ];
	    case 'update':
		$in = $this->ticketInput($text, $value, false);
		if (!$in)
		    throw new \RuntimeException('nothing to change');
		$Z->request('PUT', "/tickets/{$id}", [], [ 'ticket' => $in ]);
		return [ $this->getTicket($id), "#{$id} updated: " . implode(', ', array_keys($in)) ];
	    case 'comment':
		$c = $this->commentInput($value('message'));
		if ($c === null)
		    throw new \RuntimeException('Comment: empty');
		$Z->request('PUT', "/tickets/{$id}", [], [ 'ticket' => [ 'comment' => $c ] ]);
		return [ [ 'id' => $id, 'public' => $c['public'], 'comment' => $c['body'] ?? $c['html_body'] ], ($c['public'] ? 'public comment' : 'internal note') . " added to #{$id}" ];
	    case 'comments':
		[ $all ] = $Z->pages("/tickets/{$id}/comments", 'comments', [ 'sort' => '-created_at' ], self::MAX_ITEMS);
		return $this->listResult(array_map(fn($c) => self::commentOf((array)$c), $all), 'comment');
	    case 'status':
		$s = (string)$this->cfg('newStatus', 'solved');
		if (!isset(self::STATUSES[$s]))
		    throw new \RuntimeException('New status: not one of the choices');
		$in = [ 'status' => $s ];
		if (($c = $this->commentInput($value('message'))) !== null)
		    $in['comment'] = $c;
		$Z->request('PUT', "/tickets/{$id}", [], [ 'ticket' => $in ]);
		return [ [ 'id' => $id, 'status' => $s ], "#{$id}: {$s}" ];
	    case 'assign':
		$in = [];
		if (($u = $this->userId($value('assignee'), 'Assignee')) !== null)
		    $in['assignee_id'] = $u;
		if (($g = $this->groupId($value('group'))) !== null)
		    $in['group_id'] = $g;
		if (!$in)
		    throw new \RuntimeException('Assignee / Group: give one');
		$t = self::ticketOf((array)($Z->request('PUT', "/tickets/{$id}", [], [ 'ticket' => $in ])['ticket'] ?? []), $Z);
		return [ $t, "#{$id} assigned: " . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($in), $in)) ];
	    case 'addTags':
	    case 'removeTags':
		$tags = self::tags($value('tags'));
		if (!$tags)
		    throw new \RuntimeException('Tags: empty');
		$now = (array)($Z->request($operation === 'addTags' ? 'PUT' : 'DELETE', "/tickets/{$id}/tags", [], [ 'tags' => $tags ])['tags'] ?? []);
		return [ [ 'id' => $id, 'tags' => array_values($now) ], \util\Format::plural(count($tags), 'tag') . ($operation === 'addTags' ? " added to #{$id}" : " removed from #{$id}") ];
	    case 'attach':
		return $this->attach($id, $text, $value);
	    case 'merge':
		$sources = array_values(array_unique(array_map(fn($s) => \util\Zendesk::ticketId($s, 'Tickets to merge'),
		    is_array($v = $value('sources')) && !isset($v['id']) ? $v : preg_split('/[\s,]+/', trim(is_array($v) ? (string)$v['id'] : \util\Expression::toText($v)), -1, PREG_SPLIT_NO_EMPTY))));
		$sources = array_values(array_diff($sources, [ $id ]));
		if (!$sources)
		    throw new \RuntimeException('Tickets to merge: none (other than this one)');
		$job = (array)($Z->request('POST', "/tickets/{$id}/merge", [], array_filter([ 'ids' => $sources, 'target_comment' => $text('targetComment') ?: null ]))['job_status'] ?? []);
		return [ [ 'id' => $id, 'merged' => $sources, 'job' => $job['id'] ?? null, 'jobStatus' => $job['status'] ?? null ], \util\Format::plural(count($sources), 'ticket') . " merged into #{$id}" ];
	    case 'delete':
		$Z->request('DELETE', "/tickets/{$id}");
		return [ [ 'id' => $id, 'deleted' => true ], "#{$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a project file uploaded (uploads.json) and added in a comment: { id, name, size, attachment }
    protected function attach($id, \Closure $text, \Closure $value) {
	$fileId = $text('sourceFile');
	$file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	$max = max(1, min(self::MAX_ATTACH_MB, (int)$this->cfg('maxSize', 10))) * 1024 * 1024;
	if ((int)$file->length > $max)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . ($max >> 20) . ' MB');
	$name = (string)$file->filename;
	$up = (array)($this->zendesk->request('POST', '/uploads', [ 'filename' => $name ], null, [ $file->getContents(), 'application/binary' ])['upload'] ?? []);
	if (empty($up['token']))
	    throw new \RuntimeException('Zendesk gave no upload token');
	$c = $this->commentInput($value('message')) ?? [ 'body' => "Attached: {$name}", 'public' => (bool)$this->cfg('public', true) ];
	$this->zendesk->request('PUT', "/tickets/{$id}", [], [ 'ticket' => [ 'comment' => $c + [ 'uploads' => [ $up['token'] ] ] ] ]);
	$a = (array)($up['attachment'] ?? []);
	return [ [ 'id' => $id, 'name' => $name, 'size' => (int)$file->length, 'attachment' => $a['id'] ?? null, 'url' => $a['content_url'] ?? null ], "`{$name}` attached to #{$id}" ];
    }
}
