<?php

namespace models;

class Workflow extends \sys\BaseModel {
    protected static $collection = 'workflows';
    //CLD: 2026-10-12T12:18:11+03:00: lists of a project; published workflows (util\Scheduler) (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1 ], [] ],
	[ [ 'status' => 1, 'version' => 1 ], [] ],
    ];


    //CLD: 2026-10-11T13:29:36+03:00: `status`: only published workflows are started by their triggers (scheduler, IMAP, webhook); drafts run only by hand (designer Run, wf::run)
    const DRAFT = 'draft';
    const PUBLISHED = 'published';

    //CLD: 2026-10-11T13:51:42+03:00: set on a copy made by forVersion(): the version whose steps it holds (runs record it); such a copy is never saved
    public $runVersion = null;

    //CLD: 2026-10-19T17:10:00+03:00: the step errors found by validatesDraft() (a draft is saved with them)
    public $stepIssues = [];

    public function getSchema() {
	$fields = [
	    //name
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //icon
	    [
		'key' => 'icon',
		'type' => 'icon',
		'label' => 'Icon',
		'help' => false,
		'default' => 'app-indicator::black',
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //project
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    [
		'key' => 'status',
		'type' => 'select',
		'label' => 'Status',
		'help' => false,
		'default' => self::DRAFT,
		'choices' => [
		    self::DRAFT => 'Draft',
		    self::PUBLISHED => 'Published'
		],
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	    //CLD: 2026-10-11T13:51:42+03:00: the published version the triggers run while `status` is published (kept when unpublished: publishing again
	    //reuses it when the draft has not changed); set by WorkflowController::publish
	    [
		'key' => 'version',
		'type' => 'object',
		'object' => 'WfVersion',
		'label' => 'Published version',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //CLD: 2026-10-11T13:51:42+03:00: versions created so far (the next one is version_count + 1; incremented atomically by nextVersionNumber())
	    [
		'key' => 'version_count',
		'type' => 'number',
		'label' => 'Versions',
		'help' => false,
		'default' => 0,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	    //nodes (the draft)
	    [
		'key' => 'nodes',
		'type' => 'array',
		'label' => 'Nodes',
		'help' => false,
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	//build full permission name
	//CLD: 2026-10-06T23:06:18+03:00: only when short: AAA::checkAccess passes the full name (e.g. User::update), which was doubled
	//(User::User::update) and never matched a role permission; field checks pass the short one
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//now get the primary role permission scope
	$scope = $role->getPermissionScope($permName);
	if ($scope == 'global')
	    return true;

	//CLD: 2026-10-06T23:07:56+03:00: project scope: the user must be a member of the workflow's project
	if ($scope == 'project')
	    return \models\Project::isMember($this->project, $this->aid, $userId);

	//default: false
	return false;
    }


    //CLD: 2026-10-08T08:19:49+03:00: the workflow's own rules, then every step's validates() (rules in the node class, e.g. the webhook token);
    //step errors are reported on `nodes`, prefixed with the step title; unknown step types are left to validateFlow()
    //CLD: 2026-10-19T17:10:00+03:00: the step checks moved to validateSteps(); the designer saves with validatesDraft() (step errors do not stop the save)
    public function validates($options = []) {
	$valid = parent::validates($options);
	foreach($this->validateSteps(true) as $issue) {
	    $this->errors('nodes', $issue['message']);
	    $valid = false;
	}
	return $valid;
    }

    //CLD: 2026-10-19T17:10:00+03:00: saving a draft from the designer: the workflow's own rules only; the steps' configs are normalized, their errors are kept in
    //$stepIssues (not errors()): a draft may be saved with them, it cannot be published or run (issues())
    public function validatesDraft($options = []) {
	$valid = parent::validates($options);
	$this->stepIssues = $this->validateSteps(true);
	return $valid;
    }

    //CLD: 2026-10-19T17:10:00+03:00: the problems in the steps' settings, as validateFlow() issues: [ [ 'level' => 'error', 'node' => wfid, 'message' => '<step title>: ...' ], ... ]
    //(was inside validates()); $normalize: each step's config as normalized by its config form goes back into `nodes`
    public function validateSteps($normalize = false) {
	$issues = [];
	$defs = \models\WfNode::getAll();
	//CLD: 2026-10-09T08:10:30+03:00: + each step's config as normalized by its config form (cast values, defaults) goes back into `nodes`
	$nodes = is_array($this->nodes) ? $this->nodes : [];
	foreach($nodes as $index => $data) {
	    if (!is_array($data) || empty($data['isNode']))
		continue;
	    $def = $defs[$data['fqname'] ?? ''] ?? null;
	    if (empty($def))
		continue;

	    $node = \V::app()->om->create($def['objectName'], $data);
	    $node->workflow = $this;
	    $ok = $node->validates();
	    //CLD: 2026-10-13T15:24:28+03:00: `vars` is the project variables ({{ vars.<name> }}): no step may set a variable of that name
	    $class = get_class($node);
	    //CLD: 2026-10-22T00:28:13+03:00: CredentialVariable::EXPRESSION_NAME (was models\Variable's)
	    if (array_key_exists(\models\credential\CredentialVariable::EXPRESSION_NAME, $class::outputVariables($data['config'] ?? []) + $class::innerVariables($data['config'] ?? []))) {
		$node->errors('config', '`' . \models\credential\CredentialVariable::EXPRESSION_NAME . '` is the name of the project variables ({{ vars.<name> }}): choose another variable name');
		$ok = false;
	    }
	    if (is_array($node->config))
		$nodes[$index]['config'] = $node->config;
	    if ($ok)
		continue;

	    $title = (string)($data['title'] ?? '') !== '' ? $data['title'] : ($data['wfid'] ?? '?');
	    foreach((array)$node->errors() as $messages) {
		foreach((array)$messages as $message)
		    $issues[] = [ 'level' => 'error', 'node' => $data['wfid'] ?? null, 'message' => "{$title}: {$message}" ];
	    }
	}
	if ($normalize)
	    $this->nodes = $nodes;
	return $issues;
    }

    //CLD: 2026-10-19T17:10:00+03:00: everything that stops publishing / running: the steps' settings (validateSteps) and the connections (validateFlow)
    public function issues() {
	return array_merge($this->validateSteps(), $this->validateFlow());
    }

    //CLD: 2026-10-08T07:54:32+03:00: generic save hooks (the webhook token / index logic moved to plugins\Webhook\Plugin);
    //handlers receive the workflow and may change it in workflow:before-save
    public function beforeSave() {
	//CLD: 2026-10-11T13:51:42+03:00: a version copy (forVersion) holds a version's steps: saving it would overwrite the draft
	if ($this->runVersion !== null)
	    throw new \LogicException('a copy of a published version cannot be saved');
	parent::beforeSave();
	\V::app()->hooks->applyHook('workflow:before-save', [ $this ]);
    }

    public function afterSave() {
	parent::afterSave();
	\V::app()->hooks->applyHook('workflow:after-save', [ $this ]);
    }

    //CLD: 2026-10-11T14:05:19+03:00: deleting a workflow deletes its executions and published versions; plugins delete their own data in workflow:before-delete
    //(e.g. the webhook index, the IMAP check state). Scheduler jobs queued before are skipped (Scheduler::run)
    public function beforeDelete() {
	if ($this->runVersion !== null)
	    throw new \LogicException('a copy of a published version cannot be deleted');
	parent::beforeDelete();
	\V::app()->hooks->applyHook('workflow:before-delete', [ $this ]);
	//CLD: 2026-10-12T12:31:46+03:00: through ObjectManager (was the MongoDB collections directly): each deleted with its model (only the ids are read; both are kept out of the logs)
	foreach([ 'WfExecution', 'WfVersion' ] as $objectName) {
	    foreach(\V::app()->om->find($objectName, [ 'wf' => $this->_id, 'aid' => $this->aid ], [ 'projection' => [ '_id' => 1, 'aid' => 1 ] ]) as $o)
		$o->delete();
	}
    }

    //CLD: 2026-10-11T13:51:42+03:00: the version set in `version` (the active one while published), null when never published
    public function publishedVersion() {
	if (empty($this->version))
	    return null;
	return \V::app()->om->findOne('WfVersion', [ '_id' => $this->version, 'wf' => $this->_id, 'aid' => $this->aid ]);
    }

    //CLD: 2026-10-11T13:51:42+03:00: a copy of this workflow holding the steps of $version (runVersion set): what triggers validate and start, so the
    //code that runs a workflow (validateFlow, WfExecution::startFor, ...) needs no version logic; the copy cannot be saved
    public function forVersion($version) {
	$copy = \V::app()->om->findOne('Workflow', [ '_id' => $this->_id, 'aid' => $this->aid ]);
	if (empty($copy))
	    return null;
	$copy->nodes = is_array($version->nodes) ? $version->nodes : [];
	$copy->runVersion = $version;
	return $copy;
    }

    //CLD: 2026-10-11T13:51:42+03:00: the published version's copy (forVersion) when the workflow is published, else null: what its triggers run
    public function liveCopy() {
	if ($this->status !== self::PUBLISHED)
	    return null;
	$version = $this->publishedVersion();
	return $version ? $this->forVersion($version) : null;
    }

    //CLD: 2026-10-11T17:35:22+03:00: the audit log gets a summary of the steps (titles; counts), not the steps: they are large and their
    //configs hold secrets (e.g. webhook authentication)
    public function auditValue($key, $value) {
	if ($key !== 'nodes' || !is_array($value))
	    return $value;

	$titles = [];
	$edges = 0;
	foreach($value as $n) {
	    if (!empty($n['isNode']))
		$titles[] = (string)($n['title'] ?? $n['fqname'] ?? '?');
	    elseif (!empty($n['isEdge']))
		$edges++;
	}
	return count($titles) . ' steps (' . implode(', ', $titles) . '), ' . $edges . ' connections';
    }

    //CLD: 2026-10-11T13:51:42+03:00: whether the saved draft has the same steps as $version (nothing new to publish)
    public function draftMatches($version) {
	//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	return \util\JSON::encode(is_array($this->nodes) ? $this->nodes : []) === \util\JSON::encode(is_array($version->nodes) ? $version->nodes : []);
    }

    //CLD: 2026-10-11T13:51:42+03:00: the number of a new version (atomic, so two publishes never get the same number)
    //CLD: 2026-10-12T12:31:46+03:00: through ObjectManager (om->findOneAndUpdate; was the MongoDB collection directly)
    public function nextVersionNumber() {
	$wf = \V::app()->om->findOneAndUpdate('Workflow', [ '_id' => $this->_id ], [ '$inc' => [ 'version_count' => 1 ] ], [ 'projection' => [ 'version_count' => 1 ] ]);
	$this->version_count = (int)($wf->version_count ?? 0);
	return $this->version_count;
    }

    //CLD: 2026-10-13T12:34:29+03:00: how many steps deep a variable's type is looked for through `from` (Set steps copying Set steps; variablesAt)
    const MAX_TYPE_DEPTH = 8;

    //CLD: 2026-10-06T23:25:57+03:00: checks how the steps are connected; returns [ [ 'level' => error|warning, 'node' => wfid|null, 'message' => ... ], ... ]
    //errors: the workflow cannot run correctly; warnings: allowed, probably unintended
    //CLD: 2026-10-11T22:38:36+03:00: the variables a step can use (expression help): those set by the steps that can run before it (following the connections back; in a loop, also
    //the steps before the loop) and, inside a loop, the loop's own (innerVariables). $nodes: the steps and connections (the designer's current state, a list or a
    //map). [ name => [ type, info ] ]; info says where it comes from
    //CLD: 2026-10-13T12:34:29+03:00: $depth: how deep the type of a `from` variable is being looked for (at most MAX_TYPE_DEPTH)
    public static function variablesAt($nodes, $wfid, $depth = 0) {
	$defs = \models\WfNode::getAll();
	$byId = [];
	$from = [];
	foreach((is_array($nodes) ? $nodes : []) as $e) {
	    if (!is_array($e) || empty($e['wfid']))
		continue;
	    if (!empty($e['isNode']))
		$byId[$e['wfid']] = $e;
	    elseif (!empty($e['isEdge']) && isset($e['toNode'], $e['fromNode']))
		$from[(string)$e['toNode']][] = (string)$e['fromNode'];
	}
	if (!isset($byId[$wfid]))
	    return [];

	$classOf = function($n) use ($defs) {
	    $def = $defs[$n['fqname'] ?? ''] ?? null;
	    return $def ? \V::app()->om->getObjectClass($def['objectName']) : null;
	};
	$vars = [];
	//CLD: 2026-10-13T12:34:29+03:00: the whole type description (+ fields, items: util\Expression::typeAt; was type and info, then + keys); a variable with `from` (a value text, e.g. the
	//Set step's) gets the type of that value among the variables at its step (util\Expression::typeOf; nested at most MAX_TYPE_DEPTH steps deep)
	$add = function(array $list, $n) use (&$vars, $nodes, $depth) {
	    $title = trim((string)($n['title'] ?? ''));
	    foreach($list as $name => $v) {
		if (isset($vars[$name]))
		    continue;
		if (isset($v['from']))
		    $v = array_merge($depth < self::MAX_TYPE_DEPTH ? \util\Expression::typeOf($v['from'], self::variablesAt($nodes, (string)$n['wfid'], $depth + 1)) : [ 'type' => 'any' ], array_intersect_key($v, [ 'info' => 1 ]));
		//CLD: 2026-10-13T12:43:20+03:00: `itemsOf` (a list's value text, e.g. the Loop's item): the type of that list's items (unknown: any)
		if (isset($v['itemsOf'])) {
		    $list = $depth < self::MAX_TYPE_DEPTH ? \util\Expression::typeOf($v['itemsOf'], self::variablesAt($nodes, (string)$n['wfid'], $depth + 1)) : [];
		    $v = array_merge(is_array($list['items'] ?? null) ? $list['items'] : [ 'type' => 'any' ], array_intersect_key($v, [ 'info' => 1 ]));
		}
		unset($v['from'], $v['itemsOf']);
		$vars[$name] = [ 'type' => $v['type'] ?? 'any', 'info' => trim(($v['info'] ?? '') . ($title !== '' ? " (step \"{$title}\")" : '')) ] + array_intersect_key($v, [ 'fields' => 1, 'items' => 1 ]);
	    }
	};

	//back from the step: the steps before it, and the loops it is in (then the steps before them)
	$seen = [ $wfid => true ];
	$queue = [ $wfid ];
	while ($queue) {
	    $id = array_shift($queue);
	    $n = $byId[$id];
	    $parent = $n['parentId'] ?? null;
	    if ($parent && isset($byId[$parent]) && !isset($seen[$parent])) {
		$seen[$parent] = true;
		$queue[] = $parent;
		if ($class = $classOf($byId[$parent]))
		    $add($class::innerVariables($byId[$parent]['config'] ?? []), $byId[$parent]);
	    }
	    foreach($from[$id] ?? [] as $prev) {
		if (isset($seen[$prev]) || !isset($byId[$prev]))
		    continue;
		$seen[$prev] = true;
		$queue[] = $prev;
		if ($class = $classOf($byId[$prev]))
		    $add($class::outputVariables($byId[$prev]['config'] ?? []), $byId[$prev]);
	    }
	}
	ksort($vars);
	return $vars;
    }

    public function validateFlow() {
	$defs = \models\WfNode::getAll();
	$issues = [];
	$add = function($level, $node, $message) use (&$issues) {
	    $issues[] = [ 'level' => $level, 'node' => $node, 'message' => $message ];
	};

	//split nodes / edges (the designer saves a list or a map; the key is the element's wfid)
	$nodes = [];
	$edges = [];
	foreach((is_array($this->nodes) ? $this->nodes : []) as $e) {
	    if (!is_array($e) || empty($e['wfid']))
		continue;
	    if (!empty($e['isNode']))
		$nodes[$e['wfid']] = $e;
	    elseif (!empty($e['isEdge']))
		$edges[$e['wfid']] = $e;
	}

	//node types
	$def = [];
	foreach($nodes as $id => $n) {
	    $d = $defs[$n['fqname'] ?? ''] ?? null;
	    if (empty($d)) {
		$add('error', $id, 'Unknown step type');
		continue;
	    }
	    //CLD: 2026-10-11T20:24:33+03:00: the step's own outputs when they follow its config (e.g. Switch cases; keep in sync with outPortsOf() in app.workflow.js)
	    if (!empty($d['outPorts'])) {
		$class = \V::app()->om->getObjectClass($d['objectName']);
		$d['outLabels'] = $class::outLabelsFor($n['config'] ?? null);
		//CLD: 2026-10-12T08:26:57+03:00: outputCountFor (labels may be null: the class's own outputs)
		$d['outputs'] = $class::outputCountFor($n['config'] ?? null);
	    }
	    $def[$id] = $d;
	}

	//CLD: 2026-10-11T13:46:00+03:00: the level of a step: the loop it is in, null at the top (keep in sync with validateFlow() in app.workflow.js)
	$levelOf = function($id) use ($nodes, $def) {
	    $parent = $nodes[$id]['parentId'] ?? null;
	    return (!empty($parent) && !empty($def[$parent]['isContainer'])) ? $parent : null;
	};

	//exactly one trigger
	$triggers = array_keys(array_filter($def, fn($d) => $d['type'] == 'trigger'));
	if (empty($triggers))
	    $add('error', null, 'The workflow has no trigger');
	foreach(array_slice($triggers, 1) as $id)
	    $add('error', $id, 'Only one trigger is allowed');
	//CLD: 2026-10-14T21:06:38+03:00: a loop cannot be inside another loop (keep in sync with validateFlow() in app.workflow.js)
	foreach($def as $id => $d) {
	    if (!empty($d['isContainer']) && $levelOf($id) !== null)
		$add('error', $id, 'A loop cannot be inside another loop');
	}
	//CLD: 2026-10-11T13:39:20+03:00: a trigger cannot be inside a loop (keep in sync with validateFlow() in app.workflow.js)
	foreach($triggers as $id) {
	    $parent = $nodes[$id]['parentId'] ?? null;
	    if (!empty($parent) && !empty($def[$parent]['isContainer']))
		$add('error', $id, 'A trigger cannot be inside a loop');
	}

	//connections
	$seen = [];
	$adjacent = [];
	$inputsUsed = [];
	$outputsUsed = [];
	foreach($edges as $edgeId => $e) {
	    $from = $e['fromNode'] ?? null;
	    $to = $e['toNode'] ?? null;
	    if (!isset($def[$from]) || !isset($def[$to])) {
		$add('error', isset($def[$from]) ? $from : (isset($def[$to]) ? $to : null), 'A connection points to a missing step');
		continue;
	    }
	    $fd = $def[$from];
	    $td = $def[$to];
	    if (!empty($fd['isNote']) || !empty($td['isNote'])) {
		$add('error', !empty($fd['isNote']) ? $from : $to, 'Notes cannot be connected');
		continue;
	    }
	    if ($td['inputs'] == 0) {
		$add('error', $to, 'This step cannot have incoming connections');
		continue;
	    }
	    //CLD: 2026-10-11T13:46:00+03:00: connections stay on their level (a loop's own input / output belong to the level around it)
	    if ($levelOf($from) !== $levelOf($to)) {
		$add('error', $from, "A connection cannot cross a loop's border");
		continue;
	    }

	    $fromPort = $e['fromPort'] ?? null;
	    $toPort = $e['toPort'] ?? null;
	    if (!is_numeric($fromPort) || $fromPort < 0 || $fromPort >= $fd['outputs']) {
		$add('error', $from, 'A connection leaves from an output this step does not have');
		continue;
	    }
	    if (!is_numeric($toPort) || $toPort < 0 || $toPort >= $td['inputs']) {
		$add('error', $to, 'A connection arrives at an input this step does not have');
		continue;
	    }

	    $key = "{$from}|{$fromPort}|{$to}|{$toPort}";
	    if (isset($seen[$key])) {
		$add('error', $from, 'Duplicate connection');
		continue;
	    }
	    $seen[$key] = true;

	    if ($from == $to)
		$add('warning', $from, 'This step is connected to itself');

	    $adjacent[$from][] = $to;
	    $inputsUsed[$to][(int)$toPort] = true;
	    $outputsUsed[$from][(int)$fromPort] = true;
	}

	//CLD: 2026-10-11T13:46:00+03:00: computed for every level (was inside the trigger check)
	$hasIncoming = [];
	foreach($adjacent as $from => $tos)
	    foreach($tos as $to)
		$hasIncoming[$to] = true;

	//every step must be reachable from the trigger
	if (count($triggers) == 1) {
	    $reached = [ $triggers[0] => true ];
	    $queue = [ $triggers[0] ];
	    while ($queue) {
		$id = array_shift($queue);
		foreach($adjacent[$id] ?? [] as $next) {
		    if (!isset($reached[$next])) {
			$reached[$next] = true;
			$queue[] = $next;
		    }
		}
	    }
	    //CLD: 2026-10-07T08:01:20+03:00: flag only where a disconnected chain starts (no incoming connection), not every step after it;
	    //steps of a disconnected cycle (all have incoming connections, none starts the chain) are flagged too
	    //CLD: 2026-10-11T13:46:00+03:00: top-level steps only (the steps inside a loop are checked from the loop's starting step, below)
	    $unreached = [];
	    foreach($def as $id => $d) {
		if (empty($d['isNote']) && $levelOf($id) === null && !isset($reached[$id]))
		    $unreached[$id] = true;
	    }

	    $starts = array_keys(array_filter($unreached, fn($v, $id) => empty($hasIncoming[$id]), ARRAY_FILTER_USE_BOTH));
	    $fromStarts = array_fill_keys($starts, true);
	    $queue = $starts;
	    while ($queue) {
		$id = array_shift($queue);
		foreach($adjacent[$id] ?? [] as $next) {
		    if (!isset($fromStarts[$next])) {
			$fromStarts[$next] = true;
			$queue[] = $next;
		    }
		}
	    }
	    foreach(array_keys($unreached) as $id) {
		if (in_array($id, $starts, true) || !isset($fromStarts[$id]))
		    $add('error', $id, 'Not connected to the trigger');
	    }
	}

	//CLD: 2026-10-11T13:46:00+03:00: the inside of each loop is a flow of its own: each pass starts at its one step without an incoming connection, every step inside
	//must be reachable from it; notes and triggers (an error of their own) are left out
	foreach($def as $loopId => $loopDef) {
	    if (empty($loopDef['isContainer']))
		continue;
	    $inner = array_keys(array_filter($def, fn($d, $id) => empty($d['isNote']) && $d['type'] != 'trigger' && $levelOf($id) === $loopId, ARRAY_FILTER_USE_BOTH));
	    if (empty($inner)) {
		$add('warning', $loopId, 'The loop is empty');
		continue;
	    }
	    $starts = array_values(array_filter($inner, fn($id) => empty($hasIncoming[$id])));
	    if (empty($starts)) {
		$add('error', $loopId, 'The loop has no starting step: every step inside has an incoming connection');
		continue;
	    }
	    if (count($starts) > 1) {
		foreach($starts as $id)
		    $add('error', $id, 'Only one step inside a loop may have no incoming connection: each pass starts there');
		continue;
	    }
	    $reached = [ $starts[0] => true ];
	    $queue = [ $starts[0] ];
	    while ($queue) {
		$id = array_shift($queue);
		foreach($adjacent[$id] ?? [] as $next) {
		    if (!isset($reached[$next])) {
			$reached[$next] = true;
			$queue[] = $next;
		    }
		}
	    }
	    foreach($inner as $id) {
		if (!isset($reached[$id]))
		    $add('error', $id, "Not connected to the loop's starting step");
	    }
	}

	//several outputs (If): every output should lead somewhere; several inputs (Merge): every input should be fed
	foreach($def as $id => $d) {
	    if (!empty($d['isNote']))
		continue;
	    if ($d['outputs'] > 1) {
		for ($p = 0; $p < $d['outputs']; $p++) {
		    if (empty($outputsUsed[$id][$p]))
			$add('warning', $id, "Output `" . ($d['outLabels'][$p] ?? $p + 1) . "` is not connected");
		}
	    }
	    if ($d['inputs'] > 1) {
		$used = count($inputsUsed[$id] ?? []);
		if ($used < $d['inputs'])
		    $add('warning', $id, "Only {$used} of {$d['inputs']} inputs are connected");
	    }
	}

	return $issues;
    }

    public function __toString() {
	return $this->name ?? '';
    }
}
