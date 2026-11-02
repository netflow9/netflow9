<?php

/*
    CLD: 2026-11-02T14:00:00+02:00: new class: writes the trace of one visit of an AI step (models\AgentTrace; models\node\NodeAppAi: run, loop, decided), round by round, so the Executions panel follows a
    run live. A trace never stops a run: a failed write is logged once, the step goes on.
	What is kept is what the model saw: with Mask personal data, placeholders (the arguments as the model wrote them, the results masked).
	Secrets never: values of keys that name a secret and secrets inside texts (models\AuditLog::withoutSecrets, SECRET_VALUES), `password: x` inside texts, the values of the step's
	    credentials (addSecrets), wherever they turn up. Removed before a text is cut, so that a cut cannot leave half of one behind.
	Long texts are cut, with their full length noted (<field>_length). Past MAX_TOTAL for a visit, each text only SMALL: a document stays well under MongoDB's 16 MB
*/

namespace util;

class AgentTracer {
    //characters kept: the model's text, a tool's result, a call's arguments
    const MAX_TEXT = 20000;
    const MAX_RESULT = 8000;
    const MAX_ARGUMENTS = 8000;
    //bytes kept for a visit before each text is cut at SMALL
    const MAX_TOTAL = 4194304;
    const SMALL = 500;
    const SECRET_ASSIGNED = '/\b(pass(?:word|wd|phrase)|(?:client[_-]?)?secret|api[_-]?key|(?:access[_-]?|refresh[_-]?)?token|authorization)(\\\\?["\']?\s*[:=]\s*)(?!\\\\?["\']?\[removed\])(\\\\"(?:[^"\\\\]|\\\\[^"])*\\\\"|"[^"]*"|\'[^\']*\'|(?:Basic\s+|Bearer\s+)?[^\s,;&"\']+)/i';

    private $step;
    private $guard = null;
    private $id;
    private $rounds = [];
    private $size = 0;
    private $credits = 0.0;
    private $secrets = [];
    private $failed = false;

    private function __construct($step, $id) {
	$this->step = $step;
	$this->id = $id;
    }

    //a new visit of AI step $step
    public static function begin($step) {
	$trace = new self($step, new \MongoDB\BSON\ObjectId());
	$now = new \MongoDB\BSON\UTCDateTime();
	$exec = $step->exec;
	$trace->attempt(fn() => \models\AgentTrace::coll()->insertOne([ '_id' => $trace->id, 'aid' => $exec->aid, 'project' => $exec->project, 'wf' => $exec->wf, 'execution' => $exec->_id,
	    'node' => (string)$step->wfid, 'step' => (string)($step->title ?: $step::$label), 'visit' => (int)$step->runs + 1, 'provider' => $step::PROVIDER, 'model' => trim((string)$step->cfg('model', '')),
	    'state' => 'running', 'rounds' => [], 'totals' => self::emptyTotals(), 'created_at' => $now, 'started_at' => $now, 'updated_at' => $now ]));
	return $trace;
    }

    //the visit goes on after an approval: trace $id, or a new one when it is gone (the retention)
    public static function resume($step, $id) {
	$doc = \models\AgentTrace::read($id, $step->exec->aid);
	if (!$doc)
	    return self::begin($step);
	$trace = new self($step, $doc['_id']);
	$trace->rounds = (array)($doc['rounds'] ?? []);
	$trace->size = strlen((string)json_encode($trace->rounds, JSON_INVALID_UTF8_SUBSTITUTE));
	$trace->credits = (float)($doc['totals']['cost']['credits'] ?? 0);
	$trace->save([ 'state' => 'running' ]);
	return $trace;
    }

    public function id() {
	return (string)$this->id;
    }

    //the run's guard is there: the prices used, whether on the System AI
    public function attach($guard) {
	$this->guard = $guard;
	$this->save([ 'system' => $guard->onSystem(), 'prices' => $guard->prices() ]);
    }

    //the values of the password fields of $credential are never kept
    public function addSecrets($credential) {
	if (!$credential)
	    return;
	try {
	    //the System AI's is not a model: the key is in its request headers
	    if (!method_exists($credential, 'getSchema')) {
		foreach((array)(method_exists($credential, 'httpAuth') ? ($credential->httpAuth()['headers'] ?? []) : []) as $name => $value) {
		    $value = preg_replace('/^(Bearer|Basic)\s+/i', '', (string)$value);
		    if (preg_match(\models\AuditLog::SECRET_KEYS, (string)$name) && strlen($value) >= 4)
			$this->secrets[$value] = true;
		}
		return;
	    }
	    foreach($credential->getSchema() as $field) {
		if (($field['type'] ?? '') !== 'password')
		    continue;
		$value = $credential->{$field['key']};
		if (is_string($value) && strlen($value) >= 4)
		    $this->secrets[$value] = true;
	    }
	} catch (\Throwable $e) {
	    $this->attempt(fn() => throw $e);
	}
    }

    //round $n's call to the model ($ms) answered: $answer as NodeAppAi::callModel gives it
    public function round($n, array $answer, $ms) {
	$tokens = [ 'input' => (int)($answer['usage'][0] ?? 0), 'output' => (int)($answer['usage'][1] ?? 0) ];
	$cost = $this->guard ? $this->guard->costs() : [ 'usd' => null, 'credits' => null ];
	$credits = $cost['credits'] === null ? null : round((float)$cost['credits'] - $this->credits, 4);
	$this->credits = (float)($cost['credits'] ?? 0);
	$round = [ 'n' => (int)$n, 'model' => (string)($answer['model'] ?? ''), 'stop_reason' => (string)($answer['stop_reason'] ?? ''), 'tokens' => $tokens,
	    'cost' => [ 'usd' => $this->guard ? $this->guard->estimate([ $tokens['input'], $tokens['output'] ]) : null, 'credits' => $credits ], 'duration_ms' => (int)$ms, 'calls' => [] ];
	$text = trim((string)($answer['text'] ?? ''));
	if ($text !== '')
	    $round += $this->kept('text', $text, self::MAX_TEXT);
	$this->rounds[(int)$n - 1] = $round;
	$this->saveRound($n, [ 'model' => $round['model'] ]);
    }

    //call $i of round $n, $args as the model wrote them, $access: read | write | unknown
    public function call($n, $i, $tool, $args, $access) {
	$clean = \models\AuditLog::withoutSecrets(is_array($args) ? $args : [ 'value' => $args ], '', PHP_INT_MAX);
	$json = (string)json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	$this->rounds[(int)$n - 1]['calls'][(int)$i] = [ 'tool' => (string)$tool, 'access' => (string)$access, 'error' => false ] + $this->kept('arguments', $json, self::MAX_ARGUMENTS);
	$this->saveRound($n);
    }

    //its result: the text masked as the model gets it; $ms: how long it ran
    public function result($n, $i, $text, $isError, $ms, $images = 0) {
	$masked = $this->guard ? $this->guard->masked((string)$text) : (string)$text;
	$this->rounds[(int)$n - 1]['calls'][(int)$i] = [ 'error' => (bool)$isError, 'duration_ms' => (int)$ms, 'images' => (int)$images ] + $this->kept('result', $masked, self::MAX_RESULT)
	    + array_diff_key((array)($this->rounds[(int)$n - 1]['calls'][(int)$i] ?? []), [ 'error' => 1, 'result' => 1, 'result_length' => 1, 'duration_ms' => 1, 'images' => 1 ]);
	$this->saveRound($n);
    }

    //its approval: asked, approved, rejected, expired, not asked; $by: who decided; $at: when; $comment: theirs
    public function approval($n, $i, $state, $by = null, $at = null, $comment = '') {
	$comment = trim((string)$comment);
	$this->rounds[(int)$n - 1]['calls'][(int)$i]['approval'] = [ 'state' => (string)$state ] + ($by !== null ? [ 'by' => (string)$by ] : []) + ($at ? [ 'at' => $at ] : [])
	    + ($comment !== '' ? [ 'comment' => $this->scrub($comment) ] : []);
	$this->saveRound($n);
    }

    //it went past limit $limit: not run
    public function refused($n, $i, $limit) {
	$this->rounds[(int)$n - 1]['calls'][(int)$i]['refused'] = (string)$limit;
	$this->rounds[(int)$n - 1]['calls'][(int)$i]['error'] = true;
	$this->saveRound($n);
    }

    //the step sleeps until an approval is decided
    public function waiting() {
	$this->save([ 'state' => 'waiting' ]);
    }

    //the visit ended: done, or error with why
    public function finish($state, $error = null) {
	$this->save([ 'state' => (string)$state, 'ended_at' => new \MongoDB\BSON\UTCDateTime() ] + ($error !== null ? [ 'error' => $this->scrub((string)$error) ] : []));
    }

    //$text without secrets
    public function scrub($text) {
	$text = mb_scrub((string)$text, 'UTF-8');
	if ($this->secrets)
	    $text = str_replace(array_map('strval', array_keys($this->secrets)), \models\AuditLog::REMOVED, $text);
	$text = preg_replace(\models\AuditLog::SECRET_VALUES, \models\AuditLog::REMOVED, $text) ?? \models\AuditLog::REMOVED;
	return preg_replace(self::SECRET_ASSIGNED, '$1$2' . \models\AuditLog::REMOVED, $text) ?? \models\AuditLog::REMOVED;
    }

    //[ $field => $text without secrets, cut at $max ] and, when cut, [ <field>_length => its full length ]
    private function kept($field, $text, $max) {
	$text = $this->scrub($text);
	$limit = $this->size > self::MAX_TOTAL ? min($max, self::SMALL) : $max;
	$length = mb_strlen($text);
	$out = $length > $limit ? mb_substr($text, 0, $limit) : $text;
	$this->size += strlen($out);
	return [ $field => $out ] + ($length > $limit ? [ "{$field}_length" => $length ] : []);
    }

    private static function emptyTotals() {
	return [ 'rounds' => 0, 'tool_calls' => 0, 'write_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => [ 'usd' => null, 'credits' => null ] ];
    }

    //the visit so far
    private function totals() {
	$totals = self::emptyTotals();
	foreach($this->rounds as $round) {
	    $totals['rounds']++;
	    $totals['input_tokens'] += (int)($round['tokens']['input'] ?? 0);
	    $totals['output_tokens'] += (int)($round['tokens']['output'] ?? 0);
	    foreach((array)($round['calls'] ?? []) as $call) {
		$totals['tool_calls']++;
		if (($call['access'] ?? '') === 'write')
		    $totals['write_calls']++;
	    }
	}
	if ($this->guard)
	    $totals['cost'] = $this->guard->costs();
	return $totals;
    }

    //round $n written (the others as they are); $more: other fields
    private function saveRound($n, array $more = []) {
	$i = (int)$n - 1;
	$round = $this->rounds[$i];
	ksort($round['calls']);
	$this->rounds[$i]['calls'] = $round['calls'] = array_values($round['calls']);
	$this->save([ "rounds.{$i}" => $round, 'totals' => $this->totals() ] + $more);
    }

    private function save(array $set) {
	$this->attempt(fn() => \models\AgentTrace::coll()->updateOne([ '_id' => $this->id ], [ '$set' => $set + [ 'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]));
    }

    //a write; a failure logged once, never passed on
    private function attempt(callable $write) {
	try {
	    $write();
	} catch (\Throwable $e) {
	    if (!$this->failed)
		$this->step->exec->log($this->step->wfid, $this->step::$label . ': the trace could not be written: ' . mb_substr($e->getMessage(), 0, 200));
	    $this->failed = true;
	}
    }
}
