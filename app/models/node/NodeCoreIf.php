<?php

namespace models\node;

class NodeCoreIf extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'core';
    public static $name = 'if';
    public static $label = 'If';
    public static $icon = 'bi-signpost-split-fill';
    public static $inputs = 1;
    public static $outputs = 2;
    public static $outLabels = [ 'true', 'false' ];

    const MAX_CONDITIONS = 20;

    //model field definitions with rules (WfNode::configForm(), a sys\NestedModel)
    //several conditions, all or any of them (match); a condition's left side is an expression ({{ mail.subject }},
    //{{ mail.to.length }}: object variables and their fields); shown as the rows of the data grid filter (editgrid layout `rows`)
    public static function configSchema() {
	return [
	    [
		'key' => 'match',
		'type' => 'select',
		'label' => 'True when',
		'required' => true,
		'default' => 'all',
		'choices' => [
		    'all' => 'All conditions hold',
		    'any' => 'Any condition holds'
		],
	    ],
	    //a nested list (sys\fields\Field_nested): each condition cast and validated by
	    //conditionSchema(), its expressions checked (WfNode::expressionErrors), errors as "Condition <n>: <field>: <message>"; the editgrid's columns come from it
	    [
		'key' => 'conditions',
		'type' => 'nested',
		'multiple' => true,
		'fields' => self::conditionSchema(),
		'label' => 'Conditions',
		'itemLabel' => 'Condition',
		'default' => [ [ 'left' => '', 'operator' => 'eq', 'value' => '' ] ],
		'help' => 'Compared as numbers when both sides are numbers, else as text; a list "contains" one of its items, "is empty" when it has none',
		'ui' => [
		    'layout' => 'rows',
		    'addLabel' => 'Add condition',
		    'emptyText' => 'No conditions yet: add one below',
		    'minRows' => 1,
		    'maxRows' => self::MAX_CONDITIONS
		],
	    ],
	];
    }

    //one condition: an expression, an operator, a value (an expression or a text)
    public static function conditionSchema() {
	return [
	    [
		'key' => 'left',
		'type' => 'text',
		'label' => 'Test',
		'required' => true,
		'help' => false,
		'ui' => [
		    'width' => 5,
		    'expression' => 'reference',
		    'placeholder' => 'e.g. {{ mail.subject }}'
		]
	    ],
	    [
		'key' => 'operator',
		'type' => 'select',
		'label' => 'Operator',
		'required' => true,
		'default' => 'eq',
		'choices' => \util\Conditions::operators(),
		'ui' => [
		    'width' => 3
		],
	    ],
	    [
		'key' => 'value',
		'type' => 'text',
		'label' => 'Value',
		'help' => false,
		'ui' => [
		    'width' => 4,
		    'expression' => true,
		    'placeholder' => 'value',
		    'hideIf' => [
			'key' => 'operator', 'operator' => 'in', 'value' => [ 'empty', 'notEmpty' ], 'scope' => 'row'
		    ]
		] 
	    ],
	];
    }


    //at least one condition; each condition (required fields, operator, expressions) is checked by parent::validates (the nested field; the left side must be an expression:
    //field syntax `reference`, plain text is refused by the parser); here only how many there are
    public function validates($options = []) {
	$valid = parent::validates($options);
	$conditions = $this->cfg('conditions', []);
	$conditions = is_array($conditions) ? array_values($conditions) : [];
	if (!$conditions) {
	    $this->errors('config', 'Conditions: add at least one');
	    return false;
	}
	if (count($conditions) > self::MAX_CONDITIONS) {
	    $this->errors('config', 'Conditions: at most ' . self::MAX_CONDITIONS);
	    $valid = false;
	}
	return $valid;
    }

    //the conditions evaluated in order (any: up to the first that holds; all: up to the first that does not); output 0 = true, 1 = false
    public function execute($edgeId = null) {
	$any = $this->cfg('match', 'all') === 'any';
	$conditions = $this->cfg('conditions', []);
	$conditions = is_array($conditions) ? array_values($conditions) : [];
	if (!$conditions)
	    throw new \ErrorException('If: no conditions');
	//the project variables (vars)
	$vars = $this->exec->expressionVariables();
	$result = !$any;
	$log = [];
	foreach($conditions as $i => $c) {
	    $operator = (string)($c['operator'] ?? 'eq');
	    //the fields' operators, compared by util\Conditions::holds
	    if (!array_key_exists($operator, \util\Conditions::operators()))
		throw new \ErrorException('If: condition ' . ($i + 1) . ": unknown operator `{$operator}`");
	    //the left side keeps its type (a list for contains / is empty); the value is text
	    //in the syntax of its field (reference)
	    $left = \util\Expression::render((string)($c['left'] ?? ''), $vars, 'reference');
	    $right = in_array($operator, [ 'empty', 'notEmpty' ], true) ? '' : \util\Expression::toText(\util\Expression::render((string)($c['value'] ?? ''), $vars));
	    $holds = \util\Conditions::holds($left, $operator, $right);
	    $log[] = ($i + 1) . ': ' . mb_substr(\util\Expression::toText($left), 0, 60) . " {$operator}" . ($right !== '' ? ' ' . mb_substr($right, 0, 60) : '') . ' -> ' . ($holds ? 'true' : 'false');
	    if ($holds === $any) {
		$result = $any;
		break;
	    }
	}

	$this->exec->log($this->wfid, 'if (' . ($any ? 'any' : 'all') . ') ' . implode('; ', $log) . ' => ' . ($result ? 'true' : 'false'));
	return [ $result ? 0 : 1 ];
    }
}
