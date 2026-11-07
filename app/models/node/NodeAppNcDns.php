<?php

/*
    CLD: 2026-11-05T13:00:00+02:00: new step: Namecheap DNS - the records, name servers and email forwarding of a domain on Namecheap's DNS. Operations (setting `operation`):
	records         the records (namecheap.domains.dns.getHosts), of a host and type if given
	add             a record added, unless the same one is there
	set             the records of a host and type replaced by one (e.g. the A record of a dynamic address); unchanged when it is the only one already
	remove          the records of a host and type removed (of a value too, if given)
	replace         every record replaced by a list
	nameservers     the domain's name servers (namecheap.domains.dns.getList)
	setNameservers  custom name servers (namecheap.domains.dns.setCustom)
	resetNameservers Namecheap's own name servers again (namecheap.domains.dns.setDefault)
	forwarding      the email forwarding (namecheap.domains.dns.getEmailForwarding)
	setForwarding   the email forwarding replaced by a list (namecheap.domains.dns.setEmailForwarding)
    namecheap.domains.dns.setHosts replaces all the records: add, set and remove read them (getHosts), change the list and write it back whole, with the email type
    (emailType(): MX / MXE when there are such records, the domain's own otherwise), so the records not named are kept as they were. Two runs changing one domain at the same
    time can lose one's change: Namecheap has no per-record command
*/

namespace models\node;

class NodeAppNcDns extends NodeAppNamecheap {
    public static $name = 'namecheap-dns';
    public static $label = 'Namecheap DNS';
    public static $icon = 'bi-signpost-split';
    public static $defaultOperation = 'records';
    public static $defaultVariable = 'dns';

    const OPERATIONS = [
	'records' => 'List records',
	'add' => 'Add a record',
	'set' => 'Set a record (replace the host\'s)',
	'remove' => 'Remove records',
	'replace' => 'Replace all records',
	'nameservers' => 'Get name servers',
	'setNameservers' => 'Set custom name servers',
	'resetNameservers' => 'Use Namecheap\'s name servers',
	'forwarding' => 'Get email forwarding',
	'setForwarding' => 'Set email forwarding',
    ];
    const WRITES = [ 'add', 'set', 'remove', 'replace', 'setNameservers', 'resetNameservers', 'setForwarding' ];
    const LISTS = [];
    const TYPES = [ 'A' => 'A', 'AAAA' => 'AAAA', 'ALIAS' => 'ALIAS', 'CAA' => 'CAA', 'CNAME' => 'CNAME', 'MX' => 'MX', 'MXE' => 'MXE', 'NS' => 'NS', 'TXT' => 'TXT',
	'URL' => 'URL redirect (302)', 'URL301' => 'URL redirect (301)', 'FRAME' => 'URL frame' ];
    const TTL_MIN = 60;
    const TTL_MAX = 60000;
    const TTL_DEFAULT = 1800;

    protected static function operationFields() {
	$records = [ 'add', 'set', 'remove' ];
	return [
	    self::field('domain', 'Domain', array_keys(self::OPERATIONS), [ 'required' => true, 'help' => 'Its name, e.g. example.com; a domain of a list works too', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('host', 'Host', array_merge([ 'records' ], $records), [ 'help' => '@ for the domain itself, or a name such as www (List records: empty: all)',
		'requiredIf' => [ 'if' => 'operation', 'in' => $records ], 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '@' ] ]),
	    self::field('type', 'Type', array_merge([ 'records' ], $records), [ 'type' => 'select', 'default' => '', 'choices' => [ '' => '(any)' ] + self::TYPES,
		'help' => 'List records: empty: every type', 'requiredIf' => [ 'if' => 'operation', 'in' => $records ], 'ui' => [ 'width' => 4, 'newRow' => true ] ]),
	    self::field('address', 'Value', $records, [ 'help' => 'The address, name, text or URL (Remove records: empty: whatever the value)', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'add', 'set' ] ],
		'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('ttl', 'TTL (s)', [ 'add', 'set' ], [ 'type' => 'number', 'default' => self::TTL_DEFAULT, 'validate' => [ [ 'inRange', 'lower' => self::TTL_MIN, 'upper' => self::TTL_MAX,
		'skipEmpty' => true, 'message' => 'from ' . self::TTL_MIN . ' to ' . self::TTL_MAX ] ], 'ui' => [ 'step' => 1, 'width' => 4 ] ]),
	    self::field('mxPref', 'MX priority', [ 'add', 'set' ], [ 'type' => 'number', 'default' => 10, 'help' => 'Of an MX record', 'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 65535,
		'skipEmpty' => true, 'message' => 'from 0 to 65535' ] ], 'ui' => [ 'step' => 1, 'width' => 4 ] ]),
	    self::field('records', 'Records', [ 'replace' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list of { host, type, value, ttl, mxPref } (List records\' records work too: Name, Type, Address, TTL, MXPref). Every other record is removed',
		'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('nameservers', 'Name servers', [ 'setNameservers' ], [ 'required' => true, 'help' => 'Separated by commas, or a list; at least 2', 'ui' => [ 'expression' => true ] ]),
	    self::field('forwards', 'Forwarding', [ 'setForwarding' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list of { mailbox, forwardTo }: info forwards info@<domain>. Every other forward is removed',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    //a host as Namecheap names it: @ for the domain, else the name below it (www.example.com of example.com: www)
    public static function host($value, $domain, $label = 'Host') {
	$h = strtolower(rtrim(trim(\util\Expression::toText($value)), '.'));
	if ($h === '' || $h === '@' || $h === $domain)
	    return '@';
	if (str_ends_with($h, ".{$domain}"))
	    $h = substr($h, 0, -strlen($domain) - 1);
	if (!preg_match('/^(\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(\.(\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?))*$/', $h))
	    throw new \RuntimeException("{$label}: not a host's name: `" . mb_substr($h, 0, 64) . '`');
	return $h;
    }

    public static function type($value, $label = 'Type') {
	$t = strtoupper(trim(\util\Expression::toText($value)));
	if (!isset(self::TYPES[$t]))
	    throw new \RuntimeException("{$label}: not a record type Namecheap has: `" . mb_substr($t, 0, 16) . '`');
	return $t;
    }

    //a record as setHosts takes it: { Name, Type, Address, TTL, MXPref } (from ours: host, type, value / address, ttl, mxPref; or as getHosts gives it)
    public static function record(array $r, $domain, $label = 'Record') {
	$type = self::type(self::firstOf($r, [ 'Type', 'type' ]) ?? '', "{$label}: type");
	$address = trim(\util\Expression::toText(self::firstOf($r, [ 'Address', 'value', 'address' ]) ?? ''));
	if ($address === '')
	    throw new \RuntimeException("{$label}: no value");
	$ttl = (int)(self::firstOf($r, [ 'TTL', 'ttl' ]) ?? self::TTL_DEFAULT);
	if ($ttl < self::TTL_MIN || $ttl > self::TTL_MAX)
	    throw new \RuntimeException("{$label}: TTL from " . self::TTL_MIN . ' to ' . self::TTL_MAX . ", not {$ttl}");
	$rec = [ 'Name' => self::host(self::firstOf($r, [ 'Name', 'host', 'name' ]) ?? '@', $domain, "{$label}: host"), 'Type' => $type, 'Address' => $address, 'TTL' => $ttl ];
	if ($type === 'MX')
	    $rec['MXPref'] = (int)(self::firstOf($r, [ 'MXPref', 'mxPref' ]) ?? 10);
	return $rec;
    }

    //CLD: 2026-11-07T01:09:58+02:00: the first of keys $keys that $r has as a non-empty value; null when none
    private static function firstOf(array $r, array $keys) {
	foreach($keys as $k) {
	    if (isset($r[$k]) && !is_array($r[$k]) && trim((string)$r[$k]) !== '')
		return $r[$k];
	}
	return null;
    }

    //the same record (name, type and value; names and addresses of other types than TXT / CAA compared as names)
    public static function sameRecord(array $a, array $b) {
	if (strcasecmp($a['Name'], $b['Name']) !== 0 || $a['Type'] !== $b['Type'])
	    return false;
	if (in_array($a['Type'], [ 'TXT', 'CAA' ], true))
	    return trim($a['Address']) === trim($b['Address']);
	return strcasecmp(rtrim(trim($a['Address']), '.'), rtrim(trim($b['Address']), '.')) === 0;
    }

    //the email type written with the records: MXE / MX when there are such records; else the domain's, unless it was MX / MXE (none left: NONE); null: not sent
    public static function emailType(array $records, $current) {
	$types = array_column($records, 'Type');
	if (in_array('MXE', $types, true))
	    return 'MXE';
	if (in_array('MX', $types, true))
	    return 'MX';
	$current = strtoupper(trim((string)$current));
	if (in_array($current, [ 'MX', 'MXE' ], true))
	    return 'NONE';
	return $current !== '' ? $current : null;
    }

    //setHosts' parameters for a list of records
    public static function hostsParams($sld, $tld, array $records, $emailType) {
	$p = [ 'SLD' => $sld, 'TLD' => $tld ];
	foreach(array_values($records) as $i => $r) {
	    $n = $i + 1;
	    $p["HostName{$n}"] = $r['Name'];
	    $p["RecordType{$n}"] = $r['Type'];
	    $p["Address{$n}"] = $r['Address'];
	    $p["TTL{$n}"] = $r['TTL'];
	    if ($r['Type'] === 'MX')
		$p["MXPref{$n}"] = $r['MXPref'] ?? 10;
	}
	if ($emailType !== null)
	    $p['EmailType'] = $emailType;
	return $p;
    }

    //a JSON list of a setting (or an expression giving one)
    protected static function listValue($v, $label) {
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON list");
	}
	if (!is_array($v))
	    throw new \RuntimeException("{$label}: not a list");
	if ($v && !array_is_list($v))
	    $v = isset($v['records']) && is_array($v['records']) ? $v['records'] : [ $v ];
	return array_values($v);
    }

    // ------------------------------------------------------------------------------------
    //running

    //the domain's records: [ records, email type, the answer's attributes ]
    protected function hosts($sld, $tld) {
	$r = $this->nc->call('namecheap.domains.dns.getHosts', [ 'SLD' => $sld, 'TLD' => $tld ])->DomainDNSGetHostsResult;
	$records = [];
	foreach($r->host ?? [] as $el) {
	    $a = \util\Namecheap::attrs($el);
	    $rec = [ 'Name' => (string)($a['Name'] ?? '@'), 'Type' => strtoupper((string)($a['Type'] ?? '')), 'Address' => (string)($a['Address'] ?? ''), 'TTL' => (int)($a['TTL'] ?? self::TTL_DEFAULT) ];
	    if ($rec['Type'] === 'MX')
		$rec['MXPref'] = (int)($a['MXPref'] ?? 10);
	    $records[] = $rec + $a;
	}
	$attrs = \util\Namecheap::attrs($r);
	return [ $records, (string)($attrs['EmailType'] ?? ''), $attrs ];
    }

    //the records written back whole: the result { domain, records, count, added, removed, changed }
    protected function writeHosts($domain, $sld, $tld, array $before, array $after, $emailType, array $added, array $removed) {
	$clean = array_map(fn($r) => array_intersect_key($r, array_flip([ 'Name', 'Type', 'Address', 'TTL', 'MXPref' ])), $after);
	$changed = $added || $removed;
	if ($changed) {
	    $a = \util\Namecheap::attrs($this->call('namecheap.domains.dns.setHosts', self::hostsParams($sld, $tld, $clean, self::emailType($clean, $emailType)))->DomainDNSSetHostsResult);
	    if (($a['IsSuccess'] ?? true) !== true)
		throw new \RuntimeException("Namecheap did not save the records of {$domain}");
	}
	return [ 'domain' => $domain, 'records' => $clean, 'count' => count($clean), 'added' => array_values($added), 'removed' => array_values($removed), 'changed' => (bool)$changed ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	[ $domain, $sld, $tld ] = $this->domainOf($value);
	switch($operation) {
	    case 'records':
		[ $records, $emailType, $attrs ] = $this->hosts($sld, $tld);
		$host = $text('host') !== '' ? self::host($value('host'), $domain) : null;
		$type = $text('type') !== '' ? self::type($value('type')) : null;
		$records = array_values(array_filter($records, fn($r) => ($host === null || strcasecmp($r['Name'], $host) === 0) && ($type === null || $r['Type'] === $type)));
		return [ [ 'domain' => $domain, 'records' => $records, 'count' => count($records), 'emailType' => $emailType, 'usingNamecheapDns' => ($attrs['IsUsingOurDNS'] ?? null) ],
		    \util\Format::plural(count($records), 'record') . " of {$domain}" ];
	    case 'add':
	    case 'set':
		[ $host, $type ] = $this->namedRecord($domain);
		$new = self::record([ 'host' => $host, 'type' => $type, 'value' => $value('address'), 'ttl' => $text('ttl') !== '' ? $text('ttl') : self::TTL_DEFAULT, 'mxPref' => $text('mxPref') !== '' ? $text('mxPref') : 10 ], $domain);
		[ $records, $emailType ] = $this->hosts($sld, $tld);
		if ($operation === 'add') {
		    if (array_filter($records, fn($r) => self::sameAs($r, $new, $type)))
			return [ $this->writeHosts($domain, $sld, $tld, $records, $records, $emailType, [], []), "{$host} {$type} of {$domain} is there already" ];
		    //the same value with another TTL / priority: replaced, not doubled
		    $removed = array_values(array_filter($records, fn($r) => self::sameRecord($r, $new)));
		    $kept = array_values(array_filter($records, fn($r) => !self::sameRecord($r, $new)));
		    return [ $this->writeHosts($domain, $sld, $tld, $records, array_merge($kept, [ $new ]), $emailType, [ $new ], $removed), "{$host} {$type} {$new['Address']} added to {$domain}" ];
		}
		$theirs = array_values(array_filter($records, fn($r) => self::isHostType($r, $host, $type)));
		if (count($theirs) === 1 && self::sameAs($theirs[0], $new, $type))
		    return [ $this->writeHosts($domain, $sld, $tld, $records, $records, $emailType, [], []), "{$host} {$type} of {$domain} is {$new['Address']} already" ];
		$kept = array_values(array_filter($records, fn($r) => !self::isHostType($r, $host, $type)));
		return [ $this->writeHosts($domain, $sld, $tld, $records, array_merge($kept, [ $new ]), $emailType, [ $new ], $theirs), "{$host} {$type} of {$domain} set to {$new['Address']}" ];
	    case 'remove':
		[ $host, $type ] = $this->namedRecord($domain);
		$address = $text('address');
		[ $records, $emailType ] = $this->hosts($sld, $tld);
		$removed = array_values(array_filter($records, fn($r) => self::matchesRecord($r, $host, $type, $address)));
		$kept = array_values(array_filter($records, fn($r) => !self::matchesRecord($r, $host, $type, $address)));
		return [ $this->writeHosts($domain, $sld, $tld, $records, $kept, $emailType, [], $removed), \util\Format::plural(count($removed), 'record') . " removed from {$domain}" ];
	    case 'replace':
		$list = self::listValue($value('records'), 'Records');
		$new = [];
		foreach($list as $i => $r) {
		    if (!is_array($r))
			throw new \RuntimeException('Records: item ' . ($i + 1) . ': not an object');
		    $new[] = self::record($r, $domain, 'Records: item ' . ($i + 1));
		}
		if (!$new)
		    throw new \RuntimeException('Records: empty (a domain without records: remove them one by one)');
		[ $records, $emailType ] = $this->hosts($sld, $tld);
		$r = $this->writeHosts($domain, $sld, $tld, $records, $new, $emailType, $new, $records);
		return [ $r, \util\Format::plural(count($new), 'record') . " written to {$domain} (" . count($records) . ' before)' ];
	    case 'nameservers':
		$r = $this->call('namecheap.domains.dns.getList', [ 'SLD' => $sld, 'TLD' => $tld ])->DomainDNSGetListResult;
		$ns = [];
		foreach($r->Nameserver ?? [] as $el)
		    $ns[] = strtolower(trim((string)$el));
		$a = \util\Namecheap::attrs($r);
		return [ [ 'domain' => $domain, 'nameservers' => $ns, 'usingNamecheapDns' => $a['IsUsingOurDNS'] ?? null ], "{$domain}: " . implode(', ', $ns) ];
	    case 'setNameservers':
		$ns = [];
		foreach(self::names($value('nameservers'), [ 'name', 'Name', 'host' ]) as $n)
		    $ns[] = \util\Namecheap::domain($n, 'Name servers')[0];
		$ns = array_values(array_unique($ns));
		if (count($ns) < 2)
		    throw new \RuntimeException('Name servers: at least 2');
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.dns.setCustom', [ 'SLD' => $sld, 'TLD' => $tld, 'Nameservers' => $ns ])->DomainDNSSetCustomResult);
		if (($a['Updated'] ?? true) !== true)
		    throw new \RuntimeException("Namecheap did not change the name servers of {$domain}");
		return [ [ 'domain' => $domain, 'nameservers' => $ns ], "{$domain} on " . implode(', ', $ns) ];
	    case 'resetNameservers':
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.dns.setDefault', [ 'SLD' => $sld, 'TLD' => $tld ])->DomainDNSSetDefaultResult);
		if (($a['Updated'] ?? true) !== true)
		    throw new \RuntimeException("Namecheap did not change the name servers of {$domain}");
		return [ [ 'domain' => $domain, 'usingNamecheapDns' => true ], "{$domain} on Namecheap's name servers" ];
	    case 'forwarding':
		$items = [];
		foreach($this->call('namecheap.domains.dns.getEmailForwarding', [ 'DomainName' => $domain ])->DomainDNSGetEmailForwardingResult->Forward ?? [] as $el)
		    $items[] = [ 'mailbox' => (string)$el['mailbox'], 'forwardTo' => trim((string)$el) ];
		return [ [ 'domain' => $domain, 'forwards' => $items, 'count' => count($items) ], \util\Format::plural(count($items), 'forward') . " of {$domain}" ];
	    case 'setForwarding':
		$p = [ 'DomainName' => $domain ];
		foreach(self::listValue($value('forwards'), 'Forwarding') as $i => $f) {
		    $n = $i + 1;
		    $box = strtolower(trim((string)(is_array($f) ? ($f['mailbox'] ?? $f['MailBox'] ?? '') : '')));
		    $to = trim((string)(is_array($f) ? ($f['forwardTo'] ?? $f['ForwardTo'] ?? '') : ''));
		    if (!preg_match('/^[a-z0-9._+*-]{1,64}$/', $box))
			throw new \RuntimeException("Forwarding: item {$n}: not a mailbox: `" . mb_substr($box, 0, 64) . '`');
		    if (!filter_var($to, FILTER_VALIDATE_EMAIL))
			throw new \RuntimeException("Forwarding: item {$n}: not an email address: `" . mb_substr($to, 0, 64) . '`');
		    $p["MailBox{$n}"] = $box;
		    $p["ForwardTo{$n}"] = $to;
		}
		if (count($p) === 1)
		    throw new \RuntimeException('Forwarding: empty');
		$a = \util\Namecheap::attrs($this->call('namecheap.domains.dns.setEmailForwarding', $p)->DomainDNSSetEmailForwardingResult);
		if (($a['IsSuccess'] ?? true) !== true)
		    throw new \RuntimeException("Namecheap did not save the forwarding of {$domain}");
		$n = (count($p) - 1) / 2;
		return [ [ 'domain' => $domain, 'count' => $n ], \util\Format::plural($n, 'forward') . " set for {$domain}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //CLD: 2026-11-07T01:09:58+02:00: the settings Host and Type of a record of $domain: [ host, type ]
    private function namedRecord($domain) {
	return [ self::host($this->settingOf('host'), $domain), self::type($this->settingOf('type')) ];
    }

    //CLD: 2026-11-07T01:09:58+02:00: whether record $r is record $new, its TTL (and MX preference) too
    private static function sameAs($r, $new, $type) {
	return self::sameRecord($r, $new) && (int)$r['TTL'] === $new['TTL'] && ($type !== 'MX' || (int)($r['MXPref'] ?? 10) === $new['MXPref']);
    }

    //CLD: 2026-11-07T01:09:58+02:00: whether record $r is host $host's of type $type
    private static function isHostType($r, $host, $type) {
	return strcasecmp($r['Name'], $host) === 0 && $r['Type'] === $type;
    }

    //CLD: 2026-11-07T01:09:58+02:00: whether record $r is host $host's of type $type (and of address $address, when given)
    private static function matchesRecord($r, $host, $type, $address) {
	return strcasecmp($r['Name'], $host) === 0 && $r['Type'] === $type && ($address === '' || self::sameRecord($r, [ 'Name' => $host, 'Type' => $type, 'Address' => $address ]));
    }
}
