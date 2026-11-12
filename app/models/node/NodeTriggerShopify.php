<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify event - starts the workflow when Shopify sends a webhook of the topics chosen (an order created or paid, a product updated, stock
    changed, ...) to the step's address /shopify/<token> (ShopifyController::receive). Shopify signs each one (X-Shopify-Hmac-Sha256): with the app's client secret (the
    credential's) for webhooks the app subscribed, with the store's signing key for those made in Settings > Notifications > Webhooks (then given in Signing key).
    Connect (on, with a credential): publishing the workflow subscribes this address to each topic chosen (webhookSubscriptionCreate; one that exists is kept), unpublishing
    deletes the subscriptions to it: onPublish / onUnpublish (WorkflowController::publish / unpublish).
    The variable: { topic, shop, data (the payload as Shopify sends it: its REST shape, e.g. data.line_items), webhook_id, event_id, api_version, triggered_at, received_at }.
    Shopify may deliver an event twice (event_id is the same): a workflow that must not run twice checks it. Topics not chosen are answered 200 and start nothing
*/

namespace models\node;

class NodeTriggerShopify extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'shopify';
    public static $label = 'Shopify event';
    public static $icon = 'bi-bag';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    //Shopify's webhook topics (X-Shopify-Topic; the API's enum: ORDERS_CREATE, ...)
    const TOPICS = [
	'orders/create' => 'Order created',
	'orders/paid' => 'Order paid',
	'orders/updated' => 'Order updated',
	'orders/fulfilled' => 'Order fulfilled',
	'orders/partially_fulfilled' => 'Order partially fulfilled',
	'orders/cancelled' => 'Order cancelled',
	'orders/edited' => 'Order edited',
	'orders/delete' => 'Order deleted',
	'refunds/create' => 'Refund created',
	'fulfillments/create' => 'Fulfillment created',
	'fulfillments/update' => 'Fulfillment updated',
	'draft_orders/create' => 'Draft order created',
	'checkouts/create' => 'Checkout created',
	'products/create' => 'Product created',
	'products/update' => 'Product updated',
	'products/delete' => 'Product deleted',
	'inventory_levels/update' => 'Stock level changed',
	'customers/create' => 'Customer created',
	'customers/update' => 'Customer updated',
	'customers/delete' => 'Customer deleted',
	'app/uninstalled' => 'App uninstalled',
    ];

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/shopify/' . $token) ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'shopify')))) {
	    $this->errors('config', 'Shopify event: choose a Shopify credential of this project');
	    $valid = false;
	}
	if ($cred === '' && trim((string)$this->cfg('signingKey', '')) === '') {
	    $this->errors('config', 'Shopify event: a credential (its client secret checks the webhooks) or the signing key');
	    $valid = false;
	}
	if (!self::chosenTopics([ 'topics' => $this->cfg('topics', []) ])) {
	    $this->errors('config', 'Shopify event: choose at least one topic');
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
		'help' => 'Where Shopify sends the webhooks; subscribed for you when the workflow is published (Connect)',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Shopify credential',
		'choices' => NodeAppShopify::credentialChoices(),
		'help' => 'The store; its client secret checks the webhooks\' signature',
		'ui' => [ 'width' => 8, 'newRow' => true, 'placeholder' => 'None (webhooks made in Shopify\'s admin)' ],
	    ],
	    [
		'key' => 'connect',
		'type' => 'switch',
		'label' => 'Connect',
		'default' => true,
		'help' => 'Subscribe this address to the topics when published, unsubscribe when unpublished',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'topics',
		'type' => 'select',
		'label' => 'Topics',
		'multiple' => true,
		'required' => true,
		'default' => [ 'orders/create' ],
		'choices' => self::TOPICS,
		'help' => 'The events that start the workflow (the app needs the matching read scopes, e.g. read_orders)',
		'ui' => [ 'size' => 8 ],
	    ],
	    [
		'key' => 'signingKey',
		'type' => 'text',
		'label' => 'Signing key',
		'default' => '',
		'help' => 'Only for webhooks made in Shopify\'s admin (Settings > Notifications > Webhooks: "Your webhooks will be signed with …"); empty: the credential\'s client secret',
		'ui' => [ 'type' => 'password', 'advanced' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'shopify',
		'help' => 'e.g. {{ shopify.topic }}, {{ shopify.data.name }}, {{ shopify.data.line_items }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'shopify') : 'shopify';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the webhook: { topic, shop, data, webhook_id, event_id, api_version, triggered_at, received_at }', 'fields' => [
	    'topic' => [ 'type' => 'string', 'info' => 'e.g. orders/create' ],
	    'shop' => [ 'type' => 'string', 'info' => '<shop>.myshopify.com' ],
	    'data' => [ 'type' => 'object', 'info' => 'the payload as Shopify sends it (an order: id, name, email, total_price, line_items, customer, ...)' ],
	    'webhook_id' => [ 'type' => 'string' ],
	    'event_id' => [ 'type' => 'string', 'info' => 'the same when Shopify delivers an event again' ],
	    'api_version' => [ 'type' => 'string' ],
	    'triggered_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (Shopify\'s)' ],
	    'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (added by Rail22)' ],
	] ] ] : [];
    }

    //the topics chosen (known ones)
    public static function chosenTopics(array $config) {
	return array_values(array_intersect(array_map('strval', (array)($config['topics'] ?? [])), array_keys(self::TOPICS)));
    }

    //the step's credential of $wf, or null
    public static function credentialOf($wf, array $node) {
	return \models\Credential::findFor($wf, (string)($node['config']['credential'] ?? ''), 'shopify');
    }

    //what checks the webhooks' signature: the signing key, else the credential's client secret ('' when neither)
    public static function secretOf($wf, array $node) {
	$key = trim((string)($node['config']['signingKey'] ?? ''));
	if ($key !== '')
	    return $key;
	$cred = self::credentialOf($wf, $node);
	return $cred ? (string)$cred->clientSecret : '';
    }

    // ------------------------------------------------------------------------------------
    //publishing (WorkflowController::publish / unpublish): the store's webhook subscriptions

    public static function onPublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']) || (string)($cfg['credential'] ?? '') === '')
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return 'Shopify event: no credential, the webhooks were not subscribed';
	$url = (string)($cfg['url'] ?? '');
	$topics = self::chosenTopics($cfg);
	try {
	    $s = $cred->client($wf->aid, 60);
	    $have = [];
	    foreach(NodeAppShopStore::webhooks($s) as $w)
		if ((string)$w['callbackUrl'] === $url)
		    $have[\util\Shopify::topicEnum($w['topic'])] = $w;
	    $made = 0;
	    foreach($topics as $t) {
		if (isset($have[\util\Shopify::topicEnum($t)]))
		    continue;
		NodeAppShopStore::subscribe($s, $t, $url);
		$made++;
	    }
	    //topics no longer chosen
	    foreach($have as $enum => $w)
		if (!in_array($enum, array_map([ \util\Shopify::class, 'topicEnum' ], $topics), true))
		    $s->mutate('mutation($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }', [ 'id' => $w['id'] ], 'webhookSubscriptionDelete');
	} catch (\RuntimeException $e) {
	    return 'Shopify event: the webhooks were not subscribed: ' . $e->getMessage();
	}
	return 'Shopify sends ' . \util\Format::plural(count($topics), 'topic') . ' to this workflow' . ($made ? " ({$made} subscribed now)" : '');
    }

    public static function onUnpublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']) || (string)($cfg['credential'] ?? '') === '')
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return null;
	$url = (string)($cfg['url'] ?? '');
	$gone = 0;
	try {
	    $s = $cred->client($wf->aid, 60);
	    foreach(NodeAppShopStore::webhooks($s) as $w) {
		if ((string)$w['callbackUrl'] !== $url)
		    continue;
		$s->mutate('mutation($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }', [ 'id' => $w['id'] ], 'webhookSubscriptionDelete');
		$gone++;
	    }
	} catch (\RuntimeException $e) {
	    return 'Shopify event: the webhooks were not deleted: ' . $e->getMessage();
	}
	return $gone ? 'Shopify no longer sends its webhooks here (' . \util\Format::plural($gone, 'subscription') . ' deleted)' : null;
    }
}
