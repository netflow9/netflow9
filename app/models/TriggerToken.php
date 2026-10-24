<?php

/*
    one index of the triggers reached by a token:
	Webhook (/hook/<token>),
	Form (/form/<token>),
	Email inbound (<account mail_prefix>-<token>@MAIL_INBOUND_DOMAIN, read by Haraka:

    kept while unpublished: the trigger then answers "not published"

    the steps are a map in the workflow, so they cannot be searched by token.
    A trigger class takes part with tokenData($node):
	the extra data its entry keeps (e.g. a form's access); its token format: const TOKEN_FORMAT (a key of TriggerToken::FORMATS, default `hex`)
    checkToken($node, $what) (its checks when the workflow is saved); were repeated in each trigger's initConfig() / validates()
*/

namespace models;

class TriggerToken extends \sys\BaseModel {
    protected static $collection = 'trigger_tokens';
    public static $indexes = [
	[ [ 'token' => 1 ], [ 'unique' => true ] ],
	[ [ 'wf' => 1 ], [] ],
	[ [ 'aid' => 1, 'project' => 1, 'type' => 1 ], [] ],
    ];

    public function getSchema() {
	return [
	    [
		'key' => 'type',
		'type' => 'text',
		'label' => 'Step type',
		'required' => true
	    ],
	    [
		'key' => 'token',
		'type' => 'text',
		'label' => 'Token',
		'required' => true,
		'perm:read' => 'nobody'
	    ],
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'required' => true
	    ],
	    [
		'key' => 'node',
		'type' => 'text',
		'label' => 'Step',
		'required' => true
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Title'
	    ],
	    [
		'key' => 'data',
		'type' => 'array',
		'label' => 'Data'
	    ],
	];
    }

    //the entries of workflow $wf: its published version's token triggers
    public static function syncForWorkflow($wf) {
	foreach(\V::app()->om->find('TriggerToken', [ 'wf' => $wf->_id ]) as $old)
	    $old->delete();
	$defs = \models\WfNode::getAll();
	$version = $wf->publishedVersion();
	foreach((is_array($version->nodes ?? null) ? $version->nodes : []) as $n) {
	    $def = empty($n['isNode']) ? null : ($defs[$n['fqname'] ?? ''] ?? null);
	    $class = $def ? \V::app()->om->getObjectClass($def['objectName']) : null;
	    //CLD: 2026-10-18T15:00:00+03:00: self::isToken (was $class::isToken)
	    if (!$class || !method_exists($class, 'tokenData'))
		continue;
	    //CLD: 2026-10-21T11:30:00+03:00: the class's TOKEN_FORMAT, default `hex`, read here (was self::formatOf($class), never defined: saving a workflow whose published version
	    //had a Webhook / Form / Email inbound step was a fatal error)
	    $format = defined($class . '::TOKEN_FORMAT') ? constant($class . '::TOKEN_FORMAT') : 'hex';
	    if (!self::isToken($n['config']['token'] ?? null, $format))
		continue;

	    \V::app()->om->create('TriggerToken', [
		'type' => $n['fqname'],
		'token' => $n['config']['token'],
		'aid' => $wf->aid,
		'project' => $wf->project,
		'wf' => $wf->_id,
		'node' => $n['wfid'],
		'title' => (string)($n['title'] ?? ''),
		'data' => $class::tokenData($n),
	    ])->save();
	}
    }

    //a token used by a workflow other than $wfId (null: by any)
    public static function tokenTakenElsewhere($token, $wfId) {
	return \V::app()->om->count('TriggerToken', [ 'token' => $token, 'wf' => [ '$ne' => $wfId ] ]) > 0;
    }

    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('TriggerToken', [ 'wf' => $wf->_id ]) as $entry)
	    $entry->delete();
    }


    //CLD: 2026-10-18T15:00:00+03:00: the token formats: `hex` 32 hex digits (Webhook, Form: in their URLs), `code` 8 lower-case letters / digits (Email: the part of the address after the account prefix)
    const FORMATS = [
	'hex' => '/^[a-f0-9]{32}$/',
	'code' => '/^[a-z0-9]{8}$/'
    ];

    public static function isToken($token, $format = 'hex', $prefix = '') {
	if (!is_string($token) || !isset(self::FORMATS[$format]) || !str_starts_with($token, (string)$prefix))
	    return false;
	return preg_match(self::FORMATS[$format], substr($token, strlen((string)$prefix))) === 1;
    }

    public static function newToken($format = 'hex', $prefix = '') {
	do {
	    $token = (string)$prefix . ($format === 'code'
		? substr(str_pad(base_convert(bin2hex(random_bytes(6)), 16, 36), 8, '0', STR_PAD_LEFT), -8)
		: bin2hex(random_bytes(16)));
	} while (self::tokenTakenElsewhere($token, null));
	return $token;
    }

    public static function checkToken($node, $format) {
	$token = $node->cfg('token');
	if (!self::isToken($token, $format)) {
	    $node->errors('config', "missing or invalid token (remove the step and add it again)");
	    return false;
	}

	$valid = true;
	$wf = $node->workflow;
	if (self::tokenTakenElsewhere($token, $wf->_id ?? null)) {
	    $node->errors('config', "used by another workflow");
	    $valid = false;
	}

	$fqname = $node::$type . '::' . $node::$name;
	$same = 0;
	foreach((is_array($wf->nodes ?? null) ? $wf->nodes : []) as $n) {
	    if (($n['fqname'] ?? '') === $fqname && ($n['config']['token'] ?? null) === $token)
		$same++;
	}
	if ($same > 1) {
	    $node->errors('config', "shared with another step");
	    $valid = false;
	}
	return $valid;
    }
}
