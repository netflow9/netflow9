<?php

/*
    CLD: 2026-10-20T12:00:00+03:00: new step: Gmail - sends, drafts, searches and organizes the messages of a Gmail / Google Workspace mailbox over the Gmail API, through a
    Google Mail (OAuth) credential of the project (plugins\GoogleIntegration\models\CredentialGoogleMail, provides `gmail`; the same credential the Read emails step uses over IMAP).
    Inspired by sim.ai's Gmail block. Operations (setting `operation`):
	send     a message from the account: to / cc / bcc, subject, text or HTML, project files as attachments; optionally a reply to a message (same thread, In-Reply-To /
	           References, "Re: " subject, to the sender when no recipient is given)
	draft    the same message saved as a draft
	search   messages by a Gmail search (q: from:, subject:, has:attachment, newer_than:2d, ...), labels, unread only; read with their bodies (models\MailPoll::messageVars: the same
	           message objects as Read emails, + id, thread_id, labels, snippet), attachments optionally saved in the project's Files; larger messages are skipped
	get      one message by id
	modify   a message: marked read / unread, archived (out of the inbox), labels added (created when missing) / removed
	trash    a message to the trash
	labels   the labels of the mailbox
    Messages go through the upload endpoint (multipart: metadata + message/rfc822), 35 MB at most. The answer goes into one variable (default `gmail`).
    On error (Gmail API errors, e.g. not found, no access; a project file missing; attachments too large) - fail the step, or continue on a second output `error` (as HTTP Request)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGmail); core classes fully qualified
namespace plugins\GoogleIntegration\models;

class NodeAppGmail extends NodeAppGoogle {
    //static data/structure
    //CLD: 2026-10-20T14:00:00+03:00: the shape, the credential, the operation run and the API calls are NodeAppGoogle's
    public static $name = 'gmail';
    public static $label = 'Gmail';
    public static $icon = 'bi-envelope-at';
    public static $capability = 'gmail';
    public static $credentialLabel = 'Google Mail credential';
    public static $credentialKinds = 'Google Mail';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'gmail';

    const OPERATIONS = [
	'send' => 'Send a message',
	'draft' => 'Create a draft',
	'search' => 'Search / read messages',
	'get' => 'Get a message',
	'modify' => 'Mark / archive / label a message',
	'trash' => 'Move a message to the trash',
	'labels' => 'List the labels',
    ];

    //the operations composing a message, the ones on an existing message
    const COMPOSE = [ 'send', 'draft' ];
    const ON_MESSAGE = [ 'get', 'modify', 'trash' ];

    const BODY_TYPES = [ 'text' => 'Text', 'html' => 'HTML' ];
    const MARK = [ 'none' => 'Leave as it is', 'read' => 'Read', 'unread' => 'Unread' ];

    const API = 'https://gmail.googleapis.com/gmail/v1/users/me';
    const UPLOAD_API = 'https://gmail.googleapis.com/upload/gmail/v1/users/me';

    const MAX_MESSAGES = 50;
    const MAX_SIZE_MB = 25;
    //a message sent through the upload endpoint (the Gmail API limit)
    const MAX_SEND_MB = 35;

    //the mailbox's labels (asked once per run): [ lower-case name / id => label ]
    protected $labelMap = null;

    public static function configSchema() {
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField('A Google Mail (OAuth) credential of the project (menu: Credentials); the Google app needs the Gmail API enabled'),
	    static::operationField(),
	    [
		'key' => 'messageId',
		'type' => 'text',
		'label' => 'Message',
		'default' => '',
		'help' => 'The Gmail message id, e.g. {{ gmail[0].id }} of a search; {{ ... }} for expressions',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_MESSAGE ],
		'ui' => [ 'placeholder' => '{{ msg.id }}', 'expression' => true, 'showIf' => $op(self::ON_MESSAGE) ],
	    ],

	    //send / draft
	    [
		'key' => 'replyTo',
		'type' => 'text',
		'label' => 'In reply to',
		'default' => '',
		'help' => 'Optional: the Gmail message id answered (same thread, "Re: " subject, to its sender when To is empty); {{ ... }} for expressions',
		'ui' => [ 'placeholder' => '{{ msg.id }}', 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'to',
		'type' => 'text',
		'label' => 'To',
		'default' => '',
		'help' => 'Addresses separated by commas (Name <a@x.com> too), or {{ ... }} giving a list',
		'ui' => [ 'placeholder' => 'ana@example.com, {{ mail.from }}', 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'cc',
		'type' => 'text',
		'label' => 'Cc',
		'default' => '',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'bcc',
		'type' => 'text',
		'label' => 'Bcc',
		'default' => '',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Subject',
		'default' => '',
		'help' => 'Empty in a reply: "Re: " + the subject answered; {{ ... }} for expressions',
		'ui' => [ 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Body',
		'required' => true,
		'default' => 'text',
		'choices' => self::BODY_TYPES,
		'help' => 'HTML: a text version is made from it too',
		'ui' => [ 'width' => 6, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'fromName',
		'type' => 'text',
		'label' => 'Sender name',
		'default' => '',
		'help' => 'Empty: the account\'s name in Gmail',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'body',
		'type' => 'textarea',
		'label' => 'Content',
		'default' => '',
		'help' => '{{ ... }} for expressions',
		'ui' => [ 'rows' => 8, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'attachments',
		'type' => 'text',
		'label' => 'Attachments',
		'default' => '',
		'help' => 'Project file ids separated by commas, or {{ ... }} giving one or a list, e.g. {{ file.id }}, {{ mail.attachment_files }}',
		'ui' => [ 'placeholder' => '{{ file.id }}', 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],

	    //search
	    [
		'key' => 'query',
		'type' => 'text',
		'label' => 'Search',
		'default' => '',
		'help' => 'Gmail search, as in Gmail\'s search box: from:ana@x.com subject:invoice has:attachment newer_than:2d; empty: all; {{ ... }} for expressions',
		'ui' => [ 'placeholder' => 'from:billing@example.com has:attachment', 'expression' => true, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'labelFilter',
		'type' => 'text',
		'label' => 'In labels',
		'default' => 'INBOX',
		'help' => 'Label names separated by commas (all of them), e.g. INBOX, Invoices; empty: any',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'maxMessages',
		'type' => 'number',
		'label' => 'Messages',
		'required' => true,
		'default' => 10,
		'help' => 'The newest first',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_MESSAGES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_MESSAGES ] ],
		'ui' => [ 'step' => 1, 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'unreadOnly',
		'type' => 'switch',
		'label' => 'Unread only',
		'default' => false,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'spamTrash',
		'type' => 'switch',
		'label' => 'Include spam and trash',
		'default' => false,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'saveAttachments',
		'type' => 'switch',
		'label' => 'Save attachments as project files',
		'default' => false,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'search', 'get' ]) ],
	    ],
	    [
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest message (MB)',
		'required' => true,
		'default' => 25,
		'help' => 'Larger messages are skipped',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ],
		'ui' => [ 'step' => 1, 'width' => 6, 'showIf' => $op([ 'search', 'get' ]) ],
	    ],
	    [
		'key' => 'markAfter',
		'type' => 'select',
		'label' => 'Then mark them as',
		'required' => true,
		'default' => 'none',
		'choices' => self::MARK,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],

	    //modify
	    [
		'key' => 'mark',
		'type' => 'select',
		'label' => 'Mark as',
		'required' => true,
		'default' => 'none',
		'choices' => self::MARK,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		'key' => 'archive',
		'type' => 'switch',
		'label' => 'Archive (out of the inbox)',
		'default' => false,
		'ui' => [ 'width' => 6, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		'key' => 'addLabels',
		'type' => 'text',
		'label' => 'Add labels',
		'default' => '',
		'help' => 'Label names separated by commas (created when missing), e.g. Invoices, STARRED',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		'key' => 'removeLabels',
		'type' => 'text',
		'label' => 'Remove labels',
		'default' => '',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op([ 'modify' ]) ],
	    ],

	    static::timeoutField(60),
	    static::variableField('Search: a list of messages; get: the message; send / draft / modify: { id, thread_id, labels }; labels: a list'),
	    static::onErrorField('An error from Gmail (e.g. not found, no access, a refused address), a project file missing, attachments too large'),
	];
    }

    //the credential of the project; a message needs a recipient (or a message answered) and some content
    public function validates($options = []) {
	$valid = parent::validates($options);
	$fail = function($message) use (&$valid) {
	    $this->errors('config', $message);
	    $valid = false;
	};
	$empty = fn($key) => trim((string)$this->cfg($key, '')) === '';
	$operation = $this->cfg('operation', 'send');
	if (in_array($operation, self::COMPOSE, true)) {
	    if ($operation === 'send' && $empty('to') && $empty('cc') && $empty('bcc') && $empty('replyTo'))
		$fail('To: required (or In reply to)');
	    if ($empty('subject') && $empty('replyTo'))
		$fail('Subject: required (or In reply to)');
	}
	if ($operation === 'modify' && $this->cfg('mark', 'none') === 'none' && !$this->cfg('archive', false) && $empty('addLabels') && $empty('removeLabels'))
	    $fail('Mark as: choose what to change (mark, archive or labels)');
	return $valid;
    }

    //the type of a message object of a search / get: Read emails' (MailPoll::messageVars) without the IMAP fields, + the Gmail ones
    public static function messageType() {
	$type = \models\node\NodeAppReadEmail::messageType();
	unset($type['fields']['folder'], $type['fields']['uid']);
	$type['fields'] = [
	    'id' => [ 'type' => 'string', 'info' => 'the Gmail message id' ],
	    'thread_id' => [ 'type' => 'string' ],
	    'labels' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'label ids, e.g. INBOX, UNREAD' ],
	    'snippet' => [ 'type' => 'string', 'info' => 'the start of the text, as Gmail shows it' ],
	] + $type['fields'];
	$type['info'] = 'a Gmail message: { id, thread_id, labels, snippet, account, from, from_name, to, cc, subject, date, text, html, attachments, ... }';
	return $type;
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$sent = [ 'type' => 'object', 'info' => 'the message: { id, thread_id, labels }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'thread_id' => [ 'type' => 'string' ],
	    'labels' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ];
	return [ $name => match($config['operation'] ?? 'send') {
	    'search' => [ 'type' => 'array', 'info' => 'the messages found, newest first (empty when none)', 'items' => self::messageType() ],
	    'get' => self::messageType(),
	    'draft' => [ 'type' => 'object', 'info' => 'the draft: { draft_id, id, thread_id, labels }', 'fields' => [ 'draft_id' => [ 'type' => 'string' ] ] + $sent['fields'] ],
	    'labels' => [ 'type' => 'array', 'info' => 'the labels: [ { id, name, type } ]', 'items' => [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ],
		'name' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'info' => 'system or user' ] ] ] ],
	    default => $sent,
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    //CLD: 2026-10-20T14:00:00+03:00: the operation (NodeAppGoogle::execute runs it: the variable, the log, On error)
    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'send', 'draft' => $this->opCompose($operation === 'draft', $text, $value),
	    'search' => $this->opSearch($text),
	    'get' => $this->opGet($text),
	    'modify' => $this->opModify($text),
	    'trash' => $this->opTrash($text),
	    'labels' => $this->opLabels(),
	};
    }

    protected function opCompose($draft, \Closure $text, \Closure $value) {
	$m = [
	    'from' => (string)$this->cred->email,
	    'from_name' => $text('fromName'),
	    'to' => self::addresses($value('to')),
	    'cc' => self::addresses($value('cc')),
	    'bcc' => self::addresses($value('bcc')),
	    'subject' => $text('subject'),
	];
	$body = \util\Expression::toText($value('body'));
	if ($this->cfg('bodyType', 'text') === 'html') {
	    $m['html'] = $body;
	    $m['text'] = self::htmlToText($body);
	} else {
	    $m['text'] = $body;
	}

	//a reply: the thread, the headers of the message answered
	$threadId = null;
	$replyTo = $text('replyTo');
	if ($replyTo !== '') {
	    $orig = $this->api('GET', '/messages/' . $this->idOf($replyTo), [ 'format' => 'metadata', 'metadataHeaders' => [ 'Message-ID', 'References', 'Subject', 'From', 'Reply-To' ] ]);
	    $h = [];
	    foreach((array)($orig['payload']['headers'] ?? []) as $header)
		$h[strtolower((string)($header['name'] ?? ''))] = (string)($header['value'] ?? '');
	    $threadId = (string)($orig['threadId'] ?? '') ?: null;
	    $msgId = trim($h['message-id'] ?? '');
	    if ($msgId !== '') {
		$m['in_reply_to'] = $msgId;
		$m['references'] = trim(($h['references'] ?? '') . ' ' . $msgId);
	    }
	    if ($m['subject'] === '')
		$m['subject'] = preg_match('/^\s*re:/i', $h['subject'] ?? '') ? (string)$h['subject'] : 'Re: ' . ($h['subject'] ?? '');
	    if (!$m['to'] && !$m['cc'] && !$m['bcc'])
		$m['to'] = self::addresses($h['reply-to'] ?? '' ?: ($h['from'] ?? ''));
	}
	if (!$m['to'] && !$m['cc'] && !$m['bcc'] && !$draft)
	    throw new \RuntimeException('no recipient');

	//attachments: project files
	$files = [];
	$total = 0;
	foreach(self::fileIds($value('attachments')) as $fid) {
	    $file = \util\Validator::rule('oid', $fid) ? \V::app()->om->findOne('File', [ '_id' => $fid, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr($fid, 0, 60) . '`');
	    $total += (int)$file->length;
	    if ($total > self::MAX_SEND_MB * 1048576 * 0.72)
		throw new \RuntimeException('the attachments are larger than ' . (int)(self::MAX_SEND_MB * 0.72) . ' MB (Gmail: ' . self::MAX_SEND_MB . ' MB a message, encoded)');
	    $files[] = $file;
	}
	$m['attachments'] = $files;

	$raw = self::compose($m);
	if (strlen($raw) > self::MAX_SEND_MB * 1048576)
	    throw new \RuntimeException('the message is larger than ' . self::MAX_SEND_MB . ' MB');

	$meta = $threadId ? [ 'threadId' => $threadId ] : [];
	if ($draft) {
	    $data = $this->upload('/drafts', [ 'message' => (object)$meta ], $raw);
	    $msg = (array)($data['message'] ?? []);
	    $result = [ 'draft_id' => (string)($data['id'] ?? ''), 'id' => (string)($msg['id'] ?? ''), 'thread_id' => (string)($msg['threadId'] ?? ''),
		'labels' => array_values((array)($msg['labelIds'] ?? [])) ];
	    return [ $result, 'draft `' . mb_substr($m['subject'], 0, 100) . "` saved ({$result['draft_id']})" ];
	}
	$data = $this->upload('/messages/send', (object)$meta, $raw);
	$result = [ 'id' => (string)($data['id'] ?? ''), 'thread_id' => (string)($data['threadId'] ?? ''), 'labels' => array_values((array)($data['labelIds'] ?? [])) ];
	$to = implode(', ', array_merge($m['to'], $m['cc'], $m['bcc']));
	return [ $result, '`' . mb_substr($m['subject'], 0, 100) . '` sent to ' . mb_substr($to, 0, 200) . ($files ? ' with ' . count($files) . ' attachment(s)' : '') . ($threadId ? ' (reply)' : '') ];
    }

    protected function opSearch(\Closure $text) {
	$q = $text('query');
	if ($this->cfg('unreadOnly', false))
	    $q = trim("{$q} is:unread");
	$query = [ 'maxResults' => max(1, min(self::MAX_MESSAGES, (int)$this->cfg('maxMessages', 10))) ];
	if ($q !== '')
	    $query['q'] = $q;
	if ($this->cfg('spamTrash', false))
	    $query['includeSpamTrash'] = 'true';
	$labels = self::names($text('labelFilter'));
	if ($labels) {
	    $query['labelIds'] = [];
	    foreach($labels as $label) {
		$found = $this->label($label);
		if (!$found)
		    throw new \RuntimeException("no label `{$label}`");
		$query['labelIds'][] = $found['id'];
	    }
	}
	$list = $this->api('GET', '/messages', $query);

	$mails = [];
	$skipped = 0;
	foreach((array)($list['messages'] ?? []) as $ref) {
	    $msg = $this->readMessage((string)($ref['id'] ?? ''));
	    if ($msg === null) {
		$skipped++;
		continue;
	    }
	    $mails[] = $msg;
	}

	$mark = $this->cfg('markAfter', 'none');
	if ($mails && in_array($mark, [ 'read', 'unread' ], true)) {
	    $this->api('POST', '/messages/batchModify', [], [ 'ids' => array_column($mails, 'id' ), ($mark === 'read' ? 'removeLabelIds' : 'addLabelIds') => [ 'UNREAD' ] ]);
	    foreach($mails as &$msg)
		$msg['labels'] = $mark === 'read' ? array_values(array_diff($msg['labels'], [ 'UNREAD' ])) : array_values(array_unique(array_merge($msg['labels'], [ 'UNREAD' ])));
	    unset($msg);
	}
	return [ $mails, count($mails) . ' message(s)' . ($q !== '' ? " for `{$q}`" : '') . ($skipped ? ", {$skipped} skipped (too large)" : '') . ($mark !== 'none' && $mails ? ", marked {$mark}" : '') ];
    }

    protected function opGet(\Closure $text) {
	$id = $this->idOf($text('messageId'));
	$msg = $this->readMessage($id);
	if ($msg === null)
	    throw new \RuntimeException("message {$id} is larger than " . (int)$this->cfg('maxSize', 25) . ' MB');
	return [ $msg, "from {$msg['from']}: " . mb_substr($msg['subject'], 0, 100) ];
    }

    protected function opModify(\Closure $text) {
	$id = $this->idOf($text('messageId'));
	$add = [];
	$remove = [];
	$mark = $this->cfg('mark', 'none');
	if ($mark === 'read')
	    $remove[] = 'UNREAD';
	elseif ($mark === 'unread')
	    $add[] = 'UNREAD';
	if ($this->cfg('archive', false))
	    $remove[] = 'INBOX';
	foreach(self::names($text('addLabels')) as $name)
	    $add[] = ($this->label($name) ?? $this->createLabel($name))['id'];
	foreach(self::names($text('removeLabels')) as $name) {
	    $label = $this->label($name);
	    if ($label)
		$remove[] = $label['id'];
	}
	$add = array_values(array_unique($add));
	$remove = array_values(array_diff(array_unique($remove), $add));
	$data = $this->api('POST', "/messages/{$id}/modify", [], [ 'addLabelIds' => $add, 'removeLabelIds' => $remove ]);
	$result = [ 'id' => (string)($data['id'] ?? $id), 'thread_id' => (string)($data['threadId'] ?? ''), 'labels' => array_values((array)($data['labelIds'] ?? [])) ];
	return [ $result, "{$id}: labels " . ($add ? '+' . implode(' +', $add) : '') . ($remove ? ' -' . implode(' -', $remove) : '') ];
    }

    protected function opTrash(\Closure $text) {
	$id = $this->idOf($text('messageId'));
	$data = $this->api('POST', "/messages/{$id}/trash");
	return [ [ 'id' => (string)($data['id'] ?? $id), 'thread_id' => (string)($data['threadId'] ?? ''), 'labels' => array_values((array)($data['labelIds'] ?? [])) ], "{$id} moved to the trash" ];
    }

    protected function opLabels() {
	$labels = array_map(fn($l) => [ 'id' => (string)$l['id'], 'name' => (string)$l['name'], 'type' => (string)($l['type'] ?? 'user') ], array_values($this->labels()));
	$labels = array_values(array_column($labels, null, 'id'));
	return [ $labels, count($labels) . ' label(s)' ];
    }

    // ------------------------------------------------------------------------------------
    //the Gmail API

    //a message (multipart: the JSON metadata, then the RFC 822 message) to the upload endpoint
    protected function upload($path, $meta, $raw) {
	$boundary = 'netflow9-' . bin2hex(random_bytes(8));
	$body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\r\n"
	    . "--{$boundary}\r\nContent-Type: message/rfc822\r\n\r\n{$raw}\r\n--{$boundary}--\r\n";
	return $this->call('POST', self::UPLOAD_API . $path . '?uploadType=multipart', array_merge($this->authHeaders(), [ 'Accept: application/json',
	    "Content-Type: multipart/related; boundary={$boundary}" ]), $body);
    }

    //message $id read (raw) as a message object (MailPoll::messageVars + the Gmail fields); null when larger than the largest size
    protected function readMessage($id) {
	$id = $this->idOf($id);
	$maxBytes = max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1048576;
	$min = $this->api('GET', "/messages/{$id}", [ 'format' => 'minimal' ]);
	if ((int)($min['sizeEstimate'] ?? 0) > $maxBytes) {
	    $this->exec->log($this->wfid, "Gmail: message {$id} skipped, " . round((int)$min['sizeEstimate'] / 1048576, 1) . ' MB is over the limit');
	    return null;
	}
	//base64url: 4/3 of the message, + the JSON around it
	$data = $this->api('GET', "/messages/{$id}", [ 'format' => 'raw' ], null, (int)($maxBytes * 1.4) + 65536);
	$raw = base64_decode(strtr((string)($data['raw'] ?? ''), '-_', '+/'), false);
	if ($raw === false || $raw === '')
	    throw new \RuntimeException("message {$id}: no content");
	[ $msg ] = \models\MailPoll::messageVars($raw, [ 'saveAttachments' => (bool)$this->cfg('saveAttachments', false), 'maxBody' => \models\node\NodeAppReadEmail::MAX_BODY,
	    'mail' => [ 'id' => (string)($data['id'] ?? $id), 'thread_id' => (string)($data['threadId'] ?? ''), 'labels' => array_values((array)($data['labelIds'] ?? [])),
		'snippet' => html_entity_decode((string)($data['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'account' => (string)$this->cred->name ],
	    'meta' => [ 'project' => $this->exec->project, 'aid' => $this->exec->aid, 'wf' => $this->exec->wf ] ]);
	return $msg;
    }

    //the mailbox's labels: [ lower-case name => label, id => label ]
    protected function labels() {
	if ($this->labelMap === null) {
	    $this->labelMap = [];
	    foreach((array)($this->api('GET', '/labels')['labels'] ?? []) as $l) {
		$this->labelMap[mb_strtolower((string)$l['name'])] = $l;
		$this->labelMap[(string)$l['id']] = $l;
	    }
	}
	return $this->labelMap;
    }

    //label $name (by name, any case, or by id: INBOX, Label_12); null when there is none
    protected function label($name) {
	$labels = $this->labels();
	return $labels[$name] ?? $labels[mb_strtolower($name)] ?? null;
    }

    protected function createLabel($name) {
	$l = $this->api('POST', '/labels', [], [ 'name' => mb_substr($name, 0, 225), 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show' ]);
	$this->labelMap[mb_strtolower((string)$l['name'])] = $l;
	$this->labelMap[(string)$l['id']] = $l;
	$this->exec->log($this->wfid, "Gmail: label `{$l['name']}` created");
	return $l;
    }

    protected function idOf($id) {
	$id = trim((string)$id);
	if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id))
	    throw new \RuntimeException($id === '' ? 'no message given' : 'not a Gmail message id: `' . mb_substr($id, 0, 100) . '`');
	return $id;
    }

    // ------------------------------------------------------------------------------------
    //messages

    //project file ids of a setting's value: one id, a list, or ids separated by commas; files of objects ({ id } of Download File / Google Drive) too
    //CLD: 2026-10-22T00:35:40+03:00: models\File::idsOf (moved to the core: the FTP / SFTP Upload step uses it too)
    public static function fileIds($value) {
	return \models\File::idsOf($value);
    }

    //a text version of an HTML body
    public static function htmlToText($html) {
	$s = preg_replace('~<(script|style)\b.*?</\1>~is', '', (string)$html);
	$s = preg_replace('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '$2 ($1)', $s);
	$s = preg_replace('~<br\s*/?>~i', "\n", $s);
	$s = preg_replace('~</(p|div|h[1-6]|li|tr|table|blockquote)>~i', "\n\n", $s);
	$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = preg_replace("/[ \t]+/", ' ', $s);
	return trim(preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $s));
    }

    //the RFC 822 message of $m = { from, from_name, to, cc, bcc, subject, text, html (optional), in_reply_to, references, attachments: [ File ] }: text, or text + HTML
    //(multipart/alternative), in multipart/mixed with the attachments; encoded headers (CR / LF removed: no header injection); Bcc kept (Gmail removes it when sending)
    public static function compose(array $m) {
	$clean = fn($v) => trim(preg_replace('/[ \t]*[\r\n]+[ \t]*/', ' ', (string)$v));
	$name = fn($n) => $clean($n) === '' ? '' : (mb_check_encoding($clean($n), 'ASCII') ? '"' . addcslashes($clean($n), '"\\') . '"' : mb_encode_mimeheader($clean($n), 'UTF-8', 'Q')) . ' ';
	$list = fn($l) => implode(', ', array_map(fn($a) => '<' . $clean($a) . '>', (array)$l));
	$id = bin2hex(random_bytes(8));
	$domain = substr(strrchr((string)($m['from'] ?? ''), '@'), 1) ?: 'gmail.com';
	$headers = [];
	if (($m['from'] ?? '') !== '')
	    $headers[] = 'From: ' . $name($m['from_name'] ?? '') . '<' . $clean($m['from']) . '>';
	foreach([ 'to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc' ] as $key => $header) {
	    if (!empty($m[$key]))
		$headers[] = "{$header}: " . $list($m[$key]);
	}
	$headers[] = 'Subject: ' . mb_encode_mimeheader($clean($m['subject'] ?? ''), 'UTF-8', 'Q');
	if (!empty($m['in_reply_to'])) {
	    $headers[] = 'In-Reply-To: ' . $clean($m['in_reply_to']);
	    $headers[] = 'References: ' . $clean($m['references'] ?? $m['in_reply_to']);
	}
	$headers = array_merge($headers, [ 'Date: ' . date(DATE_RFC2822), "Message-ID: <{$id}@{$domain}>", 'MIME-Version: 1.0' ]);

	$qp = fn($s) => str_replace("\n", "\r\n", str_replace("\r\n", "\n", quoted_printable_encode(str_replace([ "\r\n", "\r" ], "\n", (string)$s))));
	$textPart = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($m['text'] ?? '') . "\r\n";
	if (isset($m['html'])) {
	    $alt = 'alt-' . $id;
	    $content = "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n--{$alt}\r\n{$textPart}--{$alt}\r\n"
		. "Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($m['html']) . "\r\n--{$alt}--\r\n";
	} else {
	    $content = $textPart;
	}
	if (empty($m['attachments']))
	    return implode("\r\n", $headers) . "\r\n" . $content;

	$mix = 'mix-' . $id;
	$body = "--{$mix}\r\n{$content}";
	foreach($m['attachments'] as $file) {
	    $filename = $clean((string)$file->filename);
	    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	    $type = self::UPLOAD_TYPES[$ext] ?? 'application/octet-stream';
	    $param = mb_check_encoding($filename, 'ASCII') ? '"' . addcslashes($filename, '"\\') . '"' : null;
	    $disposition = $param !== null ? "filename={$param}" : "filename*=UTF-8''" . rawurlencode($filename);
	    $body .= "--{$mix}\r\nContent-Type: {$type}; name=" . ($param ?? '"' . mb_encode_mimeheader($filename, 'UTF-8', 'B') . '"') . "\r\nContent-Transfer-Encoding: base64\r\n"
		. "Content-Disposition: attachment; {$disposition}\r\n\r\n" . chunk_split(base64_encode($file->getContents()), 76, "\r\n");
	}
	$headers[] = "Content-Type: multipart/mixed; boundary=\"{$mix}\"";
	return implode("\r\n", $headers) . "\r\n\r\n" . $body . "--{$mix}--\r\n";
    }
}
