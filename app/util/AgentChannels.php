<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new: where an agent's answers go when the conversation is not on a chat page: an email (threaded: In-Reply-To, References, its address to reply to) or a Teams chat.
    The chat pages read the messages themselves (models\AgentConversation).
    AgentChannels::$sink: the check suite takes what would be sent (fn(kind, data) => what the call answers), so nothing leaves and no job reaches a running daemon
*/

namespace util;

class AgentChannels {
    public static $sink = null;
    //the check suite: what Graph answers, by path
    public static $fake = [];

    public static function fakeAnswer(array $data) {
	return self::$fake[$data['path'] ?? ''] ?? [];
    }

    //answer $text (as $format, with $buttons) of $conv sent on its channel; \RuntimeException when it cannot be sent
    public static function deliver(array $conv, $exec, $text, $format, array $buttons) {
	switch((string)$conv['channel']) {
	    case 'email':
		self::email($conv, $exec, $text, $format, $buttons);
		break;
	    case 'teams':
		self::teams($conv, $exec, $text, $format, $buttons);
		break;
	}
    }

    //the buttons as lines of text, for a channel without buttons: the person answers with one of them
    public static function buttonsText(array $buttons) {
	return $buttons ? "\n\nReply with one of: " . implode(' / ', array_map(fn($b) => (string)$b['label'], $buttons)) : '';
    }

    //Re: <subject>, once
    public static function replySubject($subject) {
	$subject = trim((string)$subject);
	return preg_match('/^re:/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    //the reply by email: to the sender, from the app's address, replies come back to the trigger's own address; its Message-ID kept, so the sender's answer finds this conversation
    private static function email(array $conv, $exec, $text, $format, array $buttons) {
	$mail = (array)($conv['mail'] ?? []);
	if (empty($mail['to']))
	    throw new \RuntimeException('the conversation has no address to answer to');
	$refs = array_values(array_map('strval', (array)($conv['refs'] ?? [])));
	$from = (string)\V::app()->config('mail.from');
	$domain = substr((string)strrchr($from, '@'), 1) ?: 'localhost';
	$id = bin2hex(random_bytes(12)) . '@' . $domain;
	$extra = self::buttonsText($buttons);
	$plain = ($format === 'markdown' ? Markdown::toText($text) : (string)$text) . $extra;
	$html = ($format === 'markdown' ? Markdown::toHtml($text) : nl2br(\V::app()->html->escape((string)$text), false)) . ($extra !== '' ? '<p>' . nl2br(\V::app()->html->escape(trim($extra)), false) . '</p>' : '');
	$data = [ 'aid' => (string)$exec->aid, 'to' => [ (string)$mail['to'] ], 'cc' => [], 'bcc' => [], 'reply_to' => (string)($mail['reply_to'] ?? ''), 'subject' => mb_substr(self::replySubject($mail['subject'] ?? ''), 0, 300),
	    'html' => $html, 'text' => $plain, 'images' => [], 'message_id' => $id, 'in_reply_to' => $refs ? end($refs) : '', 'references' => array_slice($refs, -20), 'auto_submitted' => true ];
	if (self::$sink)
	    (self::$sink)('email', $data);
	else
	    \models\Job::submit('\util\EmailSend::deliver', $data, [ 'aid' => $exec->aid, 'project' => $exec->project, 'delete_on_done' => true ]);
	\models\AgentConversation::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ], [ '$push' => [ 'refs' => [ '$each' => [ $id ], '$slice' => -\models\AgentConversation::MAX_REFS ] ] ]);
    }

    //the reply in the Teams chat of the conversation, through the trigger's Teams credential
    private static function teams(array $conv, $exec, $text, $format, array $buttons) {
	$chat = (string)($conv['thread'] ?? '');
	$cred = \models\Credential::findFor($exec, (string)($conv['credential'] ?? ''), 'msteams');
	if ($chat === '' || !$cred)
	    throw new \RuntimeException('Teams: the chat or the trigger\'s credential is no longer available');
	$extra = self::buttonsText($buttons);
	$html = ($format === 'markdown' ? Markdown::toHtml($text) : nl2br(\V::app()->html->escape((string)$text), false)) . ($extra !== '' ? '<p>' . \V::app()->html->escape(trim($extra)) . '</p>' : '');
	self::graph($cred, 'POST', '/chats/' . rawurlencode($chat) . '/messages', [], [ 'body' => [ 'contentType' => 'html', 'content' => $html ] ]);
    }

    //a call of Microsoft Graph with Teams credential $cred: the decoded answer; \RuntimeException with Graph's message
    public static function graph($cred, $method, $path, array $query = [], $json = null) {
	if (self::$sink)
	    return (self::$sink)('graph', [ 'method' => $method, 'path' => $path, 'query' => $query, 'json' => $json ]);
	$url = \models\node\NodeAppMicrosoft::API . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
	$headers = [ 'Authorization: Bearer ' . $cred->accessToken(), 'Accept: application/json' ];
	if ($json !== null)
	    $headers[] = 'Content-Type: application/json; charset=UTF-8';
	$r = Http::request($method, $url, [ 'headers' => $headers, 'body' => $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null, 'timeout' => 60, 'maxBytes' => \models\node\NodeAppMicrosoft::MAX_ANSWER, 'https' => true, 'compressed' => true ]);
	$answer = json_decode((string)$r['body'], true);
	if ((string)$r['error'] !== '' || $r['status'] >= 400)
	    throw new \RuntimeException('Teams: ' . (is_array($answer) ? (string)($answer['error']['message'] ?? '') : '') . ((string)$r['error'] !== '' ? ' ' . $r['error'] : " (HTTP status {$r['status']})"));
	return is_array($answer) ? $answer : [];
    }
}
