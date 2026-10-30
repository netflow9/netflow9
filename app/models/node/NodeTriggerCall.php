<?php

/*
    CLD: 2026-10-30T19:00:00+02:00: new trigger: Called by a workflow - the workflow is started by the Run a workflow step of another workflow of the project (NodeAppWorkflow).
    Its settings are the contract: the inputs (name, type, required, default value: each becomes a variable, checked and cast to its type) and the outputs (the variables handed back
    to the caller when the run ends). The caller (`caller` variable): { workflow, name, execution } of the calling run; null when started by Run in the designer
*/

namespace models\node;

class NodeTriggerCall extends \models\WfNode {
    public static $type = 'trigger';
    public static $name = 'call';
    public static $label = 'Called by a workflow';
    public static $icon = 'bi-box-arrow-in-right';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    const TYPES = [ 'text' => 'Text', 'number' => 'Number', 'boolean' => 'Yes / no', 'list' => 'List', 'object' => 'Object', 'any' => 'Any' ];
    //the expression types of the inputs (outputVariables)
    const EXPR_TYPES = [ 'text' => 'string', 'number' => 'number', 'boolean' => 'boolean', 'list' => 'array', 'object' => 'object', 'any' => 'any' ];

    public static function configSchema() {
	return [
	    [
		'key' => 'inputs',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Inputs',
		'help' => 'What the calling workflow gives; each one becomes a variable',
		'itemLabel' => 'Input',
		'default' => [],
		'fields' => [
		    [ 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'transform' => 'trim',
			'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 4 ] ],
		    [ 'key' => 'type', 'type' => 'select', 'label' => 'Type', 'required' => true, 'default' => 'text', 'choices' => self::TYPES, 'ui' => [ 'width' => 3 ] ],
		    [ 'key' => 'required', 'type' => 'checkbox', 'label' => 'Required', 'default' => false, 'ui' => [ 'width' => 2 ] ],
		    [ 'key' => 'default', 'type' => 'text', 'label' => 'Default', 'help' => false, 'ui' => [ 'width' => 3 ] ],
		],
		'ui' => [ 'addLabel' => 'Add input', 'compact' => true, 'maxRows' => 50 ],
	    ],
	    [
		'key' => 'outputs',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Outputs',
		'help' => 'The variables handed back to the calling workflow when this one ends',
		'itemLabel' => 'Output',
		'default' => [],
		'fields' => [
		    [ 'key' => 'name', 'type' => 'text', 'label' => 'Variable', 'required' => true, 'transform' => 'trim',
			'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 12 ] ],
		],
		'ui' => [ 'addLabel' => 'Add output', 'compact' => true, 'maxRows' => 50 ],
	    ],
	];
    }

    //the inputs, names unique, not `caller`
    public function validates($options = []) {
	$valid = parent::validates($options);
	$seen = [];
	foreach((array)$this->cfg('inputs', []) as $i => $row) {
	    $name = (string)($row['name'] ?? '');
	    if ($name === 'caller' || isset($seen[$name])) {
		$this->errors('config', 'Input ' . ($i + 1) . ': ' . ($name === 'caller' ? '`caller` is the variable of the calling workflow' : "`{$name}` is already an input"));
		$valid = false;
	    }
	    $seen[$name] = true;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$vars = [];
	foreach((array)(is_array($config) ? ($config['inputs'] ?? []) : []) as $row) {
	    $name = (string)($row['name'] ?? '');
	    if (\util\Validator::isIdentifier($name))
		$vars[$name] = [ 'type' => self::EXPR_TYPES[$row['type'] ?? 'text'] ?? 'any', 'info' => 'an input' . (!empty($row['required']) ? ' (required)' : '') ];
	}
	$vars['caller'] = [ 'type' => 'object', 'info' => 'the calling workflow (null: started in the designer)', 'fields' => [ 'workflow' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ],
	    'execution' => [ 'type' => 'string' ] ] ];
	return $vars;
    }

    //the input rows of a workflow's steps ($nodes): its Called by a workflow trigger's config, null when it has none
    public static function contractOf($nodes) {
	foreach((is_array($nodes) ? $nodes : []) as $n) {
	    if (is_array($n) && !empty($n['isNode']) && ($n['fqname'] ?? '') === self::$type . '::' . self::$name)
		return [ 'wfid' => (string)$n['wfid'], 'inputs' => array_values((array)($n['config']['inputs'] ?? [])), 'outputs' => array_values(array_filter(array_map(fn($r) => (string)($r['name'] ?? ''),
		    (array)($n['config']['outputs'] ?? [])), fn($v) => $v !== '')) ];
	}
	return null;
    }

    //$value cast to an input's type; [ value, error ]
    public static function cast($type, $value) {
	switch($type) {
	    case 'text':
		return [ is_array($value) ? \util\Expression::toText($value) : (string)$value, null ];
	    case 'number':
		return is_numeric($value) ? [ $value + 0, null ] : [ null, 'a number is expected' ];
	    case 'boolean':
		$b = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		return $b === null ? [ null, 'yes / no is expected' ] : [ $b, null ];
	    case 'list':
	    case 'object':
		if (is_string($value) && ($json = json_decode($value, true)) !== null)
		    $value = $json;
		if (!is_array($value) || ($type === 'list' ? !array_is_list($value) : ($value !== [] && array_is_list($value))))
		    return [ null, 'a ' . ($type === 'list' ? 'list' : 'object') . ' is expected' ];
		return [ $value, null ];
	}
	return [ $value, null ];
    }

    //Run in the designer: the inputs' defaults
    public static function startNow($wf, array $node) {
	$exe = \models\WfExecution::startFor($wf, 'manual', [ 'user' => (string)\V::app()->user->_id ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }

    //the inputs: given (the caller's `vars`, already variables), else their default; a required one missing or a value of another type fails the run
    public function execute($edgeId = null) {
	$given = is_array($this->exec->input['vars'] ?? null) ? $this->exec->input['vars'] : [];
	foreach((array)$this->cfg('inputs', []) as $row) {
	    $name = (string)($row['name'] ?? '');
	    if (!\util\Validator::isIdentifier($name))
		continue;
	    $value = array_key_exists($name, $given) ? $given[$name] : null;
	    if ($value === null || $value === '') {
		if (!empty($row['required']))
		    throw new \ErrorException("Called by a workflow: the input `{$name}` is required");
		$value = (string)($row['default'] ?? '');
		if ($value === '') {
		    $this->exec->setVariable($name, null);
		    continue;
		}
	    }
	    [ $value, $error ] = self::cast((string)($row['type'] ?? 'text'), $value);
	    if ($error !== null)
		throw new \ErrorException("Called by a workflow: the input `{$name}`: {$error}");
	    $this->exec->setVariable($name, $value);
	}
	$caller = $this->exec->input['caller'] ?? null;
	$this->exec->setVariable('caller', is_array($caller) ? [ 'workflow' => (string)($caller['wf'] ?? ''), 'name' => (string)($caller['name'] ?? ''),
	    'execution' => (string)($caller['execution'] ?? '') ] : null);
	return [ 0 ];
    }
}
