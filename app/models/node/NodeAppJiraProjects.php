<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: Jira Projects - what the issues refer to. Operations (setting `operation`):
	projects   the projects the user can see (Cloud: project/search, paged; Data Center: project): key, name, projectTypeKey, lead
	project    a project with its issue types and components
	fields     the fields (field): id, name, custom or not, schema: to find a custom field's id (customfield_10016) for Other fields and Fields
	users      the users a search finds (user/search): accountId / name, displayName, emailAddress (when the user shows it), active
	priorities the priorities (priority)
	linkTypes  the issue link types (issueLinkType): name, inward, outward
*/

namespace models\node;

class NodeAppJiraProjects extends NodeAppJira {
    public static $name = 'jira-projects';
    public static $label = 'Jira Projects';
    public static $icon = 'bi-diagram-3';
    public static $defaultOperation = 'projects';
    public static $defaultVariable = 'jira';

    const OPERATIONS = [
	'projects' => 'List projects',
	'project' => 'Get a project',
	'fields' => 'List fields',
	'users' => 'Find users',
	'priorities' => 'List priorities',
	'linkTypes' => 'List link types',
    ];
    const WRITES = [];
    const LISTS = [ 'projects', 'fields', 'users', 'priorities', 'linkTypes' ];

    protected static function operationFields() {
	return [
	    self::field('search', 'Search', [ 'projects', 'fields', 'users' ], [ 'help' => 'Projects: in keys and names; fields: in names; users: a name or email (required)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'users' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('project', 'Project', [ 'project' ], [ 'required' => true, 'help' => 'Its key, e.g. OPS', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$J = $this->jira;
	$api = $J->api();
	$brief = fn($p) => [ 'id' => $p['id'] ?? null, 'key' => $p['key'] ?? null, 'name' => $p['name'] ?? null, 'projectTypeKey' => $p['projectTypeKey'] ?? null,
	    'lead' => NodeAppJiraIssues::userOf($p['lead'] ?? null) ];
	switch($operation) {
	    case 'projects':
		$s = $text('search');
		if ($J->isCloud()) {
		    $all = [];
		    for ($at = 0; ; ) {
			$d = (array)$J->request('GET', "{$api}/project/search", [ 'query' => $s, 'startAt' => $at, 'maxResults' => 50, 'expand' => 'lead' ]);
			$vals = (array)($d['values'] ?? []);
			$all = array_merge($all, $vals);
			$at += count($vals);
			if (!$vals || !empty($d['isLast']) || count($all) > $this->maxItems())
			    break;
		    }
		} else {
		    $all = array_values(array_filter((array)$J->request('GET', "{$api}/project"), fn($p) => $s === '' || stripos(($p['key'] ?? '') . ' ' . ($p['name'] ?? ''), $s) !== false));
		}
		return $this->listResult(array_map($brief, $all), 'project');
	    case 'project':
		$p = (array)$J->request('GET', "{$api}/project/" . rawurlencode(strtoupper($text('project'))), [ 'expand' => 'lead,issueTypes' ]);
		$r = $brief($p) + [ 'description' => $p['description'] ?? null,
		    'issueTypes' => array_map(fn($t) => [ 'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'subtask' => (bool)($t['subtask'] ?? false) ], (array)($p['issueTypes'] ?? [])),
		    'components' => array_map(fn($c) => [ 'id' => $c['id'] ?? null, 'name' => $c['name'] ?? null ], (array)($p['components'] ?? [])) ];
		return [ $r, "{$r['key']}: {$r['name']}, " . \util\Format::plural(count($r['issueTypes']), 'issue type') ];
	    case 'fields':
		$s = $text('search');
		$all = array_values(array_filter(array_map(fn($f) => [ 'id' => $f['id'] ?? null, 'name' => $f['name'] ?? null, 'custom' => (bool)($f['custom'] ?? false),
		    'type' => $f['schema']['type'] ?? null, 'items' => $f['schema']['items'] ?? null ], (array)$J->request('GET', "{$api}/field")),
		    fn($f) => $s === '' || stripos((string)$f['name'], $s) !== false || stripos((string)$f['id'], $s) !== false));
		return $this->listResult($all, 'field');
	    case 'users':
		$s = $text('search');
		if ($s === '')
		    throw new \RuntimeException('Search: a name or email');
		$found = (array)$J->request('GET', "{$api}/user/search", $J->isCloud() ? [ 'query' => $s, 'maxResults' => 100 ] : [ 'username' => $s, 'maxResults' => 100 ]);
		return $this->listResult(array_map([ NodeAppJiraIssues::class, 'userOf' ], $found), 'user');
	    case 'priorities':
		return $this->listResult(array_map(fn($p) => [ 'id' => $p['id'] ?? null, 'name' => $p['name'] ?? null ], (array)$J->request('GET', "{$api}/priority")), 'priority');
	    case 'linkTypes':
		return $this->listResult(array_map(fn($t) => [ 'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'inward' => $t['inward'] ?? null, 'outward' => $t['outward'] ?? null ],
		    (array)($J->request('GET', "{$api}/issueLinkType")['issueLinkTypes'] ?? [])), 'link type');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
