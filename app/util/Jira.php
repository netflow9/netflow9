<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: the client of Jira's REST API, for the Jira credential, steps and issue trigger. Two kinds of site:
	cloud   https://<site>.atlassian.net, /rest/api/3, Basic auth with the user's email and an API token (id.atlassian.com > Security > API tokens); users by accountId;
	        texts (description, comments) in Atlassian Document Format (ADF); search: POST /rest/api/3/search/jql, paged by nextPageToken
	server  Jira Data Center / Server, /rest/api/2, a personal access token as Bearer (Profile > Personal Access Tokens); users by name; texts as wiki markup;
	        search: POST /rest/api/2/search, paged by startAt
    A 429 (Jira's rate limit) waits for Retry-After and is sent again while the step's timeout allows. Errors: Jira's errorMessages and errors { field: message }.
    Data Center on a private address needs the account's "Integrations may connect to private network addresses"; its certificate checked unless trusted (self-signed).

    $j = new Jira($cred, $aid, $timeout);
    $j->request($method, $path, $query, $body)    the answer (decoded JSON; null when empty); \RuntimeException with Jira's messages
    $j->search($jql, $fields, $max)               the issues a JQL search finds: [ issues, truncated ]
    $j->isCloud(), $j->api()                      the kind of site; /rest/api/3 or /rest/api/2
    $j->text($v)                                  a text as the site takes it (cloud: ADF; an ADF document given stays as it is)
    Jira::plain($v)                               an ADF document (or a text) as plain text
    Jira::issueKey($v)                            an issue's key (PROJ-123) or id of a text or an issue of a list / a search

    Jira::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class Jira {
    const MAX_ANSWER = 20 * 1024 * 1024;
    //the most issues one search page gives (with their fields)
    const PAGE = 100;
    const MAX_WAIT = 30;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=-]*$#';
    const KEY = '~^[A-Z][A-Z0-9_]{0,49}-\d{1,9}$~';

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

    public function isCloud() {
	return ($this->cred->deployment ?? 'cloud') === 'cloud';
    }

    public function api() {
	return $this->isCloud() ? '/rest/api/3' : '/rest/api/2';
    }

    //an issue's address in the browser
    public function browseUrl($key) {
	return self::baseUrl($this->cred->siteUrl) . '/browse/' . rawurlencode((string)$key);
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	$o = [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => !$this->isCloud() && (bool)($account->allow_private_addresses ?? false),
	    'insecure' => !$this->isCloud() && (bool)$this->cred->trustCertificate,
	    'maxBytes' => self::MAX_ANSWER,
	    'timeout' => $this->left(),
	];
	if ($this->isCloud())
	    $o['auth'] = [ 'basic', trim((string)$this->cred->email), (string)$this->cred->apiToken ];
	return $o;
    }

    protected function authHeaders() {
	return $this->isCloud() ? [] : [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->apiToken) ];
    }

    //Jira's messages in an error answer: errorMessages, errors { field: message }, or a message
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$list = array_map('strval', array_filter((array)($d['errorMessages'] ?? []), 'is_scalar'));
	foreach((array)($d['errors'] ?? []) as $k => $v)
	    $list[] = (is_string($k) ? "{$k}: " : '') . (is_scalar($v) ? $v : json_encode($v));
	if (!$list && isset($d['message']))
	    $list[] = (string)$d['message'];
	return mb_substr(implode('; ', array_slice($list, 0, 5)), 0, 1000);
    }

    protected static function checkPath($path) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(self::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not an API path: `" . mb_substr($path, 0, 100) . '`');
	return $path;
    }

    //a request on the site (a path from /rest/…): the answer decoded; $raw: [ body, content type ] sent as they are (an attachment's multipart)
    public function request($method, $path, array $query = [], $body = null, array $headers = [], ?array $raw = null) {
	$method = strtoupper((string)$method);
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$url = self::baseUrl($this->cred->siteUrl) . self::checkPath($path) . ($q !== '' ? "?{$q}" : '');
	while (true) {
	    $o = [ 'headers' => $headers + $this->authHeaders() ] + $this->options();
	    if ($raw !== null) {
		$o['body'] = $raw[0];
		$o['headers']['Content-Type'] = $raw[1];
	    } elseif ($body !== null)
		$o['json'] = $body === [] ? (object)[] : $body;
	    elseif (!in_array($method, [ 'GET', 'DELETE' ], true))
		$o['body'] = '';
	    $r = Http::json($method, $url, $o);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 5)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Jira\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown(self::baseUrl($this->cred->siteUrl)) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = self::message($r['data']);
	    $hint = [ 401 => 'the email and API token (or the access token) are refused', 403 => 'the user may not do this', 404 => 'not found, or the user may not see it' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	if (trim((string)$r['body']) !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not Jira\'s JSON (is the address the site\'s?)');
	return $r['data'];
    }

    //the issues of a JQL search, at most $max: [ issues, truncated ]
    public function search($jql, array $fields, $max, array $more = []) {
	$max = max(1, (int)$max);
	$issues = [];
	$token = null;
	while (true) {
	    $page = min(self::PAGE, $max - count($issues) + 1);
	    if ($this->isCloud()) {
		$d = (array)$this->request('POST', '/rest/api/3/search/jql', [], [ 'jql' => (string)$jql, 'fields' => $fields, 'maxResults' => $page ] + ($token !== null ? [ 'nextPageToken' => $token ] : []) + $more);
		$list = (array)($d['issues'] ?? []);
		$token = $d['nextPageToken'] ?? null;
		$last = !empty($d['isLast']) || $token === null || !$list;
	    } else {
		$d = (array)$this->request('POST', '/rest/api/2/search', [], [ 'jql' => (string)$jql, 'fields' => $fields, 'maxResults' => $page, 'startAt' => count($issues) ] + $more);
		$list = (array)($d['issues'] ?? []);
		$last = !$list || count($issues) + count($list) >= (int)($d['total'] ?? 0);
	    }
	    foreach($list as $i)
		$issues[] = $i;
	    if ($last || count($issues) > $max)
		return [ array_slice($issues, 0, $max), count($issues) > $max || (!$last && count($issues) >= $max) ];
	}
    }

    //a text as the site takes it: cloud an ADF document (paragraphs at blank lines, line breaks kept), server the text; an ADF document given stays as it is
    public function text($v) {
	if (is_array($v) && ($v['type'] ?? '') === 'doc')
	    return $this->isCloud() ? $v : self::plain($v);
	$t = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : Expression::toText($v);
	return $this->isCloud() ? self::adf($t) : $t;
    }

    public static function adf($text) {
	$paragraphs = [];
	foreach(preg_split('/\R{2,}/', trim(str_replace("\r\n", "\n", (string)$text))) as $p) {
	    if (trim($p) === '')
		continue;
	    $content = [];
	    foreach(explode("\n", $p) as $i => $line) {
		if ($i > 0)
		    $content[] = [ 'type' => 'hardBreak' ];
		if ($line !== '')
		    $content[] = [ 'type' => 'text', 'text' => $line ];
	    }
	    $paragraphs[] = [ 'type' => 'paragraph', 'content' => $content ];
	}
	return [ 'type' => 'doc', 'version' => 1, 'content' => $paragraphs ];
    }

    //an ADF document (or node) as plain text: blocks separated by blank lines, list items by new lines; a text stays as it is
    public static function plain($v) {
	if (!is_array($v))
	    return $v === null ? '' : (string)$v;
	$type = (string)($v['type'] ?? '');
	switch($type) {
	    case 'text':
		return (string)($v['text'] ?? '');
	    case 'hardBreak':
		return "\n";
	    case 'mention':
	    case 'emoji':
		return (string)($v['attrs']['text'] ?? $v['attrs']['shortName'] ?? '');
	    case 'inlineCard':
		return (string)($v['attrs']['url'] ?? '');
	}
	$parts = array_map([ self::class, 'plain' ], (array)($v['content'] ?? []));
	if (in_array($type, [ 'doc', 'blockquote', 'panel', 'expand' ], true))
	    return trim(implode("\n\n", array_filter($parts, fn($p) => $p !== '')));
	if (in_array($type, [ 'bulletList', 'orderedList', 'tableRow', 'table' ], true))
	    return implode("\n", $parts);
	if ($type === 'listItem')
	    return '- ' . implode(' ', $parts);
	return implode('', $parts);
    }

    //an issue's key or id: PROJ-123, 10042, an issue of a list or a search ({ key } / { id }), or its browse address
    public static function issueKey($value, $label = 'Issue') {
	if (is_array($value))
	    $value = $value['key'] ?? $value['id'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/browse/([A-Za-z][A-Za-z0-9_]*-\d+)~', $v, $m))
	    $v = $m[1];
	$v = strtoupper($v);
	if (!preg_match(self::KEY, $v) && !preg_match('/^\d{1,18}$/', $v))
	    throw new \RuntimeException("{$label}: not an issue key (PROJ-123): `" . mb_substr($v, 0, 60) . '`');
	return $v;
    }

    //a JQL text value, quoted
    public static function quoted($v) {
	return '"' . addcslashes((string)$v, '"\\') . '"';
    }
}
