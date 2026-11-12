<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud Talk - the user's conversations (the Talk app's OCS API, apps/spreed). Operations (setting `operation`):
	conversations  the user's conversations (api/v4/room): token, name, displayName, type, unreadMessages, lastActivity, ...
	send           a message posted to a conversation (api/v1/chat/<token>): Markdown as Talk shows it; silent (no notification) or not
	messages       the newest messages of a conversation (api/v1/chat/<token>), the newest first
    A conversation by its token (in its address: /call/<token>) or its name (looked up among the user's)
*/

namespace models\node;

class NodeAppNxTalk extends NodeAppNextcloud {
    public static $name = 'nextcloud-talk';
    public static $label = 'Nextcloud Talk';
    public static $icon = 'bi-chat-dots';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'talk';

    const OPERATIONS = [
	'send' => 'Send a message',
	'conversations' => 'List conversations',
	'messages' => 'Read messages',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'conversations', 'messages' ];
    const MAX_MESSAGES = 200;
    const MAX_LENGTH = 32000;

    protected static function operationFields() {
	return [
	    self::field('conversation', 'Conversation', [ 'send', 'messages' ], [ 'required' => true, 'help' => 'Its token (in its address: /call/<token>) or its name',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('message', 'Message', [ 'send' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Markdown; {{ ... }} for expressions', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('silent', 'Silent', [ 'send' ], [ 'type' => 'switch', 'default' => false, 'help' => 'No notification for the participants', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //the token of the setting `conversation`: a token as it is, else the conversation of that name
    protected function token(\Closure $text) {
	$c = $text('conversation');
	if ($c === '')
	    throw new \RuntimeException('Conversation: empty');
	$rooms = (array)$this->nc->ocs('GET', '/apps/spreed/api/v4/room');
	foreach($rooms as $r)
	    if ((string)($r['token'] ?? '') === $c)
		return $c;
	foreach($rooms as $r)
	    if (mb_strtolower((string)($r['displayName'] ?? $r['name'] ?? '')) === mb_strtolower($c))
		return (string)$r['token'];
	throw new \RuntimeException("Conversation: the user has no conversation `{$c}`");
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'conversations':
		return $this->listResult((array)$this->nc->ocs('GET', '/apps/spreed/api/v4/room'), 'conversation');
	    case 'send':
		$m = $value('message');
		$m = trim(is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : \util\Expression::toText($m));
		if ($m === '')
		    throw new \RuntimeException('Message: empty');
		if (mb_strlen($m) > self::MAX_LENGTH)
		    throw new \RuntimeException('Message: longer than ' . self::MAX_LENGTH . ' characters');
		$token = $this->token($text);
		$sent = (array)$this->nc->ocs('POST', '/apps/spreed/api/v1/chat/' . rawurlencode($token), [ 'message' => $m, 'silent' => (bool)$this->cfg('silent', false) ]);
		return [ $sent, "message " . ($sent['id'] ?? '') . " sent to {$token}" ];
	    case 'messages':
		$token = $this->token($text);
		$list = (array)$this->nc->ocs('GET', '/apps/spreed/api/v1/chat/' . rawurlencode($token), [ 'lookIntoFuture' => 0, 'limit' => min(self::MAX_MESSAGES, $this->maxItems()) ]);
		return $this->listResult($list, 'message');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
