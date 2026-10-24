<?php
/*
    a project variable, a plain name = value that expressions see as {{ vars.<name> }}
    Not for node configs ($provides is empty), not encrypted, no connection test
*/

namespace models\credential;

class CredentialVariable extends \models\Credential {
    public static $label = 'Variable';
    public static $icon = 'bi-code-square';
    public static $group = 'Other';
    public static $provides = [];

    //the name expressions see the project variables under: {{ vars.<name> }} (was models\Variable::EXPRESSION_NAME)
    const EXPRESSION_NAME = 'vars';

    public function getSchema() {
	$fields = parent::getSchema();
	//the name is an identifier: it is read as vars.<name>
	foreach($fields as &$f) {
	    if (($f['key'] ?? null) !== 'name')
		continue;
	    $f['help'] = 'Used in expressions as {{ vars.<name> }}';
	    array_unshift($f['validate'], [ 'identifier', 'message' => 'Only letters, digits or underscore, not starting with a digit' ]);
	}
	unset($f);

	return array_merge($fields, [
	    [
		'key' => 'value',
		'type' => 'text',
		'label' => 'Value',
		'help' => false,
		'default' => '',
	    ],
	    [
		'key' => 'description',
		'type' => 'text',
		'label' => 'Description',
		'help' => 'Shown in the expression editors\' suggestions',
		'default' => '',
	    ],
	]);
    }

    //the project variables as expressions see them, {{ vars.<name> }}: values [ name => value ] (WfExecution::expressionVariables)
    public static function expressionValues($aid, $project) {
	$values = [];
	foreach(\V::app()->om->find('CredentialVariable', [ 'aid' => $aid, 'project' => $project ], [ 'sort' => [ 'name' => 1 ] ]) as $v)
	    $values[(string)$v->name] = (string)$v->value;
	return $values;
    }

    //and their type description for the editors' help (WorkflowController::expression: suggestions after `vars.`, warnings for a name the project does not have)
    public static function expressionType($aid, $project) {
	$fields = [];
	foreach(\V::app()->om->find('CredentialVariable', [ 'aid' => $aid, 'project' => $project ], [ 'sort' => [ 'name' => 1 ] ]) as $v)
	    $fields[(string)$v->name] = [ 'type' => 'string', 'info' => trim((string)$v->description) !== '' ? (string)$v->description : 'project variable' ];
	return [ 'type' => 'object', 'info' => 'the project variables (menu: Credentials, type Variable)', 'fields' => $fields ];
    }
}
