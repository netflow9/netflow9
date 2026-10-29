<?php
namespace models\node;

class NodeTriggerWebhook extends \models\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'webhook';
    public static $label = 'Webhook';
    public static $icon = 'bi-broadcast';
    public static $color = 'var(--wf-color-trigger)';
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

    //CLD: 2026-10-08T08:19:49+03:00: the step's own checks (called by Workflow::validates): a well-formed token, not used by another workflow
    //(else saving it would take over that workflow's webhook) and not shared with another step of this workflow
    //CLD: 2026-10-18T14:40:00+03:00: the checks are TriggerToken::checkToken (were here)
    public function validates($options = []) {
	$valid = parent::validates($options);
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    //CLD: 2026-10-09T08:10:30+03:00: model field definitions with rules (WfNode::configForm(), a sys\NestedModel; were app.smartform fields); the token is not a field (set by
    //initConfig, checked in validates()); the URL is shown read-only (perm:update nobody: a posted URL is ignored)
    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'URL',
		'perm:update' => 'nobody',
		'help' => 'Unique to this step, active once the workflow is saved; keep it secret: anyone who has it can start the workflow',
		//CLD: 2026-10-08T08:10:24+03:00: generated when the step is added (initConfig); it works once the workflow is saved
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'method',
		'type' => 'select',
		'label' => 'HTTP method',
		'required' => true,
		'default' => 'POST',
		'choices' => self::METHODS,
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
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
		//CLD: 2026-10-24T12:55:48+03:00: layout: 6 wide
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'respond',
		'type' => 'select',
		'label' => 'Respond',
		'required' => true,
		'default' => 'received',
		'choices' => self::RESPONDS,
		'ui' => [ 'advanced' => true, 'width' => 6 ],
	    ],
	    //CLD: 2026-10-07T11:36:11+03:00: request authentication
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide, on a new line
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Authentication',
		'required' => true,
		'default' => 'none',
		'choices' => self::AUTHS,
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide
		'key' => 'authHeader',
		'type' => 'text',
		'label' => 'Header name',
		'default' => '',
		'help' => 'HMAC: the header carries the hex signature (or sha256=<hex>)',
		//CLD: 2026-10-14T15:30:03+03:00: a field property (Field adds the rule; was the requiredIf rule in `validate`)
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'header', 'hmac' ] ],
		'validate' => [
		    [ 'regex', 'pattern' => '^[A-Za-z0-9-]+$', 'skipEmpty' => true, 'message' => 'Letters, digits and dashes' ],
		],
		'ui' => [ 'width' => 3,
		    'placeholder' => 'X-Api-Key',
		    'showIf' => [ 'key' => 'auth', 'operator' => 'in', 'value' => [ 'header', 'hmac' ] ] ],
	    ],
	    //a text field shown as a password input: the step's config is edited in the designer, so the secret is part of it
	    //CLD: 2026-10-10T18:32:31+03:00: app.smartform `password` field (show/hide button, no autofill of saved logins; was a text input with inputType password)
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 6 wide
		'key' => 'authSecret',
		'type' => 'text',
		'label' => 'Secret',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'header', 'bearer', 'hmac' ] ],
		'ui' => [ 'width' => 6, 'type' => 'password', 'showIf' => [ 'key' => 'auth', 'operator' => 'in', 'value' => [ 'header', 'bearer', 'hmac' ] ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide (was 6)
		'key' => 'authUser',
		'type' => 'text',
		'label' => 'Username',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'basic' ] ],
		'ui' => [ 'width' => 3, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'basic' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: layout: 3 wide (was 6)
		'key' => 'authPassword',
		'type' => 'text',
		'label' => 'Password',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'basic' ] ],
		'ui' => [ 'width' => 3, 'type' => 'password', 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'basic' ] ],
	    ],
	    //CLD: 2026-10-17T10:46:42+03:00: the variable the request goes into (one object, see outputVariables)
	    [
		//CLD: 2026-10-24T12:55:48+03:00: shorter help (was: The request as one object, e.g. {{ webhook.body }}, {{ webhook.query.id }}, {{ webhook.headers["user-agent"] }})
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
