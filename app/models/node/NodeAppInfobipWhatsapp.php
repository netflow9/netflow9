<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip WhatsApp - WhatsApp messages from the account's WhatsApp sender. Operations (setting `operation`):
	text       a text (whatsapp/1/message/text): only within 24 hours of the person's last message to the sender (WhatsApp's customer service window)
	template   an approved template (whatsapp/1/message/template), with its placeholders: the only way to write first, or after the 24 hours
	media      an image, a document, a video or an audio file by its address (whatsapp/1/message/<type>), with a caption (image, video, document)
	templates  the sender's templates (whatsapp/2/senders/<sender>/templates): name, language, status (APPROVED, …), category, placeholders' count
    Each recipient is sent its own message (the numbers of To, one by one); the result: { bulkId, messages: [ { to, messageId, status, statusGroup, description } ], count }
*/

namespace models\node;

class NodeAppInfobipWhatsapp extends NodeAppInfobip {
    public static $name = 'infobip-whatsapp';
    public static $label = 'Infobip WhatsApp';
    public static $icon = 'bi-whatsapp';
    public static $defaultOperation = 'text';
    public static $defaultVariable = 'whatsapp';

    const OPERATIONS = [
	'text' => 'Send a text',
	'template' => 'Send a template',
	'media' => 'Send a file',
	'templates' => 'List templates',
    ];
    const WRITES = [ 'text', 'template', 'media' ];
    const LISTS = [ 'templates' ];
    const MEDIA = [ 'image' => 'Image', 'document' => 'Document', 'video' => 'Video', 'audio' => 'Audio' ];
    //at most this many recipients a step (one request each)
    const MAX_TO = 100;

    protected static function operationFields() {
	$send = [ 'text', 'template', 'media' ];
	return [
	    self::field('to', 'To', $send, [ 'required' => true, 'help' => 'Numbers in international format, separated by commas, or a list (at most ' . self::MAX_TO . ')',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('from', 'From', array_merge($send, [ 'templates' ]), [ 'help' => 'The WhatsApp sender\'s number; empty: the credential\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('text', 'Text', [ 'text' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Up to 4096 characters; *bold*, _italic_ as WhatsApp writes them',
		'ui' => [ 'rows' => 3, 'expression' => 'text' ] ]),
	    self::field('templateName', 'Template', [ 'template' ], [ 'required' => true, 'help' => 'Its name, e.g. order_shipped (List templates)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('language', 'Language', [ 'template' ], [ 'required' => true, 'default' => 'en', 'help' => 'The template\'s, e.g. en, en_GB, ro', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('placeholders', 'Placeholders', [ 'template' ], [ 'help' => 'The body\'s {{1}}, {{2}}, … in order: a list, or separated by new lines', 'type' => 'textarea',
		'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('mediaType', 'Kind', [ 'media' ], [ 'type' => 'select', 'required' => true, 'default' => 'document', 'choices' => self::MEDIA, 'ui' => [ 'width' => 4 ] ]),
	    self::field('mediaUrl', 'File address', [ 'media' ], [ 'required' => true, 'help' => 'A public https address of the file (Infobip downloads it)', 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('caption', 'Caption', [ 'media' ], [ 'help' => 'Image, video, document', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filename', 'File name', [ 'media' ], [ 'help' => 'A document\'s, as the person sees it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('callbackData', 'Callback data', $send, [ 'help' => 'Comes back with the delivery report', 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	    self::field('notifyUrl', 'Delivery reports to', $send, [ 'help' => 'An address Infobip posts them to, e.g. an Infobip message trigger\'s', 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	];
    }

    //the placeholders: a list, or separated by new lines
    public static function placeholders($value) {
	$parts = is_array($value) ? array_values($value) : preg_split('/\R/', \util\Expression::toText($value));
	$out = array_map(fn($p) => is_array($p) ? json_encode($p, JSON_UNESCAPED_UNICODE) : \util\Expression::toText($p), $parts);
	while ($out && trim(end($out)) === '')
	    array_pop($out);
	return $out;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$I = $this->infobip;
	$from = $this->sender($text, 'from', 'whatsappSender');
	if ($from === '')
	    throw new \RuntimeException('From: no WhatsApp sender (the step\'s or the credential\'s)');
	$from = \util\Infobip::number($from, 'From');
	if ($operation === 'templates') {
	    $all = (array)($I->request('GET', '/whatsapp/2/senders/' . rawurlencode($from) . '/templates')['templates'] ?? []);
	    return $this->listResult(array_map(function($t) {
		$body = '';
		foreach((array)($t['structure']['body'] ?? []) as $k => $v)
		    $body = is_string($v) ? $v : $body;
		return [ 'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'language' => $t['language'] ?? null, 'status' => $t['status'] ?? null, 'category' => $t['category'] ?? null,
		    'body' => $body !== '' ? $body : null, 'placeholders' => preg_match_all('/\{\{\d+\}\}/', $body) ];
	    }, $all), 'template');
	}
	$to = \util\Infobip::numbers($value('to'));
	if (!$to)
	    throw new \RuntimeException('To: no number');
	if (count($to) > self::MAX_TO)
	    throw new \RuntimeException('To: more than ' . self::MAX_TO . ' numbers');
	$more = array_filter([ 'callbackData' => $text('callbackData'), 'notifyUrl' => $text('notifyUrl') ], fn($v) => $v !== '');
	switch($operation) {
	    case 'text':
		$t = \util\Expression::toText($value('text'));
		if (trim($t) === '')
		    throw new \RuntimeException('Text: empty');
		$sent = [];
		foreach($to as $n)
		    $sent[] = $I->request('POST', '/whatsapp/1/message/text', [], [ 'from' => $from, 'to' => $n, 'content' => [ 'text' => $t ] ] + $more);
		$r = self::sent([ 'messages' => $sent ]);
		return [ $r, self::sentSummary($r, 'WhatsApp message') ];
	    case 'template':
		$name = $text('templateName');
		if ($name === '')
		    throw new \RuntimeException('Template: empty');
		$content = [ 'templateName' => $name, 'templateData' => [ 'body' => [ 'placeholders' => self::placeholders($value('placeholders')) ] ], 'language' => $text('language') ?: 'en' ];
		$r = self::sent($I->request('POST', '/whatsapp/1/message/template', [], [ 'messages' => array_map(fn($n) => [ 'from' => $from, 'to' => $n, 'content' => $content ] + $more, $to) ]));
		return [ $r, self::sentSummary($r, 'WhatsApp template') . " ({$name})" ];
	    case 'media':
		$type = (string)$this->cfg('mediaType', 'document');
		if (!isset(self::MEDIA[$type]))
		    throw new \RuntimeException('Kind: not one of the choices');
		$url = $text('mediaUrl');
		if (!preg_match('~^https://\S+$~i', $url))
		    throw new \RuntimeException('File address: an https address');
		$content = array_filter([ 'mediaUrl' => $url, 'caption' => $type !== 'audio' ? $text('caption') : '', 'filename' => $type === 'document' ? $text('filename') : '' ], fn($v) => $v !== '');
		$sent = [];
		foreach($to as $n)
		    $sent[] = $I->request('POST', "/whatsapp/1/message/{$type}", [], [ 'from' => $from, 'to' => $n, 'content' => $content ] + $more);
		$r = self::sent([ 'messages' => $sent ]);
		return [ $r, self::sentSummary($r, 'WhatsApp ' . strtolower(self::MEDIA[$type])) ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
