<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip 2FA - one-time PINs by SMS (or a voice call), for a 2FA application and its message template made in Infobip (2FA > Applications, or
    the API). Operations (setting `operation`):
	send          a PIN sent to a number (2fa/2/pin; voice: 2fa/2/pin/voice): { pinId, to, status } - keep pinId to verify
	verify        the PIN the person typed checked (2fa/2/pin/<pinId>/verify): { pinId, to, verified, attemptsRemaining, error }; a wrong PIN is not an error (verified false)
	resend        the same PIN's message sent again (2fa/2/pin/<pinId>/resend)
	applications  the 2FA applications: id, name, enabled, their settings (attempts, PIN time to live)
	messages      an application's message templates: id, the text ({{pin}}), PIN type and length, the sender
*/

namespace models\node;

class NodeAppInfobip2fa extends NodeAppInfobip {
    public static $name = 'infobip-2fa';
    public static $label = 'Infobip 2FA';
    public static $icon = 'bi-shield-lock';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'otp';

    const OPERATIONS = [
	'send' => 'Send a PIN',
	'verify' => 'Verify a PIN',
	'resend' => 'Send the PIN again',
	'applications' => 'List applications',
	'messages' => 'List message templates',
    ];
    const WRITES = [ 'send', 'verify', 'resend' ];
    const LISTS = [ 'applications', 'messages' ];
    const CHANNELS = [ 'sms' => 'SMS', 'voice' => 'Voice call' ];

    protected static function operationFields() {
	return [
	    self::field('applicationId', 'Application ID', [ 'send', 'messages' ], [ 'required' => true, 'help' => 'The 2FA application\'s (List applications)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('messageId', 'Message template ID', [ 'send' ], [ 'required' => true, 'help' => 'Its message template\'s (List message templates)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('to', 'To', [ 'send' ], [ 'required' => true, 'help' => 'A number in international format', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('channel', 'By', [ 'send' ], [ 'type' => 'select', 'default' => 'sms', 'choices' => self::CHANNELS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('from', 'From', [ 'send' ], [ 'help' => 'Empty: the template\'s sender', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('placeholders', 'Placeholders', [ 'send' ], [ 'type' => 'textarea', 'help' => 'The template\'s own placeholders as a JSON object, e.g. {"name": "Ana"}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('pinId', 'PIN ID', [ 'verify', 'resend' ], [ 'required' => true, 'help' => 'Send a PIN\'s pinId, or its result', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('pin', 'PIN', [ 'verify' ], [ 'required' => true, 'help' => 'What the person typed', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //a PIN's id: a text, or a result of Send a PIN ({ pinId })
    protected static function pinId($v) {
	if (is_array($v))
	    $v = $v['pinId'] ?? '';
	$t = trim(\util\Expression::toText($v));
	if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $t))
	    throw new \RuntimeException('PIN ID: not a PIN id: `' . mb_substr($t, 0, 60) . '`');
	return $t;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$I = $this->infobip;
	switch($operation) {
	    case 'send':
		$to = \util\Infobip::number($value('to'));
		$app = $text('applicationId');
		$msg = $text('messageId');
		if ($app === '' || $msg === '')
		    throw new \RuntimeException('Application ID / Message template ID: required');
		$body = array_filter([ 'applicationId' => $app, 'messageId' => $msg, 'from' => $text('from'), 'to' => $to ], fn($v) => $v !== '');
		if ($p = $this->jsonOf('placeholders', 'Placeholders'))
		    $body['placeholders'] = array_map('strval', $p);
		$voice = (string)$this->cfg('channel', 'sms') === 'voice';
		$d = $I->request('POST', $voice ? '/2fa/2/pin/voice' : '/2fa/2/pin', [], $body);
		$r = [ 'pinId' => $d['pinId'] ?? null, 'to' => $d['to'] ?? $to, 'status' => $d[$voice ? 'callStatus' : 'smsStatus'] ?? null, 'ncStatus' => $d['ncStatus'] ?? null ];
		if (empty($r['pinId']))
		    throw new \RuntimeException('Infobip gave no pinId' . (!empty($d['smsStatus']) ? " ({$d['smsStatus']})" : ''));
		return [ $r, "PIN sent to {$r['to']}" . ($r['status'] ? " ({$r['status']})" : '') ];
	    case 'verify':
		$id = self::pinId($value('pinId'));
		$d = $I->request('POST', '/2fa/2/pin/' . rawurlencode($id) . '/verify', [], [ 'pin' => $text('pin') ]);
		$r = [ 'pinId' => $d['pinId'] ?? $id, 'to' => $d['msisdn'] ?? null, 'verified' => (bool)($d['verified'] ?? false), 'attemptsRemaining' => $d['attemptsRemaining'] ?? null,
		    'error' => $d['pinError'] ?? null ];
		return [ $r, $r['verified'] ? 'PIN verified' : 'PIN not verified' . ($r['error'] ? " ({$r['error']})" : '') . ($r['attemptsRemaining'] !== null ? ", {$r['attemptsRemaining']} attempts left" : '') ];
	    case 'resend':
		$id = self::pinId($value('pinId'));
		$d = $I->request('POST', '/2fa/2/pin/' . rawurlencode($id) . '/resend', [], []);
		return [ [ 'pinId' => $d['pinId'] ?? $id, 'to' => $d['to'] ?? null, 'status' => $d['smsStatus'] ?? null ], 'PIN sent again' ];
	    case 'applications':
		$all = $I->request('GET', '/2fa/2/applications');
		return $this->listResult(array_map(fn($a) => [ 'id' => $a['applicationId'] ?? null, 'name' => $a['name'] ?? null, 'enabled' => (bool)($a['enabled'] ?? false),
		    'configuration' => $a['configuration'] ?? null ], array_is_list($all) ? $all : []), 'application');
	    case 'messages':
		$app = $text('applicationId');
		if ($app === '')
		    throw new \RuntimeException('Application ID: required');
		$all = $I->request('GET', '/2fa/2/applications/' . rawurlencode($app) . '/messages');
		return $this->listResult(array_map(fn($m) => [ 'id' => $m['messageId'] ?? null, 'text' => $m['messageText'] ?? null, 'pinType' => $m['pinType'] ?? null,
		    'pinLength' => $m['pinLength'] ?? null, 'from' => $m['senderId'] ?? null, 'language' => $m['language'] ?? null ], array_is_list($all) ? $all : []), 'message template');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
