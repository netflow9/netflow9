<?php

/*
    Chat - people talk to the workflow. Published, it has a chat page in the app (/agent/<token>, listed under Agents for the users and roles allowed: project members,
	Participants too) and, optionally, a page to embed (/chat/<embed token>, no sign-in: AgentController, ChatController). Each message starts a run of the published version, with the variables:
	    message       what the person wrote (or the value of the button chosen)
	    conversation  { id, channel, turn, title, history: [ { role, text, at } ] (the newest turns), summary }
	    user          { id, name, email } (a guest on the embedded page: no id, no email)
	The workflow answers with Reply to chat (NodeAppChatReply); an AI step with Continue the conversation reads the earlier turns (models\agent\AgentConversation).
    The embed token is apart from the page's: the page's address is seen by everyone who may open it. It can be replaced: turn Embed off, save, turn it on, save, publish
*/

namespace models\node;

class NodeTriggerChat extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'chat';
    public static $label = 'Chat';
    public static $icon = 'bi-chat-dots';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    //what the token index keeps: who may open it, what the Agents list shows, the embed token
    public static function tokenData(array $node) {
	$c = (array)($node['config'] ?? []);
	$embed = !empty($c['embed']) && (string)($c['embed']) !== '0' && \models\TriggerToken::isToken($c['embedToken'] ?? null, 'hex');
	return [ 'users' => array_values(array_map('strval', (array)($c['users'] ?? []))), 'roles' => array_values(array_map('strval', (array)($c['roles'] ?? []))), 'description' => (string)($c['description'] ?? '') ]
	    + ($embed ? [ 'embed_token' => $c['embedToken'] ] : []);
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/agent/' . $token) ];
    }

    //the config of a workflow brought in by an import (util\Transfer): a new embed token, the other server's does not go on working here
    public static function forTransfer(array $config) {
	unset($config['embedToken'], $config['embedUrl']);
	if (!empty($config['embed'])) {
	    $config['embedToken'] = bin2hex(random_bytes(16));
	    $config['embedUrl'] = \V::app()->createAbsoluteUrl('/chat/' . $config['embedToken']);
	}
	return $config;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Page',
		'perm:update' => 'nobody',
		'help' => 'The chat page; also under Agents, once the workflow is published',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'users',
		'type' => 'select',
		'multiple' => true,
		'choices' => NodeAppTask::memberChoices(),
		'label' => 'Users allowed',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'None chosen' ],
	    ],
	    [
		'key' => 'roles',
		'type' => 'select',
		'multiple' => true,
		'choices' => NodeAppTask::roleChoices(),
		'label' => 'Roles allowed',
		'help' => 'No user and no role: any project member, Participants too',
		'ui' => [ 'width' => 6, 'placeholder' => 'None chosen' ],
	    ],
	    [
		'key' => 'description',
		'type' => 'text',
		'label' => 'Description',
		'default' => '',
		'help' => 'What this agent is for: under its name in Agents',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'greeting',
		'type' => 'text',
		'label' => 'Greeting',
		'default' => 'Hello, how can I help?',
		'help' => 'Shown before the first message',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'embed',
		'type' => 'switch',
		'label' => 'Embeddable page',
		'default' => false,
		'help' => 'Anyone with its link can chat, without signing in',
		'ui' => [ 'width' => 4, 'newRow' => true ],
	    ],
	    [
		'key' => 'embedUrl',
		'type' => 'text',
		'label' => 'Embed link',
		'perm:update' => 'nobody',
		'help' => 'For an iframe. Its token is made when you save; a new one: turn this off, save, turn it on',
		'ui' => [ 'width' => 8, 'placeholder' => 'Made when the workflow is saved', 'showIf' => [ 'key' => 'embed', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    [
		'key' => 'embedOrigins',
		'type' => 'text',
		'label' => 'Sites that may embed it',
		'default' => '',
		'help' => 'Addresses by commas, e.g. https://www.example.com; empty: any site',
		'ui' => [ 'showIf' => [ 'key' => 'embed', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    [
		'key' => 'testMessage',
		'type' => 'text',
		'label' => 'Message for a Run in the designer',
		'default' => 'Hello',
		'help' => 'Run has no person to ask, and its answers go nowhere (they are in the log)',
		'ui' => [ 'advanced' => true ],
	    ],
	];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$wf = $this->workflow;
	$project = $wf ? \V::app()->om->findOne('Project', [ '_id' => $wf->project, 'aid' => $wf->aid ]) : null;
	foreach((array)($config['users'] ?? []) as $uid) {
	    if (!$project || !isset($project->members[(string)$uid])) {
		$this->errors('config', 'Users allowed: one of them is not a member of the project');
		$valid = false;
		break;
	    }
	}
	foreach((array)($config['roles'] ?? []) as $rid) {
	    if (!$wf || !\util\Validator::rule('oid', (string)$rid) || !\V::app()->om->findOne('Role', [ '_id' => (string)$rid, 'aid' => $wf->aid ])) {
		$this->errors('config', 'Roles allowed: one of them is not a role of the account');
		$valid = false;
		break;
	    }
	}
	foreach(self::origins($config['embedOrigins'] ?? '') as $origin) {
	    if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $origin)) {
		$this->errors('config', "Sites that may embed it: `{$origin}` is not an address such as https://www.example.com");
		$valid = false;
	    }
	}
	//the embed token follows the switch: off drops it, on makes one
	if (!empty($config['embed']) && (string)$config['embed'] !== '0') {
	    if (!\models\TriggerToken::isToken($config['embedToken'] ?? null, 'hex'))
		$config['embedToken'] = bin2hex(random_bytes(16));
	    $config['embedUrl'] = \V::app()->createAbsoluteUrl('/chat/' . $config['embedToken']);
	} else {
	    unset($config['embedToken'], $config['embedUrl']);
	}
	$this->config = $config;
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    //setting Sites that may embed it as a list
    public static function origins($value) {
	return array_values(array_filter(array_map('trim', explode(',', (string)$value)), fn($o) => $o !== ''));
    }

    public static function outputVariables($config) {
	return self::conversationVariables();
    }

    //the variables of a run started by a message (this trigger; Email (inbound) and Teams message when they keep conversations)
    public static function conversationVariables() {
	return [
	    'message' => [ 'type' => 'string', 'info' => 'what the person wrote, or the value of the button chosen' ],
	    'conversation' => [ 'type' => 'object', 'info' => 'the conversation: { id, channel, turn, title, history, summary }', 'fields' => [
		'id' => [ 'type' => 'string' ], 'channel' => [ 'type' => 'string', 'info' => 'app, embed, email or teams' ], 'turn' => [ 'type' => 'number', 'info' => 'the number of this message' ], 'title' => [ 'type' => 'string' ],
		'history' => [ 'type' => 'array', 'info' => 'the earlier turns, newest ' . \models\agent\AgentConversation::HISTORY_TURNS, 'items' => [ 'type' => 'object', 'fields' => [ 'role' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ], 'at' => [ 'type' => 'string' ] ] ] ],
		'summary' => [ 'type' => 'string', 'info' => 'the older turns summarized (an AI step with Continue the conversation does it)' ] ] ],
	    'user' => [ 'type' => 'object', 'info' => 'who wrote it (a guest: no id, no email)', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ] ] ],
	];
    }

    //Run in the designer: the test message, no conversation
    public static function startNow($wf, array $node) {
	$user = \V::app()->user;
	$exe = \models\wf\WfExecution::startFor($wf, 'manual', [ 'vars' => [ 'message' => (string)($node['config']['testMessage'] ?? 'Hello') ?: 'Hello',
	    'conversation' => [ 'id' => null, 'channel' => 'test', 'turn' => null, 'title' => '', 'history' => [], 'summary' => '' ],
	    'user' => [ 'id' => (string)($user->_id ?? ''), 'name' => (string)($user->name ?? ''), 'email' => (string)($user->email ?? '') ] ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => null ];
    }

    // ------------------------------------------------------------------------------------
    //who, and what runs

    //whether user $user may talk to the agent of index entry $link: a member of its project, among its users and roles (none: any member). As a Human task's assignment (models\wf\WfTask::isFor)
    public static function allows($user, $link) {
	if (empty($user) || empty($user->_id))
	    return false;
	$project = \V::app()->om->findOne('Project', [ '_id' => $link->project, 'aid' => $link->aid ]);
	$member = $project ? ($project->members[(string)$user->_id] ?? null) : null;
	if ($member === null)
	    return false;
	$data = (array)$link->data;
	$users = array_map('strval', (array)($data['users'] ?? []));
	$roles = array_map('strval', (array)($data['roles'] ?? []));
	if (!$users && !$roles)
	    return true;
	return in_array((string)$user->_id, $users, true) || in_array((string)($member['role'] ?? ''), $roles, true) || in_array((string)($user->role ?? ''), $roles, true);
    }

    //the published step of index entry $link: [ the workflow, its published copy, the step ]; null: not published (any more)
    public static function live($link) {
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $link->wf, 'aid' => $link->aid ]);
	$live = $wf ? $wf->liveCopy() : null;
	foreach((is_array($live->nodes ?? null) ? $live->nodes : []) as $n)
	    if (($n['wfid'] ?? null) == $link->node && ($n['config']['token'] ?? null) === $link->token)
		return [ $wf, $live, $n ];
	return null;
    }
}
