<?php

/*
    a fake Namecheap API for the check suite (CheckCommand::suiteNamecheap), run by PHP's built-in server:
    NC_STATE=<a folder> php -S 127.0.0.1:<port> NamecheapTestServer.php
	POST /xml.response, the form: ApiUser nfuser, ApiKey k3y, ClientIp 203.0.113.10 (others: Namecheap's errors 1011102 / 1011150), Command and its parameters; answers as Namecheap does,
	    <ApiResponse Status="OK|ERROR" xmlns="http://api.namecheap.com/xml.response">
	<state>/domains.json: [ { Name, Expires (MM/DD/YYYY), ... } ]; <state>/hosts.json: { domain: { emailType, records: [ { Name, Type, Address, TTL, MXPref } ] } };
	<state>/forwards.json, <state>/ns.json: as Namecheap keeps them. setHosts refuses MX records without EmailType MX (as Namecheap does)
	Each request appended to <state>/requests.jsonl (method, form)
*/

$state = getenv('NC_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$f = $_POST;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'form' => $f ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: text/xml');

$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$attrs = function(array $a) use ($x) {
    $s = '';
    foreach($a as $k => $v)
	$s .= ' ' . $k . '="' . $x(is_bool($v) ? ($v ? 'true' : 'false') : $v) . '"';
    return $s;
};
$command = (string)($f['Command'] ?? '');
$answer = function($inner, $type = null) use ($command, $x) {
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><Warnings /><RequestedCommand>' . $x(strtolower($command))
	. '</RequestedCommand><CommandResponse Type="' . $x($type ?? $command) . '">' . $inner . '</CommandResponse><Server>TEST</Server><GMTTimeDifference>--5:00</GMTTimeDifference><ExecutionTime>0.01</ExecutionTime></ApiResponse>';
    exit;
};
$error = function($number, $message) use ($command, $x) {
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="' . $x($number) . '">' . $x($message)
	. '</Error></Errors><Warnings /><RequestedCommand>' . $x(strtolower($command)) . '</RequestedCommand><Server>TEST</Server><GMTTimeDifference>--5:00</GMTTimeDifference><ExecutionTime>0</ExecutionTime></ApiResponse>';
    exit;
};

if ($method !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/xml.response') {
    http_response_code(404);
    echo 'not found';
    exit;
}
if (($f['ApiUser'] ?? '') !== 'nfuser')
    $error('1011101', 'Parameter ApiUser is invalid');
if (($f['ApiKey'] ?? '') !== 'k3y')
    $error('1011102', 'Parameter APIKey is invalid');
if (($f['ClientIp'] ?? '') !== '203.0.113.10')
    $error('1011150', 'Parameter RequestIP is invalid');
if (($f['UserName'] ?? '') !== 'nfuser')
    $error('1011104', 'Parameter UserName is invalid');

$domains = $load('domains');
$find = function($name) use ($domains, $error) {
    foreach($domains as $d) {
	if (strcasecmp($d['Name'], (string)$name) === 0)
	    return $d;
    }
    $error('2019166', 'Domain not found');
};
$sldTld = function() use ($f, $find) {
    $name = strtolower(($f['SLD'] ?? '') . '.' . ($f['TLD'] ?? ''));
    $find($name);
    return $name;
};

switch(strtolower($command)) {
    case 'namecheap.users.getbalances':
	$answer('<UserGetBalancesResult Currency="USD" AvailableBalance="42.50" AccountBalance="42.50" EarnedAmount="0.00" WithdrawableAmount="0.00" FundsRequiredForAutoRenew="10.98" />');
    case 'namecheap.domains.getlist':
	$list = $domains;
	$type = strtoupper($f['ListType'] ?? 'ALL');
	if ($type === 'EXPIRED')
	    $list = array_filter($list, fn($d) => strtotime($d['Expires']) < time());
	if (($f['SearchTerm'] ?? '') !== '')
	    $list = array_filter($list, fn($d) => str_contains($d['Name'], $f['SearchTerm']));
	$sort = strtoupper($f['SortBy'] ?? 'NAME');
	usort($list, fn($a, $b) => str_starts_with($sort, 'EXPIREDATE') ? strtotime($a['Expires']) <=> strtotime($b['Expires']) : strcmp($a['Name'], $b['Name']));
	if (str_ends_with($sort, '_DESC'))
	    $list = array_reverse($list);
	$size = (int)($f['PageSize'] ?? 20);
	if ($size < 10 || $size > 100)
	    $error('2011333', 'PageSize should be between 10 and 100');
	$page = max(1, (int)($f['Page'] ?? 1));
	$rows = '';
	foreach(array_slice(array_values($list), ($page - 1) * $size, $size) as $d)
	    $rows .= '<Domain' . $attrs($d + [ 'ID' => crc32($d['Name']), 'User' => 'nfuser', 'Created' => '01/01/2020', 'IsExpired' => false, 'IsLocked' => false, 'AutoRenew' => false, 'WhoisGuard' => 'ENABLED', 'IsPremium' => false, 'IsOurDNS' => true ]) . ' />';
	$answer("<DomainGetListResult>{$rows}</DomainGetListResult><Paging><TotalItems>" . count($list) . "</TotalItems><CurrentPage>{$page}</CurrentPage><PageSize>{$size}</PageSize></Paging>");
    case 'namecheap.domains.getinfo':
	$d = $find($f['DomainName'] ?? '');
	$answer('<DomainGetInfoResult Status="Ok" ID="1" DomainName="' . $x($d['Name']) . '" OwnerName="nfuser" IsOwner="true" IsPremium="false"><DomainDetails><CreatedDate>01/01/2020</CreatedDate><ExpiredDate>'
	    . $x($d['Expires']) . '</ExpiredDate><NumYears>0</NumYears></DomainDetails><Whoisguard Enabled="True"><ID>1</ID></Whoisguard><DnsDetails ProviderType="FREE" IsUsingOurDNS="true"><Nameserver>dns1.registrar-servers.com</Nameserver><Nameserver>dns2.registrar-servers.com</Nameserver></DnsDetails></DomainGetInfoResult>');
    case 'namecheap.domains.check':
	$rows = '';
	foreach(explode(',', (string)($f['DomainList'] ?? '')) as $n) {
	    $taken = str_starts_with($n, 'taken') || array_filter($domains, fn($d) => $d['Name'] === $n);
	    $rows .= '<DomainCheckResult' . $attrs([ 'Domain' => $n, 'Available' => !$taken, 'ErrorNo' => '0', 'Description' => '', 'IsPremiumName' => false, 'PremiumRegistrationPrice' => '0' ]) . ' />';
	}
	$answer($rows);
    case 'namecheap.domains.getcontacts':
	$d = $find($f['DomainName'] ?? '');
	$answer('<DomainContactsResult Domain="' . $x($d['Name']) . '" domainnameid="1"><Registrant ReadOnly="false"><OrganizationName>Example SRL</OrganizationName><FirstName>Ana</FirstName><EmailAddress>ana@example.com</EmailAddress></Registrant></DomainContactsResult>');
    case 'namecheap.domains.getregistrarlock':
	$d = $find($f['DomainName'] ?? '');
	$answer('<DomainGetRegistrarLockResult Domain="' . $x($d['Name']) . '" RegistrarLockStatus="' . (!empty($load('locks')[$d['Name']]) ? 'true' : 'false') . '" />');
    case 'namecheap.domains.setregistrarlock':
	$d = $find($f['DomainName'] ?? '');
	$locks = $load('locks');
	$locks[$d['Name']] = strtoupper($f['LockAction'] ?? 'LOCK') === 'LOCK';
	$save('locks', $locks);
	$answer('<DomainSetRegistrarLockResult Domain="' . $x($d['Name']) . '" IsSuccess="true" />');
    case 'namecheap.domains.renew':
	$d = $find($f['DomainName'] ?? '');
	$years = (int)($f['Years'] ?? 1);
	$answer('<DomainRenewResult' . $attrs([ 'DomainName' => $d['Name'], 'DomainID' => 1, 'Renew' => true, 'OrderID' => 77, 'TransactionID' => 88, 'ChargedAmount' => number_format(10.98 * $years, 2, '.', '') ]) . '><DomainDetails><ExpiredDate>01/01/2030</ExpiredDate></DomainDetails></DomainRenewResult>');
    case 'namecheap.domains.gettldlist':
	$answer('<Tlds><Tld Name="com" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="10" IsApiRegisterable="true" IsApiRenewable="true">Most recognized top level domain</Tld><Tld Name="ro" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="1" IsApiRegisterable="true" IsApiRenewable="true">Romania</Tld><Tld Name="co.uk" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="10" IsApiRegisterable="true" IsApiRenewable="true">United Kingdom</Tld></Tlds>');
    case 'namecheap.users.getpricing':
	if (strtoupper($f['ProductType'] ?? '') !== 'DOMAIN')
	    $error('2011170', 'ProductType is invalid');
	$tld = strtolower($f['ProductName'] ?? '');
	$action = strtolower($f['ActionName'] ?? 'register');
	if ($tld === 'nope')
	    $answer('<UserGetPricingResult />');
	$answer('<UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="' . $x($action) . '"><Product Name="' . $x($tld) . '"><Price Duration="1" DurationType="YEAR" Price="10.98" RegularPrice="12.98" YourPrice="10.98" Currency="USD" /><Price Duration="2" DurationType="YEAR" Price="21.96" RegularPrice="25.96" YourPrice="21.96" Currency="USD" /></Product></ProductCategory></ProductType></UserGetPricingResult>');
    case 'namecheap.ssl.getlist':
	$answer('<SSLListResult><SSL CertificateID="11" HostName="www.example.com" SSLType="PositiveSSL" PurchaseDate="01/01/2026" ExpireDate="01/01/2027" IsExpiredYN="false" Status="active" /></SSLListResult><Paging><TotalItems>1</TotalItems><CurrentPage>1</CurrentPage><PageSize>100</PageSize></Paging>');
    case 'namecheap.domains.dns.gethosts':
	$name = $sldTld();
	$h = $load('hosts')[$name] ?? [ 'emailType' => 'NONE', 'records' => [] ];
	$rows = '';
	foreach($h['records'] as $i => $r)
	    $rows .= '<host' . $attrs([ 'HostId' => 100 + $i, 'Name' => $r['Name'], 'Type' => $r['Type'], 'Address' => $r['Address'], 'MXPref' => $r['MXPref'] ?? 10, 'TTL' => $r['TTL'], 'AssociatedAppTitle' => '', 'FriendlyName' => '', 'IsActive' => true, 'IsDDNSEnabled' => false ]) . ' />';
	$answer('<DomainDNSGetHostsResult Domain="' . $x($name) . '" EmailType="' . $x($h['emailType']) . '" IsUsingOurDNS="true">' . $rows . '</DomainDNSGetHostsResult>');
    case 'namecheap.domains.dns.sethosts':
	$name = $sldTld();
	$records = [];
	for ($i = 1; isset($f["HostName{$i}"]); $i++) {
	    $r = [ 'Name' => $f["HostName{$i}"], 'Type' => $f["RecordType{$i}"] ?? '', 'Address' => $f["Address{$i}"] ?? '', 'TTL' => (int)($f["TTL{$i}"] ?? 1800) ];
	    if ($r['Type'] === 'MX')
		$r['MXPref'] = (int)($f["MXPref{$i}"] ?? 10);
	    $records[] = $r;
	}
	$hosts = $load('hosts');
	$emailType = $f['EmailType'] ?? ($hosts[$name]['emailType'] ?? 'NONE');
	if (in_array('MX', array_column($records, 'Type'), true) && $emailType !== 'MX')
	    $error('2050900', 'Unknown error when adding host: MX records need EmailType MX');
	$hosts[$name] = [ 'emailType' => $emailType, 'records' => $records ];
	$save('hosts', $hosts);
	$answer('<DomainDNSSetHostsResult Domain="' . $x($name) . '" IsSuccess="true"><Warnings /></DomainDNSSetHostsResult>');
    case 'namecheap.domains.dns.getlist':
	$name = $sldTld();
	$ns = $load('ns')[$name] ?? null;
	$list = $ns ?? [ 'dns1.registrar-servers.com', 'dns2.registrar-servers.com' ];
	$answer('<DomainDNSGetListResult Domain="' . $x($name) . '" IsUsingOurDNS="' . ($ns === null ? 'true' : 'false') . '" IsPremiumDNS="false" IsUsingFreeDNS="false">'
	    . implode('', array_map(fn($n) => '<Nameserver>' . $x($n) . '</Nameserver>', $list)) . '</DomainDNSGetListResult>');
    case 'namecheap.domains.dns.setcustom':
	$name = $sldTld();
	$ns = $load('ns');
	$ns[$name] = explode(',', (string)($f['Nameservers'] ?? ''));
	$save('ns', $ns);
	$answer('<DomainDNSSetCustomResult Domain="' . $x($name) . '" Updated="true" />');
    case 'namecheap.domains.dns.setdefault':
	$name = $sldTld();
	$ns = $load('ns');
	unset($ns[$name]);
	$save('ns', $ns);
	$answer('<DomainDNSSetDefaultResult Domain="' . $x($name) . '" Updated="true" />');
    case 'namecheap.domains.dns.getemailforwarding':
	$d = $find($f['DomainName'] ?? '');
	$rows = '';
	foreach($load('forwards')[$d['Name']] ?? [] as $fw)
	    $rows .= '<Forward mailbox="' . $x($fw[0]) . '">' . $x($fw[1]) . '</Forward>';
	$answer('<DomainDNSGetEmailForwardingResult Domain="' . $x($d['Name']) . '">' . $rows . '</DomainDNSGetEmailForwardingResult>');
    case 'namecheap.domains.dns.setemailforwarding':
	$d = $find($f['DomainName'] ?? '');
	$list = [];
	for ($i = 1; isset($f["MailBox{$i}"]); $i++)
	    $list[] = [ $f["MailBox{$i}"], $f["ForwardTo{$i}"] ?? '' ];
	$fw = $load('forwards');
	$fw[$d['Name']] = $list;
	$save('forwards', $fw);
	$answer('<DomainDNSSetEmailForwardingResult Domain="' . $x($d['Name']) . '" IsSuccess="true" />');
    case 'namecheap.domains.ns.getinfo':
	$answer('<DomainNSInfoResult Domain="' . $x(($f['SLD'] ?? '') . '.' . ($f['TLD'] ?? '')) . '" Nameserver="' . $x($f['Nameserver'] ?? '') . '" IP="198.51.100.7"><NameserverStatuses><Status>OK</Status><Status>Linked</Status></NameserverStatuses></DomainNSInfoResult>');
}
$error('1010000', "Command {$command} not found");
