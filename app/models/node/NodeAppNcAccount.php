<?php

/*
    Namecheap Account - the account's balance, Namecheap's prices and the SSL certificates. Operations (setting `operation`):
	balances    the balances (namecheap.users.getBalances): available, account, earned, withdrawable, needed for the auto-renewals
	pricing     the prices of a TLD (namecheap.users.getPricing, ProductType DOMAIN): to register, renew, transfer or reactivate, per number of years
	ssl         the SSL certificates (namecheap.ssl.getList): of a status, containing a text
*/

namespace models\node;

class NodeAppNcAccount extends NodeAppNamecheap {
    public static $name = 'namecheap-account';
    public static $label = 'Namecheap Account';
    public static $icon = 'bi-wallet2';
    public static $defaultOperation = 'balances';
    public static $defaultVariable = 'account';

    const OPERATIONS = [
	'balances' => 'Get balances',
	'pricing' => 'Get domain prices',
	'ssl' => 'List SSL certificates',
    ];
    const WRITES = [];
    const LISTS = [ 'pricing', 'ssl' ];
    const ACTIONS = [ 'REGISTER' => 'Register', 'RENEW' => 'Renew', 'TRANSFER' => 'Transfer', 'REACTIVATE' => 'Reactivate' ];
    const SSL_LISTS = [ 'ALL' => 'All', 'Active' => 'Active', 'Processing' => 'Processing', 'EmailSent' => 'Email sent', 'InProgress' => 'In progress', 'Completed' => 'Completed',
	'NewPurchase' => 'New purchase', 'NewRenewal' => 'New renewal', 'Deactivated' => 'Deactivated', 'Cancelled' => 'Cancelled', 'TechnicalProblem' => 'Technical problem' ];

    protected static function operationFields() {
	return [
	    self::field('tld', 'TLD', [ 'pricing' ], [ 'required' => true, 'help' => 'e.g. com, or co.uk', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('action', 'Prices to', [ 'pricing' ], [ 'type' => 'select', 'default' => 'REGISTER', 'choices' => self::ACTIONS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('promotionCode', 'Promotion code', [ 'pricing' ], [ 'help' => 'Empty: none', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sslList', 'Certificates', [ 'ssl' ], [ 'type' => 'select', 'default' => 'ALL', 'choices' => self::SSL_LISTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'ssl' ], [ 'help' => 'A text in the host name; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'balances':
		$b = \util\Namecheap::attrs($this->call('namecheap.users.getBalances')->UserGetBalancesResult);
		return [ $b, 'available ' . ($b['AvailableBalance'] ?? '?') . ' ' . ($b['Currency'] ?? '') ];
	    case 'pricing':
		$tld = strtolower(ltrim($text('tld'), '.'));
		if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)?$/', $tld))
		    throw new \RuntimeException('TLD: not a TLD: `' . mb_substr($tld, 0, 32) . '`');
		$action = isset(self::ACTIONS[(string)$this->cfg('action', 'REGISTER')]) ? (string)$this->cfg('action', 'REGISTER') : 'REGISTER';
		$r = $this->call('namecheap.users.getPricing', [ 'ProductType' => 'DOMAIN', 'ActionName' => $action, 'ProductName' => $tld,
		    'PromotionCode' => $text('promotionCode') ?: null ])->UserGetPricingResult;
		$all = [];
		foreach($r->ProductType ?? [] as $type)
		    foreach($type->ProductCategory ?? [] as $cat)
			foreach($cat->Product ?? [] as $product)
			    foreach($product->Price ?? [] as $price)
				$all[] = [ 'tld' => (string)$product['Name'], 'action' => strtolower((string)$cat['Name']) ] + \util\Namecheap::attrs($price);
		if (!$all)
		    throw new \RuntimeException("Namecheap has no price to " . strtolower($action) . " .{$tld}");
		return $this->listResult($all, 'price');
	    case 'ssl':
		$list = isset(self::SSL_LISTS[(string)$this->cfg('sslList', 'ALL')]) ? (string)$this->cfg('sslList', 'ALL') : 'ALL';
		return $this->listOf('namecheap.ssl.getList', [ 'ListType' => $list, 'SearchTerm' => $text('search') ?: null ], 'SSLListResult', 'SSL', 'certificate');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
