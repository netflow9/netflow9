<?php

/*
    Email (inbound) - starts the workflow (its published version) for each message sent to the step's address
    <account mail_prefix>-<code>@<MAIL_INBOUND_DOMAIN>.
    Haraka (haraka/plugins/nfn.js) accepts only the addresses of the token index (TriggerToken) whose account prefix matches, stores the raw message as a project File
    and submits a job: deliver() (run by the daemon) starts the run like the IMAP trigger (models\MailPoll::startMessage: one variable, default `mail`, attachments as project files); the raw message File is deleted when the run completes, kept when it fails (afterExecutionEnd below;
*/
//CLD: 2026-11-01T16:11:37+02:00: in the core (was plugins\InboundMail\models\NodeTriggerEmail, registered only when Haraka was found): always registered, available() when Haraka is found
namespace models\node;

use models\InboundMailSettings;

//CLD: 2026-10-17T15:20:00+03:00: renamed NodeTriggerInbound -> NodeTriggerEmail
class NodeTriggerEmail extends \models\WfNode {
    public static $type = 'trigger';
    //CLD: 2026-10-17T15:35:00+03:00: trigger::email (was trigger::inbound); runs started by it have trigger `email` (was `inbound`)
    public static $name = 'email';
    public static $label = 'Email (inbound)';
    public static $icon = 'bi-envelope-arrow-down';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    //CLD: 2026-10-21T11:30:00+03:00: its token format for the token index (TriggerToken::syncForWorkflow; without it, `hex`: the Email inbound steps were never indexed)
    const TOKEN_FORMAT = 'code';
    //CLD: 2026-11-02T18:00:00+02:00: the messages of one conversation accepted in an hour (Keep conversations)
    const MAX_THREAD_RATE = 20;

    //CLD: 2026-11-01T16:11:37+02:00: only where Haraka is installed with NetFlow9's plugin (util\InboundMail::found): else not in the palette, and a workflow with it cannot run (Workflow::validateFlow)
    public static function available() {
	return \util\InboundMail::found();
    }

    //CLD: 2026-11-01T16:11:37+02:00: new: the address of the step's code, from its account's current prefix ('' without a domain or a prefix)
    public static function addressFor($aid, $token) {
	$prefix = InboundMailSettings::prefixOf($aid);
	$domain = \util\InboundMail::domain();
	return $prefix !== '' && $domain !== '' && (string)$token !== '' ? "{$prefix}-{$token}@{$domain}" : '';
    }

    //an entry in the token index (TriggerToken), no extra data
    public static function tokenData(array $node) {
	return [];
    }

    //a new step gets its code and address when it is added (mail is accepted once the workflow is published)
    //CLD: 2026-10-18T14:40:00+03:00: TriggerToken::newToken (was the loop here)
    public static function initConfig() {
	$token = \models\TriggerToken::newToken('code');

	//CLD: 2026-11-01T16:11:37+02:00: addressFor (was built here; no address without a domain, was `<prefix>-<code>@`)
	return [ 'token' => $token, 'address' => self::addressFor(\V::app()->config('rt.account')->_id, $token) ];
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'address',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		//CLD: 2026-10-19T21:10:00+03:00: the prefix is in Inbound mail settings (was Account settings)
		//CLD: 2026-11-01T16:11:37+02:00: the prefix is on the Inbound mail tab of Account settings (was the plugin's page)
		'help' => 'Mail sent here starts the workflow once it is published (the start is your account\'s email prefix: Account settings, Inbound mail)',
		'ui' => [ 'placeholder' => 'Set the email prefix in Account settings, Inbound mail' ],
	    ],
	    [
		'key' => 'senders',
		'type' => 'textarea',
		'label' => 'Accept mail only from',
		'default' => '',
		'help' => 'One sender address or @domain per line (the envelope sender); empty: anyone',
		'ui' => [ 'rows' => 3, 'placeholder' => 'invoices@supplier.com' . "\n" . '@example.com' ],
	    ],
	    [
		'key' => 'saveAttachments',
		'type' => 'switch',
		'label' => 'Save attachments as project files',
		'default' => true,
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: shorter help (was: The message as one object, e.g. {{ mail.subject }}, {{ mail.from }}, {{ mail.text }})
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Message variable',
		'required' => true,
		'default' => 'mail',
		'help' => 'e.g. {{ mail.subject }}, {{ mail.text }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    //CLD: 2026-11-02T18:00:00+02:00: new: an agent's conversation by email: replies thread into the same conversation; Reply to chat answers the sender
	    [
		'key' => 'conversation',
		'type' => 'switch',
		'label' => 'Keep conversations',
		'default' => false,
		'help' => 'A reply to the workflow\'s answer continues the same conversation (variables message, conversation, user); Reply to chat answers by email',
	    ],
	];
    }

    //the code: valid, of this step only; the address shown again from the account's current prefix
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$token = $config['token'] ?? null;
	//CLD: 2026-10-18T14:40:00+03:00: the token checks are TriggerToken::checkToken (were here); no address without a valid code
	$valid = \models\TriggerToken::checkToken($this, 'code') && $valid;

	//CLD: 2026-11-01T16:11:37+02:00: the address again from the account's current prefix (was kept as it was built: changing the prefix left every step showing its old address)
	$wf = $this->workflow;
	if ($wf && !empty($config['token']))
	    $config['address'] = self::addressFor($wf->aid, $config['token']);
	$this->config = $config;
	if (empty($config['address'])) {
	    $this->errors('config', \util\InboundMail::domain() === '' ? 'Address: the inbound domain is not set (MAIL_INBOUND_DOMAIN in app.env)'
		: 'Address: set the email prefix in Account settings, Inbound mail first');
	    $valid = false;
	}
	return $valid;
    }

    //the IMAP trigger's message variable, without the mailbox fields (account, folder, uid), with the address it was sent to
    //CLD: 2026-10-17T14:20:00+03:00: the message type of the Read emails step (NodeAppReadEmail::messageType; was NodeTriggerImap::outputVariables), named by `variable` (default `mail`)
    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'mail') : 'mail';
	$vars = \util\Validator::isIdentifier($name) ? [ $name => \models\node\NodeAppReadEmail::messageType() ] : [];
	foreach($vars as $name => $v) {
	    unset($v['fields']['account'], $v['fields']['folder'], $v['fields']['uid']);
	    $v['fields']['recipient'] = [ 'type' => 'string', 'info' => 'the address it was sent to' ];
	    $v['info'] = 'the received message: { recipient, message_id, from, from_name, to, cc, reply_to (lists), subject, date (ISO 8601), text, html, in_reply_to, references (list), '
		. 'headers (lower-case name => value, or a list when repeated), attachments ([ { filename, contentType, size, disposition, file } ]), attachment_count, attachment_files }';
	    $vars[$name] = $v;
	}
	//CLD: 2026-11-02T18:00:00+02:00: + the variables of a conversation
	if (is_array($config) && !empty($config['conversation']))
	    $vars += NodeTriggerChat::conversationVariables();
	return $vars;
    }

    //CLD: 2026-11-01T16:11:37+02:00: the job is \models\node\NodeTriggerEmail::deliver (haraka/plugins/nfn.js JOB_SPEC; was \plugins\InboundMail\models\NodeTriggerEmail::deliver)
    //a message Haraka received (a daemon job submitted by haraka/plugins/nfn.js): { file (the raw message, a project File), token, rcpt, mail_from }; starts and runs the
    //workflow's published version. Failing here fails the job (the File stays, as for a failed run); a sender the step does not accept: logged, the File deleted
    public static function deliver($data) {
	$data = (array)$data;
	$om = \V::app()->om;
	$entry = $om->findOne('TriggerToken', [ 'type' => 'trigger::email', 'token' => (string)($data['token'] ?? '') ]);
	$file = $entry ? $om->findOne('File', [ '_id' => (string)($data['file'] ?? ''), 'aid' => $entry->aid ]) : null;
	if (!$entry || !$file)
	    throw new \ErrorException('Email inbound: no step for ' . ($data['rcpt'] ?? '?') . ', or the message file is missing');
	\V::app()->var('rt.account', $om->findById('Account', $entry->aid));
	$wf = $om->findOne('Workflow', [ '_id' => $entry->wf, 'aid' => $entry->aid ]);
	$version = $wf ? $wf->publishedVersion() : null;
	$live = $version ? $wf->forVersion($version) : null;
	$node = null;
	foreach((is_array($live->nodes ?? null) ? $live->nodes : []) as $n) {
	    if (($n['wfid'] ?? null) == $entry->node && ($n['config']['token'] ?? null) === $entry->token)
		$node = $n;
	}
	if (!$node || $wf->status !== \models\Workflow::PUBLISHED)
	    throw new \ErrorException("Email inbound: the workflow of {$data['rcpt']} is not published");
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \ErrorException("Email inbound: the workflow `{$wf->name}` has errors and cannot run");

	$cfg = $node['config'];
	$from = strtolower(trim((string)($data['mail_from'] ?? '')));
	$senders = array_values(array_filter(array_map(fn($l) => strtolower(trim($l)), preg_split('/\R/', (string)($cfg['senders'] ?? '')))));
	if ($senders && !array_filter($senders, fn($s) => $s[0] === '@' ? str_ends_with($from, $s) : $from === $s)) {
	    \models\ExecutionLog::record('inbound.refused', $wf, "{$from} is not an accepted sender of {$data['rcpt']}", [], null, $wf->aid);
	    $file->delete();
	    return;
	}

	//CLD: 2026-11-02T18:00:00+02:00: Keep conversations: an automatic message (or a bounce) is not answered: agents would write to each other without end
	if (!empty($cfg['conversation']) && self::automatic($file->getContents(), $from)) {
	    \models\ExecutionLog::record('inbound.refused', $wf, "an automatic message to {$data['rcpt']} was not answered", [], null, $wf->aid);
	    $file->delete();
	    return;
	}
	$exe = \models\MailPoll::startMessage($live, $node['wfid'], 'email', $file->getContents(), [ 'saveAttachments' => !empty($cfg['saveAttachments'] ?? true),
	    'variable' => \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'mail',
	    'mail' => [ 'recipient' => (string)($data['rcpt'] ?? '') ],
	    'input' => [ 'recipient' => (string)($data['rcpt'] ?? ''), 'mail_from' => $from, 'raw_file' => (string)$file->_id ],
	    'log' => 'message to ' . ($data['rcpt'] ?? '') ]);
	//CLD: 2026-11-02T18:00:00+02:00: Keep conversations: the run takes its place in the conversation; refused when too many came too fast
	if (!empty($cfg['conversation']) && !self::threadEmail($exe, $live, $node, (string)($data['rcpt'] ?? ''))) {
	    \models\ExecutionLog::record('inbound.refused', $wf, "too many messages in a conversation of {$data['rcpt']}", [], null, $wf->aid);
	    $exe->delete();
	    $file->delete();
	    return;
	}
	$exe->run();
    }

    //CLD: 2026-11-02T18:00:00+02:00: new: whether raw message $raw is an automatic one: a bounce (no envelope sender, $from), Auto-Submitted other than no, Precedence bulk / list / junk / auto_reply, an auto-responder's own headers
    public static function automatic($raw, $from) {
	if ($from === '' || preg_match('/^(mailer-daemon|postmaster)@/i', $from))
	    return true;
	$h = (new \util\MailParser($raw))->headers();
	$one = function($name) use ($h) {
	    $v = $h[$name] ?? '';
	    return strtolower(trim((string)(is_array($v) ? ($v[0] ?? '') : $v)));
	};
	$auto = $one('auto-submitted');
	return ($auto !== '' && $auto !== 'no') || in_array($one('precedence'), [ 'bulk', 'list', 'junk', 'auto_reply' ], true) || $one('x-autoreply') !== '' || $one('x-autorespond') !== '';
    }

    //CLD: 2026-11-02T18:00:00+02:00: new: run $exe of an email (MailPoll::startMessage: its variable has the message) in its conversation: the one whose messages it replies to, when it comes from the same sender (an address that knows a
    //Message-ID does not get into someone else's conversation), else a new one. Sets its variables. False: more than MAX_THREAD_RATE messages of that conversation in the last hour (a loop)
    public static function threadEmail($exe, $live, array $node, $rcpt) {
	$name = \util\Validator::isIdentifier((string)($node['config']['variable'] ?? '')) ? (string)$node['config']['variable'] : 'mail';
	$msg = (array)$exe->getVariable($name, []);
	$from = strtolower(trim((string)($msg['from'] ?? '')));
	$own = trim((string)($msg['message_id'] ?? ''), '<> ');
	$ids = array_values(array_filter(array_map(fn($r) => trim((string)$r, '<> '), array_merge([ (string)($msg['in_reply_to'] ?? '') ], (array)($msg['references'] ?? [])))));
	$C = \models\AgentConversation::class;
	$conv = $ids ? $C::coll()->findOne([ 'aid' => $live->aid, 'wf' => $live->_id, 'channel' => 'email', 'refs' => [ '$in' => $ids ], 'user_email' => $from ], $C::ARRAYS) : null;
	if ($conv) {
	    $hourAgo = new \MongoDB\BSON\UTCDateTime((time() - 3600) * 1000);
	    if (\models\AgentMessage::coll()->countDocuments([ 'conversation' => $conv['_id'], 'aid' => $conv['aid'], 'role' => 'user', 'at' => [ '$gte' => $hourAgo ] ]) >= self::MAX_THREAD_RATE)
		return false;
	}
	$mail = [ 'to' => $from, 'subject' => (string)($msg['subject'] ?? ''), 'reply_to' => (string)$rcpt ];
	$conv = $conv ?: $C::begin([ 'aid' => $live->aid, 'project' => $live->project, 'wf' => $live->_id, 'node' => $node['wfid'], 'channel' => 'email', 'user_name' => (string)($msg['from_name'] ?? ''), 'user_email' => $from,
	    'who' => 'm:' . $from, 'refs' => [], 'mail' => $mail ]);
	$C::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ], [ '$set' => [ 'mail' => $mail ] ] + ($own !== '' ? [ '$push' => [ 'refs' => [ '$each' => [ $own ], '$slice' => -$C::MAX_REFS ] ] ] : []));
	//the refs just pushed: the answer's In-Reply-To
	$conv = $C::read($conv['_id'], $conv['aid']);
	$text = trim((string)($msg['text'] ?? '')) !== '' ? (string)$msg['text'] : \models\node\NodeAppOutlookMail::htmlToText((string)($msg['html'] ?? ''));
	$text = \util\EmailHtml::withoutQuote($text);
	$seq = $C::addMessage($conv, 'user', [ 'text' => mb_substr($text, 0, $C::MAX_MESSAGE) ]);
	$input = is_array($exe->input) ? $exe->input : [];
	$exe->input = [ 'conversation' => (string)$conv['_id'], 'turn' => $seq, 'channel' => 'email', 'who' => 'm:' . $from ] + $input;
	$exe->setVariable('message', mb_substr($text, 0, $C::MAX_MESSAGE));
	$exe->setVariable('conversation', [ 'id' => (string)$conv['_id'], 'channel' => 'email', 'turn' => $seq, 'title' => (string)($conv['title'] ?: mb_substr((string)($msg['subject'] ?? ''), 0, 80)),
	    'history' => $C::historyVar($conv, $seq), 'summary' => (string)($conv['summary'] ?? '') ]);
	$exe->setVariable('user', [ 'id' => null, 'name' => (string)($msg['from_name'] ?? ''), 'email' => $from ]);
	$exe->save();
	$C::coll()->updateOne([ '_id' => $conv['_id'], 'aid' => $conv['aid'] ], [ '$set' => [ 'execution' => (string)$exe->_id ] ]);
	return true;
    }

    public static function afterExecutionEnd($exe) {
	$id = (string)($exe->input['raw_file'] ?? '');
	if ($exe->trigger !== 'email' || $id === '')
	    return;
	$file = \V::app()->om->findOne('File', [ '_id' => $id, 'aid' => $exe->aid ]);
	if ($file)
	    $file->delete();
    }
}
