<?php

namespace models;

/*
    past the trigger and beforeSave dropped the edges), node receive()/execute() contract (see WfNode),
    output ports followed per edge (If takes one branch),
    Merge joins, re-entrant nodes with a step limit, per-execution variables and log
*/

class WfExecution extends \sys\BaseModel {
    protected static $collection = 'executions';

    public static $indexes = [
	[ [ 'wf' => 1, '_id' => -1 ], [] ],
	[ [ 'state' => 1, 'wake_at' => 1 ], [] ],
    ];


    //node executions allowed per run() call (protects against endless loops)
    const MAX_STEPS = 10000;
    //CLD: 2026-10-23T00:40:00+03:00: a running execution is saved after a pass at most this often (s), so its progress shows (run())
    const SAVE_EVERY = 2;

    //log entries kept per execution
    const MAX_LOG = 500;

    //runtime objects, keyed by wfid
    public $__nodes = [];
    public $__edges = [];

    //node executions in the current run()
    private $steps = 0;
    //CLD: 2026-10-13T13:15:14+03:00: step executions in the current pass of each active loop (loop wfid => count; reset when a pass starts)
    private $passSteps = [];

    public function _init() {
	parent::_init();

	//CLD: 2026-10-18T11:40:00+03:00: the step classes only (WfNode::nodeClasses; was WfNode::getAll(): every step's definition and config form, rebuilt for each execution loaded,
	//e.g. ~2 s for the Overview's executions)
	$nodeClasses = \models\WfNode::nodeClasses();

	if (!is_array($this->tokens))
	    $this->tokens = [];
	if (!is_array($this->nodes))
	    $this->nodes = [];
	if (!is_array($this->variables))
	    $this->variables = [];
	if (!is_array($this->log))
	    $this->log = [];

	//build nodes and edges; the designer saves a list or a map, the key is always the element's wfid
	foreach($this->nodes as $data) {
	    $wfid = $data['wfid'] ?? null;
	    if (empty($wfid))
		continue;

	    if (!empty($data['isNode'])) {
		$objectName = $nodeClasses[$data['fqname'] ?? ''] ?? null;
		if (empty($objectName))
		    continue; //unknown node type: it will never receive a token (and fails the run if it does)
		$this->__nodes[$wfid] = \V::app()->om->create($objectName, $data);
	    } elseif (!empty($data['isEdge'])) {
		$this->__edges[$wfid] = \V::app()->om->create('WfEdge', $data);
	    }
	}

	//a new execution starts at its trigger node(s)
	if ($this->state == 'new' && empty($this->tokens)) {
	    $tokens = [];
	    foreach($this->__nodes as $wfid => $node) {
		if ($node::$type == 'trigger')
		    $tokens[] = [ 'node' => $wfid, 'edge' => null ];
	    }
	    $this->tokens = $tokens;
	}
    }

    //an execution of workflow $wf, ready to run (not saved)
    //CLD: 2026-10-07T11:28:54+03:00: + $trigger (manual, webhook, ...) and $input (what started it, e.g. the webhook request); top-level simple
    //values of $input['vars'] become the first variables
    //CLD: 2026-10-08T08:57:32+03:00: + $startNode: wfid of the trigger step that started it; only that step gets a token (default: all trigger steps)
    public static function startFor($wf, $trigger = 'manual', $input = null, $startNode = null) {
	//CLD: 2026-10-22T23:00:00+03:00: none for a suspended account
	if (\models\Account::isSuspended($wf->aid))
	    throw new \ErrorException('the account is suspended');
	$data = [
	    'aid' => $wf->aid,
	    'project' => $wf->project,
	    'wf' => $wf->_id,
	    'nodes' => is_array($wf->nodes) ? array_values($wf->nodes) : [],
	    'trigger' => $trigger,
	    'input' => $input,
	    //CLD: 2026-10-11T13:51:42+03:00: the published version it runs (a Workflow::forVersion copy), null = the draft (designer Run, wf::run)
	    'version' => $wf->runVersion ? (int)$wf->runVersion->number : null,
	];
	if ($startNode !== null)
	    $data['tokens'] = [ [ 'node' => (string)$startNode, 'edge' => null ] ];
	$exe = \V::app()->om->create('WfExecution', $data);
	foreach((is_array($input['vars'] ?? null) ? $input['vars'] : []) as $name => $value)
	    $exe->setVariable($name, $value);
	return $exe;
    }

    public function beforeSave() {
	parent::beforeSave();

	//nodes and edges back into `nodes`, with their runtime state
	$nodes = [];
	foreach($this->__nodes as $wfid => $node)
	    $nodes[$wfid] = $node->data();
	foreach($this->__edges as $wfid => $edge)
	    $nodes[$wfid] = $edge->data();
	$this->nodes = $nodes;
    }

    public function getSchema() {
	$fields = [
	    //workflow
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'help' => false,
	    ],
	    //project (of the workflow)
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project',
		'help' => false,
	    ],
	    //nodes: snapshot of the workflow's nodes and edges, with their runtime state, keyed by wfid
	    [
		'key' => 'nodes',
		'type' => 'array',
		'label' => 'Nodes',
		'help' => false,
	    ],
	    //tokens: [ [ node => wfid, edge => wfid of the edge it came through | null ], ... ]
	    [
		'key' => 'tokens',
		'type' => 'array',
		'label' => 'Tokens',
		'help' => false,
	    ],
	    //CLD: 2026-10-07T11:28:54+03:00: how the execution was started: manual, webhook, ...
	    [
		'key' => 'trigger',
		'type' => 'text',
		'label' => 'Trigger',
		'help' => false,
		'default' => 'manual',
	    ],
	    //CLD: 2026-10-11T13:51:42+03:00: number of the published version it ran; empty: the draft
	    [
		'key' => 'version',
		'type' => 'number',
		'label' => 'Version',
		'help' => false,
	    ],
	    //CLD: 2026-10-07T11:28:54+03:00: what started it (e.g. the webhook request: method, query, body, ...)
	    [
		'key' => 'input',
		'type' => 'array',
		'label' => 'Input',
		'help' => false,
	    ],
	    //variables set by the nodes (Set), read by others (If)
	    [
		'key' => 'variables',
		'type' => 'array',
		'label' => 'Variables',
		'help' => false,
	    ],
	    //log: [ [ ts, node, message ], ... ]
	    [
		'key' => 'log',
		'type' => 'array',
		'label' => 'Log',
		'help' => false,
	    ],
	    //state
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'default' => 'new',
		'choices' => [
		    'new' => 'New',
		    'run' => 'Run',
		    'end' => 'Completed',
		    'cancel' => 'Cancel',
		    'error' => 'Error',
		    'sleep' => 'Sleep',
		]
	    ],
	    //error message when state = error
	    [
		'key' => 'error',
		'type' => 'text',
		'label' => 'Error',
		'help' => false,
	    ],
	    //when a sleeping execution should be resumed (earliest Wait)
	    [
		'key' => 'wake_at',
		'type' => 'datetime',
		'label' => 'Wake at',
	    ],
	    //start_at
	    [
		'key' => 'start_at',
		'type' => 'datetime',
		'label' => 'Start at',
	    ],
	    //end_at
	    [
		'key' => 'end_at',
		'type' => 'datetime',
		'label' => 'End at',
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //---------------------------------------------------------------------------------------------------------------
    //variables and log (used by the nodes)

    //CLD: 2026-10-13T15:24:28+03:00: the names expressions see: the execution's variables and `vars` = the project variables (models\Variable; loaded once per run, never saved with the
    //execution: a resumed run sees their current values)
    private $projectVars = null;

    //CLD: 2026-10-22T00:28:13+03:00: the project variables are credentials of type Variable (models\credential\CredentialVariable; was models\Variable)
    public function expressionVariables() {
	if ($this->projectVars === null)
	    $this->projectVars = \models\credential\CredentialVariable::expressionValues($this->aid, $this->project);
	return array_merge(is_array($this->variables) ? $this->variables : [], [ \models\credential\CredentialVariable::EXPRESSION_NAME => $this->projectVars ]);
    }

    public function getVariable($name, $default = null) {
	$vars = $this->variables;
	return array_key_exists($name, $vars) ? $vars[$name] : $default;
    }

    public function setVariable($name, $value) {
	$vars = $this->variables;
	$vars[$name] = $value;
	$this->variables = $vars;
    }

    public function log($nodeId, $message) {
	$log = $this->log;
	$log[] = [ 'ts' => new \MongoDB\BSON\UTCDateTime(), 'node' => $nodeId, 'message' => (string)$message ];
	if (count($log) > self::MAX_LOG)
	    $log = array_slice($log, -self::MAX_LOG);
	$this->log = $log;
    }

    //---------------------------------------------------------------------------------------------------------------
    //running

    public function canRun() {
	return in_array($this->state, [ 'new', 'run', 'sleep' ]);
    }

    //run until the execution ends, fails, or only sleeping tokens are left; saves the execution
    //CLD: 2026-10-11T17:45:25+03:00: the execution log: changes made while running are recorded there (ExecutionLog::$execution, also when a user clicked Run), with
    //execution.start (a new execution; saved first, so the entry has its id) and how the run stopped (end / error / sleep)
    public function run() {
	$outer = [ \models\ExecutionLog::$execution, \models\ExecutionLog::$step ];
	\models\ExecutionLog::$execution = $this;
	\models\ExecutionLog::$step = null;
	try {
	    if ($this->state == 'new') {
		if (!$this->exists())
		    $this->save();
		\models\ExecutionLog::record('execution.start', null, 'trigger: ' . $this->trigger . ($this->version ? ', v' . (int)$this->version : ', draft'));
	    }

	    //CLD: 2026-10-23T00:40:00+03:00: saved as running at once (was: `new` in the database until the whole run ended - minutes with a long AI step), then after the passes,
	    //at most every SAVE_EVERY s (the designer's Executions panel shows the steps as they run)
	    if ($this->state == 'new') {
		$this->state = 'run';
		if (empty($this->start_at))
		    $this->start_at = new \MongoDB\BSON\UTCDateTime();
		$this->save();
	    }
	    $saved = microtime(true);

	    $this->steps = 0;
	    while ($this->canRun()) {
		//CLD: 2026-10-17T19:29:50+03:00: keep the sync slot while running
		//CLD: 2026-10-18T16:05:00+03:00: ExecutionSlot::renew (was self::renewSlot)
		\models\ExecutionSlot::renew();
		$progress = $this->advance();
		if (!$progress)
		    break;
		if ($this->state == 'run' && microtime(true) - $saved >= self::SAVE_EVERY) {
		    $this->save();
		    $saved = microtime(true);
		}
	    }
	    $this->save();

	    \models\ExecutionLog::$step = null;
	    switch($this->state) {
		case 'end':
		    \models\ExecutionLog::record('execution.end', null, $this->steps . ' steps');
		    //CLD: 2026-10-15T11:54:00+03:00: plugins act on a completed run (e.g. the Email inbound trigger deletes the raw message)
		    \V::app()->hooks->applyHook('execution:end', [ $this ]);
		    break;
		case 'error':
		    \models\ExecutionLog::record('execution.error', null, (string)$this->error);
		    break;
		case 'sleep':
		    $wake = $this->wake_at instanceof \MongoDB\BSON\UTCDateTime ? $this->wake_at->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') : 'unknown';
		    \models\ExecutionLog::record('execution.sleep', null, 'waiting until ' . $wake);
		    break;
	    }
	} finally {
	    [ \models\ExecutionLog::$execution, \models\ExecutionLog::$step ] = $outer;
	}
	return $this->state;
    }

    //---------------------------------------------------------------------------------------------------------------
    //CLD: 2026-10-17T19:29:50+03:00: sync executions (run inside a web request): at most app.executions.max_sync at the same time per account
    //CLD: 2026-10-18T16:05:00+03:00: the slots are models\ExecutionSlot (acquire / release / renew; were SLOTS_COLLECTION, acquireSlot, releaseSlot, renewSlot here)

    //run as a sync execution: in this process when a slot is free, else handed to the daemon (Scheduler::start); returns the state, `queued` when handed over
    //CLD: 2026-10-18T16:05:00+03:00: ExecutionSlot::acquire / release (were self::acquireSlot / releaseSlot)
    public function runSync() {
	if (!\models\ExecutionSlot::acquire($this->aid)) {
	    $this->queue();
	    return 'queued';
	}

	try {
	    return $this->run();
	} finally {
	    \models\ExecutionSlot::release();
	}
    }

    //hand the run to the daemon (saved first)
    public function queue() {
	$max = (int)\V::app()->config('app.executions.max_sync');
	$this->log(null, "queued for the daemon: {$max} executions of the account were already running");
	$this->save();
	\models\Job::submit('\util\Scheduler::start', [ 'exe' => (string)$this->_id ], [
	    'aid' => $this->aid,
	    'project' => $this->project,
	    'delete_on_done' => true,
	]);
    }

    //one pass over the current tokens; true if any node completed (more work may follow)
    public function advance() {
	if (!$this->canRun())
	    throw new \ErrorException('state does not permit running');

	if ($this->state == 'new') {
	    $this->state = 'run';
	    if (empty($this->start_at))
		$this->start_at = new \MongoDB\BSON\UTCDateTime();
	}

	$pending = $this->tokens;
	$tokens = [];
	$progress = false;
	//CLD: 2026-10-13T12:43:29+03:00: loops whose pass was given up in this pass over the tokens (a step inside failed, the loop skips the item): their other tokens are dropped
	$aborted = [];

	foreach($pending as $token) {
	    $nodeId = $token['node'] ?? null;
	    $node = $this->__nodes[$nodeId] ?? null;
	    if (empty($node))
		return $this->fail($nodeId, 'node not found or of an unknown type');
	    if ($aborted && $this->insideAny($nodeId, $aborted))
		continue;

	    //CLD: 2026-10-13T13:15:14+03:00: inside a loop: counted for its pass (not for the run)
	    $loop = $this->activeLoop($nodeId);
	    if ($loop !== null) {
		$this->passSteps[$loop->wfid] = ($this->passSteps[$loop->wfid] ?? 0) + 1;
		if ($this->passSteps[$loop->wfid] > self::MAX_STEPS)
		    return $this->fail($nodeId, 'too many steps in one pass of the loop `' . ($loop->title ?? $loop->wfid) . '` (endless cycle?)');
	    } elseif (++$this->steps > self::MAX_STEPS) {
		return $this->fail($nodeId, 'too many steps (endless loop?)');
	    }

	    $node->exec = $this;
	    //CLD: 2026-10-11T17:45:25+03:00: the step making changes now (execution log)
	    \models\ExecutionLog::$step = (string)($node->title ?? $nodeId);
	    try {
		$result = $node->receive($token['edge'] ?? null);
	    } catch (\Throwable $e) {
		$node->state = 'fail';
		//CLD: 2026-10-13T12:43:29+03:00: inside a loop that skips failed items: the pass ends, the workflow goes on
		if (($loop = $this->skippingLoop($nodeId)) !== null) {
		    $this->abortPass($loop, (string)($node->title ?? $nodeId), $e->getMessage());
		    $aborted[] = $loop->wfid;
		    $tokens = array_values(array_filter($tokens, fn($t) => !$this->inside((string)$t['node'], $loop->wfid)));
		    $progress = true;
		    continue;
		}
		return $this->fail($nodeId, $e->getMessage());
	    }

	    if ($result === WfNode::SLEEP) {
		$tokens[] = $token; //try again later
		continue;
	    }
	    if (!is_array($result))
		continue;           //taken, nothing continues (e.g. Merge waiting for other inputs)

	    $progress = true;
	    $tokens = array_merge($tokens, $this->tokensFrom($nodeId, $result));
	}

	//CLD: 2026-10-13T12:43:29+03:00: loops: a pass ends when nothing runs inside the loop any more; the next one starts, or the loop continues on its output
	$tokens = $this->stepLoops($tokens, $progress);
	if ($this->state === 'error')
	    return false;

	//nothing else can arrive: release Merge nodes still waiting for inputs (e.g. If -> two branches -> Merge)
	if (empty($tokens)) {
	    foreach($this->__nodes as $wfid => $node) {
		if ($node->state == 'wait' && method_exists($node, 'release')) {
		    $node->exec = $this;
		    $result = $node->release();
		    if (is_array($result)) {
			$progress = true;
			$tokens = array_merge($tokens, $this->tokensFrom($wfid, $result));
		    }
		}
	    }
	}

	$this->tokens = $tokens;

	if (empty($tokens)) {
	    $this->state = 'end';
	    $this->end_at = new \MongoDB\BSON\UTCDateTime();
	    $this->wake_at = null;
	    $this->log(null, 'completed');
	} elseif (!$progress) {
	    //only sleeping tokens left
	    $this->state = 'sleep';
	    $this->wake_at = $this->earliestWake();
	} else {
	    $this->state = 'run';
	}
	return $progress;
    }

    //CLD: 2026-10-13T12:43:29+03:00: the active loops (deepest first) whose pass is over (no token inside, no active loop inside, after releasing the Merge steps waiting inside): next()
    //starts the next pass (a token for the loop's start step) or ends the loop (tokens on its output); a failing next() ends the pass of the loop around it (when that one skips
    //failed items) or fails the execution
    //CLD: 2026-10-14T21:17:55+03:00: no loop inside a loop (Workflow::validateFlow): no ordering by depth, no active loop inside to wait for, a failing next() fails the execution
    private function stepLoops(array $tokens, &$progress) {
	$loops = array_filter($this->__nodes, fn($n) => !empty($n->active) && method_exists($n, 'next'));
	foreach($loops as $id => $loop) {
	    $id = (string)$id;
	    if (empty($loop->active))
		continue;
	    $busy = false;
	    foreach($tokens as $t)
		$busy = $busy || $this->inside((string)$t['node'], $id);
	    if ($busy)
		continue;

	    //Merge steps waiting inside: nothing else arrives in this pass
	    $released = [];
	    foreach($this->__nodes as $wfid => $n) {
		if ($n->state == 'wait' && method_exists($n, 'release') && $this->inside((string)$wfid, $id)) {
		    $n->exec = $this;
		    $result = $n->release();
		    if (is_array($result))
			$released = array_merge($released, $this->tokensFrom($wfid, $result));
		}
	    }
	    if ($released) {
		$tokens = array_merge($tokens, $released);
		$progress = true;
		continue;
	    }

	    $loop->exec = $this;
	    \models\ExecutionLog::$step = (string)($loop->title ?? $id);
	    $this->passSteps[$id] = 0;
	    try {
		$result = $loop->next();
		$start = $this->innerStart($id);
		//an empty loop: its passes do nothing
		while ($result === 'enter' && $start === null)
		    $result = $loop->next();
	    } catch (\Throwable $e) {
		$loop->state = 'fail';
		$loop->active = false;
		$this->fail($id, $e->getMessage());
		return $tokens;
	    }
	    $progress = true;
	    if ($result === 'enter')
		$tokens[] = [ 'node' => $start, 'edge' => null ];
	    elseif (is_array($result))
		$tokens = array_merge($tokens, $this->tokensFrom($id, $result));
	}
	return $tokens;
    }

    //the start step of a loop's passes: the step inside it (not a note) without an incoming connection; null for an empty loop (validateFlow requires exactly one)
    private function innerStart($loopId) {
	foreach($this->__nodes as $wfid => $n) {
	    if ((string)($n->parentId ?? '') === (string)$loopId && !$n::$isNote && !$this->incomingEdges($wfid))
		return (string)$wfid;
	}
	return null;
    }

    //whether step $nodeId is inside loop $loopId
    //CLD: 2026-10-14T21:17:55+03:00: its parent (a loop cannot be inside another loop; was any depth)
    private function inside($nodeId, $loopId) {
	return (string)($this->__nodes[$nodeId]->parentId ?? '') === (string)$loopId;
    }

    private function insideAny($nodeId, array $loopIds) {
	foreach($loopIds as $id) {
	    if ($this->inside($nodeId, $id))
		return true;
	}
	return false;
    }

    //CLD: 2026-10-13T13:15:14+03:00: the innermost active loop around step $nodeId (null: none)
    //CLD: 2026-10-14T21:17:55+03:00: its parent, when that is an active loop (a loop cannot be inside another loop; was searched through the loops around it)
    private function activeLoop($nodeId) {
	$loop = $this->__nodes[(string)($this->__nodes[$nodeId]->parentId ?? '')] ?? null;
	return $loop !== null && !empty($loop->active) && method_exists($loop, 'next') ? $loop : null;
    }

    //the innermost active loop around step $nodeId, when it skips failed items (else null: the failure stops the workflow)
    //CLD: 2026-10-13T13:15:14+03:00: through activeLoop
    private function skippingLoop($nodeId) {
	$loop = $this->activeLoop($nodeId);
	return $loop !== null && $loop->cfg('onError', 'stop') === 'skip' ? $loop : null;
    }

    //gives up the current pass of $loop: the failure listed by the loop, the steps inside reset (waiting Merge, sleeping Wait, active loops)
    //CLD: 2026-10-14T21:17:55+03:00: no active loop inside to reset (a loop cannot be inside another loop)
    private function abortPass($loop, $stepTitle, $message) {
	$loop->exec = $this;
	$loop->skip($stepTitle, $message);
	foreach($this->__nodes as $wfid => $n) {
	    if (!$this->inside((string)$wfid, $loop->wfid))
		continue;
	    if (in_array($n->state, [ 'wait', 'sleep' ], true))
		$n->state = 'ready';
	    if (!empty($n->arrived))
		$n->arrived = [];
	    if (!empty($n->resume_at))
		$n->resume_at = null;
	}
    }

    //tokens for the edges leaving $nodeId on the given output ports
    private function tokensFrom($nodeId, array $ports) {
	$tokens = [];
	foreach($this->__edges as $edgeId => $edge) {
	    if ($edge->fromNode == $nodeId && in_array((int)$edge->fromPort, $ports, true))
		$tokens[] = [ 'node' => $edge->toNode, 'edge' => $edgeId ];
	}
	return $tokens;
    }

    //incoming edges of a node (used by Merge)
    public function incomingEdges($nodeId) {
	$edges = [];
	foreach($this->__edges as $edgeId => $edge) {
	    if ($edge->toNode == $nodeId)
		$edges[] = $edgeId;
	}
	return $edges;
    }

    private function earliestWake() {
	$earliest = null;
	foreach($this->__nodes as $node) {
	    if ($node->state == 'sleep' && $node->resume_at instanceof \MongoDB\BSON\UTCDateTime) {
		if ($earliest === null || (int)(string)$node->resume_at < (int)(string)$earliest)
		    $earliest = $node->resume_at;
	    }
	}
	return $earliest;
    }

    private function fail($nodeId, $message) {
	$this->state = 'error';
	$this->error = (string)$message;
	$this->end_at = new \MongoDB\BSON\UTCDateTime();
	$this->log($nodeId, 'error: ' . $message);
	return false;
    }
}
