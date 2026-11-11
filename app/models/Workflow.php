<?php

namespace models;

class Workflow extends \sys\BaseModel {
    //a published workflow that starts with Called by a workflow can be shared with other projects of the account: they call it and see what it offers (not Workflow::read: that is also its runs,
    //its settings, its tests). It runs in its own project

    use \models\share\ShareableTrait;
    use \models\share\MovableTrait;

    const MOVE_PERMS = [
	'from' => 'Workflow::delete',
	'to' => 'Workflow::create'
    ];
    const MOVE_ACTION = 'workflow.move';
    //what follows it, by their `wf`: its versions and runs, its tests, its tasks, its triggers' state and token, its conversations and traces. The logs stay as written
    const MOVE_RELATED = [ 'WfVersion', 'WfExecution', 'WfTestCase', 'WfTestRun', 'WfTask', 'TriggerToken', 'TriggerState', 'MailPoll', 'RemoteFileState', 'AgentConversation', 'AgentTrace' ];

    const SHARE_PERM = 'Workflow::share';
    const SHARE_WHAT = 'Workflow';
    const SHARE_LEVELS = [ 'run' => 'Call and view' ];
    const SHARE_PERMS = [ 'run' => [ 'Workflow::viewShared' ] ];

    protected static $collection = 'workflows';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1 ], [] ],
	[ [ 'aid' => 1, 'shares.project' => 1 ], [] ],
	[ [ 'status' => 1, 'version' => 1 ], [] ],
    ];


    //`status`: only published workflows are started by their triggers (scheduler, IMAP, webhook); drafts run only by hand (designer Run, sys::run)
    const DRAFT = 'draft';
    const PUBLISHED = 'published';

    //set on a copy made by forVersion(): the version whose steps it holds (runs record it); such a copy is never saved
    public $runVersion = null;

    //the step errors found by validatesDraft() (a draft is saved with them)
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
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //the projects it is shared with
	    [
		'key' => 'shares',
		'type' => 'array',
		'label' => 'Shared with',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
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

	    //the published version the triggers run while `status` is published (kept when unpublished: publishing again
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
	    //versions created so far (the next one is version_count + 1; incremented atomically by nextVersionNumber())
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

	    //Tests must pass to publish (util\AgentEval::gate; EvalController::required)
	    [
		'key' => 'tests_required',
		'type' => 'checkbox',
		'label' => 'Tests must pass to publish',
		'help' => false,
		'default' => false,
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
	//build full permission name
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	//the user's role in the workflow's project decides
	//or a share with a project where their role allows it (only Workflow::viewShared)
	return \V::app()->om->findById('Project', $this->project)?->checkAccess($userId, $permName) || $this->sharedCan($userId, $permName);
    }

    //why it cannot be shared now, null when it can (the Share dialog)
    public function shareProblem() {
	$live = $this->liveCopy();
	if (!$live)
	    return 'Publish it first: other projects only run its published version.';
	if (\models\node\NodeTriggerCall::contractOf($live->nodes) === null)
	    return 'Only a workflow that starts with Called by a workflow can be shared: its inputs and outputs are what other projects use.';
	return null;
    }

    //what the Share dialog says
    public function shareWarnings() {
	return [ 'It runs in this project, with this project\'s credentials and tables. Who calls it chooses its inputs and gets its outputs and errors. They see its description, inputs, outputs and step names, not its settings or runs.' ];
    }

    //a run in progress holds its project: it would go on with the old one's credentials and tables, and a waiting one's task would be left behind
    public function moveProblem() {
	if (\V::app()->om->count('WfExecution', [ 'wf' => $this->_id, 'aid' => $this->aid, 'state' => [ '$in' => [ 'new', 'run', 'sleep' ] ] ]) > 0)
	    return 'It has runs in progress or waiting: let them finish or stop them first (Executions).';
	if (\V::app()->om->count('WfTestRun', [ 'wf' => $this->_id, 'aid' => $this->aid, 'state' => [ '$in' => \models\wf\WfTestRun::ACTIVE ] ]) > 0)
	    return 'Its tests are running: let them finish first.';
	return null;
    }

    //a published one keeps running there: that needs the right to publish there too
    protected function moveAllowedIn($project, $userId) {
	return $project->checkAccess($userId, self::MOVE_PERMS['to'])
	    && (!$this->liveCopy() || $project->checkAccess($userId, 'Workflow::publish'));
    }

    //any number of workflows may have the same name
    public function moveNameProblem($project) {
	return null;
    }

    protected function moveRelated($project) {
	foreach(self::MOVE_RELATED as $objectName)
	    \V::app()->om->updateMany($objectName, [ 'wf' => $this->_id, 'aid' => $this->aid ], [ '$set' => [ 'project' => $project->_id ] ]);
    }

    //what the Move dialog warns of: what its steps use stays in this project; the files its runs saved too
    public function moveWarnings() {
	$warnings = [];
	$used = $this->moveUses();
	if ($used)
	    $warnings[] = 'It uses these of this project, which stay here: ' . implode('; ', $used) . '. Those steps fail in the new project until these are moved, shared with it or made again there.';
	$warnings[] = 'The files its runs saved stay in this project.';
	return $warnings;
    }

    //what its draft and published version point to in this project: [ '<kind>: <names>' ]; the variables are read by name (vars.<name>), the knowledge bases named in the step
    private function moveUses() {
	$om = \V::app()->om;
	$home = [ 'aid' => $this->aid, 'project' => $this->project ];
	$versions = array_filter([ $this, $this->liveCopy() ]);
	$texts = \util\Transfer::textsOf($versions);

	[ $credentials, $tables ] = \util\Transfer::usedBy($om->findOne('Project', [ '_id' => $this->project, 'aid' => $this->aid ]), $versions);
	$ids = array_values(array_filter($texts, fn($t) => \util\Validator::rule('oid', $t) && $t !== (string)$this->_id));
	$workflows = $ids ? iterator_to_array($om->find('Workflow', $home + [ '_id' => [ '$in' => array_map(fn($id) => $om->to_oid($id), $ids) ] ]), false) : [];
	$bases = $texts ? iterator_to_array($om->find('KnowledgeBase', $home + [ 'name' => [ '$in' => $texts ] ]), false) : [];
	preg_match_all('/\b' . \models\credential\CredentialVariable::EXPRESSION_NAME . '\.([A-Za-z_]\w*)/', implode("\n", $texts), $m);
	$variables = $m[1] ? iterator_to_array($om->find('CredentialVariable', $home + [ 'name' => [ '$in' => array_values(array_unique($m[1])) ] ]), false) : [];

	$used = [];
	foreach([ 'credentials' => $credentials, 'data tables' => $tables, 'workflows' => $workflows, 'knowledge bases' => $bases, 'variables' => $variables ] as $kind => $list) {
	    if ($list)
		$used[] = $kind . ': ' . implode(', ', array_values(array_unique(array_map(fn($o) => (string)$o->name, $list))));
	}
	return $used;
    }

    //the copy of this workflow that a call from project $projectId runs: its published version; in its own project, else its draft. null: a shared workflow not published (now)
    public function copyFor($projectId) {
	return $this->liveCopy() ?: ($this->isHome($projectId) ? $this : null);
    }


    //the workflow's own rules, then every step's validates() (rules in the node class, e.g. the webhook token);
    //step errors are reported on `nodes`, prefixed with the step title; unknown step types are left to validateFlow()
    public function validates($options = []) {
	$valid = parent::validates($options);
	foreach($this->validateSteps(true) as $issue) {
	    $this->errors('nodes', $issue['message']);
	    $valid = false;
	}
	return $valid;
    }

    //saving a draft from the designer: the workflow's own rules only; the steps' configs are normalized, their errors are kept in
    //$stepIssues (not errors()): a draft may be saved with them, it cannot be published or run (issues())
    public function validatesDraft($options = []) {
	$valid = parent::validates($options);
	$this->stepIssues = $this->validateSteps(true);
	return $valid;
    }

    //the problems in the steps' settings, as validateFlow() issues: [ [ 'level' => 'error', 'node' => wfid, 'message' => '<step title>: ...' ], ... ]
    //$normalize: each step's config as normalized by its config form goes back into `nodes`
    public function validateSteps($normalize = false) {
	$issues = [];
	$defs = \models\wf\WfNode::getAll();
	//each step's config as normalized by its config form (cast values, defaults) goes back into `nodes`
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
	    //`vars` is the project variables ({{ vars.<name> }}): no step may set a variable of that name
	    $class = get_class($node);
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

    //everything that stops publishing / running: the steps' settings (validateSteps) and the connections (validateFlow)
    public function issues() {
	return array_merge($this->validateSteps(), $this->validateFlow());
    }

    //generic save hooks (the webhook token / index logic: plugins\Webhook\Plugin);
    //handlers receive the workflow and may change it in workflow:before-save
    public function beforeSave() {
	//a version copy (forVersion) holds a version's steps: saving it would overwrite the draft
	if ($this->runVersion !== null)
	    throw new \LogicException('a copy of a published version cannot be saved');
	parent::beforeSave();
	\V::app()->hooks->applyHook('workflow:before-save', [ $this ]);
    }

    public function afterSave() {
	parent::afterSave();
	\V::app()->hooks->applyHook('workflow:after-save', [ $this ]);
    }

    //deleting a workflow deletes its executions and published versions; plugins delete their own data in workflow:before-delete
    //(e.g. the webhook index, the IMAP check state). Scheduler jobs queued before are skipped (Scheduler::run)
    public function beforeDelete() {
	if ($this->runVersion !== null)
	    throw new \LogicException('a copy of a published version cannot be deleted');

	parent::beforeDelete();
	\V::app()->hooks->applyHook('workflow:before-delete', [ $this ]);

	//through ObjectManager: each deleted with its model (only the ids are read; both are kept out of the logs)
	//its test cases and test runs
	foreach([ 'WfTestCase', 'WfTestRun', 'WfExecution', 'WfVersion' ] as $objectName) {
	    foreach(\V::app()->om->find($objectName, [ 'wf' => $this->_id ], [ 'projection' => [ '_id' => 1, 'aid' => 1 ] ]) as $o)
		$o->delete();
	}
    }

    //the version set in `version` (the active one while published), null when never published
    public function publishedVersion() {
	if (empty($this->version))
	    return null;
	return \V::app()->om->findOne('WfVersion', [ '_id' => $this->version, 'wf' => $this->_id, 'aid' => $this->aid ]);
    }

    //a copy of this workflow holding the steps of $version (runVersion set): what triggers validate and start, so the
    //code that runs a workflow (validateFlow, WfExecution::startFor, ...) needs no version logic; the copy cannot be saved
    public function forVersion($version) {
	$copy = \V::app()->om->findOne('Workflow', [ '_id' => $this->_id, 'aid' => $this->aid ]);
	if (empty($copy))
	    return null;
	$copy->nodes = is_array($version->nodes) ? $version->nodes : [];
	$copy->runVersion = $version;
	return $copy;
    }

    //the published version's copy (forVersion) when the workflow is published, else null: what its triggers run
    public function liveCopy() {
	if ($this->status !== self::PUBLISHED)
	    return null;
	$version = $this->publishedVersion();
	return $version ? $this->forVersion($version) : null;
    }

    //the audit log gets a summary of the steps (titles; counts), not the steps: they are large and their
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

    //whether the saved draft has the same steps as $version (nothing new to publish)
    public function draftMatches($version) {
	return \util\JSON::encode(is_array($this->nodes) ? $this->nodes : []) === \util\JSON::encode(is_array($version->nodes) ? $version->nodes : []);
    }

    //the number of a new version (atomic, so two publishes never get the same number)
    public function nextVersionNumber() {
	$wf = \V::app()->om->findOneAndUpdate('Workflow', [ '_id' => $this->_id ], [ '$inc' => [ 'version_count' => 1 ] ], [ 'projection' => [ 'version_count' => 1 ] ]);
	$this->version_count = (int)($wf->version_count ?? 0);
	return $this->version_count;
    }

    //how many steps deep a variable's type is looked for through `from` (Set steps copying Set steps; variablesAt)
    const MAX_TYPE_DEPTH = 8;

    //checks how the steps are connected; returns [ [ 'level' => error|warning, 'node' => wfid|null, 'message' => ... ], ... ]
    //errors: the workflow cannot run correctly; warnings: allowed, probably unintended
    //the variables a step can use (expression help): those set by the steps that can run before it (following the connections back; in a loop, also
    //the steps before the loop) and, inside a loop, the loop's own (innerVariables). $nodes: the steps and connections (the designer's current state, a list or a
    //map). [ name => [ type, info ] ]; info says where it comes from. $depth: how deep the type of a `from` variable is being looked for (at most MAX_TYPE_DEPTH)
    public static function variablesAt($nodes, $wfid, $depth = 0) {
	$defs = \models\wf\WfNode::getAll();
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

	$vars = [];

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
		if ($class = self::nodeClassFrom($defs, $byId[$parent]))
		    self::addVariables($vars, $class::innerVariables($byId[$parent]['config'] ?? []), $byId[$parent], $nodes, $depth);
	    }
	    foreach($from[$id] ?? [] as $prev) {
		if (isset($seen[$prev]) || !isset($byId[$prev]))
		    continue;
		$seen[$prev] = true;
		$queue[] = $prev;
		if ($class = self::nodeClassFrom($defs, $byId[$prev]))
		    self::addVariables($vars, $class::outputVariables($byId[$prev]['config'] ?? []), $byId[$prev], $nodes, $depth);
	    }
	}
	ksort($vars);
	return $vars;
    }

    //the class of step $n among the step definitions $defs (null: unknown)
    private static function nodeClassFrom($defs, $n) {
	$def = $defs[$n['fqname'] ?? ''] ?? null;
	return $def ? \V::app()->om->getObjectClass($def['objectName']) : null;
    }

    //the variables $list of step $n added to $vars (those not there yet), with the whole type description (+ fields, items: util\Expression::typeAt) and its title in their info;
    //`from` (a value text, e.g. the Set step's) / `itemsOf` resolved against the variables at its step (util\Expression::typeOf; nested at most MAX_TYPE_DEPTH steps deep)
    private static function addVariables(array &$vars, array $list, $n, $nodes, $depth) {
	$title = trim((string)($n['title'] ?? ''));
	foreach($list as $name => $v) {
	    if (isset($vars[$name]))
		continue;
	    if (isset($v['from']))
		$v = array_merge($depth < self::MAX_TYPE_DEPTH ? \util\Expression::typeOf($v['from'], self::variablesAt($nodes, (string)$n['wfid'], $depth + 1)) : [ 'type' => 'any' ], array_intersect_key($v, [ 'info' => 1 ]));
	    //`itemsOf` (a list's value text, e.g. the Loop's item): the type of that list's items (unknown: any)
	    if (isset($v['itemsOf'])) {
		$items = $depth < self::MAX_TYPE_DEPTH ? \util\Expression::typeOf($v['itemsOf'], self::variablesAt($nodes, (string)$n['wfid'], $depth + 1)) : [];
		$v = array_merge(is_array($items['items'] ?? null) ? $items['items'] : [ 'type' => 'any' ], array_intersect_key($v, [ 'info' => 1 ]));
	    }
	    unset($v['from'], $v['itemsOf']);
	    $vars[$name] = [ 'type' => $v['type'] ?? 'any', 'info' => trim(($v['info'] ?? '') . ($title !== '' ? " (step \"{$title}\")" : '')) ] + array_intersect_key($v, [ 'fields' => 1, 'items' => 1 ]);
	}
    }

    public function validateFlow() {
	$defs = \models\wf\WfNode::getAll();
	$issues = [];

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
		self::addIssue($issues, 'error', $id, 'Unknown step type');
		continue;
	    }
	    //a step this server cannot run (WfNode::available: e.g. Email (inbound) without Haraka, a database without its PDO driver): the workflow cannot be published or run
	    if (!\V::app()->om->getObjectClass($d['objectName'])::available())
		self::addIssue($issues, 'error', $id, "{$d['label']} is not available on this server");
	    //the step's own outputs when they follow its config (e.g. Switch cases; keep in sync with outPortsOf() in app.workflow.js)
	    if (!empty($d['outPorts'])) {
		$class = \V::app()->om->getObjectClass($d['objectName']);
		$d['outLabels'] = $class::outLabelsFor($n['config'] ?? null);
		//outputCountFor (labels may be null: the class's own outputs)
		$d['outputs'] = $class::outputCountFor($n['config'] ?? null);
	    }
	    $def[$id] = $d;
	}


	//exactly one trigger
	$triggers = array_keys(array_filter($def, fn($d) => $d['type'] == 'trigger'));
	if (empty($triggers))
	    self::addIssue($issues, 'error', null, 'The workflow has no trigger');
	foreach(array_slice($triggers, 1) as $id)
	    self::addIssue($issues, 'error', $id, 'Only one trigger is allowed');
	//a loop cannot be inside another loop (keep in sync with validateFlow() in app.workflow.js)
	foreach($def as $id => $d) {
	    if (!empty($d['isContainer']) && self::loopOf($nodes, $def, $id) !== null)
		self::addIssue($issues, 'error', $id, 'A loop cannot be inside another loop');
	}
	//a trigger cannot be inside a loop (keep in sync with validateFlow() in app.workflow.js)
	foreach($triggers as $id) {
	    $parent = $nodes[$id]['parentId'] ?? null;
	    if (!empty($parent) && !empty($def[$parent]['isContainer']))
		self::addIssue($issues, 'error', $id, 'A trigger cannot be inside a loop');
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
		self::addIssue($issues, 'error', isset($def[$from]) ? $from : (isset($def[$to]) ? $to : null), 'A connection points to a missing step');
		continue;
	    }
	    $fd = $def[$from];
	    $td = $def[$to];
	    if (!empty($fd['isNote']) || !empty($td['isNote'])) {
		self::addIssue($issues, 'error', !empty($fd['isNote']) ? $from : $to, 'Notes cannot be connected');
		continue;
	    }
	    if ($td['inputs'] == 0) {
		self::addIssue($issues, 'error', $to, 'This step cannot have incoming connections');
		continue;
	    }
	    //connections stay on their level (a loop's own input / output belong to the level around it)
	    if (self::loopOf($nodes, $def, $from) !== self::loopOf($nodes, $def, $to)) {
		self::addIssue($issues, 'error', $from, "A connection cannot cross a loop's border");
		continue;
	    }

	    $fromPort = $e['fromPort'] ?? null;
	    $toPort = $e['toPort'] ?? null;
	    if (!is_numeric($fromPort) || $fromPort < 0 || $fromPort >= $fd['outputs']) {
		self::addIssue($issues, 'error', $from, 'A connection leaves from an output this step does not have');
		continue;
	    }
	    if (!is_numeric($toPort) || $toPort < 0 || $toPort >= $td['inputs']) {
		self::addIssue($issues, 'error', $to, 'A connection arrives at an input this step does not have');
		continue;
	    }

	    $key = "{$from}|{$fromPort}|{$to}|{$toPort}";
	    if (isset($seen[$key])) {
		self::addIssue($issues, 'error', $from, 'Duplicate connection');
		continue;
	    }
	    $seen[$key] = true;

	    if ($from == $to)
		self::addIssue($issues, 'warning', $from, 'This step is connected to itself');

	    $adjacent[$from][] = $to;
	    $inputsUsed[$to][(int)$toPort] = true;
	    $outputsUsed[$from][(int)$fromPort] = true;
	}

	//computed for every level
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
	    //flag only where a disconnected chain starts (no incoming connection), not every step after it;
	    //steps of a disconnected cycle (all have incoming connections, none starts the chain) are flagged too; top-level steps only (the steps inside a loop are
	    //checked from the loop's starting step, below)
	    $unreached = [];
	    foreach($def as $id => $d) {
		if (empty($d['isNote']) && self::loopOf($nodes, $def, $id) === null && !isset($reached[$id]))
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
		    self::addIssue($issues, 'error', $id, 'Not connected to the trigger');
	    }
	}

	//the inside of each loop is a flow of its own: each pass starts at its one step without an incoming connection, every step inside
	//must be reachable from it; notes and triggers (an error of their own) are left out
	foreach($def as $loopId => $loopDef) {
	    if (empty($loopDef['isContainer']))
		continue;
	    $inner = array_keys(array_filter($def, fn($d, $id) => empty($d['isNote']) && $d['type'] != 'trigger' && self::loopOf($nodes, $def, $id) === $loopId, ARRAY_FILTER_USE_BOTH));
	    if (empty($inner)) {
		self::addIssue($issues, 'warning', $loopId, 'The loop is empty');
		continue;
	    }
	    $starts = array_values(array_filter($inner, fn($id) => empty($hasIncoming[$id])));
	    if (empty($starts)) {
		self::addIssue($issues, 'error', $loopId, 'The loop has no starting step: every step inside has an incoming connection');
		continue;
	    }
	    if (count($starts) > 1) {
		foreach($starts as $id)
		    self::addIssue($issues, 'error', $id, 'Only one step inside a loop may have no incoming connection: each pass starts there');
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
		    self::addIssue($issues, 'error', $id, "Not connected to the loop's starting step");
	    }
	}

	//a Chat trigger with no Reply to chat: the person would get no answer
	if (array_filter($def, fn($d) => ($d['type'] ?? '') === 'trigger' && ($d['name'] ?? '') === 'chat') && !array_filter($def, fn($d) => ($d['name'] ?? '') === 'chatreply'))
	    self::addIssue($issues, 'warning', null, 'A Chat trigger needs a Reply to chat step to answer');

	//several outputs (If): every output should lead somewhere; several inputs (Merge): every input should be fed
	foreach($def as $id => $d) {
	    if (!empty($d['isNote']))
		continue;
	    if ($d['outputs'] > 1) {
		for ($p = 0; $p < $d['outputs']; $p++) {
		    if (empty($outputsUsed[$id][$p]))
			self::addIssue($issues, 'warning', $id, "Output `" . ($d['outLabels'][$p] ?? $p + 1) . "` is not connected");
		}
	    }
	    if ($d['inputs'] > 1) {
		$used = count($inputsUsed[$id] ?? []);
		if ($used < $d['inputs'])
		    self::addIssue($issues, 'warning', $id, "Only {$used} of {$d['inputs']} inputs are connected");
	    }
	    //an AI step whose spending cannot be counted (it may still run)
	    $class = \V::app()->om->getObjectClass($d['objectName']);
	    if (is_subclass_of($class, \models\node\NodeAppAi::class) && ($warning = \util\AgentGuard::priceWarning($class::CAPABILITY, (array)($nodes[$id]['config'] ?? []), $this->aid)))
		self::addIssue($issues, 'warning', $id, $warning);
	}

	return $issues;
    }

    //a problem of validateFlow(): $level (error / warning), the step it is about (null: the workflow), $message
    private static function addIssue(array &$issues, $level, $node, $message) {
	$issues[] = [ 'level' => $level, 'node' => $node, 'message' => $message ];
    }

    //the level of step $id: the loop it is in (its parent, when that is a container), null at the top (keep in sync with validateFlow() in app.workflow.js)
    private static function loopOf($nodes, $def, $id) {
	$parent = $nodes[$id]['parentId'] ?? null;
	return (!empty($parent) && !empty($def[$parent]['isContainer'])) ? $parent : null;
    }

    public function __toString() {
	return $this->name ?? '';
    }
}
