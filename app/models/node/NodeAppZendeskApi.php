<?php

/*
    CLD: 2026-11-12T23:45:00+02:00: Zendesk API - any request of Zendesk's REST API as the credential's agent (what the other Zendesk steps do not have: satisfaction ratings,
    side conversations, Help Center articles (/api/v2/help_center/…), automations, SLA policies, …): the method, the path (below /api/v2; .json added), the query and the JSON
    body (objects, or expressions giving them). The result: Zendesk's answer; a list's further pages are not read (its links.next / next_page is in the answer)
*/

namespace models\node;

class NodeAppZendeskApi extends NodeAppZendesk {
    public static $name = 'zendesk-api';
    public static $label = 'Zendesk API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'zendesk';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Below /api/v2, e.g. /satisfaction_ratings or /tickets/123/side_conversations (.json added)',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/tickets/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"page[size]": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = preg_replace('~^https://[a-z0-9-]+\.zendesk\.com~i', '', $text('path'));
	if ($path === '' || preg_match('~^https?://~i', $path))
	    throw new \RuntimeException('Path: below /api/v2, e.g. /tickets/123');
	$query = $this->jsonOf('query', 'Query');
	array_walk_recursive($query, function(&$v) {
	    if (is_bool($v))
		$v = $v ? 'true' : 'false';
	});
	$body = $this->settingOf('body');
	if (is_string($body)) {
	    $body = trim($body) === '' ? null : json_decode($body, true);
	    if ($body === null && trim((string)$this->settingOf('body')) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	return [ $this->zendesk->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
