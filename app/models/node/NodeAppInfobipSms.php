<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip SMS - text messages. Operations (setting `operation`):
	send      a text to one or more numbers (sms/2/text/advanced): from the sender (else the credential's), at a time (sendAt), valid for a while, its delivery reports sent to
	          an address (e.g. an Infobip message trigger's) with callback data
	preview   how a text is split (sms/1/preview): parts, characters left, the encoding
	logs      the messages sent in the last 48 hours (sms/1/logs): by bulk, message, number or status
	reports   the delivery reports not read yet (sms/1/reports): each is given once, then Infobip no longer has it (with a delivery address, none come here)
	inbox     the messages received not read yet (sms/1/inbox/reports): each given once (a number forwarding to an address: none come here)
    A send's result: { bulkId, messages: [ { to, messageId, status, statusGroup (PENDING, DELIVERED, UNDELIVERABLE, REJECTED, EXPIRED), description } ], count }
*/

namespace models\node;

class NodeAppInfobipSms extends NodeAppInfobip {
    public static $name = 'infobip-sms';
    public static $label = 'Infobip SMS';
    public static $icon = 'bi-chat-left-text';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'sms';

    const OPERATIONS = [
	'send' => 'Send an SMS',
	'preview' => 'Preview a text',
	'logs' => 'List sent messages',
	'reports' => 'Read delivery reports',
	'inbox' => 'Read received messages',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'logs', 'reports', 'inbox' ];
    const STATUSES = [ '' => 'Any', 'PENDING' => 'Pending', 'DELIVERED' => 'Delivered', 'UNDELIVERABLE' => 'Undeliverable', 'REJECTED' => 'Rejected', 'EXPIRED' => 'Expired' ];
    //Infobip's limits of a page
    const MAX_LOGS = 1000;
    const MAX_PULL = 1000;

    protected static function operationFields() {
	return [
	    self::field('to', 'To', [ 'send', 'logs' ], [ 'help' => 'Numbers in international format, separated by commas, or a list (logs: one)', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'send' ] ],
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('from', 'From', [ 'send' ], [ 'help' => 'A sender name or number; empty: the credential\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('text', 'Text', [ 'send', 'preview' ], [ 'type' => 'textarea', 'required' => true, 'help' => '160 characters a part (70 with characters outside GSM 7-bit, e.g. ă, ș)',
		'ui' => [ 'rows' => 3, 'expression' => 'text' ] ]),
	    self::field('sendAt', 'Send at', [ 'send' ], [ 'help' => 'A time (2026-12-01 09:00, in UTC unless it has a zone) or relative (+2 hours); empty: now', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('validity', 'Valid for (minutes)', [ 'send' ], [ 'type' => 'number', 'default' => '', 'help' => 'Empty: 48 hours', 'ui' => [ 'step' => 1, 'width' => 4 ] ]),
	    self::field('callbackData', 'Callback data', [ 'send' ], [ 'help' => 'Comes back with the delivery report, e.g. {{ order.id }}', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('notifyUrl', 'Delivery reports to', [ 'send' ], [ 'help' => 'An address Infobip posts them to, e.g. an Infobip message trigger\'s; empty: Read delivery reports',
		'ui' => [ 'expression' => 'text', 'advanced' => true ] ]),
	    self::field('bulkId', 'Bulk ID', [ 'logs', 'reports' ], [ 'help' => 'Of a send (its bulkId)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('messageId', 'Message ID', [ 'logs', 'reports' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'logs' ], [ 'type' => 'select', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //a time of a setting as Infobip takes it (Y-m-d\TH:i:s.000+0000); '' stays ''
    public static function time($v, $label = 'Send at') {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return '';
	$ts = strtotime($t, time());
	if ($ts === false)
	    throw new \RuntimeException("{$label}: not a time: `" . mb_substr($t, 0, 40) . '`');
	if (!preg_match('/[+-]\d{2}:?\d{2}$|Z$|UTC|GMT/i', $t) && !str_starts_with($t, '+') && !str_starts_with($t, '-'))
	    $ts = (new \DateTimeImmutable($t, new \DateTimeZone('UTC')))->getTimestamp();
	return gmdate('Y-m-d\TH:i:s.000+0000', $ts);
    }

    //a report or a received message, as Infobip gives it, flattened a little: { messageId, bulkId, to, from, text, status, statusGroup, error, sentAt, doneAt, receivedAt, price, callbackData }
    public static function reportOf(array $r) {
	return array_filter([ 'messageId' => $r['messageId'] ?? null, 'bulkId' => $r['bulkId'] ?? null, 'to' => $r['to'] ?? null, 'from' => $r['from'] ?? null,
	    'text' => $r['text'] ?? $r['cleanText'] ?? null, 'keyword' => $r['keyword'] ?? null, 'status' => $r['status']['name'] ?? null, 'statusGroup' => $r['status']['groupName'] ?? null,
	    'description' => $r['status']['description'] ?? null, 'error' => ($r['error']['groupName'] ?? 'OK') !== 'OK' ? ($r['error']['description'] ?? $r['error']['name'] ?? null) : null,
	    'sentAt' => $r['sentAt'] ?? null, 'doneAt' => $r['doneAt'] ?? null, 'receivedAt' => $r['receivedAt'] ?? null,
	    'price' => isset($r['price']['pricePerMessage']) ? [ 'amount' => $r['price']['pricePerMessage'], 'currency' => $r['price']['currency'] ?? null ] : null,
	    'parts' => $r['smsCount'] ?? null, 'callbackData' => $r['callbackData'] ?? null ], fn($v) => $v !== null);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$I = $this->infobip;
	switch($operation) {
	    case 'send':
		$to = \util\Infobip::numbers($value('to'));
		if (!$to)
		    throw new \RuntimeException('To: no number');
		$t = \util\Expression::toText($value('text'));
		if (trim($t) === '')
		    throw new \RuntimeException('Text: empty');
		$m = array_filter([ 'from' => $this->sender($text, 'from', 'smsSender'), 'destinations' => array_map(fn($n) => [ 'to' => $n ], $to), 'text' => $t,
		    'sendAt' => self::time($text('sendAt')), 'validityPeriod' => (int)$this->cfg('validity', 0) > 0 ? (int)$this->cfg('validity', 0) : null,
		    'callbackData' => $text('callbackData'), 'notifyUrl' => $text('notifyUrl') ], fn($v) => $v !== '' && $v !== null);
		if (isset($m['notifyUrl']))
		    $m['notifyContentType'] = 'application/json';
		$r = self::sent($I->request('POST', '/sms/2/text/advanced', [], [ 'messages' => [ $m ] ]));
		return [ $r, self::sentSummary($r, 'SMS', 'SMS') ];
	    case 'preview':
		$p = (array)($I->request('POST', '/sms/1/preview', [], [ 'text' => \util\Expression::toText($value('text')) ])['previews'][0] ?? []);
		$r = [ 'parts' => (int)($p['messageCount'] ?? 0), 'charactersRemaining' => (int)($p['charactersRemaining'] ?? 0), 'text' => $p['textPreview'] ?? null,
		    'configuration' => $p['configuration'] ?? null ];
		return [ $r, \util\Format::plural($r['parts'], 'part') . ", {$r['charactersRemaining']} characters left" ];
	    case 'logs':
		$to = \util\Infobip::numbers($value('to'));
		$d = $I->request('GET', '/sms/1/logs', array_filter([ 'bulkId' => $text('bulkId'), 'messageId' => $text('messageId'), 'to' => $to[0] ?? null,
		    'generalStatus' => isset(self::STATUSES[$s = (string)$this->cfg('status', '')]) ? $s : null, 'limit' => min(self::MAX_LOGS, $this->maxItems()) ]));
		return $this->listResult(array_map(fn($r) => self::reportOf((array)$r), (array)($d['results'] ?? [])), 'message');
	    case 'reports':
	    case 'inbox':
		$d = $I->request('GET', $operation === 'reports' ? '/sms/1/reports' : '/sms/1/inbox/reports', array_filter([ 'bulkId' => $operation === 'reports' ? $text('bulkId') : null,
		    'messageId' => $operation === 'reports' ? $text('messageId') : null, 'limit' => min(self::MAX_PULL, $this->maxItems()) ]));
		return $this->listResult(array_map(fn($r) => self::reportOf((array)$r), (array)($d['results'] ?? [])), $operation === 'reports' ? 'report' : 'message');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
