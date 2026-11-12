<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: Yousign event - starts the workflow when Yousign posts a webhook of the events chosen (a signature request done, declined, expired; a signer
    done, ...) to the step's address /yousign/<token> (YousignController::receive). Yousign signs each one (X-Yousign-Signature-256: sha256=<HMAC-SHA256 of the body>) with the
    subscription's secret key: the step's Secret key (made for it, 32 characters).
    Connect (on, with a credential): publishing the workflow subscribes this address to the events chosen (POST /webhooks with the step's secret key; one with this address is
    updated), unpublishing deletes the subscriptions to it: onPublish / onUnpublish (WorkflowController::publish / unpublish). Off: a webhook made in Yousign's app
    (Developers > Webhooks), its secret key pasted in Secret key.
    The variable: { event, eventId, eventTime, sandbox, signatureRequest, signer, data, subscriptionId, received_at }; Yousign may post an event twice (eventId the same).
    Events not chosen are answered 200 and start nothing
*/

namespace models\node;

class NodeTriggerYousign extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'yousign';
    public static $label = 'Yousign event';
    public static $icon = 'bi-pen';
    public static $inputs = 0;
    public static $outputs = 1;

    const EVENTS = [
	'signature_request.done' => 'Signature request done (all signed)',
	'signature_request.declined' => 'Signature request declined',
	'signature_request.expired' => 'Signature request expired',
	'signature_request.canceled' => 'Signature request canceled',
	'signature_request.activated' => 'Signature request sent',
	'signature_request.reminder_executed' => 'Reminder sent',
	'signature_request.deleted' => 'Signature request deleted',
	'signer.done' => 'A signer signed',
	'signer.declined' => 'A signer declined',
	'signer.notified' => 'A signer was notified',
	'signer.link_opened' => 'A signer opened the link',
	'signer.error' => 'A signer\'s signature failed',
	'signer.notification_delivery_failed' => 'A signer\'s notification not delivered',
	'signer.identification_failed' => 'A signer\'s identification failed',
	'approver.approved' => 'An approver approved',
	'approver.rejected' => 'An approver rejected',
	'contact.created' => 'Contact created',
    ];
    const SECRET_LENGTH = 32;

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/yousign/' . $token), 'secretKey' => self::newSecret() ];
    }

    //32 letters and digits (Yousign's secret keys are 32 characters)
    public static function newSecret() {
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$s = '';
	for ($i = 0; $i < self::SECRET_LENGTH; $i++)
	    $s .= $chars[random_int(0, strlen($chars) - 1)];
	return $s;
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'yousign')))) {
	    $this->errors('config', 'Yousign event: choose a Yousign credential of this project');
	    $valid = false;
	}
	$secret = trim((string)$this->cfg('secretKey', ''));
	if ($secret === '') {
	    $this->errors('config', 'Yousign event: the secret key (it checks the webhooks\' signature)');
	    $valid = false;
	} elseif ($cred !== '' && $this->cfg('connect', true) && strlen($secret) !== self::SECRET_LENGTH) {
	    $this->errors('config', 'Yousign event: Connect needs a secret key of ' . self::SECRET_LENGTH . ' characters');
	    $valid = false;
	}
	if (!self::chosenEvents([ 'events' => $this->cfg('events', []) ])) {
	    $this->errors('config', 'Yousign event: choose at least one event');
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
		'help' => 'Where Yousign posts the events; subscribed for you when the workflow is published (Connect)',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Yousign credential',
		'choices' => NodeAppYousign::credentialChoices(),
		'help' => 'For Connect (the subscription made and removed for you)',
		'ui' => [ 'width' => 8, 'newRow' => true, 'placeholder' => 'None (a webhook made in Yousign\'s app)' ],
	    ],
	    [
		'key' => 'connect',
		'type' => 'switch',
		'label' => 'Connect',
		'default' => true,
		'help' => 'Subscribe this address to the events when published, unsubscribe when unpublished',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'label' => 'Events',
		'multiple' => true,
		'required' => true,
		'default' => [ 'signature_request.done' ],
		'choices' => self::EVENTS,
		'help' => 'The events that start the workflow',
		'ui' => [ 'size' => 8 ],
	    ],
	    [
		'key' => 'secretKey',
		'type' => 'text',
		'label' => 'Secret key',
		'default' => '',
		'help' => 'Checks the webhooks\' signature: made for this step (Connect gives it to Yousign); a webhook made in Yousign\'s app: its "Copy secret key"',
		'ui' => [ 'type' => 'password', 'advanced' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'yousign',
		'help' => 'e.g. {{ yousign.event }}, {{ yousign.signatureRequest.id }}, {{ yousign.signatureRequest.external_id }}, {{ yousign.signer.info.email }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'yousign') : 'yousign';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the event: { event, eventId, eventTime, sandbox, signatureRequest, signer, data, subscriptionId, received_at }', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => 'e.g. signature_request.done' ],
	    'eventId' => [ 'type' => 'string', 'info' => 'the same when Yousign posts an event again' ],
	    'eventTime' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    'sandbox' => [ 'type' => 'boolean' ],
	    'signatureRequest' => [ 'type' => 'object', 'info' => 'as Yousign sends it: id, name, status, external_id, signers, documents, ...' ],
	    'signer' => [ 'type' => 'object', 'info' => 'a signer\'s event: id, status, info { first_name, last_name, email }, signed_at; else null' ],
	    'data' => [ 'type' => 'object', 'info' => 'all the event\'s data' ],
	    'subscriptionId' => [ 'type' => 'string' ],
	    'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (added by Rail22)' ],
	] ] ] : [];
    }

    //the events chosen (known ones)
    public static function chosenEvents(array $config) {
	return array_values(array_intersect(array_map('strval', (array)($config['events'] ?? [])), array_keys(self::EVENTS)));
    }

    //the step's credential of $wf, or null
    public static function credentialOf($wf, array $node) {
	return \models\Credential::findFor($wf, (string)($node['config']['credential'] ?? ''), 'yousign');
    }

    //an event's value of the variable
    public static function valueOf(array $d, $received) {
	$t = $d['event_time'] ?? null;
	return [ 'event' => (string)($d['event_name'] ?? ''), 'eventId' => (string)($d['event_id'] ?? ''),
	    'eventTime' => is_numeric($t) ? gmdate('Y-m-d\TH:i:s\Z', (int)$t) : (is_string($t) ? $t : null), 'sandbox' => (bool)($d['sandbox'] ?? false),
	    'signatureRequest' => $d['data']['signature_request'] ?? null, 'signer' => $d['data']['signer'] ?? null, 'data' => (array)($d['data'] ?? []),
	    'subscriptionId' => $d['subscription_id'] ?? null, 'received_at' => $received ];
    }

    // ------------------------------------------------------------------------------------
    //publishing (WorkflowController::publish / unpublish): the organization's webhook subscriptions

    //the subscriptions to $url
    protected static function subscriptions($y, $url) {
	$d = $y->request('GET', '/webhooks');
	return array_values(array_filter(array_is_list($d) ? $d : (array)($d['data'] ?? []), fn($w) => is_array($w) && (string)($w['endpoint'] ?? '') === $url));
    }

    public static function onPublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']) || (string)($cfg['credential'] ?? '') === '')
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return 'Yousign event: no credential, the webhook was not subscribed';
	$url = (string)($cfg['url'] ?? '');
	$events = self::chosenEvents($cfg);
	$body = [ 'endpoint' => $url, 'description' => mb_substr('Rail22: ' . preg_replace('/[^\p{L}\p{N} .,_\-]+/u', ' ', (string)$wf->name), 0, 128), 'sandbox' => (bool)$cred->sandbox,
	    'enabled' => true, 'auto_retry' => true, 'scopes' => [ '*' ], 'subscribed_events' => $events, 'secret_key' => trim((string)($cfg['secretKey'] ?? '')) ];
	try {
	    $y = $cred->client(60);
	    $have = self::subscriptions($y, $url);
	    if ($have) {
		$y->request('PATCH', '/webhooks/' . rawurlencode((string)$have[0]['id']), [], array_diff_key($body, [ 'sandbox' => true, 'secret_key' => true ]));
		foreach(array_slice($have, 1) as $w)
		    $y->request('DELETE', '/webhooks/' . rawurlencode((string)$w['id']));
	    } else
		$y->request('POST', '/webhooks', [], $body + [ 'workspaces' => [ '*' ] ]);
	} catch (\RuntimeException $e) {
	    return 'Yousign event: the webhook was not subscribed: ' . $e->getMessage();
	}
	return 'Yousign sends ' . \util\Format::plural(count($events), 'event') . ' to this workflow' . ($have ? '' : ' (subscribed now)');
    }

    public static function onUnpublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']) || (string)($cfg['credential'] ?? '') === '')
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return null;
	$gone = 0;
	try {
	    $y = $cred->client(60);
	    foreach(self::subscriptions($y, (string)($cfg['url'] ?? '')) as $w) {
		$y->request('DELETE', '/webhooks/' . rawurlencode((string)$w['id']));
		$gone++;
	    }
	} catch (\RuntimeException $e) {
	    return 'Yousign event: the webhook was not deleted: ' . $e->getMessage();
	}
	return $gone ? 'Yousign no longer sends its events here (' . \util\Format::plural($gone, 'subscription') . ' deleted)' : null;
    }
}
