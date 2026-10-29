<?php

/*
    the loop runtime (user's choices): List = one expression giving a list (syntax `value`); one pass per item, one at a time: each pass runs the steps
//inside the loop from its start step (the inner step without an incoming connection), with the item and its position in variables (names editable, default item / index);
//a pass ends when no token is left inside the loop (WfExecution::stepLoops). On error inside a pass: stop the workflow (default) or skip the item (the error collected).
//Collect (optional): an expression evaluated at the end of each pass, its values listed. After the last pass the loop continues on its output, with variable `loop` (name
//editable) = { count, results, errors }; the item / index variables are removed. Wait steps inside work: the list and the position are kept with the execution
*/

namespace models\node;

class NodeCoreLoop extends \models\WfNode {
    //static data/structure
    public static $type = 'core';
    public static $name = 'loop';
    public static $label = 'Loop';
    public static $icon = 'bi-arrow-repeat';
    public static $color = 'var(--wf-color-loop)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $isContainer = true;
    public static $defaultWidth = 240; //px
    public static $defaultHeight = 160; //px
    public static $minWidth = 240; //px
    public static $minHeight = 160; //px

    //items per loop
    const MAX_ITEMS = 1000;

    const ON_ERROR = [ 'stop' => 'Stop the workflow', 'skip' => 'Skip the item and continue' ];

    public static function configSchema() {
	$identifier = [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ];
	//CLD: 2026-10-14T14:27:53+03:00: pattern from the validate rule (util\WidgetsUtil; was repeated here)
	$nameUi = [ 'width' => 6, 'variableName' => 'write' ];
	return [
	    [
		'key' => 'list',
		'type' => 'text',
		'label' => 'List',
		'required' => true,
		'help' => 'One expression giving a list, at most ' . self::MAX_ITEMS . ' items',
		'ui' => [ 'expression' => 'value', 'placeholder' => 'e.g. {{ mail.attachments }}' ],
	    ],
	    [
		'key' => 'itemVariable',
		'type' => 'text',
		'label' => 'Item variable',
		'required' => true,
		'default' => 'item',
		'help' => 'The current item, inside the loop',
		'validate' => $identifier,
		'ui' => $nameUi,
	    ],
	    [
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'indexVariable',
		'type' => 'text',
		'label' => 'Position variable',
		'required' => true,
		'default' => 'index',
		'help' => 'The current item\'s position (0, 1, ...)',
		'validate' => $identifier,
		'ui' => $nameUi,
		'ui' => [ 'advanced' => true ],
	    ],
	    [
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'collect',
		'type' => 'text',
		'label' => 'Collect',
		'help' => 'Optional: evaluated at the end of each pass, the values listed in the result\'s `results`, e.g. {{ ai.json }}',
		'ui' => [ 'advanced' => true, 'expression' => true, 'placeholder' => 'e.g. {{ item.filename }}' ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'loop',
		'help' => 'After the loop: { count, results, errors }',
		'validate' => $identifier,
		'ui' => $nameUi,
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error in a pass',
		'required' => true,
		'default' => 'stop',
		'choices' => self::ON_ERROR,
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //the item and position names must differ
    public function validates($options = []) {
	$valid = parent::validates($options);
	if ((string)$this->cfg('itemVariable', 'item') === (string)$this->cfg('indexVariable', 'index')) {
	    $this->errors('config', 'Position variable: must differ from the item variable');
	    $valid = false;
	}
	return $valid;
    }

    //the variables of each pass, for the steps inside: the item typed as the list's items (Workflow::variablesAt: itemsOf)
    public static function innerVariables($config) {
	$config = is_array($config) ? $config : [];
	$vars = [];
	$item = (string)($config['itemVariable'] ?? 'item');
	$index = (string)($config['indexVariable'] ?? 'index');
	if (\util\Validator::isIdentifier($item))
	    $vars[$item] = [ 'type' => 'any', 'info' => 'the current item of the loop', 'itemsOf' => (string)($config['list'] ?? '') ];
	if (\util\Validator::isIdentifier($index))
	    $vars[$index] = [ 'type' => 'number', 'info' => 'the position of the current item (0, 1, ...)' ];
	return $vars;
    }

    //after the loop: its result
    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'loop') : 'loop';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the loop\'s result: { count, results, errors }', 'fields' => [
	    'count' => [ 'type' => 'number', 'info' => 'the passes' ],
	    'results' => [ 'type' => 'array', 'info' => 'the Collect values, one per pass (none when Collect is empty)' ],
	    'errors' => [ 'type' => 'array', 'info' => 'skipped items', 'items' => [ 'type' => 'object', 'fields' => [ 'index' => [ 'type' => 'number' ], 'step' => [ 'type' => 'string' ],
		'error' => [ 'type' => 'string' ] ] ] ],
	] ] ] : [];
    }

    // ------------------------------------------------------------------------------------
    //running (WfExecution::stepLoops drives the passes)

    //a token arrives: the list is evaluated and kept; the passes start (WfExecution::stepLoops calls next() while the loop is active and nothing runs inside it)
    public function execute($edgeId = null) {
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables (vars)
	$items = \util\Expression::render((string)$this->cfg('list', ''), $this->exec->expressionVariables(), 'value');
	if (!is_array($items) || !array_is_list($items))
	    throw new \ErrorException('Loop: List does not give a list (it gives ' . (is_array($items) ? 'an object' : get_debug_type($items)) . ')');
	if (count($items) > self::MAX_ITEMS)
	    throw new \ErrorException('Loop: the list has ' . count($items) . ' items, at most ' . self::MAX_ITEMS);

	$this->items = $items;
	$this->index = -1;
	$this->results = [];
	$this->failures = [];
	$this->active = true;
	$this->exec->log($this->wfid, 'loop: ' . count($items) . ' item(s)');
	return null;
    }

    //the end of a pass (or the start, index -1): Collect, then the next item -> 'enter' (the caller starts the inner flow), or the end -> the output ports
    public function next() {
	$vars = $this->exec->expressionVariables();
	$items = is_array($this->items) ? array_values($this->items) : [];
	$index = (int)$this->index;
	if ($index >= 0 && empty($this->skipped) && trim((string)$this->cfg('collect', '')) !== '') {
	    $results = is_array($this->results) ? $this->results : [];
	    $results[] = \util\Expression::render((string)$this->cfg('collect', ''), $vars);
	    $this->results = $results;
	}
	$this->skipped = false;

	$index++;
	$this->index = $index;
	if ($index < count($items)) {
	    $this->exec->setVariable((string)$this->cfg('itemVariable', 'item'), $items[$index]);
	    $this->exec->setVariable((string)$this->cfg('indexVariable', 'index'), $index);
	    return 'enter';
	}

	//done: the result; the pass variables go away
	$all = $this->exec->variables;
	unset($all[(string)$this->cfg('itemVariable', 'item')], $all[(string)$this->cfg('indexVariable', 'index')]);
	$this->exec->variables = $all;
	$failures = is_array($this->failures) ? $this->failures : [];
	$this->exec->setVariable((string)$this->cfg('variable', 'loop'), [ 'count' => count($items), 'results' => is_array($this->results) ? $this->results : [], 'errors' => $failures ]);
	$this->exec->log($this->wfid, 'loop: done, ' . count($items) . ' pass(es)' . ($failures ? ', ' . count($failures) . ' skipped' : ''));
	$this->items = null;
	$this->active = false;
	$this->state = 'done';
	$this->runs = (int)$this->runs + 1;
	$this->end_at = new \MongoDB\BSON\UTCDateTime();
	return [ 0 ];
    }

    //a step inside failed and the loop skips the item: the error is listed, the pass ends (its Collect is not evaluated)
    public function skip($stepTitle, $message) {
	$failures = is_array($this->failures) ? $this->failures : [];
	$failures[] = [ 'index' => (int)$this->index, 'step' => (string)$stepTitle, 'error' => (string)$message ];
	$this->failures = $failures;
	$this->skipped = true;
	$this->exec->log($this->wfid, 'loop: item ' . (int)$this->index . ' skipped: ' . $message);
    }
}
