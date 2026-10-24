<?php

/*
    a MCP server (Model Context Protocol, Streamable HTTP) whose tools the AI steps can offer to the model:
	its URL and how to authenticate (none, a bearer token, or a header such as X-Api-Key; secrets encrypted).
    Provides `mcp`;
    
    client() = a util\McpClient for it.

    Test: connects (initialize) and lists the tools. NetFlow9's own server: https://<host>/mcp with a token of the project (menu: MCP server)
*/

namespace models\credential;

class CredentialMcp extends \models\Credential {
    public static $label = 'MCP server';
    public static $icon = 'bi-plug';
    public static $group = 'AI';
    public static $provides = [ 'mcp' ];
    public static $testable = true;

    const AUTH = [
	'none' => 'None',
	'bearer' => 'Bearer token',
	'header' => 'A header (e.g. X-Api-Key)' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'url',
		'type' => 'url',
		'label' => 'Server URL',
		'required' => true,
		'default' => '',
		'help' => 'The MCP endpoint (Streamable HTTP), e.g. https://example.com/mcp',
		'ui' => [
		    'placeholder' => 'https://example.com/mcp'
		],
	    ],
	    [
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Authentication',
		'required' => true,
		'default' => 'bearer',
		'choices' => self::AUTH,
		'ui' => [
		    'width' => 6
		],
	    ],
	    [
		'key' => 'headerName',
		'type' => 'text',
		'label' => 'Header name',
		'default' => '',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'header' ] ],
		'validate' => [
		    [ 'regex', 'pattern' => '^[A-Za-z0-9-]+$', 'skipEmpty' => true, 'message' => 'Letters, digits or dash' ],
		],
		'ui' => [ 'width' => 6, 'placeholder' => 'X-Api-Key', 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'header' ] ],
	    ],
	    [
		'key' => 'token',
		'type' => 'password',
		'label' => 'Token / header value',
		'encrypt' => true,
		'help' => 'Bearer: the token (sent as Authorization: Bearer ...); header: the header\'s value',
		'ui' => [
		    'showIf' => [ 'key' => 'auth', 'operator' => 'ne', 'value' => 'none' ]
		],
	    ],
	]);
    }

    //the authentication headers: name => value
    public function httpHeaders() {
	return match((string)$this->auth) {
	    'bearer' => (string)$this->token !== '' ? [ 'Authorization' => 'Bearer ' . $this->token ] : [],
	    'header' => (string)$this->headerName !== '' ? [ (string)$this->headerName => (string)$this->token ] : [],
	    default => [],
	};
    }

    public function client($timeout = 30) {
	return new \util\McpClient((string)$this->url, $this->httpHeaders(), $timeout);
    }

    //connects and lists the tools
    public function test() {
	$client = $this->client(15);
	$client->initialize();
	$tools = array_column($client->listTools(), 'name');
	$client->close();
	$server = trim((string)($client->serverInfo['name'] ?? 'server') . ' ' . (string)($client->serverInfo['version'] ?? ''));
	$shown = array_slice($tools, 0, 12);
	//CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was (s))
	return "{$server}: " . \util\Format::plural(count($tools), 'tool') . ($shown ? ': ' . implode(', ', $shown) . (count($tools) > count($shown) ? ', ...' : '') : '');
    }
}
