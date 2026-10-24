<?php

/*
    Email (inbound) - starts the workflow (its published version) for each message sent to the step's address
    <account mail_prefix>-<code>@<MAIL_INBOUND_DOMAIN>.
    Haraka (haraka/plugins/nfn.js) accepts only the addresses of the token index (TriggerToken) whose account prefix matches, stores the raw message as a project File
    and submits a job: deliver() (run by the daemon) starts the run like the IMAP trigger (models\MailPoll::startMessage: one variable, default `mail`, attachments as project files); the raw message File is deleted when the run completes, kept when it fails (afterExecutionEnd below;
*/
namespace plugins\InboundMail\models;

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

    //an entry in the token index (TriggerToken), no extra data
    public static function tokenData(array $node) {
	return [];
    }

    //a new step gets its code and address when it is added (mail is accepted once the workflow is published)
    //CLD: 2026-10-18T14:40:00+03:00: TriggerToken::newToken (was the loop here)
    public static function initConfig() {
	$token = \models\TriggerToken::newToken('code');

	//CLD: 2026-10-21T11:30:00+03:00: InboundMailSettings::prefixOf (was findOne(...)->prefix: an error for an account without settings yet)
	$prefix = InboundMailSettings::prefixOf(\V::app()->config('rt.account')->_id);
	$domain = \V::app()->config('mail.inbound_domain');

	$address = "{$prefix}-{$token}@{$domain}";
	return [ 'token' => $token, 'address' => $address ];
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'address',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		//CLD: 2026-10-19T21:10:00+03:00: the prefix is in Inbound mail settings (was Account settings)
		'help' => 'Mail sent here starts the workflow once it is published (the start is your account\'s email prefix: Settings, Inbound mail)',
		'ui' => [ 'placeholder' => 'Set the email prefix in Inbound mail settings' ],
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
	];
    }

    //the code: valid, of this step only; the address shown again from the account's current prefix
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$token = $config['token'] ?? null;
	//CLD: 2026-10-18T14:40:00+03:00: the token checks are TriggerToken::checkToken (were here); no address without a valid code
	$valid = \models\TriggerToken::checkToken($this, 'code') && $valid;

	$wf = $this->workflow;
	$account = $wf ? \V::app()->om->findById('Account', $wf->aid) : null;
	$this->config = $config;
	if (empty($config['address'])) {
	    $this->errors('config', 'Address: set the email prefix in Inbound mail settings first');
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
	return $vars;
    }

    //CLD: 2026-10-19T21:10:00+03:00: the job is \plugins\InboundMail\models\NodeTriggerEmail::deliver (haraka/plugins/nfn.js JOB_SPEC; was \models\node\NodeTriggerEmail::deliver)
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

	$exe = \models\MailPoll::startMessage($live, $node['wfid'], 'email', $file->getContents(), [ 'saveAttachments' => !empty($cfg['saveAttachments'] ?? true),
	    'variable' => \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'mail',
	    'mail' => [ 'recipient' => (string)($data['rcpt'] ?? '') ],
	    'input' => [ 'recipient' => (string)($data['rcpt'] ?? ''), 'mail_from' => $from, 'raw_file' => (string)$file->_id ],
	    'log' => 'message to ' . ($data['rcpt'] ?? '') ]);
	$exe->run();
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
