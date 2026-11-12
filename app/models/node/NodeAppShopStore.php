<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify Store - the store itself and its webhooks. Operations (setting `operation`):
	shop         the store's details (shop): name, domains, currency, time zone, plan
	webhooks     the app's webhook subscriptions (webhookSubscriptions): topic, address, format
	subscribe    a webhook subscription to an address (webhookSubscriptionCreate, JSON); the Shopify event trigger makes its own when published
	unsubscribe  a subscription deleted (webhookSubscriptionDelete)
*/

namespace models\node;

class NodeAppShopStore extends NodeAppShopify {
    public static $name = 'shopify-store';
    public static $label = 'Shopify Store';
    public static $icon = 'bi-shop';
    public static $defaultOperation = 'shop';
    public static $defaultVariable = 'store';

    const OPERATIONS = [
	'shop' => 'Get the store',
	'webhooks' => 'List webhooks',
	'subscribe' => 'Subscribe a webhook',
	'unsubscribe' => 'Delete a webhook',
    ];
    const WRITES = [ 'subscribe', 'unsubscribe' ];
    const LISTS = [ 'webhooks' ];
    const WEBHOOK = 'id topic format createdAt endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } }';

    protected static function operationFields() {
	return [
	    self::field('topic', 'Topic', [ 'subscribe' ], [ 'type' => 'select', 'required' => true, 'default' => 'orders/create', 'choices' => NodeTriggerShopify::TOPICS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('address', 'Address', [ 'subscribe' ], [ 'required' => true, 'help' => 'https://…, e.g. a Webhook trigger\'s URL', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('webhook', 'Webhook', [ 'unsubscribe' ], [ 'required' => true, 'help' => 'Its ID, or a webhook of the list', 'ui' => [ 'expression' => true ] ]),
	];
    }

    //a subscription with its address at the top ({ id, topic, format, createdAt, callbackUrl })
    public static function webhookOf(array $w) {
	$url = $w['endpoint']['callbackUrl'] ?? null;
	unset($w['endpoint']);
	return $w + [ 'callbackUrl' => $url ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->shop;
	switch($operation) {
	    case 'shop':
		$d = $S->query('{ shop { id name email contactEmail myshopifyDomain primaryDomain { url host } currencyCode enabledPresentmentCurrencies ianaTimezone weightUnit'
		    . ' plan { displayName partnerDevelopment shopifyPlus } billingAddress { country countryCodeV2 city } } }');
		$shop = \util\Shopify::simplify($d['shop'] ?? []);
		return [ $shop, ($shop['name'] ?? '?') . ' (' . ($shop['myshopifyDomain'] ?? '') . ')' ];
	    case 'webhooks':
		$max = $this->maxItems();
		$all = self::webhooks($S, $max + 1);
		$items = array_slice($all, 0, $max);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => count($all) > $max ], \util\Format::plural(count($items), 'webhook') ];
	    case 'subscribe':
		$topic = (string)$this->cfg('topic', '');
		if (!isset(NodeTriggerShopify::TOPICS[$topic]))
		    throw new \RuntimeException("Topic: not one Shopify has: `{$topic}`");
		$url = $text('address');
		if (!preg_match('~^https://[^\s/?#]+(/\S*)?$~', $url))
		    throw new \RuntimeException('Address: an https:// address');
		$w = self::subscribe($S, $topic, $url);
		return [ $w, "{$topic} → {$url}" ];
	    case 'unsubscribe':
		$id = \util\Shopify::gid('WebhookSubscription', $value('webhook'), 'Webhook');
		$p = $S->mutate('mutation($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }', [ 'id' => $id ], 'webhookSubscriptionDelete');
		return [ [ 'id' => $p['deletedWebhookSubscriptionId'] ?? $id, 'deleted' => true ], "{$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //a subscription of $topic (orders/create) to $url: the subscription ({ id, topic, callbackUrl, ... })
    public static function subscribe(\util\Shopify $S, $topic, $url) {
	$p = $S->mutate('mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {'
	    . ' webhookSubscription { ' . self::WEBHOOK . ' } userErrors { field message } } }', [ 'topic' => \util\Shopify::topicEnum($topic), 'sub' => [ 'callbackUrl' => (string)$url, 'format' => 'JSON' ] ],
	    'webhookSubscriptionCreate');
	return self::webhookOf((array)$p['webhookSubscription']);
    }

    //the app's subscriptions (at most $max; webhookSubscriptions has no search)
    public static function webhooks(\util\Shopify $S, $max = 1000) {
	$S->pageSize = 250;
	[ $items ] = $S->connection('query($first: Int!, $after: String) { webhookSubscriptions(first: $first, after: $after) { nodes { ' . self::WEBHOOK . ' } pageInfo { hasNextPage endCursor } } }',
	    [], 'webhookSubscriptions', $max);
	return array_map([ self::class, 'webhookOf' ], $items);
    }
}
