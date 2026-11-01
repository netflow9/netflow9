<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall Web - the web filter's URL groups (WebFilterURLGroup: a name and its URLlist of domains). Operations (setting `operation`):
	block     domains put in a URL group a web policy blocks (the group read, its list changed, sent back whole: an update replaces the list); already in it: fine
	unblock   domains taken out of the group (not in it: fine)
	groups    the URL groups (name containing a text)
	get       a URL group
	Domains: a list, or text separated by commas / lines; a URL given is taken as its host (https://www.example.com/x: www.example.com)
*/

namespace models\node;

class NodeAppSfosWeb extends NodeAppSophos {
    public static $name = 'sfos-web';
    public static $label = 'Sophos Firewall Web';
    public static $icon = 'bi-globe2';
    public static $defaultOperation = 'block';
    public static $defaultVariable = 'web';

    const OPERATIONS = [
	'block' => 'Block domains (URL group)',
	'unblock' => 'Unblock domains',
	'groups' => 'List URL groups',
	'get' => 'Get a URL group',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'block', 'unblock' ];
    const LISTS = [ 'groups' ];
    const MAX_DOMAINS = 500;

    protected static function operationFields() {
	return [
	    self::field('domains', 'Domains', [ 'block', 'unblock' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or separated by commas / lines, e.g. bad.example.com',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('group', 'URL group', [ 'block', 'unblock', 'get' ], [ 'required' => true, 'help' => 'The URL group (blocked by a web policy)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('contains', 'Name contains', [ 'groups' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //domains (lower case; a URL's host): a list, or text separated by commas / spaces / lines; each checked
    public static function domains($value, $label, $max) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? ($v['domain'] ?? $v['host'] ?? $v['url'] ?? '') : $v, array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$list = [];
	foreach($parts as $p) {
	    $p = strtolower(trim(\util\Expression::toText($p)));
	    if ($p === '')
		continue;
	    if (str_contains($p, '://'))
		$p = (string)parse_url($p, PHP_URL_HOST);
	    $p = rtrim($p, '.');
	    if (!preg_match('/^(?=.{1,253}$)(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $p))
		throw new \RuntimeException("{$label}: not a domain: `" . mb_substr($p, 0, 64) . '`');
	    $list[] = $p;
	}
	$list = array_values(array_unique($list));
	if (!$list)
	    throw new \RuntimeException("{$label}: empty");
	if (count($list) > $max)
	    throw new \RuntimeException("{$label}: " . count($list) . " domains, at most {$max} in one step");
	return $list;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'groups':
		return $this->listOf('WebFilterURLGroup', $text('contains'), 'URL group');
	    case 'get':
		$g = self::objName($value('group'), 'URL group');
		return [ $this->need('WebFilterURLGroup', $g, 'the URL group'), "URL group {$g}" ];
	    case 'block':
	    case 'unblock':
		$domains = self::domains($value('domains'), 'Domains', self::MAX_DOMAINS);
		$g = self::objName($value('group'), 'URL group');
		$block = $operation === 'block';
		[ $group, $changed ] = self::withMembers($this->need('WebFilterURLGroup', $g, 'the URL group'), 'URLlist', 'URL', $block ? $domains : [], $block ? [] : $domains);
		$msg = $changed ? $this->change('update', 'WebFilterURLGroup', $group) : 'no change';
		return [ [ 'group' => $g, 'domains' => $domains, 'count' => count($domains), 'message' => $msg ],
		    \util\Format::plural(count($domains), 'domain') . ($block ? ' blocked' : ' unblocked') . " (URL group {$g})" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
