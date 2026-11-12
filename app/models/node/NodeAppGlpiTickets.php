<?php

/*
    CLD: 2026-11-12T21:00:00+02:00: GLPI Tickets - the helpdesk's tickets. Operations (setting `operation`):
	search     the tickets the search engine finds: a status (Not solved, Not closed, New, …), a text in their titles, and GLPI's own criteria; the newest updated first
	get        a ticket with its actors (requesters, assigned to, observers: users, groups, emails)
	create     a ticket: title, description, type, urgency, impact, priority, category, entity, requester (a user, or an email of no user: notified), assigned to (a user, a group);
	           other fields in Other fields
	update     what is not empty of those; Other fields merged
	status     its status changed (New, Processing, Pending, Solved, Closed), a follow-up added with it
	assign     a user and / or a group added as requester, assigned to or observer; Replace: the others of that role removed
	followup   a follow-up added (private or not); followups: its follow-ups, the newest first
	task       a task added (duration, to do / done / information, technician); tasks: its tasks, the newest first
	solve      a solution added (its type): GLPI marks the ticket Solved (or Closed, as the entity's settings say)
	attach     a project file added as a document of the ticket
	delete     to the trash, or purged
    A ticket: { id, url, name, content (plain text), status, statusId, type, urgency, impact, priority (texts; their numbers: urgencyId, …), category, entity, location, requestType,
    date (opened), date_mod, date_creation, solvedate, closedate, fields (all, as GLPI gives them: dropdowns by name) }. Texts sent as HTML (paragraphs; a text with tags as it is)
*/

namespace models\node;

class NodeAppGlpiTickets extends NodeAppGlpi {
    public static $name = 'glpi-tickets';
    public static $label = 'GLPI Tickets';
    public static $icon = 'bi-ticket-detailed';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'tickets';

    const OPERATIONS = [
	'search' => 'Search tickets',
	'get' => 'Get a ticket',
	'create' => 'Create a ticket',
	'update' => 'Update a ticket',
	'status' => 'Change the status',
	'assign' => 'Add an actor',
	'followup' => 'Add a follow-up',
	'followups' => 'List follow-ups',
	'task' => 'Add a task',
	'tasks' => 'List tasks',
	'solve' => 'Add a solution',
	'attach' => 'Attach a file',
	'delete' => 'Delete a ticket',
    ];
    const WRITES = [ 'create', 'update', 'status', 'assign', 'followup', 'task', 'solve', 'attach', 'delete' ];
    const LISTS = [ 'search', 'followups', 'tasks' ];
    const ONE = [ 'get', 'update', 'status', 'assign', 'followup', 'followups', 'task', 'tasks', 'solve', 'attach', 'delete' ];

    const STATUSES = [ 1 => 'New', 2 => 'Processing (assigned)', 3 => 'Processing (planned)', 4 => 'Pending', 5 => 'Solved', 6 => 'Closed' ];
    const STATUS_KEYS = [ 'new' => 1, 'assigned' => 2, 'planned' => 3, 'pending' => 4, 'solved' => 5, 'closed' => 6 ];
    //the search engine's status values (field 12)
    const STATUS_FILTERS = [ '' => 'Any', 'notold' => 'Not solved or closed', 'notclosed' => 'Not closed', 'process' => 'Processing', 'old' => 'Solved or closed',
	'1' => 'New', '2' => 'Processing (assigned)', '3' => 'Processing (planned)', '4' => 'Pending', '5' => 'Solved', '6' => 'Closed' ];
    const TYPES = [ 1 => 'Incident', 2 => 'Request' ];
    const LEVELS = [ 1 => 'Very low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very high', 6 => 'Major' ];
    const ROLES = [ 'requester' => 1, 'assigned' => 2, 'observer' => 3 ];
    const TASK_STATES = [ 'todo' => 1, 'done' => 2, 'info' => 0 ];
    //the search engine's fields of a ticket
    const F_NAME = 1;
    const F_ID = 2;
    const F_STATUS = 12;
    const F_DATE = 15;
    const F_DATE_MOD = 19;
    const ORDERS = [ 'updated' => 'The newest updated first', 'newest' => 'The newest first', 'oldest' => 'The oldest first' ];
    const MAX_ATTACH_MB = 100;

    protected static function operationFields() {
	$edit = [ 'create', 'update' ];
	$level = fn($max) => [ '' => '(GLPI\'s default / unchanged)' ] + array_map(fn($k) => self::LEVELS[$k], array_combine(range(1, $max), range(1, $max)));
	return [
	    self::field('statusFilter', 'Status', [ 'search' ], [ 'type' => 'select', 'default' => 'notold', 'choices' => self::STATUS_FILTERS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('text', 'Title contains', [ 'search' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('order', 'Order', [ 'search' ], [ 'type' => 'select', 'default' => 'updated', 'choices' => self::ORDERS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('criteria', 'Criteria', [ 'search' ], [ 'type' => 'textarea', 'help' => 'GLPI\'s search criteria, a JSON list added to those above, e.g. [{"field": 7, "searchtype": "equals", "value": 3}] '
		. '(the fields\' numbers: GLPI Setup, List search options)', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('ticket', 'Ticket', self::ONE, [ 'required' => true, 'help' => 'Its id, its address, or a ticket of a search or a trigger', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('name', 'Title', $edit, [ 'help' => 'Required to create; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('content', 'Description', $edit, [ 'type' => 'textarea', 'help' => 'Plain text (paragraphs at blank lines) or HTML; required to create', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('type', 'Type', $edit, [ 'type' => 'select', 'choices' => [ '' => '(GLPI\'s default / unchanged)' ] + self::TYPES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('urgency', 'Urgency', $edit, [ 'type' => 'select', 'choices' => $level(5), 'ui' => [ 'width' => 3 ] ]),
	    self::field('impact', 'Impact', $edit, [ 'type' => 'select', 'choices' => $level(5), 'ui' => [ 'width' => 3 ] ]),
	    self::field('priority', 'Priority', $edit, [ 'type' => 'select', 'choices' => [ '' => '(from urgency and impact / unchanged)' ] + self::LEVELS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('category', 'Category', $edit, [ 'help' => 'Its id or complete name, e.g. Hardware > Printers', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('entity', 'Entity', [ 'create' ], [ 'help' => 'Its id or complete name; empty: the session\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('requester', 'Requester', [ 'create' ], [ 'help' => 'A login, an email or a user id; an email of no user is notified. Empty: GLPI\'s default (often the API user)',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('user', 'Assigned to', [ 'create', 'assign' ], [ 'help' => 'A login, an email or a user id. Add an actor: the user (or a group) to add', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('group', 'Group', [ 'create', 'assign' ], [ 'help' => 'Assigned to: a group\'s id or complete name', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('fields', 'Other fields', $edit, [ 'type' => 'textarea', 'help' => 'A JSON object merged into the input, as GLPI names the fields, e.g. {"locations_id": 3, "requesttypes_id": 1}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('role', 'As', [ 'assign' ], [ 'type' => 'select', 'required' => true, 'default' => 'assigned', 'choices' => [ 'assigned' => 'Assigned to', 'requester' => 'Requester', 'observer' => 'Observer' ],
		'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	    self::field('replace', 'Replace the others', [ 'assign' ], [ 'type' => 'switch', 'default' => false, 'help' => 'The ticket\'s other users (groups) of that role removed', 'ui' => [ 'width' => 6 ] ]),
	    self::field('newStatus', 'New status', [ 'status' ], [ 'type' => 'select', 'required' => true, 'default' => 'assigned',
		'choices' => array_combine(array_keys(self::STATUS_KEYS), array_values(self::STATUSES)), 'ui' => [ 'width' => 6 ] ]),
	    self::field('message', 'Text', [ 'status', 'followup', 'task', 'solve' ], [ 'type' => 'textarea', 'help' => 'Plain text or HTML. Change the status: optional, a follow-up added with it',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'followup', 'task', 'solve' ] ], 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('private', 'Private', [ 'status', 'followup', 'task' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Seen by the technicians only', 'ui' => [ 'width' => 3 ] ]),
	    self::field('duration', 'Duration (minutes)', [ 'task' ], [ 'type' => 'number', 'default' => 0, 'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'from 0' ] ],
		'ui' => [ 'step' => 1, 'width' => 3 ] ]),
	    self::field('taskState', 'State', [ 'task' ], [ 'type' => 'select', 'default' => 'done', 'choices' => [ 'todo' => 'To do', 'done' => 'Done', 'info' => 'Information' ], 'ui' => [ 'width' => 3 ] ]),
	    self::field('technician', 'Technician', [ 'task' ], [ 'help' => 'A login, an email or a user id; empty: the API user', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('solutionType', 'Solution type', [ 'solve' ], [ 'help' => 'Its id or name; empty: none', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sourceFile', 'Project file', [ 'attach' ], [ 'required' => true, 'help' => 'The id of a file of the project, e.g. {{ file.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'attach' ], [ 'type' => 'number', 'required' => true, 'default' => 10,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ATTACH_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ATTACH_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	    self::field('purge', 'Purge', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Deleted for good; off: to the trash', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    //a ticket of an answer (expand_dropdowns), simplified (see the header); `fields` all of them
    public static function ticketOf(array $raw, \util\Glpi $g) {
	unset($raw['links']);
	$num = fn($k) => isset($raw[$k]) && is_numeric($raw[$k]) ? (int)$raw[$k] : null;
	$name = fn($k) => isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== 0 && $raw[$k] !== '0' ? self::decoded($raw[$k]) : null;
	return [
	    'id' => $num('id'),
	    'url' => isset($raw['id']) ? $g->formUrl('Ticket', $raw['id']) : null,
	    'name' => isset($raw['name']) ? self::decoded($raw['name']) : null,
	    'content' => isset($raw['content']) ? \util\Glpi::plain($raw['content']) : null,
	    'status' => self::STATUSES[$num('status')] ?? null,
	    'statusId' => $num('status'),
	    'type' => self::TYPES[$num('type')] ?? null,
	    'urgency' => self::LEVELS[$num('urgency')] ?? null,
	    'impact' => self::LEVELS[$num('impact')] ?? null,
	    'priority' => self::LEVELS[$num('priority')] ?? null,
	    'urgencyId' => $num('urgency'),
	    'impactId' => $num('impact'),
	    'priorityId' => $num('priority'),
	    'category' => $name('itilcategories_id'),
	    'entity' => $name('entities_id'),
	    'location' => $name('locations_id'),
	    'requestType' => $name('requesttypes_id'),
	    'date' => $raw['date'] ?? null,
	    'date_mod' => $raw['date_mod'] ?? null,
	    'date_creation' => $raw['date_creation'] ?? null,
	    'solvedate' => $raw['solvedate'] ?? null,
	    'closedate' => $raw['closedate'] ?? null,
	    'fields' => $raw,
	];
    }

    //the search criteria: a status (STATUS_FILTERS; '': all), a text in the titles, then GLPI's own (each without a link: AND)
    public static function criteria($status, $text, array $more) {
	$c = [];
	//any: said, or GLPI's default search (not solved or closed) applies
	$c[] = [ 'field' => self::F_STATUS, 'searchtype' => 'equals', 'value' => (string)$status !== '' && isset(self::STATUS_FILTERS[(string)$status]) ? (string)$status : 'all' ];
	if (trim((string)$text) !== '')
	    $c[] = [ 'link' => 'AND', 'field' => self::F_NAME, 'searchtype' => 'contains', 'value' => trim((string)$text) ];
	foreach($more as $m) {
	    if (!is_array($m) || array_is_list($m))
		throw new \RuntimeException('Criteria: a list of objects { field, searchtype, value }');
	    $c[] = isset($m['link']) ? $m : [ 'link' => 'AND' ] + $m;
	}
	return $c;
    }

    //a ticket's text (a follow-up, a task): { id, date, user, content (plain text), private, … }
    protected static function noteOf(array $n) {
	return [ 'id' => isset($n['id']) ? (int)$n['id'] : null, 'date' => $n['date'] ?? $n['date_creation'] ?? null, 'user' => isset($n['users_id']) ? self::decoded($n['users_id']) : null,
	    'content' => \util\Glpi::plain($n['content'] ?? ''), 'private' => (bool)($n['is_private'] ?? false) ];
    }

    protected function getTicket($id) {
	return self::ticketOf((array)$this->glpi->request('GET', "/Ticket/{$id}", [ 'expand_dropdowns' => 'true' ]), $this->glpi);
    }

    //its actors: { requesters, assigned, observers }, each [ { kind: user | group | email, name, email } ]
    protected function actors($id) {
	$out = [ 'requesters' => [], 'assigned' => [], 'observers' => [] ];
	$role = [ 1 => 'requesters', 2 => 'assigned', 3 => 'observers' ];
	foreach((array)$this->glpi->request('GET', "/Ticket/{$id}/Ticket_User", [ 'expand_dropdowns' => 'true' ]) as $a) {
	    $email = trim((string)($a['alternative_email'] ?? ''));
	    $user = in_array($a['users_id'] ?? 0, [ 0, '0', '', null ], true) ? null : self::decoded($a['users_id']);
	    if (isset($role[(int)($a['type'] ?? 0)]))
		$out[$role[(int)$a['type']]][] = array_filter([ 'kind' => $user !== null ? 'user' : 'email', 'name' => $user, 'email' => $email !== '' ? $email : null ], fn($v) => $v !== null);
	}
	foreach((array)$this->glpi->request('GET', "/Ticket/{$id}/Group_Ticket", [ 'expand_dropdowns' => 'true' ]) as $a)
	    if (isset($role[(int)($a['type'] ?? 0)]))
		$out[$role[(int)$a['type']]][] = [ 'kind' => 'group', 'name' => self::decoded($a['groups_id'] ?? '') ];
	return $out;
    }

    //the input of create / update: what is not empty; Other fields merged
    protected function ticketInput(\Closure $text, \Closure $value, $create) {
	$in = [];
	if (($t = $text('name')) !== '')
	    $in['name'] = $t;
	$c = $value('content');
	if (is_array($c) || trim(\util\Expression::toText($c)) !== '')
	    $in['content'] = \util\Glpi::html($c);
	foreach([ 'type', 'urgency', 'impact', 'priority' ] as $k)
	    if (($t = (string)$this->cfg($k, '')) !== '')
		$in[$k] = (int)$t;
	if (($id = $this->refId('ITILCategory', $value('category'), 'Category')) !== null)
	    $in['itilcategories_id'] = $id;
	if ($create) {
	    if (($id = $this->refId('Entity', $value('entity'), 'Entity')) !== null)
		$in['entities_id'] = $id;
	    $r = $this->userId($value('requester'), 'Requester', true);
	    if (is_int($r))
		$in['_users_id_requester'] = $r;
	    elseif (is_string($r))
		$in += [ '_users_id_requester' => 0, '_users_id_requester_notif' => [ 'use_notification' => [ 1 ], 'alternative_email' => [ $r ] ] ];
	    if (($u = $this->userId($value('user'), 'Assigned to')) !== null)
		$in['_users_id_assign'] = $u;
	    if (($gid = $this->refId('Group', $value('group'), 'Group')) !== null)
		$in['_groups_id_assign'] = $gid;
	}
	return array_replace($in, $this->jsonOf('fields', 'Other fields'));
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->glpi;
	switch($operation) {
	    case 'search':
		$order = (string)$this->cfg('order', 'updated');
		[ $rows, $total ] = $G->search('Ticket', self::criteria($this->cfg('statusFilter', 'notold'), $text('text'), $this->jsonOf('criteria', 'Criteria', true)), $this->maxItems(),
		    [ 'forcedisplay' => [ self::F_ID ], 'sort' => $order === 'updated' ? self::F_DATE_MOD : self::F_ID, 'order' => $order === 'oldest' ? 'ASC' : 'DESC' ]);
		$items = array_map(fn($t) => self::ticketOf($t, $G), $G->many('Ticket', array_column($rows, (string)self::F_ID), [ 'expand_dropdowns' => 'true' ]));
		$truncated = $total > count($rows);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'ticket') . ($truncated ? " of {$total}" : '') ];
	    case 'create':
		$in = $this->ticketInput($text, $value, true);
		if (trim((string)($in['name'] ?? '')) === '')
		    throw new \RuntimeException('Title: required to create a ticket');
		if (trim((string)($in['content'] ?? '')) === '')
		    throw new \RuntimeException('Description: required to create a ticket');
		$made = (array)$G->request('POST', '/Ticket', [], [ 'input' => $in ]);
		$t = $this->getTicket(\util\Glpi::itemId($made['id'] ?? ''));
		return [ $t, "#{$t['id']} created: {$t['name']}" ];
	}
	$id = \util\Glpi::itemId($value('ticket'));
	switch($operation) {
	    case 'get':
		$t = $this->getTicket($id);
		$t = array_slice($t, 0, -1, true) + $this->actors($id) + [ 'fields' => $t['fields'] ];
		return [ $t, "#{$id}: {$t['status']}, {$t['name']}" ];
	    case 'update':
		$in = $this->ticketInput($text, $value, false);
		if (!$in)
		    throw new \RuntimeException('nothing to change');
		$G->request('PUT', "/Ticket/{$id}", [], [ 'input' => $in ]);
		return [ $this->getTicket($id), "#{$id} updated: " . implode(', ', array_keys($in)) ];
	    case 'status':
		$to = self::STATUS_KEYS[(string)$this->cfg('newStatus', 'assigned')] ?? null;
		if ($to === null)
		    throw new \RuntimeException('New status: not one of the choices');
		$m = $value('message');
		if (is_array($m) || trim(\util\Expression::toText($m)) !== '')
		    $this->addNote('ITILFollowup', $id, $m);
		$G->request('PUT', "/Ticket/{$id}", [], [ 'input' => [ 'status' => $to ] ]);
		$t = $this->getTicket($id);
		return [ $t, "#{$id}: {$t['status']}" ];
	    case 'assign':
		return $this->addActor($id, $value);
	    case 'followup':
		$n = $this->addNote('ITILFollowup', $id, $value('message'));
		return [ $n, "follow-up added to #{$id}" ];
	    case 'task':
		$n = $this->addNote('TicketTask', $id, $value('message'));
		return [ $n, "task added to #{$id}" ];
	    case 'solve':
		$m = $value('message');
		if (!is_array($m) && trim(\util\Expression::toText($m)) === '')
		    throw new \RuntimeException('Text: the solution, empty');
		$in = [ 'itemtype' => 'Ticket', 'items_id' => $id, 'content' => \util\Glpi::html($m) ];
		if (($st = $this->refId('SolutionType', $value('solutionType'), 'Solution type')) !== null)
		    $in['solutiontypes_id'] = $st;
		$s = (array)$G->request('POST', '/ITILSolution', [], [ 'input' => $in ]);
		$t = $this->getTicket($id);
		return [ [ 'id' => isset($s['id']) ? (int)$s['id'] : null, 'ticket' => $id, 'status' => $t['status'] ], "#{$id} solved: {$t['status']}" ];
	    case 'followups':
	    case 'tasks':
		[ $all ] = $G->items("/Ticket/{$id}/" . ($operation === 'tasks' ? 'TicketTask' : 'ITILFollowup'), [ 'expand_dropdowns' => 'true' ], self::MAX_ITEMS);
		$list = array_map(fn($n) => self::noteOf($n) + ($operation === 'tasks' ? [ 'state' => array_search((int)($n['state'] ?? 0), self::TASK_STATES, true) ?: 'info',
		    'duration' => (int)round((int)($n['actiontime'] ?? 0) / 60), 'technician' => !in_array($n['users_id_tech'] ?? 0, [ 0, '0', '' ], true) ? self::decoded($n['users_id_tech']) : null ] : []), $all);
		usort($list, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']) ?: $b['id'] <=> $a['id']);
		return $this->listResult($list, $operation === 'tasks' ? 'task' : 'follow-up');
	    case 'attach':
		return $this->attach($id, $text);
	    case 'delete':
		$G->request('DELETE', "/Ticket/{$id}", [ 'force_purge' => $this->cfg('purge', false) ? 'true' : 'false' ]);
		return [ [ 'id' => $id, 'deleted' => true, 'purged' => (bool)$this->cfg('purge', false) ], "#{$id} " . ($this->cfg('purge', false) ? 'purged' : 'put in the trash') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a follow-up (ITILFollowup) or a task (TicketTask) added: { id, ticket, content, private, … }
    protected function addNote($itemtype, $id, $message) {
	if (!is_array($message) && trim(\util\Expression::toText($message)) === '')
	    throw new \RuntimeException('Text: empty');
	$private = (bool)$this->cfg('private', false);
	$in = [ 'content' => \util\Glpi::html($message), 'is_private' => $private ? 1 : 0 ];
	if ($itemtype === 'TicketTask') {
	    $state = self::TASK_STATES[(string)$this->cfg('taskState', 'done')] ?? 2;
	    $in += [ 'tickets_id' => $id, 'actiontime' => max(0, (int)$this->cfg('duration', 0)) * 60, 'state' => $state ];
	    if (($u = $this->userId($this->settingOf('technician'), 'Technician')) !== null)
		$in['users_id_tech'] = $u;
	} else
	    $in += [ 'itemtype' => 'Ticket', 'items_id' => $id ];
	$made = (array)$this->glpi->request('POST', "/{$itemtype}", [], [ 'input' => $in ]);
	return [ 'id' => isset($made['id']) ? (int)$made['id'] : null, 'ticket' => $id, 'content' => \util\Glpi::plain($in['content']), 'private' => $private ];
    }

    //a user and / or a group added as requester / assigned to / observer; Replace: the others of that role removed
    protected function addActor($id, \Closure $value) {
	$G = $this->glpi;
	$role = (string)$this->cfg('role', 'assigned');
	$type = self::ROLES[$role] ?? 2;
	$user = $this->userId($value('user'), 'Assigned to');
	$group = $this->refId('Group', $value('group'), 'Group');
	if ($user === null && $group === null)
	    throw new \RuntimeException('Assigned to / Group: give a user or a group');
	$done = [];
	foreach([ [ 'Ticket_User', 'users_id', $user, 'user' ], [ 'Group_Ticket', 'groups_id', $group, 'group' ] ] as [ $link, $key, $who, $what ]) {
	    if ($who === null)
		continue;
	    $have = false;
	    foreach((array)$G->request('GET', "/Ticket/{$id}/{$link}") as $a) {
		if ((int)($a['type'] ?? 0) !== $type)
		    continue;
		if ((int)($a[$key] ?? 0) === $who)
		    $have = true;
		elseif ($this->cfg('replace', false)) {
		    $G->request('DELETE', "/{$link}/" . (int)$a['id']);
		    $done[] = "{$what} " . (int)($a[$key] ?? 0) . ' removed';
		}
	    }
	    if (!$have) {
		$G->request('POST', "/{$link}", [], [ 'input' => [ 'tickets_id' => $id, $key => $who, 'type' => $type ] ]);
		$done[] = "{$what} {$who} added";
	    }
	}
	return [ [ 'id' => $id, 'role' => $role, 'user' => $user, 'group' => $group, 'changes' => $done ], "#{$id} {$role}: " . (implode(', ', $done) ?: 'already there') ];
    }

    //a project file added as a document of the ticket (multipart: uploadManifest + filename[0]): { id (the document's), name, filename, ticket }
    protected function attach($id, \Closure $text) {
	$fileId = $text('sourceFile');
	$file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	$max = max(1, min(self::MAX_ATTACH_MB, (int)$this->cfg('maxSize', 10))) * 1024 * 1024;
	if ((int)$file->length > $max)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . ($max >> 20) . ' MB');
	$name = str_replace([ '"', "\r", "\n" ], '_', (string)$file->filename);
	$manifest = json_encode([ 'input' => [ 'name' => $name, '_filename' => [ $name ], 'itemtype' => 'Ticket', 'items_id' => $id ] ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$boundary = 'r22-' . bin2hex(random_bytes(12));
	$body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"uploadManifest\"\r\nContent-Type: application/json\r\n\r\n{$manifest}\r\n"
	    . "--{$boundary}\r\nContent-Disposition: form-data; name=\"filename[0]\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n"
	    . $file->getContents() . "\r\n--{$boundary}--\r\n";
	$made = (array)$this->glpi->request('POST', '/Document', [], null, [ $body, "multipart/form-data; boundary={$boundary}" ]);
	return [ [ 'id' => isset($made['id']) ? (int)$made['id'] : null, 'name' => $name, 'size' => (int)$file->length, 'ticket' => $id ], "`{$name}` attached to #{$id}" ];
    }
}
