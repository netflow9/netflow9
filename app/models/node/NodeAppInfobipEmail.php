<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip Email - email sent through Infobip (a sender of a domain verified there). Operations (setting `operation`):
	send     a message (email/3/send, multipart): to, cc, bcc, subject, text and / or HTML, reply-to, a project file attached, its delivery reports sent to an address
	logs     the messages sent (email/1/logs): by message, bulk or recipient
	reports  the delivery reports not read yet (email/1/reports)
    A send's result: { bulkId, messages: [ { to, messageId, status, statusGroup, description } ], count }
*/

namespace models\node;

class NodeAppInfobipEmail extends NodeAppInfobip {
    public static $name = 'infobip-email';
    public static $label = 'Infobip Email';
    public static $icon = 'bi-envelope';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'email';

    const OPERATIONS = [
	'send' => 'Send an email',
	'logs' => 'List sent messages',
	'reports' => 'Read delivery reports',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'logs', 'reports' ];
    const MAX_ATTACH_MB = 20;

    protected static function operationFields() {
	return [
	    self::field('to', 'To', [ 'send', 'logs' ], [ 'help' => 'Addresses separated by commas, or a list (logs: one)', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'send' ] ],
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('from', 'From', [ 'send' ], [ 'help' => 'Name <address> of a verified domain; empty: the credential\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cc', 'Cc', [ 'send' ], [ 'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	    self::field('bcc', 'Bcc', [ 'send' ], [ 'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	    self::field('replyTo', 'Reply to', [ 'send' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('subject', 'Subject', [ 'send' ], [ 'required' => true, 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('text', 'Text', [ 'send' ], [ 'type' => 'textarea', 'help' => 'Plain text; a text or an HTML body is required', 'ui' => [ 'rows' => 3, 'expression' => 'text' ] ]),
	    self::field('html', 'HTML', [ 'send' ], [ 'type' => 'textarea', 'ui' => [ 'rows' => 3, 'expression' => 'text', 'code' => true ] ]),
	    self::field('sourceFile', 'Attachment', [ 'send' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('notifyUrl', 'Delivery reports to', [ 'send' ], [ 'help' => 'An address Infobip posts them to, e.g. an Infobip message trigger\'s', 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	    self::field('messageId', 'Message ID', [ 'logs', 'reports' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('bulkId', 'Bulk ID', [ 'logs', 'reports' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$I = $this->infobip;
	if ($operation !== 'send') {
	    $to = $operation === 'logs' ? NodeAppMicrosoft::addresses($value('to')) : [];
	    $d = $I->request('GET', $operation === 'logs' ? '/email/1/logs' : '/email/1/reports', array_filter([ 'messageId' => $text('messageId'), 'bulkId' => $text('bulkId'),
		'to' => $to[0] ?? null, 'limit' => min(1000, $this->maxItems()) ]));
	    return $this->listResult(array_map(fn($r) => NodeAppInfobipSms::reportOf((array)$r) + array_filter([ 'subject' => $r['text'] ?? null ]), (array)($d['results'] ?? [])),
		$operation === 'logs' ? 'message' : 'report');
	}
	$from = $this->sender($text, 'from', 'emailFrom');
	if ($from === '')
	    throw new \RuntimeException('From: no sender (the step\'s or the credential\'s)');
	$to = NodeAppMicrosoft::addresses($value('to'));
	if (!$to)
	    throw new \RuntimeException('To: no address');
	$parts = [ [ 'from', $from ] ];
	foreach($to as $a)
	    $parts[] = [ 'to', $a ];
	foreach([ 'cc', 'bcc' ] as $k)
	    foreach(NodeAppMicrosoft::addresses($value($k)) as $a)
		$parts[] = [ $k, $a ];
	if (($s = $text('subject')) === '')
	    throw new \RuntimeException('Subject: empty');
	$parts[] = [ 'subject', $s ];
	$plain = \util\Expression::toText($value('text'));
	$html = \util\Expression::toText($value('html'));
	if (trim($plain) === '' && trim($html) === '')
	    throw new \RuntimeException('Text / HTML: give one');
	foreach([ 'text' => $plain, 'html' => $html, 'replyTo' => $text('replyTo'), 'notifyUrl' => $text('notifyUrl') ] as $k => $v)
	    if (trim((string)$v) !== '')
		$parts[] = [ $k, $v ];
	if (isset(array_column($parts, 1, 0)['notifyUrl']))
	    $parts[] = [ 'notifyContentType', 'application/json' ];
	$file = null;
	if (($fid = $text('sourceFile')) !== '') {
	    $file = \util\Validator::rule('oid', $fid) ? \V::app()->om->findOne('File', [ '_id' => $fid, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr($fid, 0, 60) . '`');
	    if ((int)$file->length > self::MAX_ATTACH_MB * 1024 * 1024)
		throw new \RuntimeException("`{$file->filename}` is larger than " . self::MAX_ATTACH_MB . ' MB');
	}
	$boundary = 'r22-' . bin2hex(random_bytes(12));
	$body = '';
	foreach($parts as [ $k, $v ])
	    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$v}\r\n";
	if ($file) {
	    $name = str_replace([ '"', "\r", "\n" ], '_', (string)$file->filename);
	    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $file->getContents() . "\r\n";
	}
	$body .= "--{$boundary}--\r\n";
	$r = self::sent($I->request('POST', '/email/3/send', [], null, [ $body, "multipart/form-data; boundary={$boundary}" ]));
	return [ $r, self::sentSummary($r, 'email') ];
    }
}
