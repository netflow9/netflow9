<?php

namespace models\node;

class NodeAppSet extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'set';
    public static $label = 'Set';
    public static $icon = 'bi-pencil-fill';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	return [
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Variable',
		'required' => true,
		'help' => 'Letters, digits or underscore',
		'validate' => [
		    [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ]
		],
		'ui' => [
		    'placeholder' => 'variable_name',
		    'variableName' => 'write'
		],
	    ],
	    [
		'key' => 'value',
		'type' => 'textarea',
		'label' => 'Value',
		'help' => 'Text',
		'ui' => [
		    'rows' => 3,
		    'expression' => true
		],
	    ],
	];
    }

    //CLD: 2026-10-11T22:38:36+03:00: the variable it sets (its value is text)
    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? '') : '';
	//CLD: 2026-10-13T12:34:29+03:00: `from`: its type is the value's (Workflow::variablesAt, util\Expression::typeOf: {{ mail.attachments }} gives a list of attachments; a text, string)
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'any', 'info' => 'set by a Set step', 'from' => (string)($config['value'] ?? '') ] ] : [];
    }

    //CLD: 2026-10-06T23:21:04+03:00: sets execution variable `variable` to `value` (text, as entered)
    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', '');
	if (!\util\Validator::isIdentifier($name))
	    throw new \ErrorException("Set: invalid variable name `{$name}`");

	//CLD: 2026-10-11T22:53:04+03:00: {{ }} expressions evaluated (a single expression keeps its type: number, list, ...)
	$value = $this->expr('value', '');
	$this->exec->setVariable($name, $value);
	$this->exec->log($this->wfid, "set {$name} = " . mb_substr(\util\Expression::toText($value), 0, 200));
	return [ 0 ];
    }

}
