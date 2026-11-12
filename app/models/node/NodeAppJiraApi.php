<?php

/*
    CLD: 2026-11-12T14:00:00+02:00: Jira API - any request of the site's REST API (what the other Jira steps do not have: worklogs, watchers, sprints of Jira Software
    (/rest/agile/1.0), versions, filters, …): the method, the path (from /rest/), the query and the JSON body (objects, or expressions giving them). The result: Jira's answer
*/

namespace models\node;

class NodeAppJiraApi extends NodeAppJira {
    public static $name = 'jira-api';
    public static $label = 'Jira API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'jira';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'From /rest/, e.g. /rest/api/3/issue/OPS-1/worklog or /rest/agile/1.0/board',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/rest/api/3/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"maxResults": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    //a JSON setting: [] / null when empty
    protected function jsonOf($key, $label, $object) {
	$v = $this->settingValue((string)$this->cfg($key, ''), $this->settingVars);
	if (is_string($v)) {
	    if (trim($v) === '')
		return null;
	    $v = json_decode($v, true);
	    if ($v === null)
		throw new \RuntimeException("{$label}: not JSON");
	}
	if ($object && (!is_array($v) || ($v && array_is_list($v))))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	if (!str_starts_with('/' . ltrim($path, '/'), '/rest/'))
	    throw new \RuntimeException('Path: from /rest/');
	$query = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(',', $v) : $v), (array)$this->jsonOf('query', 'Query', true));
	return [ $this->jira->request($method, $path, $query, $this->jsonOf('body', 'Body', false)), "{$method} {$path}" ];
    }
}
