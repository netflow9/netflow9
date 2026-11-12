<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: Infobip API - any request of Infobip's API with the credential's key (what the other Infobip steps do not have: Viber, RCS, voice messages,
    Conversations, People, number configuration, …): the method, the path (e.g. /viber/2/messages), the query and the JSON body (objects, or expressions giving them). The result:
    Infobip's answer
*/

namespace models\node;

class NodeAppInfobipApi extends NodeAppInfobip {
    public static $name = 'infobip-api';
    public static $label = 'Infobip API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'infobip';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'After the base URL, e.g. /viber/2/messages or /tts/3/advanced',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/sms/…' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"limit": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = preg_replace('~^https://[a-z0-9-]+\.api(-[a-z0-9]+)?\.infobip\.com~i', '', $text('path'));
	if ($path === '' || preg_match('~^https?://~i', $path))
	    throw new \RuntimeException('Path: after the base URL, e.g. /sms/1/logs');
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
	return [ $this->infobip->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
