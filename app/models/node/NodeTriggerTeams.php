<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new trigger: Teams message - a conversation with an agent in Microsoft Teams: a message in a chat of the credential's account starts a run (variables message, conversation, user,
	teams: { chat, message_id }); Reply to chat answers in that chat as that account. One conversation per chat. It checks on its schedule (models\WfSchedule, every minute by default; util\Scheduler runs
	runScheduled; models\TriggerState: how far it has read): one chat, or the account's latest MAX_CHATS. The first check only notes where it starts. Not read: the account's own messages (use an account for the
	agent, else it would answer itself), apps' and system messages.
    Chats only, not channels: reading channel messages takes a permission only an administrator can consent to (ChannelMessage.Read.All), the Teams credential would ask it of everyone
*/

namespace models\node;

class NodeTriggerTeams extends \models\WfNode {
    public static $type = 'trigger';
    public static $name = 'teams';
    public static $label = 'Teams message';
    public static $icon = 'bi-microsoft-teams';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const MAX_CHATS = 50;
    const MAX_MESSAGES = 50;
    //the runs started per check; the message ids remembered per chat
    const MAX_RUNS = 20;
    const OVERLAP = 60;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('msteams', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Teams credential',
		'choices' => $choices,
		'help' => 'The agent reads and answers as this account: use one of its own',
		'required' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'chat',
		'type' => 'text',
		'label' => 'Chat',
		'default' => '',
		'help' => 'A chat id (the Teams step\'s List my chats); empty: every chat of the account',
		'ui' => [ 'width' => 6 ],
	    ],
	], \models\WfSchedule::schema([ 'mode' => 'interval', 'every' => 1, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'msteams')))) {
	    $this->errors('config', 'Teams message: choose a Teams credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	return NodeTriggerChat::conversationVariables() + [ 'teams' => [ 'type' => 'object', 'info' => 'where it was written', 'fields' => [ 'chat' => [ 'type' => 'string' ], 'message_id' => [ 'type' => 'string' ] ] ] ];
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Teams message: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //one check: [ the new state data { me, since (ISO 8601), seen: { chat: [ ids ] } }, the executions started ]. $start: what is done with a run (default: queued for the daemon; the check suite runs it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$first = empty($data['since']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'msteams');
	if (!$cred)
	    throw new \Exception('Teams message: the credential is no longer available');
	$graph = fn($path, $query = []) => \util\AgentChannels::graph($cred, 'GET', $path, $query);
	$me = (string)($data['me'] ?? '');
	if ($me === '')
	    $me = (string)($graph('/me', [ '$select' => 'id' ])['id'] ?? '');
	$now = gmdate('Y-m-d\TH:i:s\Z');
	if ($first)
	    return [ [ 'me' => $me, 'since' => $now, 'seen' => [] ], [] ];
	$since = substr((string)$data['since'], 0, 19);
	$seen = (array)($data['seen'] ?? []);
	$chat = trim((string)($cfg['chat'] ?? ''));
	$chats = [ $chat ];
	if ($chat === '') {
	    $chats = [];
	    foreach((array)($graph('/me/chats', [ '$expand' => 'lastMessagePreview', '$orderby' => 'lastMessagePreview/createdDateTime desc', '$top' => self::MAX_CHATS ])['value'] ?? []) as $c)
		if (substr((string)($c['lastMessagePreview']['createdDateTime'] ?? ''), 0, 19) >= $since)
		    $chats[] = (string)($c['id'] ?? '');
	}
	$started = [];
	$later = false;
	foreach($chats as $id) {
	    $known = array_map('strval', (array)($seen[$id] ?? []));
	    $list = (array)($graph('/chats/' . rawurlencode($id) . '/messages', [ '$top' => self::MAX_MESSAGES, '$orderby' => 'createdDateTime desc' ])['value'] ?? []);
	    usort($list, fn($a, $b) => strcmp((string)($a['createdDateTime'] ?? ''), (string)($b['createdDateTime'] ?? '')));
	    foreach($list as $m) {
		$mid = (string)($m['id'] ?? '');
		if ($mid === '' || in_array($mid, $known, true) || substr((string)($m['createdDateTime'] ?? ''), 0, 19) < $since)
		    continue;
		$from = $m['from']['user'] ?? null;
		if (($m['messageType'] ?? 'message') !== 'message' || !is_array($from) || (string)($from['id'] ?? '') === $me) {
		    $known[] = $mid;
		    continue;
		}
		//over the limit: the next check takes it (not noted as seen; `since` stays)
		if (count($started) >= self::MAX_RUNS) {
		    $later = true;
		    continue;
		}
		$known[] = $mid;
		$exe = self::startMessage($wf, $node, $id, $m);
		if ($exe) {
		    ($start ?? fn($e) => $e->queue())($exe);
		    $started[] = $exe;
		}
	    }
	    $seen[$id] = array_slice($known, -(self::MAX_MESSAGES * 2));
	}
	//a minute back: Teams' clock is not this server's; `seen` keeps a message from starting two runs
	$next = gmdate('Y-m-d\TH:i:s\Z', time() - self::OVERLAP);
	return [ [ 'me' => $me, 'since' => $later || $next < $since ? $since : $next, 'seen' => $seen ], $started ];
    }

    //a run of Graph message $m of chat $chat; null: it has no text
    private static function startMessage($wf, array $node, $chat, array $m) {
	$content = (string)($m['body']['content'] ?? '');
	$text = trim(strtolower((string)($m['body']['contentType'] ?? 'text')) === 'html' ? NodeAppOutlookMail::htmlToText($content) : $content);
	if ($text === '')
	    return null;
	$from = (array)($m['from']['user'] ?? []);
	$person = [ 'id' => (string)($from['id'] ?? ''), 'name' => (string)($from['displayName'] ?? ''), 'email' => '' ];
	$c = \models\AgentConversation::coll();
	$conv = $c->findOne([ 'aid' => $wf->aid, 'wf' => $wf->_id, 'thread' => (string)$chat ], \sys\Mongo::ARRAYS)
	    ?: \models\AgentConversation::begin([ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'node' => $node['wfid'], 'channel' => 'teams', 'user_name' => $person['name'], 'who' => 't:' . $person['id'], 'thread' => (string)$chat,
		'credential' => (string)($node['config']['credential'] ?? '') ]);
	return \models\AgentConversation::startTurn($wf, $node, $conv, $text, [ 'wait' => false, 'trigger' => 'teams', 'user' => $person, 'input' => [ 'who' => 't:' . $person['id'] ],
	    'vars' => [ 'teams' => [ 'chat' => (string)$chat, 'message_id' => (string)($m['id'] ?? '') ] ] ]);
    }
}
