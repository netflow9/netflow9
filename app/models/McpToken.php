<?php

/*
    an access token of the project's MCP server (controllers\McpController, /mcp).
    Made in the project (menu: MCP server); the token itself is shown once, only its SHA-256 hash is kept (token_hash) with its first characters (token_prefix) to recognise it.
    A call with the token acts as the user who made it (`user`), within that user's permissions, on this project's data only.
    last_used_at is written directly (om->updateMany: not an audit entry)
*/

namespace models;

class McpToken extends \sys\BaseModel {
    protected static $collection = 'mcp_tokens';

    //tokens of a project; a token by its hash (created by `v sys::indexes`)
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1 ], [] ],
	[ [ 'token_hash' => 1 ], [ 'unique' => true ] ],
    ];

    //nfn_ + 40 hex characters
    const PREFIX = 'nfn_';

    public function getSchema() {
	$fields = [
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => 'What uses it, e.g. Claude Desktop',
		'required' => true,
		'validate' => [
		    [ 'uniqueInProject', 'message' => 'This name already exists', 'collection' => self::_collection() ],
		],
	    ],
	    [
		'key' => 'token_prefix',
		'type' => 'text',
		'label' => 'Token',
		'help' => false,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //password type: masked in the audit log, never shown
	    [
		'key' => 'token_hash',
		'type' => 'password',
		'label' => 'Token hash',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //the user the calls act as (who made the token)
	    [
		'key' => 'user',
		'type' => 'object',
		'label' => 'Acts as',
		'object' => 'User',
		'help' => false,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'last_used_at',
		'type' => 'datetime',
		'label' => 'Last used',
		'help' => false,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //a new token: sets token_hash / token_prefix, returns the token (to be shown once)
    public function generate() {
	$token = self::PREFIX . bin2hex(random_bytes(20));
	$this->token_hash = hash('sha256', $token);
	$this->token_prefix = substr($token, 0, strlen(self::PREFIX) + 6) . '…';
	return $token;
    }

    //the token record of $token, or null
    public static function findByToken($token) {
	$token = (string)$token;
	if (!preg_match('/^' . self::PREFIX . '[a-f0-9]{40}$/', $token))
	    return null;
	return \V::app()->om->findOne('McpToken', [ 'token_hash' => hash('sha256', $token) ]);
    }

    //as models\Credential: global scope = the account's tokens, project scope = the tokens of the projects the user is a member of
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	return \V::app()->om->findById('Project', $this->project)?->checkAccess($userId, $permName) ?? false;
    }

    public function __toString() {
	return (string)$this->name;
    }
}
