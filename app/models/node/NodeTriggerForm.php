<?php
namespace models\node;

class NodeTriggerForm extends \models\WfNode {
    public static $type = 'trigger';
    public static $name = 'form';
    public static $label = 'Form';
    public static $icon = 'bi-ui-checks';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    const ACCESS = [
	'members' => 'Project members (logged in)',
	'public' => 'Anyone with the link'
    ];

    public static function tokenData(array $node) {
	return [ 'access' => ($node['config']['access'] ?? 'members') === 'public' ? 'public' : 'members' ];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/form/' . $token) ];
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Page',
		'perm:update' => 'nobody',
		'help' => 'The form\'s address; it works once the workflow is published',
		'ui' => [ 'placeholder' => 'Generating…', 'width' => 8 ],
	    ],
	    [
		'key' => 'access',
		'type' => 'select',
		'label' => 'Who can fill it in',
		'required' => true,
		'default' => 'members',
		'choices' => self::ACCESS,
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'form',
		'type' => 'array',
		'label' => 'Form',
		'required' => true,
		'default' => [ 'direction' => 'vertical', 'fields' => [] ],
		'ui' => [
		    'type' => 'formdesigner'
		],
	    ],
	    [
		'key' => 'submitLabel',
		'type' => 'text',
		'label' => 'Button',
		'default' => 'Send',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'message',
		'type' => 'text',
		'label' => 'Message after sending',
		'default' => 'Thank you, your answers were sent.',
		'ui' => [ 'width' => 8 ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: shorter help (was: The answers, by key ({{ form.amount }}); who sent them: <variable>_user)
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Answers variable',
		'required' => true,
		'default' => 'form',
		'help' => 'The answers by key; who sent them: <variable>_user',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [
		    'width' => 6,
		    'variableName' => 'write'
		],
	    ],
	];
    }

    //the form stored normalized (util\SmartForm: only known nodes / properties, showIf made from the conditions); token checks as the webhook's
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$config['form'] = \util\SmartForm::normalize($config['form'] ?? []);
	$this->config = $config;
	foreach(\util\SmartForm::errors($config['form']) as $m) {
	    $this->errors('config', "Form: {$m}");
	    $valid = false;
	}
	if (!\util\SmartForm::inputs($config['form'])) {
	    $this->errors('config', 'Form: add at least one field');
	    $valid = false;
	}
	//CLD: 2026-10-18T14:40:00+03:00: the token checks are TriggerToken::checkToken (were here)
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    //the answers (typed by the form) and who sent them
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = (string)($config['variable'] ?? 'form');
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [
	    $name => \util\SmartForm::type(\util\SmartForm::normalize($config['form'] ?? []), 'the answers of the form'),
	    $name . '_user' => [ 'type' => 'object', 'info' => 'who sent the form (null: someone not logged in)',
		'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ] ] ],
	];
    }
}
