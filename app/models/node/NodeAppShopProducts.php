<?php

/*
    CLD: 2026-11-12T10:00:00+02:00: Shopify Products - the store's products and their variants. Operations (setting `operation`):
	list        the products a search finds (products), sorted: without their variants (variantsCount; the Variants operation lists those)
	variants    the variants a search finds (productVariants: e.g. sku:ABC-1, barcode:…), each with its product and inventory item
	get         a product with its variants (product); by its ID, its handle, or a product of a list or a webhook
	create      a product (productCreate): title, description, vendor, type, tags, status; its first variant's price, compare-at price and SKU
	update      what is not empty of those (productUpdate)
	setPrice    a variant's price and compare-at price (productVariantsBulkUpdate); the variant by its ID or SKU
	addTags     tags added / removed (tagsAdd / tagsRemove)
	removeTags
	delete      a product deleted (productDelete)
*/

namespace models\node;

class NodeAppShopProducts extends NodeAppShopify {
    public static $name = 'shopify-products';
    public static $label = 'Shopify Products';
    public static $icon = 'bi-box-seam';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'products';

    const OPERATIONS = [
	'list' => 'List products',
	'variants' => 'List variants',
	'get' => 'Get a product',
	'create' => 'Create a product',
	'update' => 'Update a product',
	'setPrice' => 'Set a variant\'s price',
	'addTags' => 'Add tags',
	'removeTags' => 'Remove tags',
	'delete' => 'Delete a product',
    ];
    const WRITES = [ 'create', 'update', 'setPrice', 'addTags', 'removeTags', 'delete' ];
    const LISTS = [ 'list', 'variants' ];
    const INPUTS = [ 'create', 'update' ];
    const ONE = [ 'get', 'update', 'addTags', 'removeTags', 'delete' ];
    const SORTS = [ 'TITLE' => 'Title', 'UPDATED_AT_DESC' => 'Updated, latest first', 'CREATED_AT_DESC' => 'Newest first', 'INVENTORY_TOTAL' => 'Inventory, lowest first',
	'PRODUCT_TYPE' => 'Type', 'VENDOR' => 'Vendor' ];
    const STATUSES = [ 'ACTIVE' => 'Active', 'DRAFT' => 'Draft', 'ARCHIVED' => 'Archived' ];
    const FINDS = [ 'id' => 'Its ID', 'sku' => 'Its SKU' ];

    const PRODUCT = 'id legacyResourceId title handle status vendor productType tags createdAt updatedAt publishedAt totalInventory tracksInventory onlineStoreUrl variantsCount { count }';
    const VARIANT = 'id legacyResourceId title sku barcode price compareAtPrice inventoryQuantity availableForSale selectedOptions { name value } inventoryItem { id tracked }';

    protected static function operationFields() {
	$writeOps = [ 'create', 'update' ];
	return [
	    self::searchField([ 'list' ], 'status:active vendor:Acme tag:sale'),
	    self::field('sort', 'Sorted by', [ 'list' ], [ 'type' => 'select', 'default' => 'TITLE', 'choices' => self::SORTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('variantSearch', 'Search', [ 'variants' ], [ 'help' => 'Shopify\'s search syntax, e.g. sku:ABC-1, barcode:5901234123457, product_id:123; empty: all',
		'ui' => [ 'expression' => 'text' ] ]),
	    self::field('product', 'Product', self::ONE, [ 'required' => true, 'help' => 'Its ID, its handle, or a product of a list or a webhook', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('title', 'Title', $writeOps, [ 'help' => 'Required to create; update: empty is unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('status', 'Status', $writeOps, [ 'type' => 'select', 'choices' => [ '' => 'Unchanged (create: draft)' ] + self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('descriptionHtml', 'Description', $writeOps, [ 'type' => 'textarea', 'help' => 'HTML; empty: unchanged', 'ui' => [ 'rows' => 3, 'expression' => 'text' ] ]),
	    self::field('vendor', 'Vendor', $writeOps, [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('productType', 'Type', $writeOps, [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('tags', 'Tags', array_merge($writeOps, [ 'addTags', 'removeTags' ]), [ 'help' => 'Separated by commas, or a list; update: replaces them all',
		'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	    self::field('find', 'Variant by', [ 'setPrice' ], [ 'type' => 'select', 'default' => 'id', 'choices' => self::FINDS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('variant', 'Variant', [ 'setPrice' ], [ 'required' => true, 'help' => 'Its ID (or a variant of a list), or its SKU', 'ui' => [ 'expression' => true, 'width' => 9 ] ]),
	    self::field('price', 'Price', [ 'create', 'setPrice' ], [ 'help' => 'e.g. 19.90; empty: unchanged (create: 0)', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('compareAtPrice', 'Compare-at price', [ 'create', 'setPrice' ], [ 'help' => 'The price before a sale; empty: unchanged', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('sku', 'SKU', [ 'create' ], [ 'help' => 'Of the first variant', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	];
    }

    //the GID of the setting `product`: an ID, a GID, an object (a list item, a webhook's payload), or a handle (looked up)
    protected function productGid(\Closure $value) {
	$v = $value('product');
	$t = is_array($v) ? '' : trim(\util\Expression::toText($v));
	if ($t !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $t) && !preg_match('/^\d+$/', $t)) {
	    $p = $this->findOne('products', 'handle:' . \util\Shopify::quoted($t), 'id handle');
	    if (!$p || $p['handle'] !== $t)
		throw new \RuntimeException("Product: no product with the handle `{$t}`");
	    return $p['id'];
	}
	return \util\Shopify::gid('Product', $v, 'Product');
    }

    //the variant of the setting `variant` (by ID or SKU): { id, sku, product: { id } }
    protected function variantOf(\Closure $value) {
	if ((string)$this->cfg('find', 'id') === 'sku') {
	    $sku = trim(\util\Expression::toText($value('variant')));
	    if ($sku === '')
		throw new \RuntimeException('Variant: empty');
	    $v = $this->findOne('productVariants', 'sku:' . \util\Shopify::quoted($sku), 'id sku product { id }');
	    if (!$v || (string)$v['sku'] !== $sku)
		throw new \RuntimeException("Variant: no variant with the SKU `{$sku}`");
	    return $v;
	}
	return $this->one('productVariant', \util\Shopify::gid('ProductVariant', $value('variant'), 'Variant'), 'id sku product { id }', 'variant');
    }

    //the product's settings that are not empty (create / update)
    protected function productInput(\Closure $text, \Closure $value) {
	$input = [];
	foreach([ 'title', 'descriptionHtml', 'vendor', 'productType' ] as $k)
	    if (($t = $text($k)) !== '')
		$input[$k] = $t;
	if (isset(self::STATUSES[(string)$this->cfg('status', '')]))
	    $input['status'] = (string)$this->cfg('status', '');
	if (($tags = self::names($value('tags'))) !== [])
	    $input['tags'] = $tags;
	return $input;
    }

    //a variant's price, compare-at price, SKU (those not empty) set: the variant as Shopify gives it back, or null when there is nothing to set
    protected function setVariant($productId, $variantId, \Closure $text, $sku = '') {
	$v = [ 'id' => $variantId ];
	if (($p = self::money($text('price'), 'Price')) !== '')
	    $v['price'] = $p;
	if (($c = self::money($text('compareAtPrice'), 'Compare-at price')) !== '')
	    $v['compareAtPrice'] = $c;
	if ($sku !== '')
	    $v['inventoryItem'] = [ 'sku' => $sku ];
	if (count($v) < 2)
	    return null;
	$p = $this->shop->mutate('mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId: $productId, variants: $variants) {'
	    . ' productVariants { ' . self::VARIANT . ' } userErrors { field message } } }', [ 'productId' => $productId, 'variants' => [ $v ] ], 'productVariantsBulkUpdate');
	return \util\Shopify::simplify($p['productVariants'][0] ?? null);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->shop;
	switch($operation) {
	    case 'list':
		$sort = isset(self::SORTS[(string)$this->cfg('sort', 'TITLE')]) ? (string)$this->cfg('sort', 'TITLE') : 'TITLE';
		$S->pageSize = 100;
		return $this->listOf('products', self::PRODUCT, $text('search'), 'product',
		    ', sortKey: ' . preg_replace('/_DESC$/', '', $sort) . ', reverse: ' . (str_ends_with($sort, '_DESC') ? 'true' : 'false'));
	    case 'variants':
		$S->pageSize = 100;
		return $this->listOf('productVariants', self::VARIANT . ' product { id title handle status }', $text('variantSearch'), 'variant');
	    case 'create':
		$input = $this->withFields($this->productInput($text, $value));
		if (trim((string)($input['title'] ?? '')) === '')
		    throw new \RuntimeException('Title: required to create a product');
		$p = $S->mutate('mutation($product: ProductCreateInput!) { productCreate(product: $product) { product { ' . self::PRODUCT . ' variants(first: 1) { nodes { id } } } userErrors { field message } } }',
		    [ 'product' => $input ], 'productCreate');
		$product = \util\Shopify::simplify($p['product']);
		$first = $product['variants'][0]['id'] ?? null;
		if ($first && ($v = $this->setVariant($product['id'], $first, $text, $text('sku'))))
		    $product['variants'] = [ $v ];
		return [ $product, ($product['title'] ?? '') . ' created (' . ($product['id'] ?? '?') . ')' ];
	    case 'setPrice':
		$variant = $this->variantOf($value);
		$v = $this->setVariant($variant['product']['id'], $variant['id'], $text);
		if (!$v)
		    throw new \RuntimeException('nothing to change: a price or a compare-at price');
		return [ $v, (($v['sku'] ?? '') ?: $v['id']) . ': ' . ($v['price'] ?? '') . (!empty($v['compareAtPrice']) ? " (was {$v['compareAtPrice']})" : '') ];
	}
	$id = $this->productGid($value);
	switch($operation) {
	    case 'get':
		$p = $this->one('product', $id, self::PRODUCT . ' descriptionHtml options { name values } variants(first: 250) { nodes { ' . self::VARIANT . ' } }', 'product');
		return [ $p, ($p['title'] ?? $id) . ': ' . ($p['status'] ?? '') . ', ' . \util\Format::plural(count((array)($p['variants'] ?? [])), 'variant') ];
	    case 'update':
		$input = $this->withFields($this->productInput($text, $value));
		if (!$input)
		    throw new \RuntimeException('nothing to change');
		$p = $S->mutate('mutation($product: ProductUpdateInput!) { productUpdate(product: $product) { product { ' . self::PRODUCT . ' } userErrors { field message } } }',
		    [ 'product' => [ 'id' => $id ] + $input ], 'productUpdate');
		return [ \util\Shopify::simplify($p['product']), ($p['product']['title'] ?? $id) . ' updated: ' . implode(', ', array_keys($input)) ];
	    case 'addTags':
	    case 'removeTags':
		return $this->changeTags($id, $value('tags'), $operation === 'addTags', 'product');
	    case 'delete':
		$p = $S->mutate('mutation($input: ProductDeleteInput!) { productDelete(input: $input) { deletedProductId userErrors { field message } } }', [ 'input' => [ 'id' => $id ] ], 'productDelete');
		return [ [ 'id' => $p['deletedProductId'] ?? $id, 'deleted' => true ], "{$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
