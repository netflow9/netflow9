<?php

/*
    CLD: 2026-11-05T13:00:00+02:00: new step: Namecheap Domains - the domains of the account. Operations (setting `operation`):
	list        the domains (namecheap.domains.getList): all, expiring or expired, containing a text, sorted
	expiring    the domains that expire within some days (getList, all of them, sorted by expiry): each with daysLeft; expired ones too unless left out
	get         a domain's details (namecheap.domains.getInfo)
	check       whether names can be registered (namecheap.domains.check, at most 50 names): each with Available, and the premium price
	contacts    a domain's contacts (namecheap.domains.getContacts)
	lockStatus  whether a domain's registrar lock is on (namecheap.domains.getRegistrarLock)
	lock        the registrar lock on / off (namecheap.domains.setRegistrarLock)
	unlock
	renew       a domain renewed for some years (namecheap.domains.renew): charged to the account's balance
	tlds        the TLDs Namecheap offers (namecheap.domains.getTldList)
    Registering a domain (namecheap.domains.create: four contacts) is left to the Namecheap API step
*/

namespace models\node;

class NodeAppNcDomains extends NodeAppNamecheap {
    public static $name = 'namecheap-domains';
    public static $label = 'Namecheap Domains';
    public static $icon = 'bi-globe2';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'domains';

    const OPERATIONS = [
	'list' => 'List domains',
	'expiring' => 'List domains expiring soon',
	'get' => 'Get a domain',
	'check' => 'Check availability',
	'contacts' => 'Get a domain\'s contacts',
	'lockStatus' => 'Get the registrar lock',
	'lock' => 'Lock a domain',
	'unlock' => 'Unlock a domain',
	'renew' => 'Renew a domain',
	'tlds' => 'List TLDs',
    ];
    const WRITES = [ 'lock', 'unlock', 'renew' ];
    const LISTS = [ 'list', 'expiring', 'tlds' ];
    const LIST_TYPES = [ 'ALL' => 'All', 'EXPIRING' => 'Expiring', 'EXPIRED' => 'Expired' ];
    const SORTS = [ 'NAME' => 'Name', 'NAME_DESC' => 'Name, descending', 'EXPIREDATE' => 'Expiry', 'EXPIREDATE_DESC' => 'Expiry, latest first', 'CREATEDATE' => 'Created',
	'CREATEDATE_DESC' => 'Created, latest first' ];
    //namecheap.domains.check takes at most this many names
    const MAX_CHECK = 50;

    protected static function operationFields() {
	return [
	    self::field('listType', 'Domains', [ 'list' ], [ 'type' => 'select', 'default' => 'ALL', 'choices' => self::LIST_TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('sortBy', 'Sorted by', [ 'list' ], [ 'type' => 'select', 'default' => 'NAME', 'choices' => self::SORTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'list', 'expiring' ], [ 'help' => 'A text in the name; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('days', 'Within (days)', [ 'expiring' ], [ 'type' => 'number', 'required' => true, 'default' => 30, 'help' => 'Expiring in at most this many days',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 3650, 'skipEmpty' => true, 'message' => 'from 0 to 3650' ] ], 'ui' => [ 'step' => 1, 'width' => 6 ] ]),
	    self::field('includeExpired', 'Expired ones too', [ 'expiring' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Those already expired (while they can still be renewed)', 'ui' => [ 'width' => 6 ] ]),
	    self::field('domain', 'Domain', [ 'get', 'contacts', 'lockStatus', 'lock', 'unlock', 'renew' ], [ 'required' => true, 'help' => 'Its name, e.g. example.com; a domain of a list works too',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('domains', 'Names', [ 'check' ], [ 'required' => true, 'help' => 'Separated by commas, or a list; at most ' . self::MAX_CHECK, 'ui' => [ 'expression' => true ] ]),
	    self::field('years', 'Years', [ 'renew' ], [ 'type' => 'number', 'required' => true, 'default' => 1, 'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 10, 'skipEmpty' => true,
		'message' => 'from 1 to 10' ] ], 'ui' => [ 'step' => 1, 'width' => 3 ] ]),
	    self::field('promotionCode', 'Promotion code', [ 'renew' ], [ 'help' => 'Empty: none', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	];
    }

    //a domain of getList, with the days from today to the day it expires (its Expires: MM/DD/YYYY): 0 on that day, negative after
    public static function withDaysLeft(array $d, $now = null) {
	$t = strtotime((string)($d['Expires'] ?? '') . ' 00:00:00 UTC');
	$today = strtotime(gmdate('Y-m-d', $now ?? time()) . ' 00:00:00 UTC');
	$d['daysLeft'] = $t === false ? null : (int)round(($t - $today) / 86400);
	return $d;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		$type = isset(self::LIST_TYPES[(string)$this->cfg('listType', 'ALL')]) ? (string)$this->cfg('listType', 'ALL') : 'ALL';
		$sort = isset(self::SORTS[(string)$this->cfg('sortBy', 'NAME')]) ? (string)$this->cfg('sortBy', 'NAME') : 'NAME';
		return $this->listOf('namecheap.domains.getList', [ 'ListType' => $type, 'SortBy' => $sort, 'SearchTerm' => $text('search') ?: null ], 'DomainGetListResult', 'Domain', 'domain');
	    case 'expiring':
		$days = max(0, (int)$value('days'));
		$expired = (bool)$this->cfg('includeExpired', true);
		$all = [];
		//every domain is read (sorted by expiry), the window applied here: Namecheap's own EXPIRING list has a fixed window
		$max = self::MAX_ITEMS;
		for ($page = 1; ; $page++) {
		    $r = $this->call('namecheap.domains.getList', [ 'ListType' => 'ALL', 'SortBy' => 'EXPIREDATE', 'SearchTerm' => $text('search') ?: null, 'Page' => $page, 'PageSize' => \util\Namecheap::PAGE ]);
		    $n = 0;
		    $past = false;
		    foreach($r->DomainGetListResult->Domain ?? [] as $el) {
			$n++;
			$d = self::withDaysLeft(\util\Namecheap::attrs($el));
			if ($d['daysLeft'] !== null && $d['daysLeft'] > $days)
			    $past = true;
			elseif ($expired || ($d['daysLeft'] ?? 0) >= 0)
			    $all[] = $d;
		    }
		    $total = isset($r->Paging->TotalItems) ? (int)(string)$r->Paging->TotalItems : 0;
		    if ($past || $n < \util\Namecheap::PAGE || $page * \util\Namecheap::PAGE >= $total || count($all) >= $max)
			break;
		}
		usort($all, fn($a, $b) => ($a['daysLeft'] ?? PHP_INT_MAX) <=> ($b['daysLeft'] ?? PHP_INT_MAX));
		[ $res, $sum ] = $this->listResult($all, 'domain');
		return [ $res, "{$sum} expiring within {$days} days" ];
	    case 'get':
		[ $domain ] = $this->domainOf($value);
		$d = \util\Namecheap::toArray($this->call('namecheap.domains.getInfo', [ 'DomainName' => $domain ])->DomainGetInfoResult);
		$expires = $d['DomainDetails']['ExpiredDate'] ?? null;
		return [ $d, "{$domain}: " . ($d['Status'] ?? '') . (is_scalar($expires) ? ", expires {$expires}" : '') ];
	    case 'check':
		$names = array_map(fn($n) => \util\Namecheap::domain($n, 'Names')[0], self::names($value('domains')));
		if (!$names)
		    throw new \RuntimeException('Names: empty');
		if (count($names) > self::MAX_CHECK)
		    throw new \RuntimeException('Names: at most ' . self::MAX_CHECK . ' in one check, not ' . count($names));
		$items = [];
		foreach($this->call('namecheap.domains.check', [ 'DomainList' => $names ])->DomainCheckResult ?? [] as $el)
		    $items[] = \util\Namecheap::attrs($el);
		$available = array_values(array_map(fn($i) => (string)$i['Domain'], array_filter($items, fn($i) => ($i['Available'] ?? false) === true)));
		return [ [ 'items' => $items, 'available' => $available, 'count' => count($items) ], count($available) . ' of ' . \util\Format::plural(count($items), 'name') . ' available' ];
	    case 'contacts':
		[ $domain ] = $this->domainOf($value);
		return [ \util\Namecheap::toArray($this->call('namecheap.domains.getContacts', [ 'DomainName' => $domain ])->DomainContactsResult), "the contacts of {$domain}" ];
	    case 'lockStatus':
		[ $domain ] = $this->domainOf($value);
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.getRegistrarLock', [ 'DomainName' => $domain ])->DomainGetRegistrarLockResult);
		$locked = ($a['RegistrarLockStatus'] ?? false) === true;
		return [ [ 'domain' => $domain, 'locked' => $locked ], "{$domain}: " . ($locked ? 'locked' : 'unlocked') ];
	    case 'lock':
	    case 'unlock':
		[ $domain ] = $this->domainOf($value);
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.setRegistrarLock', [ 'DomainName' => $domain, 'LockAction' => strtoupper($operation) ])->DomainSetRegistrarLockResult);
		if (($a['IsSuccess'] ?? true) !== true)
		    throw new \RuntimeException("Namecheap did not {$operation} {$domain}");
		return [ [ 'domain' => $domain, 'locked' => $operation === 'lock' ], "{$domain} {$operation}ed" ];
	    case 'renew':
		[ $domain ] = $this->domainOf($value);
		$years = (int)$value('years');
		if ($years < 1 || $years > 10)
		    throw new \RuntimeException('Years: from 1 to 10');
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.renew', [ 'DomainName' => $domain, 'Years' => $years, 'PromotionCode' => $text('promotionCode') ?: null ])->DomainRenewResult);
		if (($a['Renew'] ?? true) !== true)
		    throw new \RuntimeException("Namecheap did not renew {$domain}");
		return [ $a, "{$domain} renewed for " . \util\Format::plural($years, 'year') . (isset($a['ChargedAmount']) ? ", charged {$a['ChargedAmount']}" : '') ];
	    case 'tlds':
		$all = [];
		foreach($this->call('namecheap.domains.getTldList')->Tlds->Tld ?? [] as $el)
		    $all[] = \util\Namecheap::attrs($el) + [ 'Description' => trim((string)$el) ];
		return $this->listResult($all, 'TLD');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
