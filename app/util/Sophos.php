<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new: the client of the Sophos Firewall XML API (SFOS 18 to 22 and later), for the Sophos Firewall credential and steps.
    POST https://<firewall>:4444/webconsole/APIController, the form field `reqxml`: <Request><Login><Username/><Password/></Login> <Get> | <Set operation="add|update"> | <Remove> </Request>
    (the API turned on, and Rail22's address allowed, under Backup & firmware > API). The answer: <Response APIVersion="..."> <Login><status>…</status></Login> <Status code="…"> (a
    request refused) and an element per entity: its fields, or <Status code="2xx|5xx">text</Status> (a change), or <Status>Number of records Zero.</Status> (an empty read).
    A Login status "Authentication Failure", a code that is not 2xx: a \RuntimeException (532 the API off, 534 this address not allowed: said what to do).
    Entities as arrays: an element with children an array (a repeated child a list), else its text; written back the same way (a list: the element repeated).
    Public addresses only, unless the account allows private network addresses; the certificate checked unless the credential trusts it (the firewall's own is self-signed).

    $s = new Sophos($cred, $aid, $timeout);
    $s->get($tag, $filter)                 the entities ([] when none); $filter [ key, criteria (= != like), value ]
    $s->one($tag, $name)                   the entity named $name, or null
    $s->set($operation, $tag, $entity)     add / update: the firewall's message
    $s->remove($tag, $name)                the firewall's message
    $s->send($xml)                         the elements of <Request> after <Login> as given: the whole answer as an array (the API step)

    Sophos::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Sophos {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PATH = '/webconsole/APIController';
    //the request codes said with what to do
    const CODES = [
	'532' => 'the API is off: turn it on under Backup & firmware > API',
	'534' => 'this address is not allowed: add Rail22\'s address to Allowed IP address under Backup & firmware > API',
    ];
    //the key that names an entity (Name, but a user's is Username)
    const NAME_KEYS = [ 'User' => 'Username' ];

    public static $testing = false;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the firewall's address as given, without a trailing / and without a /webconsole/... pasted after it
    public static function baseUrl($url) {
	return preg_replace('~/webconsole(/.*)?$~i', '', rtrim(trim((string)$url), '/'));
    }

    public static function nameKey($tag) {
	return self::NAME_KEYS[$tag] ?? 'Name';
    }

    public static function esc($v) {
	return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    //an element name (an entity, a field) as the API has them
    public static function tag($tag) {
	$tag = (string)$tag;
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/', $tag))
	    throw new \RuntimeException("not an element name of the API: `" . mb_substr($tag, 0, 64) . '`');
	return $tag;
    }

    //$value as the element $name: an array with keys its children, a list the element repeated, else its text (true / false: Enable / Disable)
    public static function toXml($name, $value) {
	$name = self::tag($name);
	if (is_array($value) && array_is_list($value) && $value)
	    return implode('', array_map(fn($v) => self::toXml($name, $v), $value));
	if (is_array($value)) {
	    $inner = '';
	    foreach($value as $k => $v)
		$inner .= self::toXml($k, $v);
	    return "<{$name}>{$inner}</{$name}>";
	}
	if ($value === null)
	    return "<{$name}/>";
	if (is_bool($value))
	    $value = $value ? 'Enable' : 'Disable';
	return "<{$name}>" . self::esc($value) . "</{$name}>";
    }

    //an element as an array (children by name, repeated ones a list), or its trimmed text; attributes left out
    public static function toArray(\SimpleXMLElement $el) {
	$children = $el->children();
	if (count($children) === 0)
	    return trim((string)$el);
	$a = [];
	foreach($children as $name => $child) {
	    $v = self::toArray($child);
	    if (!array_key_exists($name, $a))
		$a[$name] = $v;
	    elseif (is_array($a[$name]) && array_is_list($a[$name]) && $a[$name] !== [])
		$a[$name][] = $v;
	    else
		$a[$name] = [ $a[$name], $v ];
	}
	return $a;
    }

    //a field that may hold one value or several (<HostList><Host>…</Host>…</HostList> read back): always a list, '' and null none
    public static function items($v) {
	if ($v === null || $v === '' || $v === [])
	    return [];
	return is_array($v) && array_is_list($v) ? $v : [ $v ];
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    //a request ($xml: what follows <Login>): the answer's root element; the login and the request's own status checked
    protected function post($xml) {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$req = '<Request><Login><Username>' . self::esc($this->cred->username) . '</Username><Password>' . self::esc($this->cred->password) . '</Password></Login>' . $xml . '</Request>';
	$base = self::baseUrl($this->cred->serverUrl);
	$r = Http::request('POST', $base . self::PATH, [ 'form' => [ 'reqxml' => $req ], 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($base) . ": {$r['error']}");
	if ($r['status'] >= 400)
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($r['status'] === 404 ? ': no XML API at this address (is it the administration port, usually 4444?)' : ''));
	$body = (string)$r['body'];
	if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false)
	    throw new \RuntimeException('the answer is not the Sophos Firewall API\'s');
	$prev = libxml_use_internal_errors(true);
	$root = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($root === false || $root->getName() !== 'Response')
	    throw new \RuntimeException('the answer is not the Sophos Firewall API\'s (is the address right?)');
	$login = isset($root->Login) ? trim((string)($root->Login->status ?? '')) : '';
	if ($login !== '' && stripos($login, 'Success') === false)
	    throw new \RuntimeException("{$login}: the user name or password is refused");
	if (isset($root->Status))
	    self::checkStatus($root->Status, true);
	return $root;
    }

    //a <Status>: a code that is not 2xx thrown (532, 534 said what to do); returns [ code, text ]
    public static function checkStatus(\SimpleXMLElement $status, $request = false) {
	$code = trim((string)($status['code'] ?? ''));
	$text = trim((string)$status);
	if ($code !== '' && $code[0] !== '2')
	    throw new \RuntimeException("status {$code}: " . (self::CODES[$code] ?? ($text !== '' ? mb_substr($text, 0, 300) : 'refused')));
	if ($request && $code === '' && $text !== '' && stripos($text, 'records Zero') === false)
	    throw new \RuntimeException(mb_substr($text, 0, 300));
	return [ $code, $text ];
    }

    //the entities $tag of the answer: [ entities, messages ] (an entity with a Status: its message; "Number of records Zero.": none)
    protected static function entities(\SimpleXMLElement $root, $tag) {
	$list = [];
	$messages = [];
	foreach($root->children() as $name => $el) {
	    if ($name !== $tag)
		continue;
	    if (isset($el->Status) && count($el->children()) === 1) {
		[ , $text ] = self::checkStatus($el->Status);
		if (stripos($text, 'records Zero') === false)
		    $messages[] = $text;
		continue;
	    }
	    $list[] = self::toArray($el);
	}
	return [ $list, $messages ];
    }

    public function get($tag, array $filter = null) {
	$tag = self::tag($tag);
	$f = '';
	if ($filter) {
	    [ $key, $criteria, $value ] = $filter;
	    if (!in_array($criteria, [ '=', '!=', 'like' ], true))
		throw new \RuntimeException("not a filter criteria: `{$criteria}`");
	    $f = '<Filter><key name="' . self::esc(self::tag($key)) . '" criteria="' . self::esc($criteria) . '">' . self::esc($value) . '</key></Filter>';
	}
	return self::entities($this->post("<Get><{$tag}>{$f}</{$tag}></Get>"), $tag)[0];
    }

    public function one($tag, $name) {
	$key = self::nameKey($tag);
	foreach($this->get($tag, [ $key, '=', (string)$name ]) as $e) {
	    if ((string)($e[$key] ?? '') === (string)$name)
		return $e;
	}
	return null;
    }

    public function set($operation, $tag, array $entity) {
	if (!in_array($operation, [ 'add', 'update' ], true))
	    throw new \RuntimeException("not a Set operation: `{$operation}`");
	$tag = self::tag($tag);
	[ , $messages ] = self::entities($this->post('<Set operation="' . $operation . '">' . self::toXml($tag, $entity) . '</Set>'), $tag);
	return $messages[0] ?? 'done';
    }

    public function remove($tag, $name) {
	$tag = self::tag($tag);
	[ , $messages ] = self::entities($this->post("<Remove><{$tag}>" . self::toXml(self::nameKey($tag), (string)$name) . "</{$tag}></Remove>"), $tag);
	return $messages[0] ?? 'done';
    }

    //any request (its elements after <Login>): the answer as an array, its APIVersion as `APIVersion`; an entity's error status thrown
    public function send($xml) {
	$xml = trim((string)$xml);
	if ($xml === '' || preg_match('~<\s*/?\s*(Login|Request|!DOCTYPE|!ENTITY)\b~i', $xml))
	    throw new \RuntimeException('the request: the elements inside <Request> after <Login>, such as <Get><IPHost/></Get>');
	$prev = libxml_use_internal_errors(true);
	$ok = simplexml_load_string('<Request>' . $xml . '</Request>', \SimpleXMLElement::class, LIBXML_NONET) !== false;
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if (!$ok)
	    throw new \RuntimeException('the request is not well-formed XML');
	$root = $this->post($xml);
	foreach($root->children() as $el) {
	    if (isset($el->Status))
		self::checkStatus($el->Status);
	}
	$a = self::toArray($root);
	$a = is_array($a) ? $a : [];
	unset($a['Login']);
	return [ 'APIVersion' => (string)($root['APIVersion'] ?? '') ] + $a;
    }

    //the API version of the firewall (the answer's APIVersion) and its hostname (AdminSettings), for the credential's test
    public function about() {
	$root = $this->post('<Get><AdminSettings></AdminSettings></Get>');
	[ $list ] = self::entities($root, 'AdminSettings');
	return [ 'apiVersion' => (string)($root['APIVersion'] ?? ''), 'hostname' => (string)($list[0]['HostnameSettings']['HostName'] ?? '') ];
    }
}
