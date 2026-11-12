<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify Inventory - stock by location. Operations (setting `operation`):
	levels      a variant's quantities at each location (available, on hand, committed, incoming, reserved) and its total available
	adjust      the available quantity at a location changed by a delta (inventoryAdjustQuantities)
	set         the available quantity at a location set (inventorySetQuantities)
	locations   the store's locations (locations)
    The variant by its ID (or a variant of a list) or its SKU; the location by its ID or its name (empty: the store's only active location). A change gives { sku, location,
    before, after, delta }. From API version CAS_VERSION Shopify takes the quantity a change starts from (changeFromQuantity) and an idempotency key: the quantity just read is
    sent, so a change made in between is refused rather than overwritten; earlier versions get compareQuantity-less changes (ignoreCompareQuantity)
*/

namespace models\node;

class NodeAppShopInventory extends NodeAppShopify {
    public static $name = 'shopify-inventory';
    public static $label = 'Shopify Inventory';
    public static $icon = 'bi-boxes';
    public static $defaultOperation = 'levels';
    public static $defaultVariable = 'stock';

    const OPERATIONS = [
	'levels' => 'Get stock levels',
	'adjust' => 'Adjust stock',
	'set' => 'Set stock',
	'locations' => 'List locations',
    ];
    const WRITES = [ 'adjust', 'set' ];
    const LISTS = [ 'locations' ];
    const ONE = [ 'levels', 'adjust', 'set' ];
    const CHANGES = [ 'adjust', 'set' ];
    const FINDS = [ 'sku' => 'Its SKU', 'id' => 'Its ID' ];
    //Shopify's reasons of a change (its documented values)
    const REASONS = [ 'correction' => 'Correction', 'received' => 'Received', 'cycle_count_available' => 'Cycle count', 'restock' => 'Restock', 'damaged' => 'Damaged',
	'shrinkage' => 'Shrinkage', 'promotion' => 'Promotion', 'quality_control' => 'Quality control', 'safety_stock' => 'Safety stock', 'movement_created' => 'Movement', 'other' => 'Other' ];
    const QUANTITIES = [ 'available', 'on_hand', 'committed', 'incoming', 'reserved' ];
    //the first API version whose inventory changes take changeFromQuantity and @idempotent
    const CAS_VERSION = '2026-04';

    protected static function operationFields() {
	return [
	    self::field('find', 'Variant by', self::ONE, [ 'type' => 'select', 'default' => 'sku', 'choices' => self::FINDS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('variant', 'Variant', self::ONE, [ 'required' => true, 'help' => 'Its SKU, or its ID (a variant of a list works too)', 'ui' => [ 'expression' => true, 'width' => 9 ] ]),
	    self::field('location', 'Location', self::CHANGES, [ 'help' => 'Its name or ID; empty: the store\'s only active location', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('delta', 'Change by', [ 'adjust' ], [ 'required' => true, 'help' => 'e.g. 5 or -2', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('quantity', 'Quantity', [ 'set' ], [ 'required' => true, 'help' => 'Available', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('reason', 'Reason', self::CHANGES, [ 'type' => 'select', 'required' => true, 'default' => 'correction', 'choices' => self::REASONS, 'ui' => [ 'width' => 3 ] ]),
	    self::searchField([ 'locations' ], 'active:true'),
	];
    }

    //the variant of the setting `variant` with its inventory item's levels: { id, sku, inventoryItem: { id, tracked, inventoryLevels: [ { location, quantities } ] } }
    protected function variantLevels(\Closure $value) {
	$fields = 'id sku title displayName product { id title } inventoryItem { id tracked inventoryLevels(first: 50) { nodes { location { id name isActive }'
	    . ' quantities(names: ' . json_encode(self::QUANTITIES) . ') { name quantity } } } }';
	if ((string)$this->cfg('find', 'sku') === 'sku') {
	    $sku = trim(\util\Expression::toText($value('variant')));
	    if ($sku === '')
		throw new \RuntimeException('Variant: empty');
	    $v = $this->findOne('productVariants', 'sku:' . \util\Shopify::quoted($sku), $fields);
	    if (!$v || (string)$v['sku'] !== $sku)
		throw new \RuntimeException("Variant: no variant with the SKU `{$sku}`");
	    return $v;
	}
	return $this->one('productVariant', \util\Shopify::gid('ProductVariant', $value('variant'), 'Variant'), $fields, 'variant');
    }

    //a variant's levels: [ { location, locationId, available, on_hand, ... } ]
    public static function levelsOf(array $variant) {
	$levels = [];
	foreach((array)($variant['inventoryItem']['inventoryLevels'] ?? []) as $l) {
	    $row = [ 'location' => (string)($l['location']['name'] ?? ''), 'locationId' => (string)($l['location']['id'] ?? '') ];
	    foreach((array)($l['quantities'] ?? []) as $q)
		$row[(string)$q['name']] = (int)$q['quantity'];
	    $levels[] = $row;
	}
	return $levels;
    }

    //the location of the setting `location` among the variant's levels (its ID or name; empty: its only one, else the store's only active location)
    protected function locationOf(\Closure $text, array $levels) {
	$want = $text('location');
	if ($want === '') {
	    if (count($levels) === 1)
		return $levels[0]['locationId'];
	    $all = $this->shop->connection('query($first: Int!, $after: String) { locations(first: $first, after: $after, query: "active:true") { nodes { id name } pageInfo { hasNextPage endCursor } } }',
		[], 'locations', 50)[0];
	    if (count($all) === 1)
		return $all[0]['id'];
	    throw new \RuntimeException('Location: required (the store has ' . count($all) . ' active locations)');
	}
	if (preg_match('/^\d+$|^gid:/', $want))
	    return \util\Shopify::gid('Location', $want, 'Location');
	foreach($levels as $l)
	    if (mb_strtolower($l['location']) === mb_strtolower($want))
		return $l['locationId'];
	$found = $this->findOne('locations', 'name:' . \util\Shopify::quoted($want), 'id name');
	if (!$found || mb_strtolower((string)$found['name']) !== mb_strtolower($want))
	    throw new \RuntimeException("Location: no location `{$want}`");
	return $found['id'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'locations') {
	    $this->shop->pageSize = 100;
	    return $this->listOf('locations', 'id legacyResourceId name isActive fulfillsOnlineOrders address { address1 city province zip country countryCode }', $text('search'), 'location');
	}
	$v = $this->variantLevels($value);
	$levels = self::levelsOf($v);
	$item = (string)($v['inventoryItem']['id'] ?? '');
	if ($operation === 'levels') {
	    $available = array_sum(array_map(fn($l) => (int)($l['available'] ?? 0), $levels));
	    return [ [ 'variant' => $v['id'], 'sku' => $v['sku'] ?? null, 'title' => $v['displayName'] ?? $v['title'], 'product' => $v['product'] ?? null, 'inventoryItem' => $item,
		'tracked' => (bool)($v['inventoryItem']['tracked'] ?? false), 'available' => $available, 'levels' => $levels ],
		(($v['sku'] ?? '') ?: $v['id']) . ": {$available} available at " . \util\Format::plural(count($levels), 'location') ];
	}
	$location = $this->locationOf($text, $levels);
	$level = array_values(array_filter($levels, fn($l) => $l['locationId'] === $location))[0] ?? null;
	if (!$level)
	    throw new \RuntimeException('Location: the variant is not stocked there (activate it at the location in Shopify first)');
	$before = (int)($level['available'] ?? 0);
	$reason = isset(self::REASONS[(string)$this->cfg('reason', 'correction')]) ? (string)$this->cfg('reason', 'correction') : 'correction';
	$cas = strcmp($this->shop->version(), self::CAS_VERSION) >= 0;
	$key = bin2hex(random_bytes(16));
	if ($operation === 'adjust') {
	    $delta = self::integer($value('delta'), 'Change by', true);
	    $change = [ 'delta' => $delta, 'inventoryItemId' => $item, 'locationId' => $location ] + ($cas ? [ 'changeFromQuantity' => $before ] : []);
	    $this->shop->mutate('mutation($input: InventoryAdjustQuantitiesInput!' . ($cas ? ', $key: String!' : '') . ') { inventoryAdjustQuantities(input: $input)' . ($cas ? ' @idempotent(key: $key)' : '')
		. ' { inventoryAdjustmentGroup { id reason } userErrors { field message } } }', [ 'input' => [ 'reason' => $reason, 'name' => 'available', 'changes' => [ $change ] ] ] + ($cas ? [ 'key' => $key ] : []),
		'inventoryAdjustQuantities');
	    $after = $before + $delta;
	} else {
	    $after = self::integer($value('quantity'), 'Quantity');
	    $delta = $after - $before;
	    $q = [ 'inventoryItemId' => $item, 'locationId' => $location, 'quantity' => $after ] + ($cas ? [ 'changeFromQuantity' => $before ] : []);
	    $this->shop->mutate('mutation($input: InventorySetQuantitiesInput!' . ($cas ? ', $key: String!' : '') . ') { inventorySetQuantities(input: $input)' . ($cas ? ' @idempotent(key: $key)' : '')
		. ' { inventoryAdjustmentGroup { id reason } userErrors { field message } } }', [ 'input' => [ 'reason' => $reason, 'name' => 'available', 'quantities' => [ $q ] ]
		+ ($cas ? [] : [ 'ignoreCompareQuantity' => true ]) ] + ($cas ? [ 'key' => $key ] : []), 'inventorySetQuantities');
	}
	return [ [ 'variant' => $v['id'], 'sku' => $v['sku'] ?? null, 'location' => $level['location'], 'locationId' => $location, 'before' => $before, 'after' => $after, 'delta' => $delta ],
	    (($v['sku'] ?? '') ?: $v['id']) . " at {$level['location']}: {$before} → {$after}" ];
    }
}
