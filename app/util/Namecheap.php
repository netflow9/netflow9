<?php

/*
    the client of the Namecheap API, for the Namecheap credential and steps. One address (https://api.namecheap.com/xml.response, or the
    sandbox's), one request per command: the form fields ApiUser, ApiKey, UserName, ClientIp, Command and the command's own; sent as a POST (the key stays out of the address,
    and setHosts can carry many records). The answer is XML: <ApiResponse Status="OK|ERROR"> <Errors><Error Number="…">…</Error></Errors> <CommandResponse>…</CommandResponse>.
    Namecheap answers only requests from an address whitelisted for the API user (Profile > Tools > API Access): ClientIp must be it.
    Limits of Namecheap: 20 commands a minute, 700 an hour, 8000 a day (per user); over them it answers with an error

    $n = new Namecheap($cred, $aid, $timeout);
    $n->call($command, $params)          the <CommandResponse> element; \RuntimeException with Namecheap's errors (their numbers) or no answer
    Namecheap::toArray($el)              an element as an array: its attributes (true / false as booleans), children by name (repeated ones a list), its text as `value`
    Namecheap::attrs($el)                its attributes only
    Namecheap::domain($v)                [ domain, sld, tld ] of a registered domain's name (example.co.uk: example, co.uk)

    Namecheap::$testUrl: the check suite's local server (http://127.0.0.1:…/xml.response): no https, no address guard
*/

namespace util;

class Namecheap {
    const PRODUCTION = 'https://api.namecheap.com/xml.response';
    const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';
    const MAX_ANSWER = 10 * 1024 * 1024;
    //a page of a list (Namecheap's largest)
    const PAGE = 100;
    const COMMAND = '~^namecheap(\.[A-Za-z]+){2,4}$~';
    //what Namecheap's errors about the caller mean
    const HINTS = [
	'1011102' => 'the API key is wrong, or API access is not enabled for the user',
	'1011150' => 'the credential\'s client IP is not whitelisted for the API user (Namecheap: Profile > Tools > API Access)',
	'1010101' => 'the API user is wrong',
	'1011101' => 'the API user is wrong',
    ];

    public static $testUrl = null;

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

    public function url() {
	return self::$testUrl ?? ($this->cred->sandbox ? self::SANDBOX : self::PRODUCTION);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //the command's answer: its <CommandResponse>; Namecheap's errors as a \RuntimeException
    public function call($command, array $params = []) {
	$command = trim((string)$command);
	if (!preg_match(self::COMMAND, $command))
	    throw new \RuntimeException("not a Namecheap command: `" . mb_substr($command, 0, 64) . '`');
	$user = trim((string)$this->cred->apiUser);
	$form = [];
	foreach($params as $k => $v) {
	    if ($v === null)
		continue;
	    $form[(string)$k] = is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(',', array_map('strval', $v)) : (string)$v);
	}
	//the caller's own fields cannot be changed by the parameters
	$form = [ 'ApiUser' => $user, 'ApiKey' => (string)$this->cred->apiKey, 'UserName' => trim((string)$this->cred->username) ?: $user,
	    'ClientIp' => trim((string)$this->cred->clientIp), 'Command' => $command ] + array_diff_key($form, array_flip([ 'ApiUser', 'ApiKey', 'UserName', 'ClientIp', 'Command' ]));
	$testing = self::$testUrl !== null;
	$r = Http::request('POST', $this->url(), [ 'form' => $form, 'timeout' => $this->left(), 'maxBytes' => self::MAX_ANSWER, 'https' => !$testing, 'public' => !$testing ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from Namecheap: ' . $r['error']);
	$root = self::parse($r['body']);
	if ($root === null)
	    throw new \RuntimeException("Namecheap's answer is not its XML (HTTP status {$r['status']})");
	if (strtoupper((string)$root['Status']) !== 'OK')
	    throw new \RuntimeException(self::errors($root) ?: 'Namecheap refused the command');
	$response = $root->CommandResponse;
	if (!$response || !$response->getName())
	    throw new \RuntimeException('Namecheap\'s answer has no CommandResponse');
	return $response;
    }

    public static function parse($xml) {
	$xml = (string)$xml;
	if (trim($xml) === '' || preg_match('~<!(DOCTYPE|ENTITY)~i', $xml))
	    return null;
	//the default namespace of the answer (http://api.namecheap.com/xml.response) dropped, so the children are found by name
	$prev = libxml_use_internal_errors(true);
	$root = simplexml_load_string(preg_replace('~\sxmlns="[^"]*"~', '', $xml, 1), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	return $root === false || $root->getName() !== 'ApiResponse' ? null : $root;
    }

    //the errors of an answer: "<message> (error <number>)[: what it means]", separated by "; "
    public static function errors(\SimpleXMLElement $root) {
	$list = [];
	foreach($root->Errors->Error ?? [] as $e) {
	    $n = trim((string)$e['Number']);
	    $list[] = trim((string)$e) . ($n !== '' ? " (error {$n})" : '') . (isset(self::HINTS[$n]) ? ': ' . self::HINTS[$n] : '');
	}
	return mb_substr(implode('; ', array_slice($list, 0, 5)), 0, 1000);
    }

    protected static function scalar($v) {
	$v = (string)$v;
	return [ 'true' => true, 'false' => false ][strtolower($v)] ?? $v;
    }

    public static function attrs(?\SimpleXMLElement $el) {
	$a = [];
	foreach($el ? $el->attributes() : [] as $k => $v)
	    $a[$k] = self::scalar($v);
	return $a;
    }

    //an element: its attributes, its children by name (repeated ones a list), its text as `value` (only text: the text itself)
    public static function toArray(\SimpleXMLElement $el) {
	$a = self::attrs($el);
	$children = $el->children();
	$text = trim((string)$el);
	if (count($children) === 0)
	    return $a ? ($text !== '' ? $a + [ 'value' => $text ] : $a) : self::scalar($text);
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

    //a registered domain's name, as Namecheap takes it: [ domain, sld, tld ] (a name, a domain of a list or an answer: { Name | Domain | DomainName })
    public static function domain($value, $label = 'Domain') {
	if (is_array($value))
	    $value = $value['Name'] ?? $value['DomainName'] ?? $value['Domain'] ?? $value['domain'] ?? $value['name'] ?? '';
	$v = strtolower(rtrim(trim(Expression::toText($value)), '.'));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (preg_match('/[^\x00-\x7f]/', $v) && function_exists('idn_to_ascii'))
	    $v = (string)idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
	if (strlen($v) > 253 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $v))
	    throw new \RuntimeException("{$label}: not a domain's name: `" . mb_substr($v, 0, 64) . '`');
	[ $sld, $tld ] = explode('.', $v, 2);
	return [ $v, $sld, $tld ];
    }
}
