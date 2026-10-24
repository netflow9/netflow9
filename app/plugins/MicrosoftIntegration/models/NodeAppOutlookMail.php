<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new step: Outlook Mail - sends, drafts, searches and organizes the messages of a Microsoft 365 / Outlook.com mailbox over Microsoft Graph,
    through an Outlook Mail (Microsoft Graph) credential of the project (CredentialMicrosoftOutlook, provides `msmail`). As the Gmail step (GoogleIntegration plugin).
    Operations (setting `operation`):
	send     a message from the account: to / cc / bcc, subject, text or HTML, project files as attachments (3 MB in all: Graph's limit of a request); optionally a reply
	           to a message (In reply to: same conversation, "RE:" subject, to the sender when no recipient is given)
	draft    the same message saved as a draft (a reply draft when In reply to is given)
	search   messages of a folder (or of the whole mailbox) by Outlook's search (from:, subject:, hasattachments:yes, words), unread only; read with their bodies (text and
	           HTML), attachments optionally saved in the project's Files; the newest first
	get      one message by id
	modify   a message: marked read / unread, moved to a folder (e.g. archive), categories added / removed
	delete   a message to Deleted Items
	folders  the mail folders of the mailbox (top level)
    Folders are given by their well-known name (inbox, archive, sentitems, drafts, deleteditems, junkemail) or by id. The answer goes into one variable (default `outlook`).
    On error (Graph errors, e.g. not found, no access; a project file missing; attachments too large) - fail the step, or continue on a second output `error`
*/

namespace plugins\MicrosoftIntegration\models;

class NodeAppOutlookMail extends NodeAppMicrosoft {
    //static data/structure
    public static $name = 'outlook-mail';
    public static $label = 'Outlook Mail';
    public static $icon = 'bi-envelope-at';
    public static $capability = 'msmail';
    public static $credentialLabel = 'Outlook credential';
    public static $credentialKinds = 'Outlook Mail';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'outlook';

    const OPERATIONS = [
	'send' => 'Send a message',
	'draft' => 'Create a draft',
	'search' => 'Search / read messages',
	'get' => 'Get a message',
	'modify' => 'Mark / move / categorize a message',
	'delete' => 'Delete a message',
	'folders' => 'List the folders',
    ];

    //the operations composing a message, the ones on an existing message
    const COMPOSE = [ 'send', 'draft' ];
    const ON_MESSAGE = [ 'get', 'modify', 'delete' ];

    const BODY_TYPES = [ 'text' => 'Text', 'html' => 'HTML' ];
    const MARK = [ 'none' => 'Leave as it is', 'read' => 'Read', 'unread' => 'Unread' ];

    //the attachments of a message sent (Graph: 4 MB a request, base64 included), the messages of a search, the text kept of a body
    const MAX_ATTACHMENTS = 3 * 1024 * 1024;
    const MAX_MESSAGES = 50;
    const MAX_SIZE_MB = 25;
    const MAX_BODY = 64 * 1024;

    const SELECT = 'id,conversationId,internetMessageId,subject,from,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,sentDateTime,body,bodyPreview,isRead,'
	. 'isDraft,importance,categories,hasAttachments,webLink,parentFolderId';

    public static function configSchema() {
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),

	    //get / modify / delete
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: The message id; {{ ... }} for expressions, e.g. {{ outlook[0].id }})
		'key' => 'messageId',
		'type' => 'text',
		'label' => 'Message',
		'default' => '',
		'help' => 'The message id, e.g. {{ outlook[0].id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_MESSAGE ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op(self::ON_MESSAGE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: Addresses separated by commas; {{ ... }} for expressions, e.g. {{ mail.from }})
		'key' => 'to',
		'type' => 'text',
		'label' => 'To',
		'default' => '',
		'help' => 'Addresses separated by commas, e.g. {{ mail.from }}',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'ana@example.com, Ion <ion@example.com>', 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'cc',
		'type' => 'text',
		'label' => 'Cc',
		'default' => '',
		'ui' => [ 'width' => 3, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'bcc',
		'type' => 'text',
		'label' => 'Bcc',
		'default' => '',
		'ui' => [ 'width' => 3, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 9 wide, on a new line
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Subject',
		'default' => '',
		'help' => 'A reply keeps the original subject when empty',
		'ui' => [ 'width' => 9, 'newRow' => true, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Body',
		'required' => true,
		'default' => 'text',
		'choices' => self::BODY_TYPES,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    //send / draft
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: Optional: the id of a message to answer (same conversation; to its sender when To is empty); {{ ... }} for expressions, e.g. {{ outlook[0].id }})
		'key' => 'replyTo',
		'type' => 'text',
		'label' => 'In reply to',
		'default' => '',
		'help' => 'Optional: the id of the message answered, e.g. {{ outlook[0].id }}',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: Project file ids, separated by commas, or a list; e.g. {{ file.id }} (Download File) or {{ mail.attachment_files }}; 3 MB in all)
		'key' => 'attachments',
		'type' => 'text',
		'label' => 'Attachments',
		'default' => '',
		'help' => 'Project file ids separated by commas, or a list; 3 MB in all',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],
	    [
		'key' => 'body',
		'type' => 'textarea',
		'label' => 'Message',
		'default' => '',
		'help' => '{{ ... }} for expressions',
		'ui' => [ 'rows' => 6, 'expression' => true, 'showIf' => $op(self::COMPOSE) ],
	    ],

	    //search
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 4 wide (was 6), on a new line; shorter help (was: inbox, archive, sentitems, drafts, deleteditems, junkemail or a folder id; empty: the whole mailbox)
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'default' => 'inbox',
		'help' => 'inbox, archive, sentitems, ... or a folder id; empty: all',
		'ui' => [ 'width' => 4, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 8 wide; shorter help (was: Outlook's search, e.g. from:ana@example.com, subject:invoice, hasattachments:yes, or words; empty: the newest messages)
		'key' => 'query',
		'type' => 'text',
		'label' => 'Search',
		'default' => '',
		'help' => 'Outlook search, e.g. from:ana@example.com subject:invoice; empty: the newest',
		'ui' => [ 'width' => 8, 'placeholder' => 'from:ana@example.com subject:invoice', 'expression' => true, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: a limit (the last line)
		'key' => 'maxResults',
		'type' => 'number',
		'label' => 'Messages',
		'required' => true,
		'default' => 10,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_MESSAGES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_MESSAGES ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'unreadOnly',
		'type' => 'switch',
		'label' => 'Unread only',
		'default' => false,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6); shorter help (was: Allowed file types only; their ids in attachment_files)
		'key' => 'saveAttachments',
		'type' => 'switch',
		'label' => 'Save the attachments in the project Files',
		'default' => false,
		'help' => 'Allowed file types only (attachment_files)',
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'search', 'get' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: a limit (the last line)
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest attachment (MB)',
		'required' => true,
		'default' => 10,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => [ 'key' => 'saveAttachments', 'operator' => 'eq', 'value' => true ] ],
	    ],


	    //modify
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'mark',
		'type' => 'select',
		'label' => 'Mark as',
		'required' => true,
		'default' => 'none',
		'choices' => self::MARK,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6); shorter help (was: e.g. archive, or a folder id; empty: not moved)
		'key' => 'moveTo',
		'type' => 'text',
		'label' => 'Move to folder',
		'default' => '',
		'help' => 'e.g. archive, or a folder id',
		'ui' => [ 'width' => 3, 'expression' => true, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'addCategories',
		'type' => 'text',
		'label' => 'Add categories',
		'default' => '',
		'help' => 'Names separated by commas',
		'ui' => [ 'width' => 3, 'expression' => true, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'removeCategories',
		'type' => 'text',
		'label' => 'Remove categories',
		'default' => '',
		'ui' => [ 'width' => 3, 'expression' => true, 'showIf' => $op([ 'modify' ]) ],
	    ],

	    static::timeoutField(60),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Outlook, e.g. not found, no access, attachments too large)
	    static::onErrorField('Outlook errors, attachments too large'),
	];
    }

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
	if ($operation === 'modify' && $this->cfg('mark', 'none') === 'none' && $empty('moveTo') && $empty('addCategories') && $empty('removeCategories'))
	    $fail('Mark as: choose what to change (mark, move or categories)');
	return $valid;
    }

    //the type of a message object (messageInfo())
    public static function messageType() {
	$list = [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
	return [ 'type' => 'object', 'info' => 'a message: { id, conversation_id, message_id, from, from_name, to, cc, bcc, reply_to, subject, date, sent, text, html, preview, is_read, '
	    . 'is_draft, importance, categories, has_attachments, attachments, attachment_count, attachment_files, link, folder_id, account }', 'fields' => [
	    'id' => [ 'type' => 'string', 'info' => 'the Graph id (get, modify, delete, In reply to)' ], 'conversation_id' => [ 'type' => 'string' ],
	    'message_id' => [ 'type' => 'string', 'info' => 'the Message-ID header' ], 'from' => [ 'type' => 'string', 'info' => 'the sender address' ],
	    'from_name' => [ 'type' => 'string' ], 'to' => $list, 'cc' => $list, 'bcc' => $list, 'reply_to' => $list, 'subject' => [ 'type' => 'string' ],
	    'date' => [ 'type' => 'string', 'info' => 'received (ISO 8601)' ], 'sent' => [ 'type' => 'string', 'info' => 'sent (ISO 8601)' ],
	    'text' => [ 'type' => 'string', 'info' => 'the body as text' ], 'html' => [ 'type' => 'string', 'info' => 'the body as HTML (empty for a text message)' ],
	    'preview' => [ 'type' => 'string', 'info' => 'the start of the body' ], 'is_read' => [ 'type' => 'boolean' ], 'is_draft' => [ 'type' => 'boolean' ],
	    'importance' => [ 'type' => 'string', 'info' => 'low, normal or high' ], 'categories' => $list, 'has_attachments' => [ 'type' => 'boolean' ],
	    'attachments' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'filename' => [ 'type' => 'string' ], 'contentType' => [ 'type' => 'string' ],
		'size' => [ 'type' => 'number' ], 'file' => [ 'type' => 'string', 'info' => 'the saved project file id (Save the attachments)' ] ] ] ],
	    'attachment_count' => [ 'type' => 'number' ], 'attachment_files' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the saved project file ids' ],
	    'link' => [ 'type' => 'string', 'info' => 'opens the message in Outlook on the web' ], 'folder_id' => [ 'type' => 'string' ],
	    'account' => [ 'type' => 'string', 'info' => 'the credential' ] ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$folder = [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'total' => [ 'type' => 'number' ],
	    'unread' => [ 'type' => 'number' ], 'children' => [ 'type' => 'number' ] ] ];
	return [ $name => match($config['operation'] ?? 'send') {
	    'send' => [ 'type' => 'object', 'info' => 'what was sent: { to, cc, bcc, subject, reply_to_id, attachments }', 'fields' => [ 'to' => [ 'type' => 'array' ],
		'cc' => [ 'type' => 'array' ], 'bcc' => [ 'type' => 'array' ], 'subject' => [ 'type' => 'string' ], 'reply_to_id' => [ 'type' => 'string' ],
		'attachments' => [ 'type' => 'number' ] ] ],
	    'search' => [ 'type' => 'array', 'items' => self::messageType(), 'info' => 'the messages found, the newest first' ],
	    'delete' => [ 'type' => 'object', 'info' => '{ id, deleted }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'deleted' => [ 'type' => 'boolean' ] ] ],
	    'folders' => [ 'type' => 'array', 'items' => $folder, 'info' => 'the folders: [ { id, name, total, unread, children } ]' ],
	    default => self::messageType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'send', 'draft' => $this->opCompose($operation === 'draft', $text, $value),
	    'search' => $this->opSearch($text),
	    'get' => $this->opGet($text),
	    'modify' => $this->opModify($text),
	    'delete' => $this->opDelete($text),
	    'folders' => $this->opFolders(),
	};
    }

    protected function opCompose($draft, \Closure $text, \Closure $value) {
	$replyTo = $text('replyTo');
	$message = [];
	foreach([ 'to' => 'toRecipients', 'cc' => 'ccRecipients', 'bcc' => 'bccRecipients' ] as $key => $field) {
	    $list = self::recipients($value($key));
	    if ($list)
		$message[$field] = $list;
	}
	$subject = $text('subject');
	if ($subject !== '')
	    $message['subject'] = $subject;
	$body = \util\Expression::toText($value('body'));
	$message['body'] = [ 'contentType' => $this->cfg('bodyType', 'text') === 'html' ? 'html' : 'text', 'content' => $body ];
	if ($replyTo === '') {
	    if (!$draft && empty($message['toRecipients']) && empty($message['ccRecipients']) && empty($message['bccRecipients']))
		throw new \RuntimeException('no recipient (To, Cc or Bcc)');
	    if ($subject === '')
		throw new \ErrorException('Outlook Mail: the message needs a subject');
	}
	$attachments = $this->attachments(\models\File::idsOf($value('attachments')));
	if ($attachments)
	    $message['attachments'] = $attachments;

	$summary = [ 'to' => self::addressesOf($message['toRecipients'] ?? []), 'cc' => self::addressesOf($message['ccRecipients'] ?? []),
	    'bcc' => self::addressesOf($message['bccRecipients'] ?? []), 'subject' => $subject, 'reply_to_id' => $replyTo !== '' ? $replyTo : null, 'attachments' => count($attachments) ];
	if ($draft) {
	    $saved = $replyTo !== ''
		? $this->api('POST', '/me/messages/' . self::graphId($replyTo, 'message') . '/createReply', [], [ 'message' => $message ])
		: $this->api('POST', '/me/messages', [], $message);
	    $result = $this->messageInfo($saved);
	    return [ $result, 'draft saved' . ($result['subject'] !== '' ? ": `{$result['subject']}`" : '') ];
	}
	if ($replyTo !== '')
	    $this->api('POST', '/me/messages/' . self::graphId($replyTo, 'message') . '/reply', [], [ 'message' => $message ]);
	else
	    $this->api('POST', '/me/sendMail', [], [ 'message' => $message, 'saveToSentItems' => true ]);
	$to = implode(', ', array_merge($summary['to'], $summary['cc'], $summary['bcc']));
	return [ $summary, ($replyTo !== '' ? 'reply sent' : 'sent') . ($to !== '' ? " to {$to}" : '') . ($attachments ? ', ' . count($attachments) . ' attachment(s)' : '') ];
    }

    //Graph's file attachments (base64) of project files $ids, 3 MB in all
    protected function attachments(array $ids) {
	$list = [];
	$total = 0;
	$om = \V::app()->om;
	foreach($ids as $fid) {
	    $file = \util\Validator::rule('oid', $fid) ? $om->findOne('File', [ '_id' => $fid, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr((string)$fid, 0, 60) . '`');
	    $total += (int)$file->length;
	    if ($total > self::MAX_ATTACHMENTS)
		throw new \RuntimeException('the attachments are larger than ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all');
	    $stream = \models\File::_bucket()->openDownloadStream($file->_id);
	    $content = stream_get_contents($stream);
	    fclose($stream);
	    $ext = strtolower(pathinfo((string)$file->filename, PATHINFO_EXTENSION));
	    $list[] = [ '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string)$file->filename,
		'contentType' => \models\File::CONTENT_TYPES[$ext] ?? 'application/octet-stream', 'contentBytes' => base64_encode((string)$content) ];
	}
	return $list;
    }

    protected function opSearch(\Closure $text) {
	$folder = $text('folder');
	$path = $folder === '' ? '/me/messages' : '/me/mailFolders/' . self::graphId($folder, 'folder') . '/messages';
	$max = max(1, min(self::MAX_MESSAGES, (int)$this->cfg('maxResults', 10)));
	$query = $text('query');
	$unread = (bool)$this->cfg('unreadOnly', false);
	$params = [ '$select' => self::SELECT, '$top' => min($max, 50) ];
	//Graph does not take $search with $filter / $orderby on messages: a search is filtered here (unread) and comes by relevance
	if ($query !== '') {
	    $params['$search'] = '"' . str_replace('"', '\\"', $query) . '"';
	} else {
	    $params['$orderby'] = 'receivedDateTime desc';
	    if ($unread)
		$params['$filter'] = 'isRead eq false';
	}
	[ $items ] = $this->pages($path, $params, $query !== '' && $unread ? self::MAX_MESSAGES : $max);
	if ($query !== '' && $unread)
	    $items = array_slice(array_values(array_filter($items, fn($m) => empty($m['isRead']))), 0, $max);
	$messages = [];
	foreach($items as $m)
	    $messages[] = $this->messageInfo($m, (bool)$this->cfg('saveAttachments', false));
	return [ $messages, count($messages) . ' message(s)' . ($query !== '' ? " for `{$query}`" : '') ];
    }

    protected function opGet(\Closure $text) {
	$id = self::graphId($text('messageId'), 'message');
	$result = $this->messageInfo($this->api('GET', "/me/messages/{$id}", [ '$select' => self::SELECT ]), (bool)$this->cfg('saveAttachments', false));
	return [ $result, "`{$result['subject']}` from {$result['from']}" ];
    }

    protected function opModify(\Closure $text) {
	$id = self::graphId($text('messageId'), 'message');
	$changes = [];
	$mark = (string)$this->cfg('mark', 'none');
	if ($mark !== 'none')
	    $changes['isRead'] = $mark === 'read';
	$add = self::names($text('addCategories'));
	$remove = self::names($text('removeCategories'));
	if ($add || $remove) {
	    $current = (array)($this->api('GET', "/me/messages/{$id}", [ '$select' => 'categories' ])['categories'] ?? []);
	    $changes['categories'] = array_values(array_diff(array_values(array_unique(array_merge($current, $add))), $remove));
	}
	$m = $changes ? $this->api('PATCH', "/me/messages/{$id}", [], $changes) : null;
	$done = array_keys($changes);
	$moveTo = $text('moveTo');
	if ($moveTo !== '') {
	    $m = $this->api('POST', "/me/messages/{$id}/move", [], [ 'destinationId' => rawurldecode(self::graphId($moveTo, 'folder')) ]);
	    $done[] = "moved to {$moveTo}";
	}
	if ($m === null)
	    throw new \ErrorException('Outlook Mail: modify: nothing to change');
	$result = $this->messageInfo($this->api('GET', '/me/messages/' . rawurlencode((string)$m['id']), [ '$select' => self::SELECT ]));
	return [ $result, "`{$result['subject']}`: " . implode(', ', $done) ];
    }

    protected function opDelete(\Closure $text) {
	$id = self::graphId($text('messageId'), 'message');
	$this->api('DELETE', "/me/messages/{$id}");
	return [ [ 'id' => rawurldecode($id), 'deleted' => true ], 'moved to Deleted Items' ];
    }

    protected function opFolders() {
	[ $items ] = $this->pages('/me/mailFolders', [ '$top' => 100 ], 500);
	$folders = array_map(fn($f) => [ 'id' => (string)($f['id'] ?? ''), 'name' => (string)($f['displayName'] ?? ''), 'total' => (int)($f['totalItemCount'] ?? 0),
	    'unread' => (int)($f['unreadItemCount'] ?? 0), 'children' => (int)($f['childFolderCount'] ?? 0) ], $items);
	return [ $folders, count($folders) . ' folder(s)' ];
    }

    // ------------------------------------------------------------------------------------
    //messages

    //a message object of the step from Graph's message; the attachments listed (and saved: $save) when it has some
    protected function messageInfo($m, $save = false) {
	$info = self::messageOf($m);
	$info['account'] = (string)$this->cred->name;
	if (!empty($m['hasAttachments']) && $info['id'] !== '')
	    [ $info['attachments'], $info['attachment_files'] ] = $this->messageAttachments($info['id'], $save);
	$info['attachment_count'] = count($info['attachments']);
	return $info;
    }

    //the message object of Graph's message (no attachments)
    public static function messageOf($m) {
	$m = is_array($m) ? $m : [];
	$isHtml = strtolower((string)($m['body']['contentType'] ?? 'text')) === 'html';
	$content = (string)($m['body']['content'] ?? '');
	$text = $isHtml ? self::htmlToText($content) : $content;
	return [
	    'id' => (string)($m['id'] ?? ''),
	    'conversation_id' => (string)($m['conversationId'] ?? ''),
	    'message_id' => trim((string)($m['internetMessageId'] ?? ''), '<>'),
	    'from' => (string)($m['from']['emailAddress']['address'] ?? ''),
	    'from_name' => (string)($m['from']['emailAddress']['name'] ?? ''),
	    'to' => self::addressesOf($m['toRecipients'] ?? []),
	    'cc' => self::addressesOf($m['ccRecipients'] ?? []),
	    'bcc' => self::addressesOf($m['bccRecipients'] ?? []),
	    'reply_to' => self::addressesOf($m['replyTo'] ?? []),
	    'subject' => (string)($m['subject'] ?? ''),
	    'date' => (string)($m['receivedDateTime'] ?? ''),
	    'sent' => (string)($m['sentDateTime'] ?? ''),
	    'text' => mb_substr($text, 0, self::MAX_BODY),
	    'html' => $isHtml ? mb_substr($content, 0, self::MAX_BODY) : '',
	    'preview' => (string)($m['bodyPreview'] ?? ''),
	    'is_read' => !empty($m['isRead']),
	    'is_draft' => !empty($m['isDraft']),
	    'importance' => (string)($m['importance'] ?? 'normal'),
	    'categories' => array_values((array)($m['categories'] ?? [])),
	    'has_attachments' => !empty($m['hasAttachments']),
	    'attachments' => [],
	    'attachment_count' => 0,
	    'attachment_files' => [],
	    'link' => (string)($m['webLink'] ?? ''),
	    'folder_id' => (string)($m['parentFolderId'] ?? ''),
	];
    }

    //the attachments of message $id: [ list, saved file ids ]; saved ($save) when their type is allowed and they are not larger than Largest attachment
    protected function messageAttachments($id, $save) {
	$maxBytes = max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 10))) * 1024 * 1024;
	$select = $save ? [] : [ '$select' => 'name,contentType,size,isInline' ];
	[ $items ] = $this->pages('/me/messages/' . rawurlencode($id) . '/attachments', $select, 50, []);
	$list = [];
	$files = [];
	foreach($items as $a) {
	    $entry = [ 'filename' => (string)($a['name'] ?? ''), 'contentType' => (string)($a['contentType'] ?? ''), 'size' => (int)($a['size'] ?? 0), 'file' => null ];
	    $isFile = ($a['@odata.type'] ?? '#microsoft.graph.fileAttachment') === '#microsoft.graph.fileAttachment';
	    if ($save && $isFile && isset($a['contentBytes'])) {
		$filename = \models\node\NodeAppDownload::filename($entry['filename'], '', '', $entry['contentType']);
		if (\models\File::isAllowedName($filename) && $entry['size'] <= $maxBytes) {
		    $entry['file'] = (string)\models\File::createFromString($filename, (string)base64_decode((string)$a['contentBytes']), [ 'metadata' => [
			'project' => $this->exec->project,
			'aid' => $this->exec->aid,
			'owner' => null,
			'source' => 'outlook-mail',
			'wf' => $this->exec->wf,
			'created_at' => new \MongoDB\BSON\UTCDateTime(),
		    ] ]);
		    $files[] = $entry['file'];
		} else {
		    $this->exec->log($this->wfid, "Outlook Mail: attachment `{$entry['filename']}` not saved (" . (\models\File::isAllowedName($filename) ? 'too large' : 'type not allowed') . ')');
		}
	    }
	    $list[] = $entry;
	}
	return [ $list, $files ];
    }

    //a text version of an HTML body
    public static function htmlToText($html) {
	$s = preg_replace('~<(script|style|head)\b.*?</\1>~is', '', (string)$html);
	$s = preg_replace('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '$2 ($1)', $s);
	$s = preg_replace('~<br\s*/?>~i', "\n", $s);
	$s = preg_replace('~</(p|div|h[1-6]|li|tr|table|blockquote)>~i', "\n\n", $s);
	$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = preg_replace("/[ \t\x{00A0}]+/u", ' ', $s);
	return trim(preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $s));
    }
}
