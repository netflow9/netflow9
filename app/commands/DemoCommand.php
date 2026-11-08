<?php

namespace commands;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/*
    a demo account with realistic data, for a demo site
	../v demo::create [--name="Northwind Supplies"] [--password=<the users' password>] [--ai-from=<account id>]
	../v demo::delete --account=<id>

    create: the account (marked `demo`), its roles and five users (nw.maria: owner and approver; nw.daniel, nw.aisha, nw.tom, nw.elena: members), with the password given
	(asked, hidden, when not: at least 12 characters); the project "Finance" with
	- the data table `expenses` (employee, amount, category, reason, status, decided by, comment) and ten earlier requests, two saved views;
	- the workflow "Expense approval" (the docs' guide: Form -> Data Table insert -> Human task (3 days) -> Data Table update -> If -> emails; a reminder on the
	  time limit), published as v1, v2, v3 with notes; five runs started by its form as the members' requests, spread over the last two days: four decided by
	  Maria, one waiting at the approval (in her My tasks);
	- three more workflows, a manual trigger only (names in the sidebar);
	- with --ai-from (an account of this server): its Assistant and embedding settings copied (the keys stay encrypted with this server's key) and the knowledge
	  base "Expense policy" (five policy texts) indexed; without it there is no knowledge base (it needs an embedding model).
	The runs are run here, not queued (a running daemon would take them); their Send email steps queue their emails as always (4): a running daemon takes
	them at once and they fail (the addresses are @northwind.example: reserved, never delivered); those still waiting at the end are removed.
	Prints the account id and the users.
    delete: an account made by demo::create (its `demo` mark), with everything of its id (its data tables' collections too)
*/

class DemoCommand extends \sys\BaseController {

    const PEOPLE = [
	'maria' => [ 'Maria', 'Lopez', 'owner' ],
	'daniel' => [ 'Daniel', 'Kim', 'member' ],
	'aisha' => [ 'Aisha', 'Patel', 'member' ],
	'tom' => [ 'Tom', 'Becker', 'member' ],
	'elena' => [ 'Elena', 'Rossi', 'member' ],
    ];

    //earlier requests: who, amount, category, reason, status, comment, days ago (all decided by Maria)
    const HISTORY = [
	[ 'daniel', 1240, 'Travel', 'Flights and hotel, customer workshop in Lyon', 'approved', 'Booked within the travel policy.', 26 ],
	[ 'aisha', 89.5, 'Meals', 'Team lunch after the quarterly close', 'approved', '', 24 ],
	[ 'tom', 2350, 'Equipment', 'Laptop replacement (battery failure)', 'approved', 'IT confirmed the failure.', 21 ],
	[ 'elena', 420, 'Training', 'Online course: advanced Excel modelling', 'approved', '', 19 ],
	[ 'daniel', 76.2, 'Meals', 'Client dinner, Hamburg', 'rejected', 'Over the per-person limit: resubmit with the attendees.', 17 ],
	[ 'aisha', 199, 'Software', 'Diagram tool, yearly licence', 'approved', '', 14 ],
	[ 'tom', 610, 'Travel', 'Train tickets, supplier audit in Milan', 'approved', '', 12 ],
	[ 'elena', 1890, 'Equipment', 'Second monitor and docking station', 'rejected', 'Use the spare stock in the office first.', 9 ],
	[ 'daniel', 345, 'Training', 'Conference ticket: FinOps Summit', 'approved', '', 7 ],
	[ 'aisha', 58.4, 'Meals', 'Working lunch with the auditors', 'approved', '', 6 ],
    ];

    //the runs: who, amount, category, reason, Maria's decision (null: still waiting), comment, hours ago
    const RUNS = [
	[ 'tom', 135.8, 'Meals', 'Dinner with the logistics partner', 'approved', '', 52 ],
	[ 'elena', 980, 'Travel', 'Hotel for the trade fair in Frankfurt (3 nights)', 'approved', 'Fine: within the fair budget.', 29 ],
	[ 'daniel', 2400, 'Equipment', 'Ergonomic chair and standing desk', 'rejected', 'Please order through facilities: they have a framework contract.', 26 ],
	[ 'aisha', 129, 'Software', 'PDF editor licence', 'approved', '', 5 ],
	[ 'tom', 460, 'Training', 'Forklift safety certificate renewal', null, null, 1.5 ],
    ];

    const CATEGORIES = [ 'Travel', 'Meals', 'Software', 'Equipment', 'Training' ];

    const POLICY = [
	'Travel' => "Book trips through the travel desk at least 14 days before departure when you can. Economy class is the rule for flights. Business class may be booked for flights longer than 6 hours, or when a customer pays for the trip. Trains are preferred to flights for journeys under 4 hours.\n\nHotels: up to 160 EUR a night in capital cities, 120 EUR elsewhere. Trade fairs and conferences may exceed this with your manager's approval, written in the request.\n\nTaxis are reimbursed between the airport or station and the hotel, and late at night after 22:00. Use public transport otherwise. Car rental needs a reason: several sites in one day, or no public transport.",
	'Meals and entertainment' => "While travelling, meals are reimbursed up to 55 EUR a day, with receipts. Breakfast included in the hotel price is not claimed again.\n\nCustomer and supplier meals: up to 70 EUR per person, alcohol included. List the attendees and their companies in the request; a request without them is sent back.\n\nTeam lunches and celebrations are paid from the team budget: ask your manager before booking. Tips up to 10% are accepted.",
	'Equipment and software' => "Laptops, phones and monitors are ordered by IT from the framework contract; personal purchases are not reimbursed, except in an emergency approved by IT (for example a broken laptop during a customer visit).\n\nOffice furniture, including chairs and standing desks, is ordered through facilities, who check the ergonomic needs with you.\n\nSoftware licences up to 300 EUR a year may be bought by the employee with their manager's approval. Larger licences go through procurement. Cancel monthly subscriptions you no longer use.",
	'Training' => "Each employee has a training budget of 1,200 EUR a year. Courses, certificates and conference tickets come from this budget. Mandatory certificates (safety, forklift, first aid) are paid by the company and do not count towards it.\n\nAsk for approval before you register. Keep the invoice and the certificate of attendance: both are attached to the request.",
	'Reimbursement' => "Send each expense within 30 days with a photo of the receipt. Approved expenses are paid with the next salary. Expenses in another currency are converted at the rate of the card statement, or the ECB rate of the day when paid in cash.\n\nA request without a receipt is reimbursed up to 25 EUR, with a written explanation. Lost receipts above that amount need your manager's and finance's approval.",
    ];

    public function create($req = null, $resp = null) {
	$om = \V::app()->om;
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$name = trim((string)($req->params['name'] ?? '')) ?: 'Northwind Supplies';
	$password = (string)($req->params['password'] ?? '') ?: $this->askPassword();
	if (strlen($password) < 12)
	    $this->fail('the password needs at least 12 characters');
	$aiFrom = (string)($req->params['ai-from'] ?? '');
	if ($aiFrom !== '' && !\util\Validator::rule('oid', $aiFrom))
	    $this->fail('--ai-from: an account id');
	foreach(array_keys(self::PEOPLE) as $k) {
	    if ($om->count('User', [ 'username' => "nw.{$k}" ]))
		$this->fail("a user nw.{$k} exists already (a demo account is there: ../v demo::delete --account=<id>)");
	}

	//the account, its roles, the users, the project
	$account = \models\Account::create([ 'state' => 'active', 'name' => $name ]);
	if (!$account->validates() || !$account->save())
	    $this->fail('account: ' . json_encode($account->errors()));
	$db->selectCollection(\models\Account::_collection())->updateOne([ '_id' => $account->_id ], [ '$set' => [ 'demo' => true ] ]);
	\V::app()->var('rt.account', $account);
	$roles = $account->setupRoles();
	$u = [];
	$names = [];
	foreach(self::PEOPLE as $k => [ $first, $last, $role ]) {
	    $user = \models\User::create([ 'aid' => $account->_id, 'username' => "nw.{$k}", 'email' => "{$k}@northwind.example", 'firstname' => $first, 'lastname' => $last,
		'password' => \V::app()->security->generatePasswordHash($password), 'state' => 'active', 'role' => $roles[$role]->_id ]);
	    if (!$user->validates() || !$user->save())
		$this->fail("user nw.{$k}: " . json_encode($user->errors()));
	    $u[$k] = $user;
	    $names[$k] = "{$first} {$last}";
	}
	$members = [];
	foreach($u as $k => $user)
	    $members[(string)$user->_id] = [ 'role' => $roles[self::PEOPLE[$k][2]]->_id ];
	//CLD: 2026-11-01T12:30:00+02:00: "Finance"
	$project = $om->create('Project', [ 'aid' => $account->_id, 'name' => 'Finance', 'owner' => $u['maria']->_id, 'members' => $members ]);
	if (!$project->validates() || !$project->save())
	    $this->fail('project: ' . json_encode($project->errors()));
	//Maria opens it when she signs in (her personal project made now, as sys\Boot would)
	$om->create('Project', [ 'aid' => $account->_id, 'name' => 'Personal', 'owner' => $u['maria']->_id, 'personal' => true,
	    'members' => [ (string)$u['maria']->_id => [ 'role' => $roles['owner']->_id ] ] ])->save();
	$u['maria']->last_project = (string)$project->_id;
	$u['maria']->save();
	\V::app()->user->crtProject = (string)$project->_id;

	//the AI settings of another account of this server
	if ($aiFrom !== '') {
	    foreach([ \models\assistant\AssistantSettings::class, \models\kb\EmbeddingSettings::class ] as $class) {
		$coll = $db->selectCollection($class::_collection());
		$src = $coll->findOne([ 'aid' => new ObjectId($aiFrom) ], [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ]);
		if ($src) {
		    unset($src['_id']);
		    $src['aid'] = $account->_id;
		    $coll->insertOne($src);
		}
	    }
	}

	//the expenses table and the workflow, made as an import (util\Transfer: columns, rows, the workflow's table id)
	$base = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
	$token = bin2hex(random_bytes(16));
	$bundle = [ 'format' => \util\Transfer::FORMAT, 'version' => \util\Transfer::VERSION, 'exported_at' => date(DATE_ATOM), 'source' => [ 'url' => $base, 'project' => 'demo' ],
	    'secrets' => false, 'credentials' => [], 'refs' => [ 'credentials' => [], 'datatables' => [], 'workflows' => [] ],
	    'datatables' => [ [ 'id' => 'TABLE', 'iname' => 'data-table-demo', 'name' => 'expenses', 'columns' => self::columns(), 'rows' => self::historyRows($u) ] ],
	    'workflows' => [ [ 'id' => 'WF', 'name' => 'Expense approval', 'icon' => 'receipt::primary', 'nodes' => self::expenseNodes('TABLE', $token, $base, (string)$u['maria']->_id) ] ] ];
	$r = \util\Transfer::apply($bundle, $project, []);
	foreach($r['warnings'] as $w)
	    echo "warning: {$w}\n";
	$wf = $om->findOne('Workflow', [ 'aid' => $account->_id, 'project' => $project->_id, 'name' => 'Expense approval' ]);
	$dt = $om->findOne('DataTableDef', [ 'aid' => $account->_id, 'project' => $project->_id, 'name' => 'expenses' ]);
	if (!$wf || !$dt)
	    $this->fail('the table or the workflow was not imported: ' . json_encode($r));

	//three more workflows: names in the sidebar
	foreach([ [ 'Weekly pending expenses report', 'calendar-week::info' ], [ 'Supplier invoice intake', 'envelope-paper::success' ], [ 'New hire onboarding', 'person-plus::warning' ] ] as [ $wname, $icon ]) {
	    $om->create('Workflow', [ 'aid' => $account->_id, 'project' => $project->_id, 'name' => $wname, 'icon' => $icon,
		'nodes' => [ 'start' => self::node('start', 'trigger::manual', 'Start', 0, 0, []) ] ])->save();
	}

	//v1 .. v3, published by Maria (as WorkflowController::publish), dated 20, 11 and 3 days ago
	$version = null;
	foreach([ [ 'First version: form, manager approval, emails', 20 ], [ 'Record each request and decision in the expenses table', 11 ], [ 'Remind finance after 3 days without a decision', 3 ] ] as [ $note, $days ]) {
	    $version = $om->create('WfVersion', [ 'aid' => $account->_id, 'project' => $project->_id, 'wf' => $wf->_id, 'number' => $wf->nextVersionNumber(), 'name' => (string)$wf->name,
		'nodes' => $wf->nodes, 'note' => $note, 'from_number' => null, 'published_by' => $u['maria']->_id ]);
	    if (!$version->validates() || !$version->save())
		$this->fail('version: ' . json_encode($version->errors()));
	    $db->selectCollection(\models\wf\WfVersion::_collection())->updateOne([ '_id' => $version->_id ], [ '$set' => [ 'created_at' => self::daysAgo($days, 16, 20) ] ]);
	    $wf = $om->findById('Workflow', (string)$wf->_id);
	}
	$wf->version = $version->_id;
	$wf->status = \models\Workflow::PUBLISHED;
	if (!$wf->save())
	    $this->fail('publish: ' . json_encode($wf->errors()));

	//the runs, as FormController starts them, run here; Maria's decisions as TaskController records them, the run then run again
	foreach(self::RUNS as [ $who, $amount, $category, $reason, $decision, $comment, $hours ]) {
	    $person = [ 'id' => (string)$u[$who]->_id, 'name' => $names[$who], 'email' => (string)$u[$who]->email ];
	    $exe = \models\wf\WfExecution::startFor($wf->forVersion($version), 'form', [ 'form' => $token, 'user' => $person['id'], 'received_at' => new UTCDateTime(),
		'vars' => [ 'form' => [ 'amount' => $amount, 'category' => $category, 'reason' => $reason ], 'form_user' => $person ] ], 'form');
	    $exe->log('form', 'form sent by ' . $person['name']);
	    $exe->save();
	    $exe->run();
	    $task = $om->findOne('WfTask', [ 'execution' => $exe->_id ]);
	    if ($decision !== null && $task) {
		$om->updateMany('WfTask', [ '_id' => $task->_id ], [ '$set' => [ 'state' => 'done' ] ]);
		$task = $om->findById('WfTask', (string)$task->_id);
		$task->answers = [ 'decision' => $decision, 'comment' => $comment ];
		$task->completed_by = $u['maria']->_id;
		$task->completed_at = new UTCDateTime();
		$task->save();
		$exe = $om->findById('WfExecution', (string)$exe->_id);
		$exe->log(null, 'task completed by ' . $names['maria']);
		$exe->run();
	    }
	    $exe = $om->findById('WfExecution', (string)$exe->_id);
	    $this->backdate($exe, $dt, (int)($hours * 3600));
	    echo "run: {$names[$who]}, {$amount} EUR: {$exe->state}" . ($exe->error ? " ({$exe->error})" : '') . "\n";
	}
	//the emails of the runs still queued (to @northwind.example)
	$left = \V::app()->om->deleteMany('Job', [ 'aid' => $account->_id, 'job_spec' => '\util\EmailSend::deliver', 'state' => 'new' ]);

	//saved views
	foreach([ [ 'Approved over 300 EUR', [ [ 'field' => 'status', 'op' => 'eq', 'value' => 'approved' ], [ 'field' => 'amount', 'op' => 'gt', 'value' => '300' ] ] ],
	    [ 'Waiting for a decision', [ [ 'field' => 'status', 'op' => 'eq', 'value' => 'pending' ] ] ] ] as [ $vname, $filter ]) {
	    $om->create('DataTableView', [ 'aid' => $account->_id, 'table' => $dt->_id, 'name' => $vname, 'filter' => $filter, 'user' => $u['maria']->_id ])->save();
	}

	//the knowledge base, with an embedding model
	$kbLine = 'no knowledge base (no embedding model: --ai-from)';
	$settings = \models\kb\EmbeddingSettings::of($account->_id);
	if ($settings->ready())
	    $kbLine = $this->knowledgeBase($account, $project, $settings);

	echo "\ndemo account {$account->_id} ({$name}): project Finance, " . ($left ? "{$left} queued emails removed, " : '') . "{$kbLine}\n";
	foreach(self::PEOPLE as $k => [ $first, $last, $role ])
	    echo "  nw.{$k}  {$first} {$last} ({$role})\n";
    }

    public function delete($req = null, $resp = null) {
	$id = (string)($req->params['account'] ?? '');
	if (!\util\Validator::rule('oid', $id))
	    $this->fail('usage: ../v demo::delete --account=<id>');
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$aid = new ObjectId($id);
	if (!$db->selectCollection(\models\Account::_collection())->findOne([ '_id' => $aid, 'demo' => true ]))
	    $this->fail("{$id}: not an account made by demo::create");
	$names = iterator_to_array($db->listCollectionNames(), false);
	foreach(\V::app()->om->find('DataTableDef', [ 'aid' => $aid ]) as $t) {
	    $db->selectCollection(\sys\fields\Field::_collection())->deleteMany([ 'objectName' => (string)$t->iname ]);
	    foreach($names as $c) {
		if ($c === (string)$t->iname || str_starts_with($c, (string)$t->iname . '.'))
		    $db->dropCollection($c);
	    }
	}
	$n = 0;
	foreach(iterator_to_array($db->listCollectionNames(), false) as $c) {
	    if ($c !== \models\Account::_collection())
		$n += $db->selectCollection($c)->deleteMany([ 'aid' => $aid ])->getDeletedCount();
	}
	$db->selectCollection(\models\Account::_collection())->deleteOne([ '_id' => $aid ]);
	echo "deleted the demo account {$id} and {$n} records\n";
    }

    // ------------------------------------------------------------------------------------

    private static function node($id, $fq, $title, $x, $y, array $config) {
	return [ 'wfid' => $id, 'isNode' => true, 'parentId' => null, 'fqname' => $fq, 'title' => $title, 'geometry' => [ 'x' => $x, 'y' => $y, 'w' => 120, 'h' => 120 ], 'config' => $config ];
    }

    private static function edge($id, $from, $to, $port = 0) {
	return [ 'wfid' => $id, 'isEdge' => true, 'fromNode' => $from, 'fromPort' => $port, 'toNode' => $to, 'toPort' => 0, 'branch' => null ];
    }

    private static function columns() {
	return [
	    [ 'key' => 'employee', 'label' => 'Employee', 'type' => 'object', 'object' => 'User', 'required' => true ],
	    [ 'key' => 'amount', 'label' => 'Amount (EUR)', 'type' => 'number', 'required' => true ],
	    [ 'key' => 'category', 'label' => 'Category', 'type' => 'select', 'choices' => implode("\n", self::CATEGORIES), 'required' => true ],
	    [ 'key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true ],
	    [ 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => "pending\napproved\nrejected", 'required' => true ],
	    [ 'key' => 'decided_by', 'label' => 'Decided by', 'type' => 'object', 'object' => 'User' ],
	    [ 'key' => 'comment', 'label' => 'Comment', 'type' => 'textarea' ],
	];
    }

    //the earlier requests as rows (MongoDB extended JSON, as an export has them)
    private static function historyRows(array $u) {
	$rows = [];
	foreach(self::HISTORY as [ $who, $amount, $category, $reason, $status, $comment, $days ]) {
	    $at = (int)(string)self::daysAgo($days, 9 + $days % 6, 15 + $days);
	    $rows[] = [ 'employee' => [ '$oid' => (string)$u[$who]->_id ], 'amount' => $amount, 'category' => $category, 'reason' => $reason, 'status' => $status,
		'decided_by' => [ '$oid' => (string)$u['maria']->_id ], 'comment' => $comment,
		'created_at' => [ '$date' => [ '$numberLong' => (string)$at ] ], 'updated_at' => [ '$date' => [ '$numberLong' => (string)($at + 5 * 3600 * 1000) ] ] ];
	}
	return $rows;
    }

    //the docs' guide "Expense approval" (docs: guides/examples/expense-approval), with a reminder when nobody decides in 3 days
    private static function expenseNodes($table, $token, $base, $approver) {
	$list = [
	    self::node('form', 'trigger::form', 'Expense request', 0, 200, [
		'url' => "{$base}/form/{$token}", 'token' => $token, 'access' => 'members', 'submitLabel' => 'Send request',
		'message' => 'Thank you: your manager has been asked to approve it.', 'variable' => 'form',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'number', 'key' => 'amount', 'label' => 'Amount (EUR)', 'required' => true, 'min' => 0, 'step' => 0.01 ],
		    [ 'type' => 'select', 'key' => 'category', 'label' => 'Category', 'required' => true, 'choices' => self::choicesOf(array_combine(self::CATEGORIES, self::CATEGORIES)) ],
		    [ 'type' => 'textarea', 'key' => 'reason', 'label' => 'Reason', 'required' => true, 'rows' => 3 ],
		] ] ]),
	    self::node('record', 'app::table', 'Record request', 220, 200, [ 'table' => $table, 'operation' => 'insert', 'variable' => 'expense', 'onError' => 'fail', 'data' => [
		[ 'column' => 'employee', 'value' => '{{ form_user.id }}' ], [ 'column' => 'amount', 'value' => '{{ form.amount }}' ],
		[ 'column' => 'category', 'value' => '{{ form.category }}' ], [ 'column' => 'reason', 'value' => '{{ form.reason }}' ], [ 'column' => 'status', 'value' => 'pending' ] ] ]),
	    self::node('approve', 'app::task', 'Manager approval', 440, 200, [
		'title' => 'Approve {{ form.amount }} EUR for {{ form_user.name }}',
		'instructions' => "{{ form.category }}: {{ form.reason }}\n\nCheck the amount against the expense policy before you approve it.",
		'users' => [ $approver ], 'roles' => [], 'assignTo' => null, 'submitLabel' => 'Send decision', 'timeoutUnit' => 'days', 'timeout' => 3, 'variable' => 'task',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'decision', 'label' => 'Decision', 'required' => true, 'choices' => self::choicesOf([ 'approved' => 'Approve', 'rejected' => 'Reject' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Comment', 'rows' => 3, 'helpText' => 'Sent to the employee with the decision.' ],
		] ] ]),
	    self::node('decide', 'app::table', 'Record decision', 660, 200, [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ expense.row.id }}', 'variable' => 'expense',
		'onError' => 'fail', 'data' => [ [ 'column' => 'status', 'value' => '{{ task.decision }}' ], [ 'column' => 'decided_by', 'value' => '{{ task_user.id }}' ],
		[ 'column' => 'comment', 'value' => '{{ task.comment }}' ] ] ]),
	    self::node('check', 'core::if', 'If approved', 880, 200, [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ task.decision }}', 'operator' => 'eq', 'value' => 'approved' ] ] ]),
	    self::node('ok', 'app::send-email', 'Tell: approved', 1100, 80, [ 'to' => '{{ form_user.email }}', 'subject' => 'Your expense of {{ form.amount }} EUR is approved',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>Hello {{ form_user.name }},</p><p>{{ task_user.name }} approved your expense: {{ form.reason }}. It will be paid with your next salary.</p>') ]),
	    self::node('no', 'app::send-email', 'Tell: rejected', 1100, 320, [ 'to' => '{{ form_user.email }}', 'subject' => 'Your expense of {{ form.amount }} EUR was rejected',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>Hello {{ form_user.name }},</p><p>{{ task_user.name }} rejected your expense: {{ task.comment }}</p>') ]),
	    self::node('remind', 'app::send-email', 'Remind finance', 660, 420, [ 'to' => 'finance@northwind.example', 'subject' => 'No decision yet: {{ form.amount }} EUR for {{ form_user.name }}',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>The expense request of {{ form_user.name }} ({{ form.amount }} EUR, {{ form.category }}) has waited 3 days for a decision.</p>') ]),
	    self::edge('e1', 'form', 'record'), self::edge('e2', 'record', 'approve'), self::edge('e3', 'approve', 'decide', 0), self::edge('e4', 'approve', 'remind', 1),
	    self::edge('e5', 'decide', 'check'), self::edge('e6', 'check', 'ok', 0), self::edge('e7', 'check', 'no', 1),
	];
	$nodes = [];
	foreach($list as $n)
	    $nodes[$n['wfid']] = $n;
	return $nodes;
    }

    //CLD: 2026-11-07T01:04:46+02:00: an email of the Send Email step: one HTML block
    private static function emailOf($html) {
	return [ 'preheader' => '', 'blocks' => [ [ 'type' => 'html', 'html' => $html ] ] ];
    }

    //CLD: 2026-11-07T01:04:46+02:00: the choices of a select field from $list (value => label)
    private static function choicesOf(array $list) {
	return array_map(fn($v, $l) => [ 'value' => $v, 'label' => $l ], array_keys($list), array_values($list));
    }

    //$days ago at $hour:$minute (server time) as a date
    private static function daysAgo($days, $hour, $minute) {
	return new UTCDateTime((new \DateTime("-{$days} days"))->setTime($hour, $minute)->getTimestamp() * 1000);
    }

    //a run made now, moved $seconds back: its dates, its task's, the row it inserted
    private function backdate($exe, $dt, $seconds) {
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$map = [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ];
	$rowId = null;
	foreach([ \models\wf\WfExecution::_collection() => [ '_id' => $exe->_id ], \models\wf\WfTask::_collection() => [ 'execution' => $exe->_id ] ] as $c => $q) {
	    $coll = $db->selectCollection($c);
	    foreach($coll->find($q, $map) as $doc) {
		$coll->replaceOne([ '_id' => $doc['_id'] ], self::shiftDates($doc, $seconds));
		array_walk_recursive($doc, function($v) use (&$rowId) {
		    if (is_string($v) && preg_match('/inserted row ([0-9a-f]{24})/', $v, $m))
			$rowId = $m[1];
		});
	    }
	}
	if ($rowId) {
	    $rows = $db->selectCollection(\V::app()->om->registry[(string)$dt->iname]['collection'] ?? (string)$dt->iname);
	    $row = $rows->findOne([ '_id' => new ObjectId($rowId) ], $map);
	    if ($row)
		$rows->replaceOne([ '_id' => $row['_id'] ], self::shiftDates($row, $seconds));
	}
    }

    //CLD: 2026-11-07T01:05:06+02:00: $v with its dates (also inside arrays) $seconds earlier
    private static function shiftDates($v, $seconds) {
	if ($v instanceof UTCDateTime)
	    return new UTCDateTime((int)(string)$v - $seconds * 1000);
	if (is_array($v)) {
	    foreach($v as $k => $x)
		$v[$k] = self::shiftDates($x, $seconds);
	}
	return $v;
    }

    //the knowledge base "Expense policy", indexed here (KnowledgeBase::queue without the daemon's job, then its job)
    private function knowledgeBase($account, $project, $settings) {
	$kb = \V::app()->om->create('KnowledgeBase', [ 'aid' => $account->_id, 'project' => $project->_id, 'name' => 'Expense policy',
	    'description' => 'The travel and expense rules every request is checked against', 'model' => trim((string)$settings->model), 'chunkSize' => 600, 'overlap' => 100 ]);
	if (!$kb->validates() || !$kb->save())
	    return 'knowledge base not made: ' . json_encode($kb->errors());
	foreach(self::POLICY as $title => $text) {
	    $s = \models\kb\KnowledgeBase::textSource($title, $title . "\n\n" . $text, '');
	    $now = new UTCDateTime();
	    //CLD: 2026-11-08T14:10:00+02:00: save(), as KnowledgeBase::addDocument does; save() sets created_at
	    $doc = \V::app()->om->create('KbDocument', [ 'aid' => $kb->aid, 'kb' => $kb->_id, 'key' => $s['key'], 'title' => $s['title'], 'source' => 'text', 'file' => null, 'url' => null,
		'meta' => (object)[], 'hash' => '', 'chunks' => 0, 'chars' => 0, 'status' => 'pending', 'error' => null, 'updated_at' => $now ]);
	    if (!$doc->save())
		return 'knowledge base document not made: ' . $s['title'];
	    \models\kb\KnowledgeBase::indexJob([ 'aid' => (string)$kb->aid, 'project' => (string)$kb->project, 'kb' => (string)$kb->_id, 'key' => $s['key'],
		'src' => [ 'type' => 'text', 'title' => $s['title'], 'text' => $s['text'], 'key' => $s['key'] ] ]);
	}
	$kb = \V::app()->om->findById('KnowledgeBase', (string)$kb->_id);
	$errors = \V::app()->om->count('KbDocument', [ 'kb' => $kb->_id, 'aid' => $kb->aid, 'status' => 'error' ]);
	return "knowledge base Expense policy: {$kb->documents} documents, {$kb->chunks} chunks" . ($errors ? ", {$errors} not indexed" : '');
    }

    private function askPassword() {
	fwrite(STDERR, 'Password for the demo users: ');
	$tty = function_exists('posix_isatty') && posix_isatty(STDIN);
	if ($tty)
	    system('stty -echo');
	$password = rtrim((string)fgets(STDIN), "\r\n");
	if ($tty)
	    system('stty echo');
	fwrite(STDERR, "\n");
	return $password;
    }

    private function fail($message) {
	fwrite(STDERR, "demo: {$message}\n");
	exit(1);
    }
}
