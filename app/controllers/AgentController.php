<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new: the agents, in the app (a workflow that starts with a Chat trigger, published; models\node\NodeTriggerChat):
	/agent/list           the agents the user may talk to (Participants too: the project's membership is enough)
	/agent/<token>        the chat page: the user's conversations with it (GET ?conversation=<id> opens one)
	/agent/send           POST (JSON { agent, conversation, message, label }): a message: starts a run, queued for the daemon => { conversation, messages, status }; 409 while a run answers; 429 too many
	/agent/poll           GET ?conversation=&after=: what is new => { messages, status }
	/agent/remove         POST (JSON { conversation }): the user's own conversation goes
	/agent/conversations  the owners (Account::update): every conversation of the account; ?id= one of them, read only
*/

namespace controllers;

use \models\AgentConversation;
use \models\node\NodeTriggerChat;

class AgentController extends \sys\BaseController {

    public function _init() {
	parent::_init();
	if (\V::app()->user->isGuest) {
	    if (\V::app()->request->is('post') || \V::app()->request->is('ajax'))
		\V::app()->response->sendJSON([ 'ok' => false, 'error' => 'Log in first.' ], true, 401);
	    $this->redirect(\V::app()->user->getLoginUrl());
	}
    }

    private static function aid() {
	return \V::app()->config('rt.account')->_id ?? null;
    }

    //the index entry of the Chat trigger $token in this account, when the user may talk to it; null: not
    private function agent($token) {
	if (!\models\TriggerToken::isToken((string)$token, 'hex'))
	    return null;
	$link = \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::chat', 'token' => (string)$token, 'aid' => self::aid() ]);
	return $link && NodeTriggerChat::allows(\V::app()->user, $link) ? $link : null;
    }

    public function list($req, $resp) {
	$agents = [];
	$projects = [];
	foreach(\V::app()->om->find('TriggerToken', [ 'aid' => self::aid(), 'type' => 'trigger::chat' ], [ 'sort' => [ 'title' => 1 ] ]) as $link) {
	    if (!NodeTriggerChat::allows(\V::app()->user, $link))
		continue;
	    $wf = \V::app()->om->findOne('Workflow', [ '_id' => $link->wf, 'aid' => $link->aid ]);
	    if (!$wf || $wf->status !== \models\Workflow::PUBLISHED)
		continue;
	    $pid = (string)$link->project;
	    if (!isset($projects[$pid]))
		$projects[$pid] = (string)(\V::app()->om->findOne('Project', [ '_id' => $link->project, 'aid' => $link->aid ])->name ?? '');
	    $agents[] = [ 'link' => $link, 'wf' => $wf ];
	}
	$this->render('list', compact('agents', 'projects'));
    }

    public function open($req, $resp) {
	$link = $this->agent($req->query['id'] ?? '');
	$live = $link ? NodeTriggerChat::live($link) : null;
	if (!$live)
	    \V::app()->error(404, 'This agent does not exist, is not published, or is not for you.');
	[ $wf, , $node ] = $live;
	$conversations = AgentConversation::mine($wf, \V::app()->user->_id);
	$current = null;
	foreach($conversations as $c)
	    if ((string)$c['_id'] === (string)($req->query['conversation'] ?? ''))
		$current = $c;
	$title = trim((string)($node['title'] ?? '')) !== '' ? (string)$node['title'] : (string)$wf->name;
	$this->render('open', [ 'link' => $link, 'title' => $title === 'Chat' ? (string)$wf->name : $title, 'greeting' => (string)($node['config']['greeting'] ?? ''), 'conversations' => $conversations, 'current' => $current,
	    'messages' => $current ? AgentConversation::messagesAfter($current) : [], 'status' => $current ? AgentConversation::status($current) : 'idle' ]);
    }

    public function send($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$body = json_decode((string)file_get_contents('php://input'), true);
	$body = is_array($body) ? $body : [];
	$link = $this->agent($body['agent'] ?? '');
	$live = $link ? NodeTriggerChat::live($link) : null;
	if (!$live)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'This agent is not available.' ], true, 404);
	[ $wf, $copy, $node ] = $live;
	$text = trim((string)($body['message'] ?? ''));
	if ($text === '' || mb_strlen($text) > AgentConversation::MAX_MESSAGE)
	    $resp->sendJSON([ 'ok' => false, 'error' => $text === '' ? 'Write a message first.' : 'The message is too long (at most ' . AgentConversation::MAX_MESSAGE . ' characters).' ], true, 422);
	$key = 'chat_user:' . (string)\V::app()->user->_id;
	if (\models\user\LoginThrottle::lockedFor($key) > 0)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'Too many messages: try again in a few minutes.' ], true, 429);
	if (array_filter($copy->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $resp->sendJSON([ 'ok' => false, 'error' => 'This agent cannot answer now (its workflow has errors).' ], true, 409);
	$user = \V::app()->user;
	$conv = null;
	if (!empty($body['conversation'])) {
	    $conv = AgentConversation::read((string)$body['conversation'], $wf->aid);
	    if (!$conv || (string)$conv['wf'] !== (string)$wf->_id || (string)$conv['user'] !== (string)$user->_id)
		$resp->sendJSON([ 'ok' => false, 'error' => 'This conversation is not yours.' ], true, 404);
	}
	$conv = $conv ?: AgentConversation::begin([ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'node' => $node['wfid'], 'channel' => 'app', 'user' => (string)$user->_id, 'user_name' => (string)$user->name,
	    'user_email' => (string)$user->email, 'who' => 'u:' . (string)$user->_id ]);
	$after = (int)$conv['seq'];
	$label = isset($body['label']) && is_string($body['label']) ? mb_substr(trim($body['label']), 0, 200) : null;
	try {
	    $exe = AgentConversation::startTurn($copy, $node, $conv, $text, [ 'label' => $label ]);
	} catch (\ErrorException $e) {
	    $resp->sendJSON([ 'ok' => false, 'error' => $e->getMessage() ], true, 409);
	}
	if (!$exe)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'The agent is still answering your last message.' ], true, 409);
	\models\user\LoginThrottle::fail($key);
	$exe->queue();
	$conv = AgentConversation::read($conv['_id'], $conv['aid']);
	$resp->sendJSON([ 'ok' => true, 'conversation' => (string)$conv['_id'], 'messages' => AgentConversation::messagesAfter($conv, $after), 'status' => AgentConversation::status($conv) ]);
    }

    //the user's own conversation ?conversation / $id, else 404
    private function own($id, $resp) {
	$conv = AgentConversation::read((string)$id, self::aid());
	if (!$conv || (string)$conv['user'] !== (string)\V::app()->user->_id)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'This conversation is not yours.' ], true, 404);
	return $conv;
    }

    public function poll($req, $resp) {
	$conv = $this->own($req->query['conversation'] ?? '', $resp);
	$resp->sendJSON([ 'ok' => true, 'messages' => AgentConversation::messagesAfter($conv, (int)($req->query['after'] ?? 0)), 'status' => AgentConversation::status($conv) ]);
    }

    public function remove($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$body = json_decode((string)file_get_contents('php://input'), true);
	$conv = $this->own(is_array($body) ? ($body['conversation'] ?? '') : '', $resp);
	if (AgentConversation::busy($conv))
	    $resp->sendJSON([ 'ok' => false, 'error' => 'The agent is still answering: try again when it has finished.' ], true, 409);
	AgentConversation::remove($conv);
	$resp->sendJSON([ 'ok' => true ]);
    }

    //the owners' view
    public function conversations($req, $resp) {
	if (!\V::app()->user->can('Account::update'))
	    \V::app()->error(403, 'Unauthorized');
	$aid = self::aid();
	$names = fn($c) => (string)(\V::app()->om->findOne('Workflow', [ '_id' => $c['wf'], 'aid' => $aid ])->name ?? '');
	if (isset($req->query['id'])) {
	    $conv = AgentConversation::read((string)$req->query['id'], $aid);
	    if (!$conv)
		\V::app()->error(404, 'Not found');
	    if ($req->is('post')) {
		AgentConversation::remove($conv);
		\models\AuditLog::record('agent.conversation.delete', null, 'a conversation with ' . $names($conv) . ' of ' . ($conv['user_name'] ?: $conv['user_email'] ?: 'a guest') . ' (' . (string)$conv['_id'] . ')');
		$this->addToast('success', 'Deleted', 'The conversation was deleted');
		$this->redirect('/agent/conversations');
	    }
	    $this->render('conversation', [ 'conv' => $conv, 'agent' => $names($conv), 'turns' => AgentConversation::messagesAfter($conv), 'tools' => AgentConversation::turns($conv) ]);
	    return;
	}
	$list = [];
	foreach(AgentConversation::coll()->find([ 'aid' => $aid ], \sys\Mongo::ARRAYS + [ 'sort' => [ 'last_at' => -1 ], 'limit' => 200, 'projection' => [ 'guest_key' => 0 ] ]) as $c)
	    $list[] = $c + [ 'agent' => $names($c) ];
	$this->render('conversations', [ 'list' => $list, 'retention' => (int)(\V::app()->config('rt.account')->execution_log_retention ?? 0) ]);
    }
}
