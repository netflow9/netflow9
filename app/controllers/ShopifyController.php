<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: the public endpoint of the Shopify event triggers (/shopify/<token>, NodeTriggerShopify): Shopify POSTs a webhook (JSON), signed in
    X-Shopify-Hmac-Sha256 with the app's client secret or the store's signing key (NodeTriggerShopify::secretOf). With a credential, the store must be the credential's
    (X-Shopify-Shop-Domain: an app installed on several stores signs them all with one secret). One run of the published version per webhook of a topic chosen; other topics
    are answered 200 and start nothing (Shopify retries any other answer, and removes subscriptions that keep failing). Refusals (unknown token, signature, not published,
    errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerShopify;

class ShopifyController extends \sys\APIController {

    const REFUSED = 'shopify.refused';

    //called by Shopify: no CSRF token, the URL's token and the signature are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::shopify', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	//the body as sent (sys\http\Request): the signature is of these bytes; too large without a Content-Length (chunked): 413
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	if (!\util\Shopify::verifyHmac($raw, (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? ''), NodeTriggerShopify::secretOf($wf, $node)))
	    $this->refuse(401, 'Unauthorized (the signature does not match the client secret or signing key)');
	$shop = strtolower(trim((string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '')));
	$cred = (string)($cfg['credential'] ?? '') !== '' ? NodeTriggerShopify::credentialOf($wf, $node) : null;
	if ($cred && $shop !== strtolower((string)$cred->shop))
	    $this->refuse(403, "Another store ({$shop}) than the credential's");
	$this->requireRunnable($wf, $live);

	$topic = strtolower(trim((string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '')));
	if (!in_array($topic, NodeTriggerShopify::chosenTopics($cfg), true)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'topic' => $topic ]);
	    return;
	}
	$data = json_decode($raw, true);
	$received = new \MongoDB\BSON\UTCDateTime();
	$h = fn($k) => mb_substr(trim((string)($_SERVER[$k] ?? '')), 0, 200);
	$value = [ 'topic' => $topic, 'shop' => $shop, 'data' => is_array($data) ? self::clean($data) : [ 'raw' => mb_substr($raw, 0, 100000) ], 'webhook_id' => $h('HTTP_X_SHOPIFY_WEBHOOK_ID'),
	    'event_id' => $h('HTTP_X_SHOPIFY_EVENT_ID'), 'api_version' => $h('HTTP_X_SHOPIFY_API_VERSION'), 'triggered_at' => $h('HTTP_X_SHOPIFY_TRIGGERED_AT'),
	    'received_at' => $received->toDateTime()->format(DATE_ATOM) ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'shopify';
	$exe = \models\wf\WfExecution::startFor($live, 'shopify', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	$exe->log($entry->node, "Shopify {$topic} received" . (isset($data['name']) && is_scalar($data['name']) ? " ({$data['name']})" : (isset($data['id']) && is_scalar($data['id']) ? " ({$data['id']})" : '')));
	$exe->queue();
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ]);
    }
}
