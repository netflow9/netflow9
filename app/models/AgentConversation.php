<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new: a conversation with an agent (a workflow that starts with a Chat trigger, or an Email (inbound) / Teams message trigger that keeps conversations).
	One document per conversation; its messages are models\AgentMessage (apart: a conversation can be long, the page reads only what is new).
	Each message of a person starts a run (startTurn): the run's variables message, conversation, user; the Reply to chat step adds the answers (reply()), the AI steps read the earlier turns
	(context()) and write what their tools did on the turn (noteTools()).
    Fields: wf, project, node (the trigger step), channel (app | embed | email | teams), user (a NetFlow9 user; null: a guest, a mail sender, a Teams person), user_name, user_email, who (whose memories:
	util\AgentMemory), guest_key (sha256 of the key an embedded page holds), title, seq (the last message's number), turns, summary + summary_upto (the older turns summarized, up to that message)
	+ summary_at, execution (the run answering; 'claiming' for an instant: one run at a time on the chat pages), thread (the Teams chat), refs (the Message-IDs of an email conversation), mail (where
	its answers go: { to, subject, reply_to }), credential (the Teams credential), created_at, last_at.
    Written directly to the collections, not through save(): never in the audit or execution logs (their text is what people said; only the owners read it: AgentController::conversations).
    Retention: prune() (the daemon): messages, summaries, conversations and the runs that hold their text go after the account's `execution_log_retention` (0: kept). Removed with the workflow.
    Read as arrays
*/

namespace models;

class AgentConversation extends \sys\BaseModel {
    protected static $collection = 'agent_conversations';
    public static $indexes = [
	[ [ 'aid' => 1, 'wf' => 1, 'user' => 1, 'last_at' => -1 ], [] ],
	[ [ 'aid' => 1, 'last_at' => -1 ], [] ],
	[ [ 'aid' => 1, 'wf' => 1, 'thread' => 1 ], [ 'sparse' => true ] ],
	[ [ 'aid' => 1, 'wf' => 1, 'refs' => 1 ], [ 'sparse' => true ] ],
    ];

    //the longest message of a person (characters), the turns and the text of each in the variable `conversation.history`, the refs kept, the conversations a page lists
    const MAX_MESSAGE = 8000;
    const HISTORY_TURNS = 20;
    const HISTORY_TEXT = 4000;
    const MAX_REFS = 200;
    const LIST_LIMIT = 50;
    //the tool calls kept on a turn; the characters kept of a call's arguments; the turns read for the model's context; the text one summary is made of (characters)
    const MAX_TOOLS = 30;
    const MAX_TOOL_TEXT = 300;
    const MAX_READ = 2000;
    const MAX_SUMMARY_INPUT = 60000;
    //a claim of a run that did not finish starting (a crash) frees the conversation after this many seconds
    const CLAIM_SECONDS = 60;
    //the executions removed per account at each retention pass
    const PRUNE_RUNS = 500;

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'help' => false ],
	    [ 'key' => 'project', 'type' => 'object', 'object' => 'Project', 'label' => 'Project', 'help' => false ],
	    [ 'key' => 'channel', 'type' => 'text', 'label' => 'Channel', 'help' => false ],
	    [ 'key' => 'title', 'type' => 'text', 'label' => 'Title', 'help' => false ],
	], $this->getSystemSchema());
    }

    public static function coll() {
	return \V::app()->mongo->selectCollection(static::_database(), static::_collection());
    }

    // ------------------------------------------------------------------------------------
    //conversations

    //a new conversation: $f: aid, project, wf, node, channel, user, user_name, user_email, who, guest_key (the key itself), thread, refs, mail, credential => the document
    public static function begin(array $f) {
	$now = new \MongoDB\BSON\UTCDateTime();
	$doc = [ 'aid' => $f['aid'], 'project' => $f['project'], 'wf' => $f['wf'], 'node' => (string)$f['node'], 'channel' => (string)$f['channel'], 'user' => isset($f['user']) ? (string)$f['user'] : null,
	    'user_name' => (string)($f['user_name'] ?? ''), 'user_email' => (string)($f['user_email'] ?? ''), 'who' => (string)($f['who'] ?? ''),
	    'guest_key' => isset($f['guest_key']) ? hash('sha256', (string)$f['guest_key']) : null, 'title' => '', 'seq' => 0, 'turns' => 0, 'summary' => '', 'summary_upto' => 0, 'summary_at' => null,
	    'execution' => null, 'created_at' => $now, 'last_at' => $now ];
	foreach([ 'thread', 'refs', 'mail', 'credential' ] as $k)
	    if (isset($f[$k]))
		$doc[$k] = $f[$k];
	$doc['_id'] = self::coll()->insertOne($doc)->getInsertedId();
	return $doc;
    }

    //conversation $id of account $aid; null: no such conversation
    public static function read($id, $aid) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	return self::coll()->findOne([ '_id' => new \MongoDB\BSON\ObjectId((string)$id), 'aid' => $aid ], \sys\Mongo::ARRAYS);
    }

    //the newest conversations of user $uid with workflow $wf
    public static function mine($wf, $uid) {
	return iterator_to_array(self::coll()->find([ 'aid' => $wf->aid, 'wf' => $wf->_id, 'user' => (string)$uid ], \sys\Mongo::ARRAYS + [ 'sort' => [ 'last_at' => -1 ], 'limit' => self::LIST_LIMIT ]), false);
    }

    //whether $key is the key of guest conversation $conv
    public static function keyMatches(array $conv, $key) {
	return !empty($conv['guest_key']) && is_string($key) && $key !== '' && hash_equals((string)$conv['guest_key'], hash('sha256', $key));
    }

    //the conversation and the turn execution $exec answers: [ conversation, the number of the person's message ]; null: it was not started by a conversation. A workflow run by Run a workflow reads its input
    //`conversation` (passed on as {{ conversation }})
    public static function ofExecution($exec) {
	$input = is_array($exec->input) ? $exec->input : [];
	$id = $input['conversation'] ?? null;
	$turn = $input['turn'] ?? null;
	if (empty($id)) {
	    $var = $exec->getVariable('conversation');
	    $id = is_array($var) ? ($var['id'] ?? null) : null;
	    $turn = is_array($var) ? ($var['turn'] ?? null) : null;
	}
	$conv = $id ? self::read((string)$id, $exec->aid) : null;
	if (!$conv || (string)$conv['project'] !== (string)$exec->project)
	    return null;
	return [ $conv, $turn === null ? null : (int)$turn ];
    }

    //the conversation is removed, with its messages. Its runs stay until the retention removes them
    public static function remove(array $conv) {
	AgentMessage::coll()->deleteMany([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'] ]);
	self::coll()->deleteOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ]);
    }

    //hook workflow:before-delete (sys\Boot)
    public static function beforeWorkflowDelete($wf) {
	foreach(self::coll()->find([ 'wf' => $wf->_id, 'aid' => $wf->aid ], \sys\Mongo::ARRAYS + [ 'projection' => [ '_id' => 1, 'aid' => 1 ] ]) as $c)
	    self::remove($c);
    }

    // ------------------------------------------------------------------------------------
    //messages

    //message $fields (role: user | assistant | notice, text, ...) added => its number
    public static function addMessage(array $conv, $role, array $fields) {
	$now = new \MongoDB\BSON\UTCDateTime();
	$set = [ 'last_at' => $now ];
	$text = (string)($fields['text'] ?? '');
	if ($role === 'user' && (string)($conv['title'] ?? '') === '')
	    $set['title'] = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 80);
	$after = self::coll()->findOneAndUpdate([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ], [ '$inc' => [ 'seq' => 1 ] + ($role === 'user' ? [ 'turns' => 1 ] : []), '$set' => $set ],
	    \sys\Mongo::ARRAYS + [ 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'projection' => [ 'seq' => 1 ] ]);
	if (!$after)
	    throw new \ErrorException('the conversation is gone');
	AgentMessage::coll()->insertOne([ 'aid' => $conv['aid'], 'conversation' => $conv['_id'], 'seq' => (int)$after['seq'], 'role' => (string)$role, 'at' => $now ] + $fields);
	return (int)$after['seq'];
    }

    //the messages after number $after, as the page shows them: [ seq, role, text, html, buttons, at ]
    public static function messagesAfter(array $conv, $after = 0) {
	$out = [];
	foreach(AgentMessage::coll()->find([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'seq' => [ '$gt' => (int)$after ] ], \sys\Mongo::ARRAYS + [ 'sort' => [ 'seq' => 1 ], 'limit' => self::MAX_READ ]) as $m)
	    $out[] = [ 'seq' => (int)$m['seq'], 'role' => (string)$m['role'], 'text' => (string)($m['label'] ?? $m['text'] ?? ''), 'html' => (string)($m['html'] ?? ''), 'buttons' => (array)($m['buttons'] ?? []),
		'at' => ($m['at'] ?? null) instanceof \MongoDB\BSON\UTCDateTime ? $m['at']->toDateTime()->format('c') : null ];
	return $out;
    }

    //an answer of the agent: the text (markdown: as safe HTML too), the buttons [ { label, value } ], the run => its number
    public static function reply(array $conv, $exec, $text, $format, array $buttons) {
	return self::addMessage($conv, 'assistant', [ 'text' => (string)$text, 'format' => (string)$format, 'html' => $format === 'markdown' ? \util\Markdown::toHtml((string)$text) : '', 'buttons' => $buttons,
	    'execution' => (string)($exec->_id ?? '') ]);
    }

    //what the tools did on a turn ($calls: NodeAppAi's list; $scrub: fn(text) without secrets): on the person's message, read back as part of the turn. No turn (a run with no message): nothing
    public static function noteTools(array $conv, $turn, array $calls, callable $scrub) {
	if ($turn === null || !$calls)
	    return;
	$list = [];
	foreach($calls as $c) {
	    $args = $scrub(\util\JSON::encode($c['arguments'] ?? []));
	    $list[] = [ 'tool' => (string)($c['tool'] ?? ''), 'arguments' => mb_substr($args, 0, self::MAX_TOOL_TEXT), 'error' => !empty($c['error'] ?? false), 'approval' => (string)($c['approval'] ?? '') ];
	}
	AgentMessage::coll()->updateOne([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'seq' => (int)$turn ], [ '$push' => [ 'tools' => [ '$each' => $list, '$slice' => -self::MAX_TOOLS ] ] ]);
    }

    // ------------------------------------------------------------------------------------
    //the runs

    //whether a run still answers in $conv
    public static function busy(array $conv) {
	$current = $conv['execution'] ?? null;
	if (empty($current))
	    return false;
	if ($current === 'claiming')
	    return (time() - (int)(((string)($conv['claimed_at'] ?? 0)) / 1000)) < self::CLAIM_SECONDS;
	return self::runState($conv, $current) !== null;
    }

    //the state of run $id when it can still run; else null
    private static function runState(array $conv, $id) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	$exe = \V::app()->om->findOne('WfExecution', [ '_id' => \V::app()->om->to_oid((string)$id), 'aid' => $conv['aid'], 'state' => [ '$in' => [ 'new', 'run', 'sleep' ] ] ], [ 'projection' => [ 'state' => 1 ] ]);
	return $exe ? (string)$exe->state : null;
    }

    //what the page says about $conv: idle, working (a run answers), approval (it waits for a person's approval or task)
    public static function status(array $conv) {
	$current = (string)($conv['execution'] ?? '');
	if ($current === '')
	    return 'idle';
	if ($current === 'claiming')
	    return self::busy($conv) ? 'working' : 'idle';
	$state = self::runState($conv, $current);
	if ($state === null)
	    return 'idle';
	if ($state === 'sleep' && \V::app()->om->count('WfTask', [ 'aid' => $conv['aid'], 'execution' => new \MongoDB\BSON\ObjectId($current), 'state' => 'open' ]))
	    return 'approval';
	return 'working';
    }

    //the turn of message $text in $conv: the run of workflow copy $live (published version) at trigger step $node, saved, not run yet: the caller queues it (or runs it). $o: label (what the page shows, a
    //button's label), user ([ id, name, email ]), trigger (default chat), wait (default true: refused while a run answers; false: email, Teams, where a message cannot be refused), input (more of the run's
    //input), vars (more first variables). Null: refused, a run still answers
    public static function startTurn($live, array $node, array $conv, $text, array $o = []) {
	$text = mb_substr((string)$text, 0, self::MAX_MESSAGE);
	if ($o['wait'] ?? true) {
	    if (self::busy($conv))
		return null;
	    $claimed = self::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'], 'execution' => $conv['execution'] ?? null ], [ '$set' => [ 'execution' => 'claiming', 'claimed_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
	    if (!$claimed->getModifiedCount())
		return null;
	}
	try {
	    $seq = self::addMessage($conv, 'user', [ 'text' => $text ] + (isset($o['label']) && $o['label'] !== $text && self::isButton($conv, $text, $o['label']) ? [ 'label' => (string)$o['label'] ] : []));
	    $user = $o['user'] ?? [ 'id' => $conv['user'] ?? null, 'name' => (string)($conv['user_name'] ?? ''), 'email' => (string)($conv['user_email'] ?? '') ];
	    $variable = [ 'id' => (string)$conv['_id'], 'channel' => (string)$conv['channel'], 'turn' => $seq, 'title' => (string)($conv['title'] ?: mb_substr($text, 0, 80)), 'history' => self::historyVar($conv, $seq),
		'summary' => (string)($conv['summary'] ?? '') ];
	    $exe = \models\WfExecution::startFor($live, (string)($o['trigger'] ?? 'chat'), [ 'conversation' => (string)$conv['_id'], 'turn' => $seq, 'channel' => (string)$conv['channel'], 'who' => (string)($conv['who'] ?? ''),
		'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ 'message' => $text, 'conversation' => $variable, 'user' => $user ] + (array)($o['vars'] ?? []) ] + (array)($o['input'] ?? []), (string)$node['wfid']);
	    $exe->log($node['wfid'], 'message ' . $seq . ' of conversation ' . (string)$conv['_id'] . ' (' . $conv['channel'] . ')');
	    $exe->save();
	} catch (\Throwable $e) {
	    self::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'], 'execution' => 'claiming' ], [ '$set' => [ 'execution' => null ] ]);
	    throw $e;
	}
	self::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ], [ '$set' => [ 'execution' => (string)$exe->_id ] ]);
	return $exe;
    }

    //whether $value, $label is a button of the conversation's last answer. Else the page shows what was sent: a person's own page cannot make it read as something else to the owners
    private static function isButton(array $conv, $value, $label) {
	$last = AgentMessage::coll()->findOne([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'role' => 'assistant' ], \sys\Mongo::ARRAYS + [ 'sort' => [ 'seq' => -1 ] ]);
	foreach((array)($last['buttons'] ?? []) as $b)
	    if ((string)($b['value'] ?? '') === (string)$value && (string)($b['label'] ?? '') === (string)$label)
		return true;
	return false;
    }

    //hook execution:stopped: a run of a turn ended: it no longer answers; ended without answering (failed, killed, or no Reply to chat step ran): a notice on the page (not what went wrong: the
    //owners read it in the run). Not for a Run again
    public static function afterRun($exe) {
	$input = is_array($exe->input) ? $exe->input : [];
	if (empty($input['conversation']) || !empty($exe->rerun_of) || !in_array($exe->state, [ 'end', 'error', 'cancel' ], true))
	    return;
	$conv = self::read((string)$input['conversation'], $exe->aid);
	if (!$conv)
	    return;
	self::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'], 'execution' => (string)$exe->_id ], [ '$set' => [ 'execution' => null ] ]);
	$answered = AgentMessage::coll()->countDocuments([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'role' => 'assistant', 'execution' => (string)$exe->_id ]) > 0;
	if ($exe->state === 'end' && $answered)
	    return;
	if (in_array((string)$conv['channel'], [ 'app', 'embed' ], true))
	    self::addMessage($conv, 'notice', [ 'text' => $exe->state === 'end' ? 'The agent ended without an answer.' : 'The agent could not answer this message. Try again, or ask who runs this agent.',
		'execution' => (string)$exe->_id ]);
    }

    // ------------------------------------------------------------------------------------
    //the earlier turns

    //the variable's history: the newest turns before message $before: [ { role, text, at } ], oldest first
    public static function historyVar(array $conv, $before) {
	$out = [];
	foreach(array_slice(self::turns($conv, $before), -self::HISTORY_TURNS) as $t) {
	    $out[] = [ 'role' => 'user', 'text' => mb_substr($t['user'], 0, self::HISTORY_TEXT), 'at' => $t['at'] ];
	    if ($t['assistant'] !== '')
		$out[] = [ 'role' => 'assistant', 'text' => mb_substr($t['assistant'], 0, self::HISTORY_TEXT), 'at' => $t['at'] ];
	}
	return $out;
    }

    //the turns before message $before (null: all), oldest first: [ seq, last (the number of its last message), user, assistant, tools, at ]. Notices are not part of them
    public static function turns(array $conv, $before = null) {
	$query = [ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'role' => [ '$in' => [ 'user', 'assistant' ] ] ] + ($before !== null ? [ 'seq' => [ '$lt' => (int)$before ] ] : []);
	$rows = iterator_to_array(AgentMessage::coll()->find($query, \sys\Mongo::ARRAYS + [ 'sort' => [ 'seq' => -1 ], 'limit' => self::MAX_READ ]), false);
	$turns = [];
	foreach(array_reverse($rows) as $m) {
	    if ($m['role'] === 'user') {
		$turns[] = [ 'seq' => (int)$m['seq'], 'last' => (int)$m['seq'], 'user' => (string)($m['text'] ?? ''), 'assistant' => '', 'tools' => (array)($m['tools'] ?? []),
		    'at' => ($m['at'] ?? null) instanceof \MongoDB\BSON\UTCDateTime ? $m['at']->toDateTime()->format('c') : null ];
	    } elseif ($turns) {
		$i = count($turns) - 1;
		$turns[$i]['assistant'] = trim($turns[$i]['assistant'] . "\n\n" . (string)($m['text'] ?? ''));
		$turns[$i]['last'] = (int)$m['seq'];
	    }
	}
	return $turns;
    }

    //a text's estimated tokens (no provider's tokenizer here: about 4 characters each)
    public static function tokensOf($text) {
	return (int)ceil(mb_strlen((string)$text) / 4);
    }

    //a turn's tool calls as one line
    public static function toolsLine(array $tools) {
	return implode('; ', array_map(fn($t) => (string)($t['tool'] ?? '') . ' ' . (string)($t['arguments'] ?? '') . (!empty($t['error']) ? ' (failed)' : '') . (($t['approval'] ?? '') !== '' ? " ({$t['approval']})" : ''), $tools));
    }

    //what a model is given of $conv before message $before: [ recent: the newest turns within $maxTurns and $maxTokens (estimated), oldest first; older: the turns before them not summarized yet; summary ].
    //Always the newest turn, its text cut to fit. A summary made after message $before (a Run again of an old turn) is not used
    public static function context(array $conv, $before, $maxTurns, $maxTokens) {
	$turns = self::turns($conv, $before);
	$upto = (int)($conv['summary_upto'] ?? 0);
	$usable = $before === null || $upto < (int)$before;
	$recent = [];
	$tokens = 0;
	while ($turns && count($recent) < $maxTurns) {
	    $t = end($turns);
	    $size = self::tokensOf($t['user']) + self::tokensOf($t['assistant']) + self::tokensOf(self::toolsLine($t['tools']));
	    if ($recent && $tokens + $size > $maxTokens)
		break;
	    if (!$recent && $size > $maxTokens) {
		$keep = max(1, (int)($maxTokens * 2));
		$t['user'] = mb_substr($t['user'], 0, $keep);
		$t['assistant'] = mb_substr($t['assistant'], 0, $keep);
		$t['tools'] = [];
		$size = $maxTokens;
	    }
	    $tokens += $size;
	    array_unshift($recent, $t);
	    array_pop($turns);
	}
	return [ 'recent' => $recent, 'older' => array_values(array_filter($turns, fn($t) => $t['seq'] > ($usable ? $upto : 0))), 'summary' => $usable ? (string)($conv['summary'] ?? '') : '' ];
    }

    //turns as the text a summary is made of, at most MAX_SUMMARY_INPUT characters (the oldest first; the rest wait for a next summary): [ text, the number of the last message taken ]
    public static function turnsText(array $turns) {
	$text = '';
	$last = 0;
	foreach($turns as $t) {
	    $piece = 'User: ' . $t['user'] . ($t['tools'] ? "\nTools used: " . self::toolsLine($t['tools']) : '') . ($t['assistant'] !== '' ? "\nAssistant: " . $t['assistant'] : '') . "\n\n";
	    if ($text !== '' && mb_strlen($text) + mb_strlen($piece) > self::MAX_SUMMARY_INPUT)
		break;
	    $text .= mb_substr($piece, 0, self::MAX_SUMMARY_INPUT);
	    $last = (int)$t['last'];
	}
	return [ $text, $last ];
    }

    //the summary of $conv now covers up to message $upto (only when nobody else moved it on meanwhile)
    public static function saveSummary(array $conv, $text, $upto) {
	self::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'], 'summary_upto' => (int)($conv['summary_upto'] ?? 0) ],
	    [ '$set' => [ 'summary' => (string)$text, 'summary_upto' => (int)$upto, 'summary_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
    }

    // ------------------------------------------------------------------------------------
    //retention

    //what is older than account $acct's `execution_log_retention` (days, 0: kept), by the daemon: the messages, a summary made before then, the conversations left without messages, and the finished runs of
    //conversations (their variables hold what was said) => the conversations removed
    public static function prune($acct) {
	$days = (int)($acct->execution_log_retention ?? 0);
	if ($days <= 0)
	    return 0;
	$limit = new \MongoDB\BSON\UTCDateTime((time() - $days * 86400) * 1000);
	$aid = $acct->_id;
	AgentMessage::coll()->deleteMany([ 'aid' => $aid, 'at' => [ '$lte' => $limit ] ]);
	self::coll()->updateMany([ 'aid' => $aid, 'summary_at' => [ '$lte' => $limit ] ], [ '$set' => [ 'summary' => '', 'summary_at' => null ] ]);
	foreach(\V::app()->om->find('WfExecution', [ 'aid' => $aid, 'input.conversation' => [ '$exists' => true ], 'state' => [ '$in' => [ 'end', 'error', 'cancel' ] ], 'end_at' => [ '$lte' => $limit ] ], [ 'limit' => self::PRUNE_RUNS ]) as $exe)
	    $exe->delete();
	return self::coll()->deleteMany([ 'aid' => $aid, 'last_at' => [ '$lte' => $limit ], 'execution' => null ])->getDeletedCount();
    }
}
