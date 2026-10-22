<?php

//Switch (from If): one variable compared case by case (the If operators); each case has its own output, named by
//the user (the case's `label`), and a last output `otherwise` when no case matches. The first matching case wins. The outputs follow the cases
//($outPorts: the designer, Workflow::validateFlow and outputPorts() read them from the step's config)

namespace models\node;

class NodeCoreSwitch extends \models\WfNode {
    //static data/structure
    public static $type = 'core';
    public static $name = 'switch';
    public static $label = 'Switch';
    public static $icon = 'bi-diagram-3-fill';
    public static $color = 'var(--wf-color-condition)';
    public static $inputs = 1;
    //the outputs of a new step (the default case + otherwise); the step's own come from its cases ($outPorts)
    public static $outputs = 2;
    public static $outLabels = [ 'case_1', self::OTHERWISE ];
    public static $outPorts = [ 'rows' => 'cases', 'label' => 'label', 'after' => [ self::OTHERWISE ] ];

    //the label of the last output (no case matched)
    const OTHERWISE = 'otherwise';

    //cases per step
    const MAX_CASES = 20;

    public static function configSchema() {
	return [
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Variable',
		'required' => true,
		'ui' => [
		    'placeholder' => 'e.g. {{ mail.subject }}',
		    'expression' => 'reference'
		],
	    ],
	    //CLD: 2026-10-18T10:45:00+03:00: a nested list (sys\fields\Field_nested; was an array checked case by case in validates()): each case cast and validated by caseSchema(),
	    //its expressions checked, errors as "Case <n>: <field>: <message>"; the edit grid's columns come from caseSchema()
	    [
		'key' => 'cases',
		'type' => 'nested',
		'multiple' => true,
		'fields' => self::caseSchema(),
		'label' => 'Cases',
		'itemLabel' => 'Case',
		'help' => 'Checked in order: the first matching case continues on its output, else `' . self::OTHERWISE . '`',
		'required' => true,
		'default' => [ [ 'label' => 'case_1', 'operator' => 'eq', 'value' => '' ] ],
		'ui' => [
		    'minRows' => 1,
		    'maxRows' => self::MAX_CASES,
		    'addLabel' => 'Add case',
		    'compact' => true,
		],
	    ],
	];
    }

    //one case: its output's label, operator, value
    public static function caseSchema() {
	return [
	    [
		'key' => 'label',
		'type' => 'text',
		'label' => 'Output',
		'required' => true,
		//CLD: 2026-10-18T10:45:00+03:00: trimmed by the field (was by validates())
		'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_ .-]{1,30}$', 'skipEmpty' => true, 'message' => 'Up to 30 letters, digits, spaces or _ . -' ] ],
		'ui' => [ 'width' => 4, 'placeholder' => 'name' ],
	    ],
	    [
		'key' => 'operator',
		'type' => 'select',
		'label' => 'Operator',
		'required' => true,
		'default' => 'eq',
		'choices' => \util\Conditions::operators(),
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 5 ],
	    ],
	    [
		'key' => 'value',
		'type' => 'text',
		'label' => 'Value',
		'help' => false,
		//CLD: 2026-10-11T22:38:36+03:00: may hold expressions ({{ }}): live help in the designer
		//CLD: 2026-10-14T14:08:12+03:00: condition made by util\FormSchema::showIf (was JS written here)
		//CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by util\SmartForm::showIf)
		'ui' => [ 'width' => 3, 'hideIf' => [ 'key' => 'operator', 'operator' => 'in', 'value' => [ 'empty', 'notEmpty' ], 'scope' => 'row' ], 'expression' => true ],
	    ],
	];
    }

    //CLD: 2026-10-18T10:45:00+03:00: removed caseForm(): the cases are a nested field (its object is the case form)

    //CLD: 2026-10-18T10:45:00+03:00: each case (required fields, operator, label format, expressions) is checked and normalized by parent::validates (the nested field); here
    //how many there are and the output labels used twice (was: each case through the case form, errors as "Case <n>: <field>: <message>")
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cases = $this->cfg('cases');
	if (!is_array($cases))
	    return $valid;

	if (count($cases) > self::MAX_CASES) {
	    $this->errors('config', 'Cases: at most ' . self::MAX_CASES);
	    $valid = false;
	}

	$labels = [];
	foreach(array_values($cases) as $i => $case) {
	    $label = (string)(is_array($case) ? ($case['label'] ?? '') : '');
	    if ($label !== '' && (isset($labels[mb_strtolower($label)]) || mb_strtolower($label) === self::OTHERWISE)) {
		$this->errors('config', 'Case ' . ($i + 1) . ": the output `{$label}` is used twice (`" . self::OTHERWISE . '` is the last output)');
		$valid = false;
	    }
	    $labels[mb_strtolower($label)] = true;
	}
	return $valid;
    }

    //CLD: 2026-10-13T12:25:23+03:00: the variable is a reference ({{ mail.subject }}): its value, typed (a list for contains / is empty), by util\Expression::render (was the variable named)
    public function execute($edgeId = null) {
	$name = trim((string)$this->cfg('variable', ''));
	$cases = array_values(is_array($this->cfg('cases')) ? $this->cfg('cases') : []);
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables (vars)
	$left = \util\Expression::render($name, $this->exec->expressionVariables(), 'reference');
	foreach($cases as $i => $case) {
	    $operator = (string)($case['operator'] ?? 'eq');
	    //CLD: 2026-10-14T20:17:14+03:00: the fields' operators, compared by util\Conditions::holds (were NodeCoreIf::OPERATORS / compare())
	    if (!array_key_exists($operator, \util\Conditions::operators()))
		throw new \ErrorException("Switch: unknown operator `{$operator}`");
	    //CLD: 2026-10-11T22:53:04+03:00: {{ }} expressions evaluated, compared as text (or as numbers)
	    $right = \util\Expression::toText(\util\Expression::render((string)($case['value'] ?? ''), $this->exec->expressionVariables()));
	    if (\util\Conditions::holds($left, $operator, $right)) {
		$this->exec->log($this->wfid, "switch {$name}: case `" . ($case['label'] ?? $i + 1) . '`');
		return [ $i ];
	    }
	}

	$this->exec->log($this->wfid, "switch {$name}: " . self::OTHERWISE);
	return [ count($cases) ];
    }
}
