<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip message - starts the workflow when Infobip posts to the step's address /infobip/<token> (InfobipController::receive): messages received
    (SMS on a number whose forwarding is this address, WhatsApp and other channels on a sender whose inbound webhook is it) and delivery reports (of messages sent with
    Delivery reports to = this address). Infobip does not sign its posts: the address's token is the secret (keep it private; a new step gets a new one).
    A post holds { results: [ … ] }: one run per result of the kinds chosen (or one per post with them all); the others are answered 200 and start nothing.
    The variable: { kind (message / report), channel (SMS, WHATSAPP, VIBER, …), from, to, text, type (TEXT, IMAGE, DOCUMENT, LOCATION, BUTTON, …), mediaUrl, caption, contactName,
    keyword, messageId, bulkId, receivedAt, status, statusGroup, description, error, sentAt, doneAt, callbackData, price, data (the result as Infobip sent it), received_at }
*/

namespace models\node;

class NodeTriggerInfobip extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'infobip';
    public static $label = 'Infobip message';
    public static $icon = 'bi-chat-dots';
    public static $inputs = 0;
    public static $outputs = 1;

    const KINDS = [ 'sms' => 'SMS received', 'whatsapp' => 'WhatsApp message received', 'other' => 'Other channels\' messages received (Viber, RCS, …)', 'reports' => 'Delivery reports' ];
    const RUNS = [ 'each' => 'One run per message or report', 'batch' => 'One run per post' ];
    //results of one post started at most
    const MAX_RESULTS = 1000;

    //its address's token is indexed (models\TriggerToken), with no data of its own
    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/infobip/' . $token) ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (!self::chosenKinds([ 'kinds' => $this->cfg('kinds', []) ])) {
	    $this->errors('config', 'Infobip message: choose at least one kind');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		'help' => 'In Infobip: a number\'s forwarding (Forward to HTTP), a sender\'s inbound webhook, or a send step\'s Delivery reports to. Keep it private: it is the secret',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'kinds',
		'type' => 'select',
		'label' => 'Start on',
		'multiple' => true,
		'required' => true,
		'default' => [ 'sms', 'whatsapp' ],
		'choices' => self::KINDS,
		'ui' => [ 'size' => 4 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Message variable',
		'required' => true,
		'default' => 'message',
		'help' => 'e.g. {{ message.from }}, {{ message.text }}, {{ message.channel }}, {{ message.statusGroup }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'message') : 'message';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$one = [ 'kind' => [ 'type' => 'string', 'info' => 'message or report' ], 'channel' => [ 'type' => 'string', 'info' => 'SMS, WHATSAPP, VIBER, …' ], 'from' => [ 'type' => 'string' ],
	    'to' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'info' => 'TEXT, IMAGE, DOCUMENT, LOCATION, BUTTON, …' ],
	    'mediaUrl' => [ 'type' => 'string' ], 'contactName' => [ 'type' => 'string' ], 'messageId' => [ 'type' => 'string' ], 'receivedAt' => [ 'type' => 'string' ],
	    'statusGroup' => [ 'type' => 'string', 'info' => 'a report\'s: DELIVERED, UNDELIVERABLE, …' ], 'error' => [ 'type' => 'string' ], 'callbackData' => [ 'type' => 'string' ],
	    'data' => [ 'type' => 'object', 'info' => 'as Infobip sent it' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count }', 'fields' => [ 'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $one ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the message or report', 'fields' => $one ] ];
    }

    //the kinds chosen (known ones)
    public static function chosenKinds(array $config) {
	return array_values(array_intersect(array_map('strval', (array)($config['kinds'] ?? [])), array_keys(self::KINDS)));
    }

    //a result of a post, as the variable gives it; its kind of KINDS in `_kind`
    public static function itemOf(array $r) {
	$report = isset($r['status']) && is_array($r['status']) && !isset($r['receivedAt']) && !isset($r['message']);
	$channel = strtoupper((string)($r['integrationType'] ?? $r['channel'] ?? ($report && isset($r['smsCount']) ? 'SMS' : ($report ? '' : 'SMS'))));
	$m = (array)($r['message'] ?? []);
	$err = (array)($r['error'] ?? []);
	$item = array_filter([
	    'kind' => $report ? 'report' : 'message',
	    'channel' => $channel !== '' ? $channel : null,
	    'from' => $r['from'] ?? null,
	    'to' => $r['to'] ?? null,
	    'text' => $m['text'] ?? $r['text'] ?? $r['cleanText'] ?? null,
	    'type' => $m['type'] ?? ($report ? null : 'TEXT'),
	    'mediaUrl' => $m['url'] ?? null,
	    'caption' => $m['caption'] ?? null,
	    'contactName' => $r['contact']['name'] ?? null,
	    'keyword' => $r['keyword'] ?? null,
	    'messageId' => $r['messageId'] ?? null,
	    'bulkId' => $r['bulkId'] ?? null,
	    'receivedAt' => $r['receivedAt'] ?? null,
	    'status' => $r['status']['name'] ?? null,
	    'statusGroup' => $r['status']['groupName'] ?? null,
	    'description' => $r['status']['description'] ?? null,
	    'error' => ($err['groupName'] ?? 'OK') !== 'OK' ? ($err['description'] ?? $err['name'] ?? null) : null,
	    'sentAt' => $r['sentAt'] ?? null,
	    'doneAt' => $r['doneAt'] ?? null,
	    'callbackData' => $r['callbackData'] ?? null,
	    'price' => isset($r['price']['pricePerMessage']) ? [ 'amount' => $r['price']['pricePerMessage'], 'currency' => $r['price']['currency'] ?? null ] : null,
	], fn($v) => $v !== null);
	$item['_kind'] = $report ? 'reports' : ([ 'SMS' => 'sms', 'WHATSAPP' => 'whatsapp' ][$channel] ?? 'other');
	return $item;
    }
}
