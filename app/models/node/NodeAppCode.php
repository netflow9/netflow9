<?php

/*
Code - runs a J script (sys\J: the sandboxed JavaScript subset of the {{ }} expressions,
    here with statements: let, if, for, functions, return).

User's choice: the workflow variables are plain names in the code and assigning them sets them (`total = items.length`; a new name too); `let` / `const` / functions
//stay local; `vars` (the project variables) is read-only. After the run the variables that changed or are new are written back (functions are not values: left out).
//console.log() lines go to the execution log. Limits: Timeout (seconds) and a step count. On error: fail, or continue on an `error` output with variable `error` = the
//message. Suggestions in later steps: the names the code assigns (util\Expression::assignedNames). The designer edits the code with app.CodeEditor (CodeMirror)

*/
//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\app)
namespace models\node;

class NodeAppCode extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'code';
    public static $label = 'Code';
    public static $icon = 'bi-code-slash';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: an advanced step, in Developer
    public static $audience = 'advanced';
    public static $category = 'developer';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    //the time limit (seconds) and the J steps (statements + calls) of a run
    const MAX_TIMEOUT = 30;
    const MAX_STEPS = 1000000;
    //largest code (characters) and console lines logged
    const MAX_CODE = 100000;
    const MAX_LOG_LINES = 50;

    public static function configSchema() {
	return [
	    [
		'key' => 'code',
		'type' => 'textarea',
		'label' => 'Code',
		'required' => true,
		'help' => 'J (JavaScript subset): workflow variables are plain names, assigning one sets it (total = items.length); let / const stay local; vars.<name> = project variables; console.log() writes to the log',
		'validate' => [
		    [ 'lengthBetween', 'min' => 0, 'max' => self::MAX_CODE, 'skipEmpty' => true, 'message' => 'At most ' . self::MAX_CODE . ' characters' ]
		],
		'ui' => [
		    'rows' => 14,
		    'expression' => 'script',
		    'code' => 'j',
		    'placeholder' => "// e.g.\ntotal = items.reduce((sum, i) => sum + i.qty, 0);\nstatus = total > 10 ? 'big' : 'small';"
		],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (seconds)',
		'required' => true,
		'default' => 5,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'Between 1 and ' . self::MAX_TIMEOUT ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'step' => 1, 'width' => 6 ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'An error in the code, or a limit reached',
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //the variables the code assigns (any type), + `error` with the error output
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$vars = [];
	$code = (string)($config['code'] ?? '');
	if (trim($code) !== '') {
	    $parsed = \util\Expression::parse($code, 'script');
	    foreach(\util\Expression::assignedNames($parsed->ast) as $name) {
		//CLD: 2026-10-22T00:28:13+03:00: CredentialVariable::EXPRESSION_NAME (was models\Variable's)
		if ($name !== \models\credential\CredentialVariable::EXPRESSION_NAME)
		    $vars[$name] = [ 'type' => 'any', 'info' => 'set by a Code step' ];
	    }
	}
	if (($config['onError'] ?? 'fail') === 'output')
	    $vars['error'] = [ 'type' => 'string', 'info' => 'the error message (error output of a Code step)' ];
	return $vars;
    }

    public function execute($edgeId = null) {
	$code = (string)$this->cfg('code', '');
	try {
	    $parsed = \util\Expression::parse($code, 'script');
	    if ($parsed->hasErrors()) {
		$e = $parsed->errors()[0];
		throw new \ErrorException("{$e['message']} (line {$e['line']}, column {$e['column']})");
	    }
	    $before = $this->exec->expressionVariables();
	    //CLD: 2026-10-20T21:40:00+03:00: dates are J Dates (hostValue with dates), given back as expressions give them (dateFormat, util\Expression::LIMITS)
	    //CLD: 2026-10-22T00:28:13+03:00: readOnly: CredentialVariable::EXPRESSION_NAME (was models\Variable's)
	    $vm = new \sys\J\Interpreter([ 'maxSteps' => self::MAX_STEPS, 'timeLimit' => (float)max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 5))),
		'writable' => true, 'readOnly' => [ \models\credential\CredentialVariable::EXPRESSION_NAME ], 'implicitGlobals' => true, 'dateFormat' => \util\Expression::LIMITS['dateFormat'] ]);
	    $context = [];
	    foreach($before as $name => $value)
		$context[$name] = \util\Expression::hostValue($value, true);
	    try {
		$vm->run($parsed, $context);
	    } finally {
		$lines = $vm->getOutput();
		foreach(array_slice($lines, 0, self::MAX_LOG_LINES) as $line)
		    $this->exec->log($this->wfid, 'console: ' . mb_substr((string)$line, 0, 500));
		if (count($lines) > self::MAX_LOG_LINES)
		    $this->exec->log($this->wfid, 'console: ... ' . (count($lines) - self::MAX_LOG_LINES) . ' more line(s)');
	    }
	} catch (\sys\J\ScriptError | \sys\J\LimitExceeded | \ErrorException | \InvalidArgumentException $e) {
	    $message = 'Code: ' . $e->getMessage();
	    if ($this->cfg('onError', 'fail') === 'output') {
		$this->exec->setVariable('error', $message);
		$this->exec->log($this->wfid, $message);
		return [ 1 ];
	    }
	    throw new \ErrorException($message);
	}

	//the changed / new top-level names become workflow variables (functions are not values)
	$set = [];
	foreach($vm->globals() as $name => $value) {
	    //CLD: 2026-10-22T00:28:13+03:00: CredentialVariable::EXPRESSION_NAME (was models\Variable's)
	    if ($name === \models\credential\CredentialVariable::EXPRESSION_NAME || $value instanceof \Closure)
		continue;
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    //CLD: 2026-10-20T21:40:00+03:00: compared with the value as J gives it back (a date is text then)
	    if (array_key_exists($name, $context) && \util\JSON::encode($vm->toPhp($vm->toJs($context[$name]))) === \util\JSON::encode($value))
		continue;
	    $this->exec->setVariable($name, $value);
	    $set[] = $name;
	}
	$this->exec->log($this->wfid, 'code: ' . ($set ? 'set ' . implode(', ', $set) : 'no variable changed'));
	return [ 0 ];
    }
}
