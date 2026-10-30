<?php

/*
    CLD: 2026-10-30T19:00:00+02:00: new step: Run a workflow - starts another workflow of the project, one that starts with Called by a workflow (NodeTriggerCall: its inputs and
    outputs are the contract). It runs the workflow's published version, else its draft (as MCP run_workflow).
    Settings: the workflow, its inputs (rows: an input of the workflow, a value; {{ }} expressions), wait for it to finish or start it and continue, the variable (default `result`),
    on error (fail the step, or continue on a second output `error`), how long to wait.
    Waiting: the workflow runs here, nested (as a workflow started by an AI step: no wait for another worker); when it pauses (Wait, Human task) this step pauses too, and is resumed
    when it ends (WfExecution::notifyCaller), or at the time limit (the workflow is then cancelled). Start and continue: queued for the daemon.
    The variable: { ok, state, execution, outputs: { <the workflow's outputs> }, error }.
    No loops: a workflow already in the chain of callers cannot be started again; at most MAX_DEPTH workflows nested
*/

namespace models\node;

class NodeAppWorkflow extends \models\WfNode {
    public static $type = 'app';
    public static $name = 'workflow';
    public static $label = 'Run a workflow';
    public static $icon = 'bi-diagram-3';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'flow';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const MODES = [ 'wait' => 'Wait for it to finish', 'start' => 'Start it and continue' ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const UNITS = [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ];
    const UNIT_SECONDS = [ 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];
    //workflows nested at most (the caller included)
    const MAX_DEPTH = 5;
    //while the workflow is paused, this step checks it at least this often (s; it is resumed when the workflow ends)
    const CHECK_EVERY = 3600;

    //the workflows of the current project (the designer's user) that start with Called by a workflow: [ id => [ name, contract ] ]
    protected static function callable() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	$list = [];
	foreach(\V::app()->om->find('Workflow', [ 'aid' => \V::app()->config('rt.account')->_id ?? null, 'project' => \V::app()->user->crtProject ], [ 'sort' => [ 'name' => 1 ] ]) as $wf) {
	    $contract = NodeTriggerCall::contractOf(($wf->liveCopy() ?: $wf)->nodes);
	    if ($contract !== null)
		$list[(string)$wf->_id] = [ 'name' => (string)$wf->name, 'contract' => $contract ];
	}
	return $list;
    }

    public static function configSchema() {
	$callable = static::callable();
	$workflows = array_map(fn($w) => $w['name'], $callable);
	//the input names of each workflow (the rows' Input)
	$inputs = [];
	foreach($callable as $id => $w) {
	    $inputs[$id] = [];
	    foreach($w['contract']['inputs'] as $row) {
		$name = (string)($row['name'] ?? '');
		if ($name !== '')
		    $inputs[$id][$name] = $name . (!empty($row['required']) ? ' (required)' : '') . ' - ' . (NodeTriggerCall::TYPES[$row['type'] ?? 'text'] ?? 'Any');
	    }
	}
	return [
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'result',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write', 'width' => 3 ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'It cannot start, it fails, or it is cancelled',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'workflow',
		'type' => 'select',
		'label' => 'Workflow',
		'required' => true,
		'default' => '',
		'choices' => $workflows,
		'help' => 'A workflow of this project that starts with Called by a workflow',
		'ui' => [ 'placeholder' => $workflows ? 'Choose a workflow...' : 'No workflow starts with Called by a workflow', 'width' => 6, 'newRow' => true, 'resource' => true ],
	    ],
	    [
		'key' => 'mode',
		'type' => 'select',
		'label' => 'Wait',
		'required' => true,
		'default' => 'wait',
		'choices' => self::MODES,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'inputs',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Inputs',
		'itemLabel' => 'Input',
		'default' => [],
		'fields' => [
		    [ 'key' => 'name', 'type' => 'text', 'label' => 'Input', 'required' => true, 'transform' => 'trim',
			'ui' => [ 'width' => 5, 'type' => 'select', 'allowCustom' => true, 'placeholder' => 'Choose an input...',
			    'choicesBy' => [ 'key' => 'workflow', 'choices' => (object)$inputs, 'empty' => 'Choose the workflow first' ] ] ],
		    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false, 'ui' => [ 'width' => 7, 'expression' => true ] ],
		],
		'ui' => [ 'addLabel' => 'Add input', 'compact' => true, 'maxRows' => 50 ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Wait at most',
		'required' => true,
		'default' => 1,
		'help' => 'While it is paused (Wait, Human task); then it is cancelled',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 1000, 'skipEmpty' => true, 'message' => 'from 1 to 1000' ] ],
		'ui' => [ 'limit' => true, 'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'wait' ] ],
	    ],
	    [
		'key' => 'timeoutUnit',
		'type' => 'select',
		'label' => 'Unit',
		'required' => true,
		'default' => 'days',
		'choices' => self::UNITS,
		'ui' => [ 'limit' => true, 'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'wait' ] ],
	    ],
	];
    }

    //the workflow called by a step of $config, in $aid (default: the current account); null when missing
    protected static function findWorkflow($id, $aid = null, $project = null) {
	$id = (string)$id;
	if ($id === '' || !\util\Validator::rule('oid', $id))
	    return null;
	$query = [ '_id' => $id, 'aid' => $aid ?? (\V::app()->config('rt.account')->_id ?? null) ];
	if ($project !== null)
	    $query['project'] = $project;
	return \V::app()->om->findOne('Workflow', $query);
    }

    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = (string)($config['variable'] ?? 'result');
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$outputs = [];
	$wf = static::findWorkflow($config['workflow'] ?? '');
	foreach(($wf ? (NodeTriggerCall::contractOf(($wf->liveCopy() ?: $wf)->nodes)['outputs'] ?? []) : []) as $o)
	    $outputs[$o] = [ 'type' => 'any' ];
	return [ $name => [ 'type' => 'object', 'info' => 'the run of the workflow: { ok, state, execution, outputs, error }', 'fields' => [
	    'ok' => [ 'type' => 'boolean', 'info' => 'it ended without an error (start and continue: it was queued)' ], 'state' => [ 'type' => 'string', 'info' => 'end, error, cancel; queued' ],
	    'execution' => [ 'type' => 'string', 'info' => 'its execution id' ], 'outputs' => [ 'type' => 'object', 'info' => 'its outputs', 'fields' => $outputs ],
	    'error' => [ 'type' => 'string' ] ] ] ];
    }

    //the workflow: of this project, starts with Called by a workflow, not this one; its required inputs given, the inputs its own
    public function validates($options = []) {
	$valid = parent::validates($options);
	$id = (string)$this->cfg('workflow', '');
	if ($id === '' || !$this->workflow)
	    return $valid;
	$wf = static::findWorkflow($id, $this->workflow->aid, $this->workflow->project);
	$contract = $wf ? NodeTriggerCall::contractOf(($wf->liveCopy() ?: $wf)->nodes) : null;
	if (!$wf || $contract === null) {
	    $this->errors('config', 'Workflow: choose a workflow of this project that starts with Called by a workflow');
	    return false;
	}
	if ((string)$wf->_id === (string)$this->workflow->_id) {
	    $this->errors('config', 'Workflow: a workflow cannot run itself');
	    return false;
	}
	$given = [];
	foreach((array)$this->cfg('inputs', []) as $row)
	    $given[(string)($row['name'] ?? '')] = true;
	$declared = array_column($contract['inputs'], null, 'name');
	foreach($declared as $name => $row) {
	    if (!empty($row['required']) && !isset($given[$name])) {
		$this->errors('config', "Inputs: `{$name}` is required by {$wf->name}");
		$valid = false;
	    }
	}
	foreach(array_keys($given) as $name) {
	    if ($name !== '' && !isset($declared[$name])) {
		$this->errors('config', "Inputs: {$wf->name} has no input `{$name}`");
		$valid = false;
	    }
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//waiting for a workflow started on an earlier visit
	if (!empty($this->child))
	    return $this->check();

	$exec = $this->exec;
	$wf = static::findWorkflow($this->cfg('workflow', ''), $exec->aid, $exec->project);
	if (!$wf)
	    return $this->failed('the workflow is no longer in this project');
	$live = $wf->liveCopy() ?: $wf;
	$contract = NodeTriggerCall::contractOf($live->nodes);
	if ($contract === null)
	    return $this->failed("{$wf->name} does not start with Called by a workflow");
	if (array_filter($live->issues(), fn($i) => $i['level'] == 'error'))
	    return $this->failed("{$wf->name} has errors (steps' settings or connections)");

	//no loops, not too deep
	$chain = array_map('strval', (array)($exec->input['caller']['chain'] ?? []));
	$chain[] = (string)$exec->wf;
	if (in_array((string)$wf->_id, $chain, true))
	    return $this->failed("{$wf->name} is already running in this chain of workflows (a loop)");
	if (count($chain) >= self::MAX_DEPTH)
	    return $this->failed('workflows nested too deep (at most ' . self::MAX_DEPTH . ')');

	$vars = $exec->expressionVariables();
	$inputs = [];
	foreach((array)$this->cfg('inputs', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$inputs[(string)$row['name']] = \util\Expression::render((string)($row['value'] ?? ''), $vars);
	}
	$caller = \V::app()->om->findOne('Workflow', [ '_id' => $exec->wf, 'aid' => $exec->aid ]);
	$wait = $this->cfg('mode', 'wait') !== 'start';
	$child = \models\WfExecution::startFor($live, 'call', [ 'vars' => $inputs, 'caller' => [ 'wf' => (string)$exec->wf, 'name' => (string)($caller->name ?? ''),
	    'execution' => (string)$exec->_id, 'node' => (string)$this->wfid, 'chain' => $chain, 'wait' => $wait ] ], $contract['wfid']);
	$child->log(null, 'started by the workflow ' . (string)($caller->name ?? $exec->wf) . ' (execution ' . (string)$exec->_id . ')');

	if (!$wait) {
	    $child->queue();
	    $this->exec->log($this->wfid, "started {$wf->name}: execution {$child->_id}");
	    return $this->done([ 'ok' => true, 'state' => 'queued', 'execution' => (string)$child->_id, 'outputs' => null, 'error' => null ]);
	}

	$child->save();
	$this->exec->log($this->wfid, "started {$wf->name}: execution {$child->_id}");
	$state = $child->run();
	if ($state === 'sleep') {
	    $unit = (string)$this->cfg('timeoutUnit', 'days');
	    $this->child = (string)$child->_id;
	    $this->deadline = new \MongoDB\BSON\UTCDateTime((time() + max(1, (int)$this->cfg('timeout', 1)) * (self::UNIT_SECONDS[$unit] ?? 86400)) * 1000);
	    return $this->pause();
	}
	return $this->finish($child);
    }

    //a workflow started earlier: still running (paused: this step too, until it ends or the time limit), or ended
    private function check() {
	$child = \V::app()->om->findOne('WfExecution', [ '_id' => \V::app()->om->to_oid($this->child), 'aid' => $this->exec->aid ]);
	if (!$child) {
	    $this->child = null;
	    return $this->failed('its execution no longer exists');
	}
	if (in_array($child->state, [ 'new', 'run', 'sleep' ], true)) {
	    if ($this->deadline instanceof \MongoDB\BSON\UTCDateTime && (int)(string)$this->deadline <= (int)(microtime(true) * 1000)) {
		$child->kill('the calling workflow (it waited too long)');
		$this->child = null;
		return $this->failed('it did not finish in time (cancelled)', (string)$child->_id, 'cancel');
	    }
	    return $this->pause();
	}
	return $this->finish($child);
    }

    //paused: resumed when the workflow ends (WfExecution::notifyCaller), checked at least every CHECK_EVERY s and at the time limit
    private function pause() {
	$next = (time() + self::CHECK_EVERY) * 1000;
	$deadline = $this->deadline instanceof \MongoDB\BSON\UTCDateTime ? (int)(string)$this->deadline : $next;
	$this->resume_at = new \MongoDB\BSON\UTCDateTime(min($next, $deadline));
	return self::SLEEP;
    }

    //the workflow ended (end, error, cancel): its outputs, the step's output
    private function finish($child) {
	$this->child = null;
	$this->deadline = null;
	$this->resume_at = null;
	$outputs = [];
	$variables = is_array($child->variables) ? $child->variables : [];
	foreach((NodeTriggerCall::contractOf($child->nodes)['outputs'] ?? []) as $name)
	    $outputs[$name] = $variables[$name] ?? null;
	if ($child->state !== 'end')
	    return $this->failed((string)($child->error ?: $child->state), (string)$child->_id, (string)$child->state, $outputs);
	$this->exec->log($this->wfid, "execution {$child->_id} ended");
	return $this->done([ 'ok' => true, 'state' => 'end', 'execution' => (string)$child->_id, 'outputs' => $outputs, 'error' => null ]);
    }

    private function done(array $result) {
	$name = (string)$this->cfg('variable', 'result');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	return [ 0 ];
    }

    //on error: the `error` output, or the step fails
    private function failed($message, $execution = null, $state = 'error', $outputs = null) {
	$this->done([ 'ok' => false, 'state' => $state, 'execution' => $execution, 'outputs' => $outputs, 'error' => $message ]);
	if ($this->cfg('onError', 'fail') === 'output') {
	    $this->exec->log($this->wfid, 'Run a workflow: ' . $message);
	    return [ 1 ];
	}
	throw new \ErrorException('Run a workflow: ' . $message);
    }
}
