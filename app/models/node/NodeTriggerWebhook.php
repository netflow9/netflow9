<?php
namespace models\node;

class NodeTriggerWebhook extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'webhook';
    public static $label = 'Webhook';
    public static $icon = 'bi-broadcast';
    //the step picker: an advanced step
    public static $audience = 'advanced';
    public static $inputs = 0;
    public static $outputs = 1;

    //accepted HTTP methods (ANY = all of them)
    const METHODS = [
	'ANY' => 'Any method',
	'GET' => 'GET',
	'POST' => 'POST',
	'PUT' => 'PUT',
	'PATCH' => 'PATCH',
	'DELETE' => 'DELETE',
    ];

    //accepted content types of the request body => label
    const CONTENT_TYPES = [
	'any' => 'Any',
	'json' => 'JSON (application/json)',
	'form' => 'Web form (application/x-www-form-urlencoded, multipart/form-data)',
	'text' => 'Plain text (text/*)',
    ];

    //when the caller gets the answer
    const RESPONDS = [
	'received' => 'When received (202, the workflow runs afterwards)',
	'finished' => 'When the workflow ends (200, with its state)',
    ];

    const AUTHS = [
	'none' => 'None (the secret URL only)',
	'header' => 'Header with a secret value',
	'bearer' => 'Bearer token (Authorization: Bearer ...)',
	'basic' => 'Basic auth (username / password)',
	'hmac' => 'HMAC-SHA256 signature of the body',
    ];

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'webhook') : 'webhook';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object',
	    'info' => 'the request: { method, path, url, query, headers, content_type, body, raw, ip, received_at }',
	    'fields' => [
		'method' => [ 'type' => 'string', 'info' => 'the HTTP method' ],
		'path' => [ 'type' => 'string', 'info' => 'the path called (/hook/<token>)' ],
		'url' => [ 'type' => 'string', 'info' => 'the full URL called, with the query string' ],
		'query' => [ 'type' => 'object', 'info' => 'the query string parameters, e.g. query.id' ],
		'headers' => [ 'type' => 'object', 'info' => 'lower-case name => value, e.g. headers["user-agent"] (without authorization, cookie and the secret header)' ],
		'content_type' => [ 'type' => 'string', 'info' => 'the content type of the body, without parameters' ],
		'body' => [ 'type' => 'any', 'info' => 'the decoded body (JSON or web form), else the body text' ],
		'raw' => [ 'type' => 'string', 'info' => 'the body text as received' ],
		'ip' => [ 'type' => 'string', 'info' => 'the caller address' ],
		'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    ] ] ] : [];
    }

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/hook/' . $token) ];
    }

    //the step's own checks (called by Workflow::validates; TriggerToken::checkToken): a well-formed token, not used by another workflow
    //(else saving it would take over that workflow's webhook) and not shared with another step of this workflow
    public function validates($options = []) {
	$valid = parent::validates($options);
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    //model field definitions with rules (WfNode::configForm(), a sys\NestedModel); the token is not a field (set by
    //initConfig, checked in validates()); the URL is shown read-only (perm:update nobody: a posted URL is ignored)
    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'URL',
		'perm:update' => 'nobody',
		'help' => 'Unique to this step, active once the workflow is saved; keep it secret: anyone who has it can start the workflow',
		//generated when the step is added (initConfig); it works once the workflow is saved
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		//layout: 3 wide, on a new line
		'key' => 'method',
		'type' => 'select',
		'label' => 'HTTP method',
		'required' => true,
		'default' => 'POST',
		'choices' => self::METHODS,
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//under "Advanced…" (the designer's closed section of the technical settings); 3 wide
		'key' => 'contentType',
		'type' => 'select',
		'label' => 'Content type',
		'required' => true,
		'default' => 'json',
		'choices' => self::CONTENT_TYPES,
		'help' => 'Of the request body (not checked for GET)',
		'ui' => [ 'advanced' => true, 'width' => 3 ],
	    ],
	    [
		//under "Advanced…" (the designer's closed section of the technical settings); 6 wide
		'key' => 'respond',
		'type' => 'select',
		'label' => 'Respond',
		'required' => true,
		'default' => 'received',
		'choices' => self::RESPONDS,
		'ui' => [ 'advanced' => true, 'width' => 6 ],
	    ],
	    //request authentication
	    [
		//layout: 3 wide, on a new line
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Authentication',
		'required' => true,
		'default' => 'none',
		'choices' => self::AUTHS,
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//layout: 3 wide
		'key' => 'authHeader',
		'type' => 'text',
		'label' => 'Header name',
		'default' => '',
		'help' => 'HMAC: the header carries the hex signature (or sha256=<hex>)',
		//a field property (Field adds the rule)
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'header', 'hmac' ] ],
		'validate' => [
		    [ 'regex', 'pattern' => '^[A-Za-z0-9-]+$', 'skipEmpty' => true, 'message' => 'Letters, digits and dashes' ],
		],
		'ui' => [ 'width' => 3,
		    'placeholder' => 'X-Api-Key',
		    'showIf' => [ 'key' => 'auth', 'operator' => 'in', 'value' => [ 'header', 'hmac' ] ] ],
	    ],
	    //a text field shown as a password input: the step's config is edited in the designer, so the secret is part of it
	    //app.smartform `password` field (show/hide button, no autofill of saved logins)
	    [
		//layout: 6 wide
		'key' => 'authSecret',
		'type' => 'text',
		'label' => 'Secret',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'header', 'bearer', 'hmac' ] ],
		'ui' => [ 'width' => 6, 'type' => 'password', 'showIf' => [ 'key' => 'auth', 'operator' => 'in', 'value' => [ 'header', 'bearer', 'hmac' ] ] ],
	    ],
	    [
		//layout: 3 wide
		'key' => 'authUser',
		'type' => 'text',
		'label' => 'Username',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'basic' ] ],
		'ui' => [ 'width' => 3, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'basic' ] ],
	    ],
	    [
		//layout: 3 wide
		'key' => 'authPassword',
		'type' => 'text',
		'label' => 'Password',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'basic' ] ],
		'ui' => [ 'width' => 3, 'type' => 'password', 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'basic' ] ],
	    ],
	    //the variable the request goes into (one object, see outputVariables)
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Request variable',
		'required' => true,
		'default' => 'webhook',
		'help' => 'e.g. {{ webhook.body }}, {{ webhook.query.id }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	];
    }
}
