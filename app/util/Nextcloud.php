<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: the client of a Nextcloud server, for the Nextcloud credential, steps and file trigger. A user signs in with an app password (Basic auth on
    every request; Personal settings > Security > Devices & sessions). Two APIs on the server's address (which may have a path: https://host/nextcloud):
	WebDAV  /remote.php/dav/files/<user>/<path>: PROPFIND (Depth 0 / 1: the items' properties), GET (into a stream), PUT (from a stream), MKCOL, MOVE, COPY, DELETE;
	        SEARCH on /remote.php/dav/ (basicsearch: names under a folder, its subfolders too)
	OCS     /ocs/v2.php/<path>?format=json, header OCS-APIRequest: true: shares, users and groups (admins), Talk; the answer { ocs: { meta: { status, statuscode, message }, data } }
    Public addresses only, unless the account allows private network addresses (Account allow_private_addresses; never this server itself); the certificate checked unless the
    credential trusts it (self-signed).

    $n = new Nextcloud($cred, $aid, $timeout);
    $n->ocs($method, $path, $params)          the answer's data; \RuntimeException with Nextcloud's message on an error
    $n->dav($method, $path, $o)               a WebDAV request on the user's files (util\Http's answer; options as Http's, + depth, destination, overwrite); errors: \RuntimeException
    $n->items($path, $depth)                  PROPFIND: the items (folder first with Depth 1): { name, path, isFolder, size, modified, contentType, etag, fileId, permissions, favorite }
    $n->search($folder, $name, $max)          SEARCH: the files whose name contains $name (any case), under $folder
    Nextcloud::path($v)                       a path in the user's files: /a/b.txt (an item of a list works too); '..' refused

    Nextcloud::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Nextcloud {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PROPS = '<d:getlastmodified/><d:getetag/><d:getcontenttype/><d:resourcetype/><d:getcontentlength/><oc:size/><oc:fileid/><oc:permissions/><oc:favorite/>';
    const NS = 'xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"';

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

    public static function baseUrl($url) {
	return rtrim(trim((string)$url), '/');
    }

    public function user() {
	return trim((string)$this->cred->username);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'auth' => [ 'basic', $this->user(), (string)$this->cred->appPassword ],
	    'timeout' => $this->left(),
	];
    }

    //a path in the user's files: /a/b.txt; a list item ({ path }) works too; '' and '/' are the top
    public static function path($value, $label = 'Path') {
	if (is_array($value))
	    $value = $value['path'] ?? '';
	$v = str_replace('\\', '/', trim(Expression::toText($value)));
	$parts = array_values(array_filter(explode('/', $v), fn($p) => $p !== '' && $p !== '.'));
	foreach($parts as $p)
	    if ($p === '..' || preg_match('/[\x00-\x1f]/', $p))
		throw new \RuntimeException("{$label}: not a path in the files: `" . mb_substr($v, 0, 100) . '`');
	return '/' . implode('/', $parts);
    }

    //the WebDAV address of a path of the user's files (each part encoded)
    public function davUrl($path) {
	$enc = implode('/', array_map('rawurlencode', array_filter(explode('/', self::path($path)), fn($p) => $p !== '')));
	return self::baseUrl($this->cred->serverUrl) . '/remote.php/dav/files/' . rawurlencode($this->user()) . ($enc !== '' ? "/{$enc}" : '');
    }

    //Nextcloud's message in a WebDAV error (<s:message> of a Sabre exception) or an OCS answer
    public static function message($body) {
	$body = (string)$body;
	if (preg_match('~<s:message>(.*?)</s:message>~s', $body, $m))
	    return mb_substr(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)), 0, 500);
	$d = json_decode($body, true);
	if (is_array($d))
	    return mb_substr(trim((string)($d['ocs']['meta']['message'] ?? $d['message'] ?? '')), 0, 500);
	return '';
    }

    //what an HTTP status means for the steps
    public static function statusText($status, $body = '') {
	$m = self::message($body);
	$hint = [ 401 => 'the user or app password is refused', 403 => 'not allowed', 404 => 'not found', 405 => 'not allowed here (it exists already?)', 409 => 'the parent folder does not exist',
	    412 => 'it exists already', 423 => 'it is locked', 507 => 'the user\'s quota is full' ][(int)$status] ?? '';
	return "HTTP status {$status}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : ''));
    }

    //an OCS request: the answer's data. GET / DELETE: $params in the query; POST / PUT: as a form
    public function ocs($method, $path, array $params = []) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('~^/[A-Za-z0-9/_.\-%]*$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an OCS path: `{$path}`");
	$query = [ 'format' => 'json' ] + (in_array($method, [ 'GET', 'DELETE' ], true) ? $params : []);
	$url = self::baseUrl($this->cred->serverUrl) . '/ocs/v2.php' . $path . '?' . self::query($query);
	$o = [ 'headers' => [ 'OCS-APIRequest' => 'true' ], 'maxBytes' => self::MAX_ANSWER ] + $this->options();
	if (!in_array($method, [ 'GET', 'DELETE' ], true))
	    $o['body'] = self::query($params);
	if (isset($o['body']))
	    $o['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
	$r = Http::json($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	$meta = $r['data']['ocs']['meta'] ?? null;
	$code = (int)($meta['statuscode'] ?? 0);
	if ($r['status'] >= 400 || !is_array($meta) || !($code === 100 || ($code >= 200 && $code < 300))) {
	    $m = trim((string)($meta['message'] ?? ''));
	    if (!is_array($meta) && $r['status'] < 400)
		throw new \RuntimeException('the answer is not Nextcloud\'s (is the address the server\'s?)');
	    throw new \RuntimeException(($m !== '' ? $m : self::statusText($r['status'], $r['body'])) . ($code && $code !== $r['status'] ? " (OCS {$code})" : ''));
	}
	return $r['data']['ocs']['data'] ?? null;
    }

    //a form / query string: lists as name[]=…, booleans as true / false; nulls left out
    public static function query(array $params) {
	$parts = [];
	foreach($params as $k => $v) {
	    if ($v === null)
		continue;
	    foreach(is_array($v) ? array_values($v) : [ $v ] as $x)
		$parts[] = rawurlencode((string)$k) . (is_array($v) ? '%5B%5D' : '') . '=' . rawurlencode(is_bool($x) ? ($x ? 'true' : 'false') : (string)$x);
	}
	return implode('&', $parts);
    }

    //a WebDAV request on path $path of the user's files: Http's answer; an error status (or none) as a \RuntimeException
    //$o: Http's options + depth, destination (a path: its address in Destination), overwrite (true / false)
    public function dav($method, $path, array $o = []) {
	$headers = (array)($o['headers'] ?? []);
	if (isset($o['depth']))
	    $headers['Depth'] = (string)$o['depth'];
	if (isset($o['destination']))
	    $headers['Destination'] = $this->davUrl($o['destination']);
	if (isset($o['overwrite']))
	    $headers['Overwrite'] = $o['overwrite'] ? 'T' : 'F';
	$o = array_diff_key($o, [ 'depth' => 1, 'destination' => 1, 'overwrite' => 1 ]);
	$o['headers'] = $headers;
	$r = Http::request($method, $o['url'] ?? $this->davUrl($path), array_diff_key($o, [ 'url' => 1 ]) + [ 'maxBytes' => self::MAX_ANSWER ] + $this->options());
	if ($r['error'] !== null && !$r['tooLarge'])
	    throw new \RuntimeException('no answer from ' . Http::shown(self::baseUrl($this->cred->serverUrl)) . ": {$r['error']}");
	if ($r['tooLarge'])
	    throw new \RuntimeException('larger than ' . (((int)($o['maxBytes'] ?? self::MAX_ANSWER)) >> 20) . ' MB');
	if ($r['status'] >= 400)
	    throw new \RuntimeException(self::statusText($r['status'], isset($o['sink']) ? '' : $r['body']));
	return $r;
    }

    //PROPFIND: the items of $path (Depth 1: the folder itself first, then its items; Depth 0: the item)
    public function items($path, $depth = 1) {
	$r = $this->dav('PROPFIND', $path, [ 'depth' => $depth, 'headers' => [ 'Content-Type' => 'application/xml; charset=utf-8' ],
	    'body' => '<?xml version="1.0"?><d:propfind ' . self::NS . '><d:prop>' . self::PROPS . '</d:prop></d:propfind>' ]);
	return self::multistatus($r['body'], $this->user());
    }

    //SEARCH (DAV basicsearch, Nextcloud's): the files under $folder whose name contains $name, at most $max, the newest first
    public function search($folder, $name, $max) {
	$like = '%' . addcslashes(mb_strtolower((string)$name), '%_\\') . '%';
	$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	$scope = '/files/' . $this->user() . (self::path($folder) === '/' ? '' : self::path($folder));
	$body = '<?xml version="1.0" encoding="UTF-8"?><d:searchrequest ' . self::NS . '><d:basicsearch><d:select><d:prop>' . self::PROPS . '<d:displayname/></d:prop></d:select>'
	    . '<d:from><d:scope><d:href>' . $x($scope) . '</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
	    . '<d:where><d:like><d:prop><d:displayname/></d:prop><d:literal>' . $x($like) . '</d:literal></d:like></d:where>'
	    . '<d:orderby><d:order><d:prop><d:getlastmodified/></d:prop><d:descending/></d:order></d:orderby><d:limit><d:nresults>' . (int)$max . '</d:nresults></d:limit>'
	    . '</d:basicsearch></d:searchrequest>';
	$r = $this->dav('SEARCH', '/', [ 'url' => self::baseUrl($this->cred->serverUrl) . '/remote.php/dav/', 'headers' => [ 'Content-Type' => 'text/xml; charset=utf-8' ], 'body' => $body ]);
	return self::multistatus($r['body'], $this->user());
    }

    //the items of a multistatus answer: their path relative to the user's files
    public static function multistatus($xml, $user) {
	$xml = (string)$xml;
	if (trim($xml) === '' || preg_match('~<!(DOCTYPE|ENTITY)~i', $xml))
	    throw new \RuntimeException('the answer is not a WebDAV listing');
	$prev = libxml_use_internal_errors(true);
	$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($doc === false)
	    throw new \RuntimeException('the answer is not a WebDAV listing');
	$doc->registerXPathNamespace('d', 'DAV:');
	$items = [];
	$marker = '/remote.php/dav/files/' . rawurlencode($user);
	foreach($doc->xpath('//d:response') ?: [] as $resp) {
	    $resp->registerXPathNamespace('d', 'DAV:');
	    $resp->registerXPathNamespace('oc', 'http://owncloud.org/ns');
	    $href = (string)($resp->xpath('d:href')[0] ?? '');
	    $pos = strpos($href, $marker);
	    if ($pos === false)
		continue;
	    $rel = rawurldecode(substr($href, $pos + strlen($marker)));
	    $path = self::path($rel);
	    $one = fn($q) => ($n = $resp->xpath("d:propstat[contains(d:status, ' 200 ')]/d:prop/{$q}")) ? $n[0] : null;
	    $folder = $one('d:resourcetype/d:collection') !== null;
	    $modified = $one('d:getlastmodified');
	    $t = $modified !== null ? strtotime((string)$modified) : false;
	    $items[] = [
		'name' => $path === '/' ? '' : basename($path),
		'path' => $path,
		'folder' => $path === '/' ? '/' : dirname($path),
		'isFolder' => $folder,
		'size' => (int)(string)($folder ? ($one('oc:size') ?? 0) : ($one('d:getcontentlength') ?? $one('oc:size') ?? 0)),
		'modified' => $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : null,
		'contentType' => $folder ? null : (($c = $one('d:getcontenttype')) !== null ? (string)$c : null),
		'etag' => trim((string)($one('d:getetag') ?? ''), '"'),
		'fileId' => ($id = $one('oc:fileid')) !== null ? (int)(string)$id : null,
		'permissions' => ($p = $one('oc:permissions')) !== null ? (string)$p : null,
		'favorite' => (string)($one('oc:favorite') ?? '0') === '1',
	    ];
	}
	return $items;
    }
}
