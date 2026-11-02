<?php

/*
    CLD: 2026-11-02T15:46:06+02:00: new: a test case of a workflow (the designer's Tests panel, util\AgentEval): the inputs of a run and what its AI steps must do with them.
	input    { vars: { name: value } }: the first variables, as a trigger gives them
	tools    mock: every tool call answered from `mocks`, no tool runs; real: the tools that only read run for real, the others are answered from `mocks` or refused (util\AgentEvalContext)
	mocks    [ { node, tool, arguments (object), result (text), error, cut } ]: the recorded tool results (of the source execution's traces, or written by hand)
	steps    { wfid: { title, variables, ports } }: what the steps that do not run in a test (util\AgentEvalContext::runs) gave in the source execution
	checks   [ { type, ... } ]: normalize() says what each type has
	source, trigger, chat: the execution it was saved from, the type of its trigger (another one now: the inputs would not fit), whether a conversation started it (a chat's message is the request)
	notes    what could not be recorded
*/

namespace models;

class WfTestCase extends \sys\BaseModel {
    protected static $collection = 'wf_test_cases';
    public static $indexes = [
	[ [ 'wf' => 1, '_id' => 1 ], [] ],
    ];

    const MODES = [ 'mock' => 'Recorded results: no tool runs', 'real' => 'Real read tools: the others recorded or refused' ];
    const CHECKS = [ 'json_field' => 'JSON answer field', 'tool_called' => 'Tool was called', 'tool_not_called' => 'Tool was not called', 'no_write_tool' => 'No write tool was called',
	'contains' => 'Answer contains', 'not_contains' => 'Answer does not contain', 'judged' => 'Judged by a model' ];
    const OPERATORS = [ 'equals' => 'equals', 'not_equals' => 'does not equal', 'contains' => 'contains', 'matches' => 'matches the pattern', 'exists' => 'exists', 'absent' => 'is absent' ];
    //a workflow's cases, a case's checks, a rubric's characters, a recorded text's characters, the JSON of a case's inputs and recordings (bytes)
    const MAX_CASES = 50;
    const MAX_CHECKS = 30;
    const MAX_RUBRIC = 2000;
    const MAX_TEXT = 2000;
    const MAX_DATA = 2097152;

    public function getSchema() {
	$field = fn($key, $type, $label) => [ 'key' => $key, 'type' => $type, 'label' => $label, 'help' => false ];
	return array_merge([
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'help' => false, 'required' => true ],
	    [ 'key' => 'project', 'type' => 'object', 'object' => 'Project', 'label' => 'Project', 'help' => false ],
	    [ 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'help' => false, 'required' => true ],
	    $field('source', 'text', 'Saved from'),
	    $field('trigger', 'text', 'Trigger'),
	    $field('chat', 'checkbox', 'Started by a conversation'),
	    $field('input', 'array', 'Inputs'),
	    $field('tools', 'text', 'Tool calls'),
	    $field('mocks', 'array', 'Recorded tool results'),
	    $field('steps', 'array', 'Recorded steps'),
	    $field('checks', 'array', 'Checks'),
	    $field('notes', 'array', 'Notes'),
	], $this->getSystemSchema());
    }

    public function __toString() {
	return (string)$this->name;
    }

    //the regular expression of pattern $pattern as the checks use it; null: not a valid one
    public static function regex($pattern) {
	$regex = '~' . preg_replace('/(?<!\\\\)~/', '\\~', (string)$pattern) . '~u';
	return @preg_match($regex, '') === false ? null : $regex;
    }

    //[ path, op, value ] of a posted condition, or a message
    private static function condition(array $c, $label) {
	$path = trim((string)($c['path'] ?? ''));
	$op = (string)($c['op'] ?? 'equals');
	if (!array_key_exists($op, self::OPERATORS))
	    return "{$label}: unknown comparison `{$op}`";
	$value = in_array($op, [ 'exists', 'absent' ], true) ? '' : (is_scalar($c['value'] ?? '') ? (string)($c['value'] ?? '') : \util\JSON::encode($c['value']));
	if ($op === 'matches' && self::regex($value) === null)
	    return "{$label}: `{$value}` is not a valid pattern";
	return [ 'path' => mb_substr($path, 0, 200), 'op' => $op, 'value' => mb_substr($value, 0, self::MAX_TEXT) ];
    }

    //the checks posted as they are kept => [ checks, [ message ] ]: only each type's own fields
    public static function normalize($checks) {
	$out = [];
	$errors = [];
	if (!is_array($checks) || ($checks && !array_is_list($checks)))
	    return [ [], [ 'Checks: a list' ] ];
	if (count($checks) > self::MAX_CHECKS)
	    return [ [], [ 'Checks: at most ' . self::MAX_CHECKS ] ];
	foreach($checks as $i => $c) {
	    $label = 'Check ' . ($i + 1);
	    $type = is_array($c) ? (string)($c['type'] ?? '') : '';
	    if (!array_key_exists($type, self::CHECKS)) {
		$errors[] = "{$label}: unknown type";
		continue;
	    }
	    $step = (string)($c['step'] ?? '');
	    $check = [ 'type' => $type ] + ($step !== '' ? [ 'step' => mb_substr($step, 0, 100) ] : []);
	    switch($type) {
		case 'json_field':
		    $one = self::condition($c, $label);
		    if (is_string($one))
			$errors[] = $one;
		    else
			$check += $one;
		    break;
		case 'tool_called':
		case 'tool_not_called':
		    $tool = trim((string)($c['tool'] ?? ''));
		    if ($tool === '')
			$errors[] = "{$label}: the tool's name";
		    $args = [];
		    foreach((array)($c['args'] ?? []) as $a) {
			$one = self::condition(is_array($a) ? $a : [], "{$label}, argument");
			if (is_string($one))
			    $errors[] = $one;
			elseif ($one['path'] === '')
			    $errors[] = "{$label}: the argument's name";
			else
			    $args[] = $one;
		    }
		    $check += [ 'tool' => mb_substr($tool, 0, 200), 'args' => $args ];
		    break;
		case 'contains':
		case 'not_contains':
		    $text = (string)($c['text'] ?? '');
		    if ($text === '')
			$errors[] = "{$label}: the text";
		    $check += [ 'text' => mb_substr($text, 0, self::MAX_TEXT), 'case_sensitive' => !empty($c['case_sensitive']) && (string)$c['case_sensitive'] !== 'false' ];
		    break;
		case 'judged':
		    $rubric = trim((string)($c['rubric'] ?? ''));
		    if ($rubric === '')
			$errors[] = "{$label}: the rubric";
		    $check += [ 'rubric' => mb_substr($rubric, 0, self::MAX_RUBRIC) ];
		    break;
	    }
	    $out[] = $check;
	}
	return [ $out, $errors ];
    }

    //the first variables posted => [ input, [ message ] ]: as run_workflow takes them
    public static function normalizeInput($vars) {
	if (!is_array($vars) || ($vars && array_is_list($vars)))
	    return [ [ 'vars' => [] ], [ 'Inputs: a JSON object { name: value }' ] ];
	$errors = [];
	foreach(array_keys($vars) as $name) {
	    if (!\util\Validator::isIdentifier((string)$name))
		$errors[] = "Inputs: `{$name}` is not a valid name";
	    elseif ((string)$name === \models\credential\CredentialVariable::EXPRESSION_NAME)
		$errors[] = 'Inputs: `' . \models\credential\CredentialVariable::EXPRESSION_NAME . '` is the name of the project variables';
	}
	return [ [ 'vars' => $vars ], $errors ];
    }

    //the recorded tool results posted => [ mocks, [ message ] ]
    public static function normalizeMocks($mocks) {
	if (!is_array($mocks) || ($mocks && !array_is_list($mocks)))
	    return [ [], [ 'Recorded tool results: a JSON list [ { tool, arguments, result, error } ]' ] ];
	$out = [];
	$errors = [];
	foreach($mocks as $i => $m) {
	    $tool = is_array($m) ? trim((string)($m['tool'] ?? '')) : '';
	    if ($tool === '') {
		$errors[] = 'Recorded tool result ' . ($i + 1) . ': the tool\'s name';
		continue;
	    }
	    $result = $m['result'] ?? '';
	    $out[] = [ 'node' => (string)($m['node'] ?? ''), 'tool' => $tool, 'arguments' => is_array($m['arguments'] ?? null) ? $m['arguments'] : [],
		'result' => is_string($result) ? $result : \util\JSON::encode($result), 'error' => !empty($m['error']), 'cut' => !empty($m['cut']) ];
	}
	return [ $out, $errors ];
    }

    //the inputs and recordings fit a case
    public function validates($options = []) {
	$valid = parent::validates($options);
	if (strlen(\util\JSON::encode([ $this->input, $this->mocks, $this->steps ])) > self::MAX_DATA) {
	    $this->errors('input', 'The inputs and recorded results are too large for a test case');
	    $valid = false;
	}
	return $valid;
    }
}
