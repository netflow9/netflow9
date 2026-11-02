<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new: the embeddable page of a Chat trigger, /chat/<embed token> (setting Embeddable page): no sign-in, no session, no cookies (a page in another site's frame gets none):
	GET                   the page; framed by the sites of the setting Sites that may embed it (any when empty)
	GET ?poll=1&conversation=&after=   what is new (the key in the header X-Chat-Key: an address ends up in access logs)
	POST (JSON { conversation, key, message, label })   a message
    The visitor's conversation is reached with the key this page made when it began (the browser keeps it; here only its hash). No CSRF token: nothing here acts on a session; the embed token and the key are the credentials.
    Each visitor's address counts its messages (models\user\LoginThrottle: chat_ip); what the agent may spend or do is the AI step's guardrails and the account's AI policy, as anywhere
*/

namespace controllers;

use \models\AgentConversation;
use \models\node\NodeTriggerChat;

class ChatController extends \sys\APIController {
    public $layout = 'bare';

    public function open($req, $resp) {
	$token = (string)($req->query['id'] ?? '');
	$post = $req->is('post');
	$fail = function($status, $message) use ($resp, $post, $req) {
	    if ($post || isset($req->query['poll']))
		$resp->sendJSON([ 'ok' => false, 'error' => $message ], true, $status);
	    \V::app()->error($status, $message);
	};
	$link = \V::app()->om->acrossAccounts(fn() => \models\TriggerToken::isToken($token, 'hex') ? \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::chat', 'data.embed_token' => $token ]) : null);
	if (!$link)
	    $fail(404, 'This chat does not exist.');
	\V::app()->var('rt.account', \V::app()->om->findById('Account', $link->aid));
	$live = NodeTriggerChat::live($link);
	if (!$live || \models\Account::isSuspended($link->aid))
	    $fail(404, 'This chat is not available now.');
	[ $wf, $copy, $node ] = $live;
	$cfg = (array)$node['config'];
	if (($cfg['embedToken'] ?? null) !== $token || empty($cfg['embed']))
	    $fail(404, 'This chat is not available now.');

	if (isset($req->query['poll'])) {
	    $conv = $this->conversation($req->query['conversation'] ?? '', (string)($_SERVER['HTTP_X_CHAT_KEY'] ?? ''), $wf);
	    if (!$conv)
		$fail(404, 'This conversation is gone.');
	    $resp->sendJSON([ 'ok' => true, 'messages' => AgentConversation::messagesAfter($conv, (int)($req->query['after'] ?? 0)), 'status' => AgentConversation::status($conv) ]);
	}
	if ($post)
	    $this->post($token, $wf, $copy, $node, $fail, $resp);

	//who may frame it: this page's frame-ancestors instead of the app's (sys\http\Response::CSP)
	$origins = NodeTriggerChat::origins($cfg['embedOrigins'] ?? '');
	$ancestors = $origins ? implode(' ', array_filter($origins, fn($o) => preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $o))) : '*';
	unset($resp->headers['X-Frame-Options']);
	$resp->addHeader('Content-Security-Policy', str_replace("frame-ancestors 'self'", 'frame-ancestors ' . ($ancestors !== '' ? $ancestors : "'self'"), \sys\http\Response::CSP));
	$title = trim((string)($node['title'] ?? '')) !== '' && (string)$node['title'] !== 'Chat' ? (string)$node['title'] : (string)$wf->name;
	$this->render('embed', [ 'token' => $token, 'title' => $title, 'greeting' => (string)($cfg['greeting'] ?? '') ]);
    }

    //guest conversation $id of workflow $wf with key $key; null: no such
    private function conversation($id, $key, $wf) {
	$conv = is_string($id) ? AgentConversation::read($id, $wf->aid) : null;
	return $conv && (string)$conv['wf'] === (string)$wf->_id && AgentConversation::keyMatches($conv, $key) ? $conv : null;
    }

    private function post($token, $wf, $copy, array $node, $fail, $resp) {
	$body = json_decode((string)file_get_contents('php://input'), true);
	$body = is_array($body) ? $body : [];
	$text = trim(is_string($body['message'] ?? null) ? $body['message'] : '');
	if ($text === '' || mb_strlen($text) > AgentConversation::MAX_MESSAGE)
	    $fail(422, $text === '' ? 'Write a message first.' : 'The message is too long (at most ' . AgentConversation::MAX_MESSAGE . ' characters).');
	$address = 'chat_ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if (\models\user\LoginThrottle::lockedFor($address) > 0)
	    $fail(429, 'Too many messages: try again later.');
	if (array_filter($copy->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $fail(409, 'This chat cannot answer now.');
	$key = null;
	$conv = null;
	if (!empty($body['conversation'])) {
	    $conv = $this->conversation($body['conversation'], $body['key'] ?? '', $wf);
	    if (!$conv)
		$fail(404, 'This conversation is gone.');
	} else {
	    $key = bin2hex(random_bytes(24));
	    $conv = AgentConversation::begin([ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'node' => $node['wfid'], 'channel' => 'embed', 'user_name' => 'Guest', 'guest_key' => $key ]);
	}
	$after = (int)$conv['seq'];
	$label = is_string($body['label'] ?? null) ? mb_substr(trim($body['label']), 0, 200) : null;
	try {
	    $exe = AgentConversation::startTurn($copy, $node, $conv, $text, [ 'label' => $label, 'user' => [ 'id' => null, 'name' => 'Guest', 'email' => null ] ]);
	} catch (\ErrorException $e) {
	    $fail(409, 'This chat cannot answer now.');
	}
	if (!$exe)
	    $fail(409, 'The agent is still answering your last message.');
	\models\user\LoginThrottle::fail($address);
	$exe->queue();
	$conv = AgentConversation::read($conv['_id'], $conv['aid']);
	$resp->sendJSON([ 'ok' => true, 'conversation' => (string)$conv['_id'], 'messages' => AgentConversation::messagesAfter($conv, $after), 'status' => AgentConversation::status($conv) ] + ($key ? [ 'key' => $key ] : []));
    }
}
