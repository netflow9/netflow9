<?php
/*
    checks a folder of a mail account (IMAP) and starts one execution of the workflow per message
//(plugins\Mail\Poller does the work; run now by `app/v Mail::poll::run`, later by the scheduler daemon). Options: which messages (new since
//the last check / unread / all) and what to do with them afterwards (nothing / mark read / move / delete)

//CLD: 2026-10-14T07:45:58+03:00: moved from the Mail plugin into BaseNodes (was plugins\Mail\models); the check is util\MailPoller, the console command `v mail::poll`
//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\trigger)

//CLD: 2026-10-17T14:20:00+03:00: a regular step, Read emails (was NodeTriggerImap, trigger::imap: one execution per message, on its own schedule): each run checks the folder
//(models\MailPoll::read; CLD: 2026-10-18T10:00:00+03:00: was util\MailPoller::read) and puts the messages it picks into one list variable (default `mails`) for the steps after it (e.g. a Loop over {{ mails }}); runs on a schedule come
//from a Schedule trigger before it. The check state (last UID, lock) is still kept per workflow and step (models\MailPoll)

*/
namespace models\node;

class NodeAppReadEmail extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'read-email';
    public static $label = 'Read emails (IMAP)';
    public static $icon = 'bi-envelope-arrow-down';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;

    const WHICH = [
	'new' => 'New messages (arrived since the last run)',
	'unread' => 'Unread messages',
	'all' => 'All messages in the folder',
    ];

    const AFTER = [
	'none' => 'Leave them as they are',
	'seen' => 'Mark them as read',
	'move' => 'Move them to another folder',
	'delete' => 'Delete them',
    ];

    const MAX_MESSAGES = 50;
    const MAX_SIZE_MB = 50;
    const MAX_BODY = 64 * 1024;

    //the step's checks (called by Workflow::validates)
    public function validates($options = []) {
	$valid = parent::validates($options);
	$wf = $this->workflow;

	if ((string)$this->cfg('credential', '') !== '' && (!$wf || empty(\models\Credential::findFor($wf, $this->cfg('credential', ''), 'imap')))) {
	    $this->errors('config', 'choose a mail credential of this project');
	    $valid = false;
	}

	//CLD: 2026-10-09T08:10:30+03:00: folder, schedule fields, which / after, moveTo, limits: rules of configSchema() (checked by parent::validates); here only the
	//checks between values and the weekdays (a list)
	//CLD: 2026-10-17T14:20:00+03:00: no schedule (a Schedule trigger runs the workflow) and no refused which / after combinations: reading the same messages at every run
	//is the user's choice for a step
	$after = $this->cfg('after', 'none');

	if ($after === 'move') {
	    $moveTo = trim((string)$this->cfg('moveTo', ''));
	    if ($moveTo !== '' && strcasecmp($moveTo, trim((string)$this->cfg('folder', ''))) === 0) {
		$this->errors('config', 'messages cannot be moved to the folder that is checked');
		$valid = false;
	    }
	}
	return $valid;
    }

    //CLD: 2026-10-08T14:37:58+03:00: credentials of the current project providing `imap` (were mail accounts), for the designer
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor('imap', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    //CLD: 2026-10-09T08:10:30+03:00: model field definitions with rules (WfNode::configForm(), a sys\NestedModel; were app.smartform fields): credential and folder, then the
    //schedule of the checks (WfSchedule::schema, default every 5 minutes), then the message options
    //CLD: 2026-10-17T14:20:00+03:00: without the schedule fields (a regular step)
    public static function configSchema() {
	$choices = [];
	foreach(static::credentialChoices() as $c)
	    $choices[$c['value']] = $c['label'];

	return [
	    [
		//CLD: 2026-10-24T12:55:27+03:00: shorter help (was: IMAP, Google Mail or Microsoft 365 Mail credentials of the project (menu: Credentials))
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Mail credential',
		'choices' => $choices,
		'help' => 'IMAP, Google Mail or Microsoft 365 Mail credential of the project',
		'required' => true,
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: 6 wide
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'required' => true,
		'default' => 'INBOX',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: shorter help (was: The messages read, a list of objects, e.g. {{ mails.length }}, {{ mails[0].subject }}; a Loop over {{ mails }} handles them one by one)
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Messages variable',
		'required' => true,
		'default' => 'mails',
		'help' => 'e.g. a Loop over {{ mails }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: 6 wide, on a new line; shorter help (was: New: the first run only notes where the folder is (an empty list); messages arriving after it are read by the next runs)
		'key' => 'which',
		'type' => 'select',
		'label' => 'Read',
		'required' => true,
		'default' => 'new',
		'choices' => self::WHICH,
		'help' => 'New: the first run notes where the folder ends; the next runs read what arrives',
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: 3 wide
		'key' => 'after',
		'type' => 'select',
		'label' => 'Afterwards',
		'required' => true,
		'default' => 'none',
		'choices' => self::AFTER,
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: 3 wide
		'key' => 'moveTo',
		'type' => 'text',
		'label' => 'Move to folder',
		'default' => '',
		'requiredIf' => [ 'if' => 'after', 'in' => [ 'move' ] ],
		'ui' => [ 'width' => 3, 'placeholder' => 'Processed', 'showIf' => [ 'key' => 'after', 'operator' => 'eq', 'value' => 'move' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: 3 wide, on a new line
		'key' => 'saveAttachments',
		'type' => 'switch',
		'label' => 'Save attachments as project files',
		'default' => true,
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: a limit (the last line)
		'key' => 'maxMessages',
		'type' => 'number',
		'label' => 'Messages per run',
		'required' => true,
		'default' => 10,
		'help' => 'Oldest first; the rest wait for the next run',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_MESSAGES, 'skipEmpty' => true, 'message' => 'Between 1 and ' . self::MAX_MESSAGES ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:27+03:00: layout: a limit (the last line)
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest message (MB)',
		'required' => true,
		'default' => 25,
		'help' => 'Larger messages are skipped',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'Between 1 and ' . self::MAX_SIZE_MB ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ],
	    ],
	];
    }

    //CLD: 2026-10-08T22:57:28+03:00: on schedule: one check of the mailbox (plugins\Mail\Poller: one execution per message)
    //CLD: 2026-10-11T22:38:36+03:00: the variables a received message starts the run with (Mail\Poller)
    //CLD: 2026-10-11T22:58:51+03:00: several values are lists (addresses, references, attachment files); + mail_in_reply_to, mail_references, mail_headers, mail_attachments (list of
    //objects; was the count, now mail_attachment_count)
    //CLD: 2026-10-13T08:15:00+03:00: one variable, an object, named by the step's `variable` setting (default `mail`; were separate mail_* variables)
    //CLD: 2026-10-17T14:20:00+03:00: a list of message objects (messageType()), default `mails`
    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'mails') : 'mails';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'array', 'info' => 'the messages read (oldest first; empty when there are none): [ { subject, from, text, html, attachments, ... } ]',
	    'items' => self::messageType() ] ] : [];
    }

    //CLD: 2026-10-17T14:20:00+03:00: the type of a message object (was in outputVariables(); also the Email inbound trigger's variable)
    public static function messageType() {
	return [
	    'type' => 'object',
	    'info' => 'a received message: { account, folder, uid, message_id, from, from_name, to, cc, reply_to (lists), subject, date (ISO 8601), text, html, in_reply_to, '
		. 'references (list), headers (lower-case name => value, or a list when repeated), attachments ([ { filename, contentType, size, disposition, file } ]), '
		. 'attachment_count, attachment_files (saved file ids) }',
	    //CLD: 2026-10-13T12:34:29+03:00: the fields and their types (util\Expression::typeAt: suggested after `mail.`, `mail.attachments[0].`; was a list of names)
	    'fields' => [
		'account' => [
		    'type' => 'string',
		    'info' => 'the mail credential'
		],
		'folder' => [
		    'type' => 'string'
		],
		'uid' => [
		    'type' => 'number'
		],
		'message_id' => [
		    'type' => 'string'
		],
		'from' => [
		    'type' => 'string',
		    'info' => 'the sender address'
		],
		'from_name' => [
		    'type' => 'string',
		    'info' => 'the sender name'
		],
		'to' => [
		    'type' => 'array',
		    'items' => [ 'type' => 'string' ]
		],
		'cc' => [
		    'type' => 'array',
		    'items' => [ 'type' => 'string' ]
		],
		'reply_to' => [
		    'type' => 'array',
		    'items' => [ 'type' => 'string' ]
		],
		'subject' => [
		    'type' => 'string'
		],
		'date' => [
		    'type' => 'string',
		    'info' => 'ISO 8601'
		],
		'text' => [ 'type' => 'string', 'info' => 'the text body' ],
		'html' => [ 'type' => 'string', 'info' => 'the HTML body' ],
		'in_reply_to' => [ 'type' => 'string' ],
		'references' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		'headers' => [ 'type' => 'object', 'info' => 'lower-case name => value (a list when repeated), e.g. headers["x-mailer"]' ],
		'attachments' => [
		    'type' => 'array',
		    'items' => [
			'type' => 'object',
			'fields' => [
			    'filename' => [ 'type' => 'string' ],
			    'contentType' => [ 'type' => 'string' ],
			    'size' => [ 'type' => 'number', 'info' => 'bytes' ],
			    'disposition' => [ 'type' => 'string' ],
			    'file' => [ 'type' => 'string', 'info' => 'the saved project file id (none when not saved)' ]
			]
		    ]
		],
		'attachment_count' => [
		    'type' => 'number'
		],
		'attachment_files' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the saved file ids' ],
	    ] ];
    }

    //CLD: 2026-10-17T14:20:00+03:00: the step's work (were runScheduled() / startNow(): one execution per message): one check of the mailbox, the messages picked go into the list
    //variable (empty when none, e.g. the first run of `new`); a failed check, or another run checking this step at the same time, fails the step
    public function execute($edgeId = null) {
	//CLD: 2026-10-18T10:00:00+03:00: models\MailPoll::read(), the log callable as an argument (was util\MailPoller, its `out` property)
	$result = \models\MailPoll::read($this->exec, $this->wfid, is_array($this->config) ? $this->config : [], function($message) {
	    $this->exec->log($this->wfid, $message);
	});
	if (in_array($result['status'], [ 'error', 'busy' ], true))
	    throw new \ErrorException('Read emails: ' . $result['message']);

	$name = (string)$this->cfg('variable', 'mails');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result['mails']);
	return [ 0 ];
    }
}
