<?php

/*
    CLD: 2026-11-02T15:46:06+02:00: new: one test case running (util\AgentEval): held by its execution ($exe->__test), never saved. What keeps a test from acting on the world:
	Steps: only the steps of RUNS, those with no effect outside the run, and the AI steps. Any other step (Send email, HTTP Request, Data table, a Wait, a Human task, Run a workflow, a new step type: nothing is
	    allowed by default) does not run: what it gave in the source execution (the case's `steps`) stands in for it, its variables and the outputs it took; nothing recorded: its first output, no variables.
	Tools: the case's mode. mock: answered from the recordings, no tool runs. real: a tool that only reads and asks for no approval runs; the others are answered from the recordings, or refused.
	    A tool's flag is the one util\AgentGuard::govern acts on: what is not known to be read-only is a write. Nothing asks for approval in a test. Only the calls that really ran go in the audit log.
    What the AI steps did (visits) is collected here for the checks: each visit's answer, its calls with their access
*/

namespace util;

class AgentEvalContext {
    //the steps that run in a test, besides the triggers and the AI steps
    const RUNS = [ \models\node\NodeAppSet::class, \models\node\NodeAppCode::class, \models\node\NodeCoreIf::class, \models\node\NodeCoreSwitch::class, \models\node\NodeCoreMerge::class, \models\node\NodeCoreLoop::class, \models\node\NodeCoreNote::class ];
    const NOT_RECORDED = 'This test case has no recorded result for this tool call: the tool did not run.';
    const REFUSED = 'A test does not run tools that change something or need an approval, and this test case has no recorded result for this call: the tool did not run.';
    //arguments that say why, not what: left out when two calls are compared
    const NOT_COMPARED = [ AgentTools::REASON, 'approval_' . AgentTools::REASON ];

    public $chat;
    public $visits = [];
    public $notes = [];
    private $mode;
    private $mocks = [];
    private $used = [];
    private $steps;
    private $ranForReal = false;

    public function __construct($case) {
	$this->chat = !empty($case->chat);
	$this->mode = (string)$case->tools === 'real' ? 'real' : 'mock';
	$this->mocks = array_values((array)$case->mocks);
	$this->steps = (array)$case->steps;
    }

    //whether step $node runs in a test
    public static function runs($node) {
	if ($node::$type === 'trigger' || $node instanceof \models\node\NodeAppAi)
	    return true;
	foreach(self::RUNS as $class)
	    if ($node instanceof $class)
		return true;
	return false;
    }

    //the work of step $node in a test (models\WfNode::receive)
    public function execute($node, $edgeId) {
	if (self::runs($node))
	    return $node->execute($edgeId);
	$title = (string)($node->title ?: $node::$label);
	$recorded = $this->steps[(string)$node->wfid] ?? null;
	$ports = $node->outputPorts();
	if (!is_array($recorded)) {
	    $this->note("{$title}: not run in a test, and nothing is recorded for it");
	    $node->exec->log($node->wfid, 'test: not run, nothing recorded for it');
	    return array_slice($ports, 0, 1);
	}
	foreach((array)($recorded['variables'] ?? []) as $name => $value)
	    $node->exec->setVariable($name, $value);
	$node->exec->log($node->wfid, 'test: not run, its recorded result used');
	$taken = array_values(array_intersect($ports, array_map('intval', (array)($recorded['ports'] ?? []))));
	return $taken ?: array_slice($ports, 0, 1);
    }

    private function note($text) {
	if (!in_array($text, $this->notes, true) && count($this->notes) < 50)
	    $this->notes[] = $text;
    }

    //$tools of AI step $step, as a test runs them
    public function tools($step, array $tools) {
	foreach($tools as $name => $t) {
	    $real = $t['run'];
	    $tools[$name]['run'] = fn(array $args) => $this->call($step, $name, $t, $args, $real);
	}
	return $tools;
    }

    private function call($step, $name, array $t, array $args, callable $real) {
	$this->ranForReal = false;
	if ($this->mode === 'real' && empty($t['writes']) && empty($t['approval'])) {
	    $this->ranForReal = true;
	    return $real($args);
	}
	$mock = $this->recorded((string)$step->wfid, $name, $args);
	if ($mock === null) {
	    $this->note($this->mode === 'real' ? "{$name}: not run, it changes something or needs an approval, and no result is recorded" : "{$name}: no recorded result");
	    return [ $this->mode === 'real' ? self::REFUSED : self::NOT_RECORDED, true, [] ];
	}
	if (!empty($mock['cut']))
	    $this->note("{$name}: the recorded result is cut, as the trace keeps it");
	return [ (string)$mock['result'], !empty($mock['error']), [] ];
    }

    //the recording for a call: not used yet; of the same step before another step's; with the same arguments before another; else the same tool's next one. Its last one again when they are used up. null: none
    private function recorded($node, $tool, array $args) {
	$wanted = self::comparable($args);
	$best = null;
	$bestScore = -1;
	$last = null;
	foreach($this->mocks as $i => $m) {
	    if ((string)($m['tool'] ?? '') !== $tool)
		continue;
	    $last = $i;
	    if (isset($this->used[$i]))
		continue;
	    $score = (self::comparable((array)($m['arguments'] ?? [])) === $wanted ? 2 : 0) + ((string)($m['node'] ?? '') === $node ? 1 : 0);
	    if ($score > $bestScore)
		[ $best, $bestScore ] = [ $i, $score ];
	}
	$i = $best ?? $last;
	if ($i === null)
	    return null;
	$this->used[$i] = true;
	return $this->mocks[$i];
    }

    private static function comparable(array $args) {
	return JSON::encode(self::sorted(array_diff_key($args, array_flip(self::NOT_COMPARED))));
    }

    private static function sorted($value) {
	if (!is_array($value))
	    return $value;
	if (!array_is_list($value))
	    ksort($value);
	return array_map([ self::class, 'sorted' ], $value);
    }

    //the tool call just made is not to be written in the audit log (util\AgentGuard::audit)
    public function notAudited() {
	return !$this->ranForReal;
    }

    //a visit of AI step $step ended: $result, its variable; else $error, with what it did so far in $progress
    public function visit($step, ?array $result, $error = null, array $progress = []) {
	$from = $result ?? $progress;
	$calls = [];
	foreach((array)($from['tool_calls'] ?? []) as $c)
	    $calls[] = [ 'tool' => (string)($c['tool'] ?? ''), 'arguments' => is_array($c['arguments'] ?? null) ? $c['arguments'] : [], 'error' => !empty($c['error']),
		'access' => $step->guard ? $step->guard->accessOf((string)($c['tool'] ?? '')) : 'unknown' ];
	$this->visits[] = [ 'node' => (string)$step->wfid, 'step' => (string)($step->title ?: $step::$label), 'text' => (string)($result['text'] ?? ''), 'json' => $result['json'] ?? null, 'is_json' => (string)$step->cfg('output', 'text') === 'json',
	    'masked' => AgentGuard::on($step->cfg('maskPersonalData', false)), 'calls' => $calls, 'error' => $error,
	    'tokens' => [ 'input' => (int)($from['usage']['input_tokens'] ?? 0), 'output' => (int)($from['usage']['output_tokens'] ?? 0) ],
	    'cost' => $step->guard ? $step->guard->costs() : [ 'usd' => null, 'credits' => null ], 'trace_id' => $step->tracer ? $step->tracer->id() : null ];
    }
}
