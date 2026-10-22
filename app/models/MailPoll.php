<?php

/*
    state of one IMAP trigger step (workflow + step wfid):
	folder, UIDVALIDITY, last UID seen,
	last check / error, and a lock (`locked_until`) so two pollers never fetch the same mailbox for the same step at once.

    Kept outside the workflow document: saving the workflow in the designer must not reset it

    //CLD: 2026-10-18T10:00:00+03:00: also the check itself and the message helpers (were util\MailPoller): read() for the Read emails step, startMessage() / messageVars()
    //for the mail triggers
*/

namespace models;

class MailPoll extends \sys\BaseModel {
    protected static $collection = 'mail_polls';

    //CLD: 2026-10-18T10:00:00+03:00: unique: two first checks of a step at the same time cannot create two states (each locked by its own check). An existing non-unique
    //wf_1_node_1 index must be dropped before `v seed::indexes` creates this one
    public static $indexes = [
	[ [ 'wf' => 1, 'node' => 1 ], [ 'unique' => true ] ],
    ];


    //a crashed poller frees the step after this many seconds
    const LOCK_SECONDS = 600;

    //text / html kept in the execution (variables and input) per message
    const MAX_BODY = 512 * 1024;

    public function getSchema() {
	return [
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'required' => true
	    ],
	    [
		'key' => 'node',
		'type' => 'text',
		'label' => 'Step',
		'required' => true
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	    [
		'key' => 'credential',
		'type' => 'object',
		'object' => 'Credential',
		'label' => 'Credential'
	    ],
	    [
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder'
	    ],
	    [
		'key' => 'uidvalidity',
		'type' => 'number',
		'label' => 'UIDVALIDITY'
	    ],
	    [
		'key' => 'last_uid',
		'type' => 'number',
		'label' => 'Last UID'
	    ],
	    [
		'key' => 'skipped',
		'type' => 'array',
		'label' => 'Skipped UIDs'
	    ],
	    [
		'key' => 'checked_at',
		'type' => 'datetime',
		'label' => 'Last check'
	    ],
	    [
		'key' => 'error',
		'type' => 'text',
		'label' => 'Last error'
	    ],
	    [
		'key' => 'locked_until',
		'type' => 'datetime',
		'label' => 'Locked until'
	    ],
	];
    }

    //lock the state of step $nodeId of $wf (created when missing); null when another poller holds it
    //CLD: 2026-10-12T14:14:59+03:00: through ObjectManager (om->findOneAndUpdate; was the MongoDB collection directly): created when missing (upsert), then locked atomically
    //CLD: 2026-10-17T14:20:00+03:00: by the workflow's id, account and project (was the workflow: the Read emails step has the execution, not the workflow)
    public static function acquire($wfId, $nodeId, $aid, $project) {
	$now = new \MongoDB\BSON\UTCDateTime();

	//CLD: 2026-10-18T10:00:00+03:00: a concurrent first check inserted the state first (duplicate key on the unique index): it exists now, go on to the lock
	try {
	    \V::app()->om->findOneAndUpdate('MailPoll',
		[ 'wf' => $wfId, 'node' => (string)$nodeId ],
		[ '$setOnInsert' => [ 'aid' => $aid, 'project' => $project, 'locked_until' => null, 'created_at' => $now ] ],
		[ 'upsert' => true, 'projection' => [ '_id' => 1 ] ]
	    );
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    if ($e->getCode() != 11000)
		throw $e;
	}

	return \V::app()->om->findOneAndUpdate('MailPoll',
	    [ 'wf' => $wfId, 'node' => (string)$nodeId, '$or' => [ [ 'locked_until' => null ], [ 'locked_until' => [ '$lt' => $now ] ] ] ],
	    [ '$set' => [ 'locked_until' => new \MongoDB\BSON\UTCDateTime((time() + self::LOCK_SECONDS) * 1000) ] ]
	);
    }

    //save the state fields (not the lock)
    //CLD: 2026-10-12T14:14:59+03:00: om->updateMany on this state's id (was the MongoDB collection directly)
    public function store() {
	\V::app()->om->updateMany('MailPoll',
	    [ '_id' => $this->_id ],
	    [ '$set' => [
		'credential' => $this->credential,
		'folder' => $this->folder,
		'uidvalidity' => $this->uidvalidity,
		'last_uid' => $this->last_uid,
		'skipped' => array_values(is_array($this->skipped) ? $this->skipped : []),
		'checked_at' => $this->checked_at,
		'error' => $this->error,
		'updated_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]
	);
    }

    public function release() {
	\V::app()->om->updateMany('MailPoll', [ '_id' => $this->_id ], [ '$set' => [ 'locked_until' => null ] ]);
    }

    //CLD: 2026-10-17T13:24:18+03:00: hook workflow:before-delete (sys\Boot): a deleted workflow's IMAP check state (last UID, lock) goes with it (was plugins\BaseNodes\Plugin::beforeWorkflowDelete)
    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('MailPoll', [ 'wf' => $wf->_id ]) as $state)
	    $state->delete();
    }

    //CLD: 2026-10-17T14:20:00+03:00: one check of the mailbox for the Read emails step (models\node\NodeAppReadEmail, a regular step; was poll(): one execution per message, for the
    //IMAP trigger): $exec the running execution, $wfid the step, $cfg its config; returns [ status (ok / baseline / busy / error), message, mails => [ message objects ] ]
    //(imapNodes() went with the trigger)
    //CLD: 2026-10-18T10:00:00+03:00: static, + $out: optional callable($message) for progress output (the run's log; was the poller's `out` property)
    public static function read($exec, $wfid, array $cfg, $out = null) {
	$wfid = (string)$wfid;

	//CLD: 2026-10-17T14:20:00+03:00: the state is the workflow's (exec->wf), not the execution's
	$state = self::acquire($exec->wf, $wfid, $exec->aid, $exec->project);
	if (empty($state))
	    return self::result($out, 'busy', 'another check of this step is running');

	$mails = [];
	//CLD: 2026-10-18T10:30:00+03:00: \Throwable (was \Exception): a PHP error (e.g. TypeError) also ends as status `error`, the state saved and the lock released (was held LOCK_SECONDS)
	try {
	    $result = $state->check($exec, $wfid, $cfg, $mails, $out);
	    $state->error = null;
	} catch (\Throwable $e) {
	    $state->error = mb_substr($e->getMessage(), 0, 500);
	    $result = self::result($out, 'error', $e->getMessage());
	}
	$state->checked_at = new \MongoDB\BSON\UTCDateTime();
	$state->store();
	$state->release();

	$result['mails'] = $mails;
	return $result;
    }

    //CLD: 2026-10-17T14:20:00+03:00: fetch and read: the message objects are added to $mails (were executions started per message)
    //CLD: 2026-10-18T10:00:00+03:00: a method of the (locked) state (was the poller's, with $state)
    protected function check($exec, $wfid, array $cfg, array &$mails, $out) {
	//CLD: 2026-10-08T14:37:58+03:00: a credential providing `imap`; its type opens the connection
	$cred = \models\Credential::findFor($exec, $cfg['credential'] ?? '', 'imap');
	if (empty($cred))
	    throw new \Exception('the mail credential of the step is missing');

	$folder = trim((string)($cfg['folder'] ?? 'INBOX')) ?: 'INBOX';
	$which = $cfg['which'] ?? 'new';
	$after = $cfg['after'] ?? 'none';
	$moveTo = trim((string)($cfg['moveTo'] ?? ''));

	$max = max(1, min(\models\node\NodeAppReadEmail::MAX_MESSAGES, (int)($cfg['maxMessages'] ?? 10)));
	$maxSize = max(1, min(\models\node\NodeAppReadEmail::MAX_SIZE_MB, (int)($cfg['maxSize'] ?? 25))) * 1024 * 1024;
	$saveAttachments = filter_var($cfg['saveAttachments'] ?? true, FILTER_VALIDATE_BOOLEAN);

	//CLD: 2026-10-09T17:21:14+03:00: the credential (a variant of models\Credential) opens the connection (was its type class)
	$imap = $cred->openImap();
	try {
	    $box = $imap->select($folder);

	    //another credential / folder, or the server renumbered the folder: start over
	    if ((string)$this->credential !== (string)$cred->_id || $this->folder !== $folder || (int)$this->uidvalidity !== (int)$box['uidvalidity']) {
		$this->credential = $cred->_id;
		$this->folder = $folder;
		$this->uidvalidity = (int)$box['uidvalidity'];
		$this->last_uid = null;
		$this->skipped = [];
	    }
	    //CLD: 2026-10-08T09:03:43+03:00: UIDs skipped earlier (too large) are not picked again
	    $skippedBefore = is_array($this->skipped) ? array_map('intval', $this->skipped) : [];

	    if ($which === 'new') {
		if ($this->last_uid === null) {
		    //first check: remember where the folder is, start nothing
		    $this->last_uid = !empty($box['uidnext']) ? (int)$box['uidnext'] - 1 : (int)max(array_merge([ 0 ], $imap->uidSearch('ALL')));
		    $imap->logout();
		    //CLD: 2026-10-17T14:20:00+03:00: wording (messages are read by the next runs; were started)
		    return self::result($out, 'baseline', "watching {$folder} from UID " . ($this->last_uid + 1) . '; messages arriving from now on are read by the next runs');
		}
		$last = (int)$this->last_uid;
		//n:* always returns the highest UID, even when it is below n
		$uids = array_values(array_filter($imap->uidSearch('UID ' . ($last + 1) . ':*'), fn($u) => $u > $last));
	    } elseif ($which === 'unread') {
		$uids = $imap->uidSearch('UNSEEN');
	    } else {
		$uids = $imap->uidSearch('ALL');
	    }

	    $uids = array_values(array_diff($uids, $skippedBefore));
	    $found = count($uids);
	    $uids = array_slice($uids, 0, $max);
	    $sizes = $imap->fetchSizes($uids);
	    $skipped = 0;

	    foreach($uids as $uid) {
		$size = $sizes[$uid] ?? 0;
		if ($size > $maxSize) {
		    $skipped++;
		    self::say($out, "UID {$uid}: skipped, " . round($size / 1048576, 1) . ' MB is over the limit');
		    $this->advance($which, $uid);
		    //CLD: 2026-10-08T09:03:43+03:00: remembered (the last 1000), so unread / all do not pick it at every check
		    if ($which !== 'new')
			$this->skipped = array_slice(array_merge($skippedBefore, [ (int)$uid ]), -1000);
		    $skippedBefore = is_array($this->skipped) ? array_map('intval', $this->skipped) : [];
		    $this->store();
		    continue;
		}

		$raw = $imap->fetchMessage($uid);
		if ($raw === null) {
		    $this->advance($which, $uid);
		    continue;
		}

		//CLD: 2026-10-15T11:37:34+03:00: startMessage() (shared with the Email inbound trigger), with the mailbox's fields (was start())
		//CLD: 2026-10-17T14:20:00+03:00: the message object (messageVars(), shared with startMessage()) is added to the list; bodies kept shorter (the list is stored in the execution)
		[ $msg ] = self::messageVars($raw, [ 'saveAttachments' => $saveAttachments, 'maxBody' => \models\node\NodeAppReadEmail::MAX_BODY,
		    'mail' => [ 'account' => (string)$cred->name, 'folder' => $folder, 'uid' => (int)$uid ],
		    'meta' => [ 'project' => $exec->project, 'aid' => $exec->aid, 'wf' => $exec->wf ] ]);
		$mails[] = $msg;
		self::say($out, "UID {$uid}: from {$msg['from']}: " . mb_substr($msg['subject'], 0, 100));

		//afterwards: failures are logged, the message was read anyway
		try {
		    if ($after === 'seen')
			$imap->addFlags([ $uid ], [ '\\Seen' ]);
		    elseif ($after === 'move')
			$imap->move([ $uid ], $moveTo);
		    elseif ($after === 'delete')
			$imap->delete([ $uid ]);
		} catch (\Exception $e) {
		    self::say($out, "UID {$uid}: afterwards ({$after}) failed: " . $e->getMessage());
		}

		//saved after each message: a crash later does not read it again
		$this->advance($which, $uid);
		$this->store();
	    }
	    $imap->logout();
	//CLD: 2026-10-18T10:30:00+03:00: \Throwable (was \Exception): the connection is closed on a PHP error too
	} catch (\Throwable $e) {
	    $imap->close();
	    throw $e;
	}

	$message = count($mails) . " message(s) read in {$folder}";
	//CLD: 2026-10-08T09:03:43+03:00: + skipped count
	if ($skipped > 0)
	    $message .= ", {$skipped} skipped (too large)";
	if ($found > count($uids))
	    $message .= ', ' . ($found - count($uids)) . ' left for the next check';
	return self::result($out, 'ok', $message) + [ 'skipped' => $skipped ];
    }

    //`new`: remember the highest UID handled
    protected function advance($which, $uid) {
	if ($which === 'new' && $uid > (int)$this->last_uid)
	    $this->last_uid = (int)$uid;
    }

    //a saved execution for message $raw
    //CLD: 2026-10-08T14:37:58+03:00: $cred (a Credential) instead of a mail account; input `credential` (was `account`)
    //CLD: 2026-10-13T08:15:00+03:00: the message is one variable, an object, named $variable (were separate mail_* variables)
    //CLD: 2026-10-15T11:37:34+03:00: public and shared by the mail triggers (IMAP, Email inbound; was start(), IMAP only): trigger $trigger, $o: saveAttachments, variable,
    //mail (the trigger's own fields of the variable, first), input (its own fields of the execution input), log (how the message is named in the run's log)
    public static function startMessage($wf, $wfid, $trigger, $raw, array $o = []) {
	$variable = (string)($o['variable'] ?? 'mail');
	//CLD: 2026-10-17T14:20:00+03:00: the variable and the input from messageVars() (shared with the Read emails step)
	[ $msg, $input ] = self::messageVars($raw, $o + [ 'meta' => [ 'project' => $wf->project, 'aid' => $wf->aid, 'wf' => $wf->_id ] ]);
	$input['vars'] = [ $variable => $msg ];

	$exe = \models\WfExecution::startFor($wf, $trigger, $input, $wfid);
	$exe->log($wfid, ($o['log'] ?? 'message') . " from {$msg['from']}: " . mb_substr($msg['subject'], 0, 100));
	$exe->save();
	return $exe;
    }

    //CLD: 2026-10-17T14:20:00+03:00: message $raw as [ the variable's object, the execution input (without vars) ] (was part of startMessage()); $o: saveAttachments,
    //mail (fields first in the object), input (fields first in the input), meta (project, aid, wf of the saved attachment files), maxBody (text / html kept, default MAX_BODY)
    public static function messageVars($raw, array $o = []) {
	$saveAttachments = !empty($o['saveAttachments']);
	$meta = (array)($o['meta'] ?? []);
	$maxBody = (int)($o['maxBody'] ?? self::MAX_BODY);
	$mail = new \util\MailParser($raw);

	//attachments -> project files
	$files = [];
	foreach($mail->attachments() as $att) {
	    $entry = [ 'filename' => $att['filename'], 'contentType' => $att['contentType'], 'size' => $att['size'], 'disposition' => $att['disposition'] ];
	    if ($saveAttachments) {
		$entry['file'] = (string)\models\File::createFromString($att['filename'], $att['content'], [ 'metadata' => [
		    'project' => $meta['project'] ?? null,
		    'aid' => $meta['aid'] ?? null,
		    'owner' => null,
		    'source' => 'mail',
		    'wf' => $meta['wf'] ?? null,
		    'created_at' => new \MongoDB\BSON\UTCDateTime(),
		] ]);
	    }
	    $files[] = $entry;
	}

	$from = $mail->from();
	//CLD: 2026-10-11T22:58:51+03:00: several values are lists (were comma-separated texts): addresses, references, attachment files; + every header and the attachments
	$emails = fn($list) => array_values(array_filter(array_map(fn($a) => (string)($a['email'] ?? ''), $list), 'strlen'));
	$date = $mail->date();
	$text = self::cut($mail->text(), $maxBody);
	$html = self::cut($mail->html(), $maxBody);

	$msg = (array)($o['mail'] ?? []) + [
	    'message_id' => (string)$mail->messageId(),
	    'from' => (string)($from['email'] ?? ''),
	    'from_name' => (string)($from['name'] ?? ''),
	    'to' => $emails($mail->to()),
	    'cc' => $emails($mail->cc()),
	    'reply_to' => $emails($mail->replyTo()),
	    'subject' => $mail->subject(),
	    'date' => $date ? $date->format(DATE_ATOM) : '',
	    'text' => $text,
	    'html' => $html,
	    'in_reply_to' => (string)$mail->inReplyTo(),
	    'references' => array_values($mail->references()),
	    'headers' => self::headerVars($mail->headers()),
	    //[ { filename, contentType, size, disposition, file } ]: file = the saved project file's id (null when attachments are not saved)
	    'attachments' => array_map(fn($f) => [ 'filename' => (string)$f['filename'], 'contentType' => (string)$f['contentType'], 'size' => (int)$f['size'],
		'disposition' => (string)$f['disposition'], 'file' => $f['file'] ?? null ], $files),
	    'attachment_count' => count($files),
	    'attachment_files' => array_values(array_filter(array_column($files, 'file'))),
	];

	$input = (array)($o['input'] ?? []) + [
	    'size' => strlen($raw),
	    'headers' => [
		'message_id' => $msg['message_id'],
		'in_reply_to' => (string)$mail->inReplyTo(),
		'references' => $mail->references(),
		'from' => $from,
		'to' => $mail->to(),
		'cc' => $mail->cc(),
		'reply_to' => $mail->replyTo(),
		'subject' => $msg['subject'],
		'date' => $msg['date'],
	    ],
	    'attachments' => $files,
	    'received_at' => new \MongoDB\BSON\UTCDateTime(),
	];
	return [ $msg, $input ];
    }

    //at most MAX_BODY bytes, cut on a UTF-8 boundary
    //CLD: 2026-10-11T22:58:51+03:00: the headers as a variable: lower-case name => its value, or a list of values when the header appears several times (e.g. received); at
    //most MAX_HEADERS names and MAX_HEADER characters per value; names a database field cannot have (`.`, leading `$`) are left out
    const MAX_HEADERS = 200;
    const MAX_HEADER = 2000;
    protected static function headerVars(array $headers) {
	$vars = [];
	foreach($headers as $name => $values) {
	    $name = (string)$name;
	    if ($name === '' || str_contains($name, '.') || $name[0] === '$')
		continue;
	    $values = array_map(fn($v) => mb_substr((string)$v, 0, self::MAX_HEADER, 'UTF-8'), array_values((array)$values));
	    $vars[$name] = count($values) == 1 ? $values[0] : $values;
	    if (count($vars) >= self::MAX_HEADERS)
		break;
	}
	return $vars;
    }

    //CLD: 2026-10-17T14:20:00+03:00: + $max (default MAX_BODY)
    protected static function cut($s, $max = self::MAX_BODY) {
	if (strlen($s) <= $max)
	    return $s;
	return mb_strcut($s, 0, $max, 'UTF-8');
    }

    //CLD: 2026-10-18T10:00:00+03:00: static, + $out (were the poller's methods)
    protected static function result($out, $status, $message) {
	self::say($out, $message);
	return [ 'status' => $status, 'message' => $message ];
    }

    protected static function say($out, $message) {
	if ($out)
	    call_user_func($out, $message);
    }
}
