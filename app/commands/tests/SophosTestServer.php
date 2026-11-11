<?php

/*
    a fake Sophos Firewall (the XML API of SFOS 20) for the check suite (CheckCommand::suiteSophos), run by PHP's built-in server:
    SFOS_STATE=<a folder> php -S 127.0.0.1:<port> SophosTestServer.php
	POST /webconsole/APIController, the form field reqxml; the login admin / sfpass (else <Login><status>Authentication Failure</status></Login>)
	each request appended to <state>/requests.jsonl ([ operation, entity, name ] of each element, the password left out)
	<Get><Tag><Filter><key name criteria="= | like">..</key></Filter></Tag></Get>: the entities, or <Status>Number of records Zero.</Status>
	<Set operation="add">: 200, or 502 when one of that name exists; operation="update": 200, or 541 when there is none; <Remove>: 200, or 541
	the entities in <state>/objects.json (IPHost, IPHostGroup, FQDNHost, FirewallRule, User, WebFilterURLGroup, AdminSettings, Interface, Zone, ...)
*/

$state = getenv('SFOS_STATE') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: text/xml');
if ($path !== '/webconsole/APIController' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
$req = simplexml_load_string((string)($_POST['reqxml'] ?? ''));
if ($req === false) {
    echo '<?xml version="1.0" encoding="UTF-8"?><Response APIVersion="2000.1"><Status code="529">Input request file is not in proper format.</Status></Response>';
    exit;
}

$out = '<?xml version="1.0" encoding="UTF-8"?><Response APIVersion="2000.1" IPS_CAT_VER="1">';
if ((string)$req->Login->Username !== 'admin' || (string)$req->Login->Password !== 'sfpass') {
    echo $out . '<Login><status>Authentication Failure</status></Login></Response>';
    exit;
}
$out .= '<Login><status>Authentication Successful</status></Login>';

$esc = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$toArray = function($el) use (&$toArray) {
    if (count($el->children()) === 0)
	return trim((string)$el);
    $a = [];
    foreach($el->children() as $n => $c) {
	$v = $toArray($c);
	if (!array_key_exists($n, $a))
	    $a[$n] = $v;
	elseif (is_array($a[$n]) && array_is_list($a[$n]))
	    $a[$n][] = $v;
	else
	    $a[$n] = [ $a[$n], $v ];
    }
    return $a;
};
$toXml = function($n, $v) use (&$toXml, $esc) {
    if (is_array($v) && array_is_list($v) && $v)
	return implode('', array_map(fn($x) => $toXml($n, $x), $v));
    if (is_array($v))
	return "<{$n}>" . implode('', array_map(fn($k) => $toXml($k, $v[$k]), array_keys($v))) . "</{$n}>";
    return "<{$n}>" . $esc($v) . "</{$n}>";
};
$nameKey = fn($tag) => $tag === 'User' ? 'Username' : 'Name';
$status = fn($tag, $code, $text) => "<{$tag} transactionid=\"\"><Status code=\"{$code}\">{$text}</Status></{$tag}>";

$objects = is_file("{$state}/objects.json") ? json_decode(file_get_contents("{$state}/objects.json"), true) : [
    'AdminSettings' => [ 'x' => [ 'HostnameSettings' => [ 'HostName' => 'SFW-HQ', 'HostnameDesc' => '' ], 'WebAdminSettings' => [ 'HTTPSport' => '4444' ] ] ],
    'IPHost' => [ 'r22-block-203.0.113.9' => [ 'Name' => 'r22-block-203.0.113.9', 'IPFamily' => 'IPv4', 'HostType' => 'IP', 'IPAddress' => '203.0.113.9' ],
	'Web server' => [ 'Name' => 'Web server', 'IPFamily' => 'IPv4', 'HostType' => 'IP', 'IPAddress' => '10.0.0.80' ] ],
    'IPHostGroup' => [ 'Blocked' => [ 'Name' => 'Blocked', 'Description' => '', 'IPFamily' => 'IPv4' ], 'Blocked6' => [ 'Name' => 'Blocked6', 'Description' => '', 'IPFamily' => 'IPv6' ] ],
    'FirewallRule' => [ 'Drop blocked' => [ 'Name' => 'Drop blocked', 'Description' => '', 'IPFamily' => 'IPv4', 'Status' => 'Enable', 'Position' => 'Top', 'PolicyType' => 'Network',
	'NetworkPolicy' => [ 'Action' => 'Drop', 'SourceNetworks' => [ 'Network' => 'Blocked' ] ] ] ],
    'User' => [ 'jdoe' => [ 'Username' => 'jdoe', 'Name' => 'John Doe', 'Status' => 'Active', 'Group' => 'Open Group' ] ],
    'WebFilterURLGroup' => [ 'Blocked sites' => [ 'Name' => 'Blocked sites', 'Description' => '', 'URLlist' => [ 'URL' => 'old.example.com' ] ] ],
    'Zone' => [ 'LAN' => [ 'Name' => 'LAN', 'Type' => 'LAN' ], 'WAN' => [ 'Name' => 'WAN', 'Type' => 'WAN' ] ],
];
$log = [];
foreach($req->children() as $op => $block) {
    if ($op === 'Login')
	continue;
    foreach($block->children() as $tag => $el) {
	$key = $nameKey($tag);
	if ($op === 'Get') {
	    $list = array_values($objects[$tag] ?? []);
	    if (isset($el->Filter->key)) {
		$k = (string)$el->Filter->key['name'];
		$c = (string)$el->Filter->key['criteria'];
		$v = (string)$el->Filter->key;
		$list = array_values(array_filter($list, fn($o) => $c === 'like' ? stripos((string)($o[$k] ?? ''), $v) !== false : ($c === '=' ? (string)($o[$k] ?? '') === $v : (string)($o[$k] ?? '') !== $v)));
	    }
	    $log[] = [ 'Get', $tag, isset($el->Filter->key) ? (string)$el->Filter->key : null ];
	    $out .= $list ? implode('', array_map(fn($o) => str_replace("<{$tag}>", "<{$tag} transactionid=\"\">", $toXml($tag, $o)), $list))
		: "<{$tag}><Status>Number of records Zero.</Status></{$tag}>";
	} elseif ($op === 'Set') {
	    $o = $toArray($el);
	    $name = (string)($o[$key] ?? '');
	    $mode = (string)($block['operation'] ?? 'add');
	    $log[] = [ "Set {$mode}", $tag, $name, $o ];
	    if ($mode === 'add' && isset($objects[$tag][$name]))
		$out .= $status($tag, 502, 'Operation failed. Entity having same name already exists.');
	    elseif ($mode === 'update' && !isset($objects[$tag][$name]))
		$out .= $status($tag, 541, 'Operation failed. Entity not found.');
	    else {
		$objects[$tag][$name] = $o;
		$out .= $status($tag, 200, 'Configuration applied successfully.');
	    }
	} elseif ($op === 'Remove') {
	    $name = (string)$el->$key;
	    $log[] = [ 'Remove', $tag, $name ];
	    if (!isset($objects[$tag][$name]))
		$out .= $status($tag, 541, 'Operation failed. Entity not found.');
	    else {
		unset($objects[$tag][$name]);
		$out .= $status($tag, 200, 'Configuration applied successfully.');
	    }
	}
    }
}
file_put_contents("{$state}/objects.json", json_encode($objects));
foreach($log as $l)
    file_put_contents("{$state}/requests.jsonl", json_encode($l, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
echo $out . '</Response>';
