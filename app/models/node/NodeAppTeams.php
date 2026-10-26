<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new step: Microsoft Teams - posts messages to channels and chats over Microsoft Graph, through a Microsoft Teams (Microsoft Graph) credential
    of the project (CredentialMicrosoftTeams, provides `msteams`; work / school accounts). Messages are sent as the signed-in account. Operations (setting `operation`):
	sendChannel  a message in a channel of a team (text or HTML, an optional subject, importance)
	reply        a reply to a channel message (its thread)
	sendChat     a message in a chat (1:1, group or meeting chat)
	teams        the teams the account is a member of
	channels     the channels of a team
	chats        the chats of the account, with their members
    Teams, channels and chats are given by id (teams / channels / chats give them; a channel's link in Teams has them too). The answer goes into one variable (default `teams`).
    On error (Graph errors, e.g. not found, no access, a permission the admin has not granted) - fail the step, or continue on a second output `error`
*/

//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\MicrosoftIntegration\models): the Microsoft credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppTeams extends NodeAppMicrosoft {
    //static data/structure
    public static $name = 'teams';
    public static $label = 'Microsoft Teams';
    public static $icon = 'bi-microsoft-teams';
    public static $capability = 'msteams';
    public static $credentialLabel = 'Teams credential';
    public static $credentialKinds = 'Microsoft Teams';
    public static $defaultOperation = 'sendChannel';
    public static $defaultVariable = 'teams';

    const OPERATIONS = [
	'sendChannel' => 'Send a channel message',
	'reply' => 'Reply to a channel message',
	'sendChat' => 'Send a chat message',
	'teams' => 'List my teams',
	'channels' => 'List the channels of a team',
	'chats' => 'List my chats',
    ];

    //the operations sending a message, the ones in a channel
    const SEND = [ 'sendChannel', 'reply', 'sendChat' ];
    const IN_CHANNEL = [ 'sendChannel', 'reply' ];

    const BODY_TYPES = [ 'text' => 'Text', 'html' => 'HTML' ];
    const IMPORTANCE = [ 'normal' => 'Normal', 'high' => 'Important', 'urgent' => 'Urgent' ];

    const MAX_MESSAGE = 28000;
    const MAX_RESULTS = 500;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'team',
		'type' => 'text',
		'label' => 'Team',
		'default' => '',
		'help' => 'The team id (List my teams gives them)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'sendChannel', 'reply', 'channels' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'sendChannel', 'reply', 'channels' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: The channel id, e.g. 19:...@thread.tacv2 (List the channels gives them))
		'key' => 'channel',
		'type' => 'text',
		'label' => 'Channel',
		'default' => '',
		'help' => 'The channel id (List the channels gives them)',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::IN_CHANNEL ],
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op(self::IN_CHANNEL) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: The id of the channel message to reply to, e.g. {{ teams.id }} of a Send a channel message)
		'key' => 'messageId',
		'type' => 'text',
		'label' => 'Message',
		'default' => '',
		'help' => 'The channel message replied to, e.g. {{ teams.id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'reply' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'reply' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: The chat id, e.g. 19:...@thread.v2 (List my chats gives them))
		'key' => 'chat',
		'type' => 'text',
		'label' => 'Chat',
		'default' => '',
		'help' => 'The chat id (List my chats gives them)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'sendChat' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'sendChat' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Subject',
		'default' => '',
		'help' => 'Optional',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'sendChannel' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Message as',
		'required' => true,
		'default' => 'text',
		'choices' => self::BODY_TYPES,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::SEND) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'importance',
		'type' => 'select',
		'label' => 'Importance',
		'required' => true,
		'default' => 'normal',
		'choices' => self::IMPORTANCE,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::SEND) ],
	    ],
	    [
		'key' => 'message',
		'type' => 'textarea',
		'label' => 'Message',
		'default' => '',
		'help' => '{{ ... }} for expressions',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::SEND ],
		'ui' => [ 'rows' => 5, 'expression' => true, 'showIf' => $op(self::SEND) ],
	    ],

	    static::timeoutField(60),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Teams, e.g. not found, no access, a permission the admin has not granted)
	    static::onErrorField('Teams errors, a permission not granted'),
	];
    }

    //the type of a message object (messageOf())
    public static function messageType() {
	return [ 'type' => 'object', 'info' => 'the message sent: { id, link, created, from, subject, text }', 'fields' => [ 'id' => [ 'type' => 'string', 'info' => 'Reply to a channel message' ],
	    'link' => [ 'type' => 'string', 'info' => 'opens it in Teams' ], 'created' => [ 'type' => 'string' ], 'from' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ],
	    'text' => [ 'type' => 'string' ] ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$named = fn($fields, $info) => [ 'type' => 'array', 'info' => $info, 'items' => [ 'type' => 'object', 'fields' => $fields ] ];
	return [ $name => match($config['operation'] ?? 'sendChannel') {
	    'teams' => $named([ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ] ], 'the teams: [ { id, name, description } ]'),
	    'channels' => $named([ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ],
		'link' => [ 'type' => 'string' ] ], 'the channels: [ { id, name, description, type, link } ]'),
	    'chats' => $named([ 'id' => [ 'type' => 'string' ], 'topic' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'info' => 'oneOnOne, group or meeting' ],
		'members' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'link' => [ 'type' => 'string' ] ], 'the chats: [ { id, topic, type, members, link } ]'),
	    default => self::messageType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'sendChannel', 'reply', 'sendChat' => $this->opSend($operation, $text, $value),
	    'teams' => $this->opTeams(),
	    'channels' => $this->opChannels($text),
	    'chats' => $this->opChats(),
	};
    }

    protected function opSend($operation, \Closure $text, \Closure $value) {
	$content = \util\Expression::toText($value('message'));
	if (trim($content) === '')
	    throw new \RuntimeException('the message is empty');
	if (mb_strlen($content) > self::MAX_MESSAGE)
	    throw new \RuntimeException('the message is longer than ' . self::MAX_MESSAGE . ' characters');
	$isHtml = $this->cfg('bodyType', 'text') === 'html';
	//text: shown as typed (Teams reads the content as HTML when it is not text: escaped, line breaks kept)
	$body = [ 'body' => [ 'contentType' => 'html', 'content' => $isHtml ? $content : nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) ] ];
	$importance = (string)$this->cfg('importance', 'normal');
	if ($importance !== 'normal' && isset(self::IMPORTANCE[$importance]))
	    $body['importance'] = $importance;

	if ($operation === 'sendChat') {
	    $path = '/chats/' . self::graphId($text('chat'), 'chat') . '/messages';
	    $where = 'chat';
	} else {
	    $path = '/teams/' . self::graphId($text('team'), 'team') . '/channels/' . self::graphId($text('channel'), 'channel') . '/messages';
	    if ($operation === 'reply') {
		$path .= '/' . self::graphId($text('messageId'), 'message') . '/replies';
		$where = 'thread';
	    } else {
		if (($subject = $text('subject')) !== '')
		    $body['subject'] = mb_substr($subject, 0, 255);
		$where = 'channel';
	    }
	}
	$message = self::messageOf($this->api('POST', $path, [], $body));
	return [ $message, "message sent to the {$where}" ];
    }

    protected function opTeams() {
	[ $items ] = $this->pages('/me/joinedTeams', [ '$select' => 'id,displayName,description' ], self::MAX_RESULTS);
	$teams = array_map(fn($t) => [ 'id' => (string)($t['id'] ?? ''), 'name' => (string)($t['displayName'] ?? ''), 'description' => (string)($t['description'] ?? '') ], $items);
	return [ $teams, count($teams) . ' team(s)' ];
    }

    protected function opChannels(\Closure $text) {
	[ $items ] = $this->pages('/teams/' . self::graphId($text('team'), 'team') . '/channels', [ '$select' => 'id,displayName,description,membershipType,webUrl' ], self::MAX_RESULTS);
	$channels = array_map(fn($c) => [ 'id' => (string)($c['id'] ?? ''), 'name' => (string)($c['displayName'] ?? ''), 'description' => (string)($c['description'] ?? ''),
	    'type' => (string)($c['membershipType'] ?? ''), 'link' => (string)($c['webUrl'] ?? '') ], $items);
	return [ $channels, count($channels) . ' channel(s)' ];
    }

    protected function opChats() {
	[ $items ] = $this->pages('/me/chats', [ '$expand' => 'members', '$top' => 50 ], self::MAX_RESULTS);
	$chats = array_map(fn($c) => [ 'id' => (string)($c['id'] ?? ''), 'topic' => (string)($c['topic'] ?? ''), 'type' => (string)($c['chatType'] ?? ''),
	    'members' => array_values(array_filter(array_map(fn($m) => (string)($m['email'] ?? $m['displayName'] ?? ''), (array)($c['members'] ?? [])))),
	    'link' => (string)($c['webUrl'] ?? '') ], $items);
	return [ $chats, count($chats) . ' chat(s)' ];
    }

    //the message object of Graph's chatMessage
    public static function messageOf($m) {
	$m = is_array($m) ? $m : [];
	$content = (string)($m['body']['content'] ?? '');
	return [
	    'id' => (string)($m['id'] ?? ''),
	    'link' => (string)($m['webUrl'] ?? ''),
	    'created' => (string)($m['createdDateTime'] ?? ''),
	    'from' => (string)($m['from']['user']['displayName'] ?? ''),
	    'subject' => (string)($m['subject'] ?? ''),
	    'text' => strtolower((string)($m['body']['contentType'] ?? 'text')) === 'html' ? NodeAppOutlookMail::htmlToText($content) : $content,
	];
    }
}
