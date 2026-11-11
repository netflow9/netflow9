<?php

/*
    HTTP Request - calls an HTTP endpoint (an API).
    Settings: method, URL, query parameters (a repeated name sends each one), headers, body (none / JSON / form / text; JSON checked on save, also as a JSON-RPC 2.0 request when `jsonRpc`),
	an optional credential of the project providing `http` (API key, Basic, Bearer, OAuth: httpAuth()),
	timeout; URL, values and body may hold {{ }} expressions.
	The response goes into one variable (default `response`): { ok, status, headers, body, error, time_ms } - body parsed when it is JSON,
	    headers by lower-case name (a list when repeated).
    On error (no answer, timeout, status 400 or more) - user's choice per step (`onError`): fail the step,
	or continue on a second output `error` (the outputs follow the setting: $outPorts)
*/

namespace models\node;

class NodeAppHttp extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'http';
    public static $label = 'HTTP Request';
    public static $icon = 'bi-globe2';
    public static $color = 'var(--wf-color-app)';
    //the step picker: an advanced step, in Developer
    public static $audience = 'advanced';
    public static $category = 'developer';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const METHODS = [
	'GET' => 'GET',
	'POST' => 'POST',
	'PUT' => 'PUT',
	'PATCH' => 'PATCH',
	'DELETE' => 'DELETE',
	'HEAD' => 'HEAD'
    ];
    const BODY_TYPES = [ 'none' => 'None', 'json' => 'JSON', 'form' => 'Form (urlencoded)', 'text' => 'Text' ];

    //the JSON-RPC request proposed when JSON-RPC is switched on
    const JSON_RPC_TEMPLATE = "{\n    \"jsonrpc\": \"2.0\",\n    \"method\": \"\",\n    \"params\": {},\n    \"id\": 1\n}";

    //the largest response body kept, and the timeout limits (seconds)
    const MAX_RESPONSE = 5 * 1024 * 1024;
    const MAX_TIMEOUT = 120;

    //a header name (RFC 7230 token)
    const HEADER_NAME = '^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$';

    public static function configSchema() {
	//the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	//no credential: the select's empty choice (ui placeholder)
	$credentials = [];
	foreach(static::credentialChoices() as $c)
	    $credentials[$c['value']] = $c['label'];

	//laid out for the wide dialog ($editor: xl, the step name 6 wide), less scrolling: the designer places the step name / response variable / on error (row 1), the credential
	//(row 2, here 4 wide: method 2 + URL 6 next to it) and the timeout (last); query parameters | headers side by side; body / JSON-RPC; the content (or the form fields) full width
	return [
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Response variable',
		'required' => true,
		'default' => 'response',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write', 'width' => 3 ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'No answer, timeout, status 400+',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Method',
		'required' => true,
		'default' => 'GET',
		'choices' => self::METHODS,
		'ui' => [ 'width' => 2 ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'URL',
		'required' => true,
		'help' => 'http:// or https://',
		'ui' => [ 'placeholder' => 'https://api.example.com/items', 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 30,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
		//min / max from the validate rule (util\WidgetsUtil)
		'ui' => [ 'width' => 2 ],
	    ],
	    //query parameters | headers before the credential / body row: the body type and JSON-RPC stay next to the content
	    self::pairsField('query', 'Query parameters', 'Query parameter', 'Add parameter', [ 'width' => 6 ]),
	    //the headers under "Advanced…" (the designer's closed section of the technical settings; the Download step's too)
	    self::pairsField('headers', 'Headers', 'Header', 'Add header', [ 'width' => 6, 'advanced' => true ]),
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Credential',
		'default' => '',
		'choices' => $credentials,
		'help' => 'API key, Basic, Bearer or OAuth credential of the project (menu: Credentials)',
		'ui' => [ 'placeholder' => 'None', 'width' => 4 ],
	    ],
	    [
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Body',
		'required' => true,
		'default' => 'none',
		'choices' => self::BODY_TYPES,
		'ui' => [
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ],
		    'width' => 3,
		],
	    ],
	    //JSON-RPC 2.0: the content is checked as a request; switching it on proposes one (ui propose: when the content is empty, else asked)
	    [
		//under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'jsonRpc',
		'type' => 'switch',
		'label' => 'JSON-RPC 2.0',
		'default' => false,
		'help' => false,
		'ui' => [
		    'advanced' => true,
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ],
		    'showIf' => [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ],
		    'propose' => [ 'key' => 'body', 'value' => self::JSON_RPC_TEMPLATE, 'confirm' => 'Replace the content with a JSON-RPC request?' ],
		    'width' => 3,
		],
	    ],
	    //a code editor (app.CodeEditor); its syntax follows the body type (syntaxBy: JSON / JSON-RPC checked live, util\JsonCheck; text: the {{ }} only)
	    [
		'key' => 'body',
		'type' => 'textarea',
		'label' => 'Content',
		'help' => 'JSON: a JSON text, or {{ ... }} giving an object / list',
		'ui' => [ 'rows' => 6, 'expression' => 'text', 'code' => true,
		    'syntaxBy' => [
			[ 'syntax' => 'jsonrpc', 'if' => [ [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ], [ 'key' => 'jsonRpc', 'operator' => 'eq', 'value' => true ] ] ],
			[ 'syntax' => 'json', 'if' => [ [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ] ] ],
		    ],
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ], 'showIf' => [ 'key' => 'bodyType', 'operator' => 'in', 'value' => [ 'json', 'text' ] ] ],
	    ],
	    self::pairsField('form', 'Form fields', 'Form field', 'Add field', [ 'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ], 'showIf' => [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'form' ] ]),
	];
    }

    //a list of name / value pairs (query parameters, headers, form fields): setting $key, a nested list (sys\fields\Field_nested): each row cast and validated by pairSchema()
    //(headers: a valid name), its expressions checked, errors as "<itemLabel> <n>: <field>: <message>"; the edit grid's columns come from pairSchema()
    private static function pairsField($key, $label, $itemLabel, $addLabel, $extra = []) {
	return [
	    'key' => $key,
	    'type' => 'nested',
	    'multiple' => true,
	    'fields' => self::pairSchema($key === 'headers'),
	    'label' => $label,
	    'itemLabel' => $itemLabel,
	    'default' => [],
	    'ui' => array_merge([ 'addLabel' => $addLabel, 'compact' => true, 'maxRows' => 50 ], $extra),
	];
    }

    //one row of query parameters / headers / form fields; $header: the name must be a header name; the name is trimmed by the field
    public static function pairSchema($header = false) {
	return [
	    [ 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'transform' => 'trim', 'ui' => [ 'width' => 5 ] ]
		+ ($header ? [ 'validate' => [ [ 'regex', 'pattern' => self::HEADER_NAME, 'skipEmpty' => true, 'message' => 'not a valid header name' ] ] ] : []),
	    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false, 'ui' => [ 'width' => 7, 'expression' => true ] ],
	];
    }

    //credentials of the current project providing `http`, for the designer (as the IMAP trigger)
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor('http', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'response') : 'response';
	//the fields and their types (suggested after `response.`)
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the HTTP response: { ok, status, headers, body, error, time_ms }', 'fields' => [
	    'ok' => [ 'type' => 'boolean' ], 'status' => [ 'type' => 'number' ], 'headers' => [ 'type' => 'object', 'info' => 'lower-case name => value (a list when repeated)' ], 'body' => [ 'type' => 'any', 'info' => 'JSON decoded, else text' ],
	    'error' => [ 'type' => 'string' ], 'time_ms' => [ 'type' => 'number' ] ] ] ] : [];
    }

    //the config, each row of the lists through the row form, header names, a credential of the workflow's project
    //the rows of the lists (names, header names, expressions) are checked and normalized by parent::validates (nested fields); here the credential
    public function validates($options = []) {
	$valid = parent::validates($options);

	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'http')))) {
	    $this->errors('config', 'Credential: choose an HTTP credential of this project');
	    $valid = false;
	}
	//a JSON body must be JSON (a JSON-RPC request when switched on); the {{ }} parts are checked by parent::validates
	if (!in_array($this->cfg('method', 'GET'), [ 'GET', 'HEAD' ], true) && $this->cfg('bodyType', 'none') === 'json') {
	    foreach(\util\Expression::checkJson((string)$this->cfg('body', ''), !empty($this->cfg('jsonRpc', false))) as $p) {
		if ($p['severity'] !== 'error')
		    continue;
		$this->errors('config', "Content: {$p['message']} (column {$p['column']}" . ($p['line'] > 1 ? ", line {$p['line']}" : '') . ')');
		$valid = false;
	    }
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    //the variables of textOf(), set by execute()
    protected $textVars = [];

    //text $v with its {{ }} rendered against the execution's variables (textVars)
    protected function textOf($v) {
	return \util\Expression::toText(\util\Expression::render((string)$v, $this->textVars));
    }

    public function execute($edgeId = null) {
	//the project variables (vars)
	$vars = $this->exec->expressionVariables();
	$this->textVars = $vars;
	$method = (string)$this->cfg('method', 'GET');
	if (!isset(self::METHODS[$method]))
	    throw new \ErrorException("HTTP Request: unknown method `{$method}`");

	$url = trim($this->textOf($this->cfg('url', '')));
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \ErrorException('HTTP Request: the URL must start with http:// or https:// (got `' . mb_substr($url, 0, 100) . '`)');
	$shownUrl = preg_replace('/[?#].*$/', '', $url);

	//query parameters, headers (the credential's added after them: it wins)
	//query parameters as [ name, value ] pairs: a repeated name sends each row (e.g. CheckMK's columns=a&columns=b)
	$query = [];
	foreach((array)$this->cfg('query', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$query[] = [ (string)$row['name'], $this->textOf($row['value'] ?? '') ];
	}
	$headers = [];
	foreach((array)$this->cfg('headers', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$headers[strtolower($row['name'])] = [ $row['name'], str_replace([ "\r", "\n" ], ' ', $this->textOf($row['value'] ?? '')) ];
	}
	$credId = (string)$this->cfg('credential', '');
	if ($credId !== '') {
	    $cred = \models\Credential::findFor($this->exec, $credId, 'http');
	    if (empty($cred))
		throw new \ErrorException('HTTP Request: the credential is no longer available');
	    //a credential shared for some hosts: only there, no redirects
	    if (!$cred->allowsUrlIn($this->exec->project, $url))
		throw new \ErrorException('HTTP Request: the credential `' . $cred->name . '` is shared with this project for other hosts');
	    $auth = $cred->httpAuth();
	    foreach((array)($auth['headers'] ?? []) as $name => $value)
		$headers[strtolower($name)] = [ $name, (string)$value ];
	}
	//self::withQuery
	$url = self::withQuery($url, $query, (array)($auth['query'] ?? []));

	//body
	$body = null;
	if (!in_array($method, [ 'GET', 'HEAD' ], true)) {
	    switch($this->cfg('bodyType', 'none')) {
		case 'json':
		    $value = \util\Expression::render((string)$this->cfg('body', ''), $vars);
		    $body = is_string($value) ? trim($value) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		    $headers += [ 'content-type' => [ 'Content-Type', 'application/json' ] ];
		    break;
		case 'form':
		    $fields = [];
		    foreach((array)$this->cfg('form', []) as $row) {
			if (($row['name'] ?? '') !== '')
			    $fields[$row['name']] = $this->textOf($row['value'] ?? '');
		    }
		    $body = http_build_query($fields, '', '&');
		    $headers += [ 'content-type' => [ 'Content-Type', 'application/x-www-form-urlencoded' ] ];
		    break;
		case 'text':
		    $body = $this->textOf($this->cfg('body', ''));
		    $headers += [ 'content-type' => [ 'Content-Type', 'text/plain; charset=utf-8' ] ];
		    break;
	    }
	}

	//public addresses only unless app.env HTTP_STEPS_ALLOW_PRIVATE
	$response = self::request($method, $url, array_map(fn($h) => $h[0] . ': ' . $h[1], array_values($headers)), $body, (int)$this->cfg('timeout', 30),
	    //a credential shared for some hosts: no redirects
	    (!empty($cred) && $cred->hostLockedIn($this->exec->project) ? [ 'redirects' => 0 ] : []) + self::guard());
	$name = (string)$this->cfg('variable', 'response');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $response);
	$this->exec->log($this->wfid, "{$method} {$shownUrl} -> " . ($response['status'] ?: 'no answer') . " ({$response['time_ms']} ms)" . ($response['error'] ? ': ' . $response['error'] : ''));

	if ($response['ok'])
	    return [ 0 ];
	if ($this->cfg('onError', 'fail') === 'output')
	    return [ 1 ];
	throw new \ErrorException("HTTP Request: {$method} {$shownUrl}: " . $response['error']);
    }

    //the util\Http options of the HTTP steps' requests (HTTP Request, Download File): public addresses only, unless app.env HTTP_STEPS_ALLOW_PRIVATE
    public static function guard() {
	return [ 'public' => !\V::app()->config('http.steps.allow_private') ];
    }

    //one request through util\Http (redirects followed, http / https only; $opts: more util\Http options, the step: `public` unless app.env HTTP_STEPS_ALLOW_PRIVATE):
    //[ ok, status, headers, body, error, time_ms ]
    //$url with the query parameters added - $pairs: [ name, value ] (a repeated name sent once per pair), $override: name => value
    //(the credential's: replaces the pairs of its name); also used by Download File
    public static function withQuery($url, array $pairs, array $override = []) {
	foreach($override as $name => $value) {
	    $pairs = array_values(array_filter($pairs, fn($pair) => $pair[0] !== (string)$name));
	    $pairs[] = [ (string)$name, (string)$value ];
	}
	if (!$pairs)
	    return $url;
	return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', array_map(fn($pair) => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), $pairs));
    }

    public static function request($method, $url, array $headers, $body, $timeout, array $opts = []) {
	$timeout = max(1, min(self::MAX_TIMEOUT, (int)$timeout));
	$r = \util\Http::request($method, $url, $opts + [
	    'headers' => array_merge([ 'Accept: application/json, text/plain, */*' ], $headers),
	    'body' => $body,
	    'timeout' => $timeout,
	    'maxBytes' => self::MAX_RESPONSE,
	    'redirects' => 5,
	    'compressed' => true,
	]);
	[ $data, $contentType, $respHeaders, $ms ] = [ $r['body'], $r['contentType'], $r['headerList'], $r['time_ms'] ];

	//headers by name, a list when repeated; names a database field cannot have are left out
	$hdrs = [];
	foreach($respHeaders as [ $n, $v ]) {
	    $n = strtolower($n);
	    if ($n === '' || str_contains($n, '.') || $n[0] === '$')
		continue;
	    if (!isset($hdrs[$n]))
		$hdrs[$n] = $v;
	    else
		$hdrs[$n] = array_merge((array)$hdrs[$n], [ $v ]);
	}

	//body: JSON parsed, text as it is (UTF-8), anything else left out
	$parsed = null;
	if ($data !== '') {
	    if (preg_match('~[/+]json\b~i', $contentType) && ($json = json_decode($data, true)) !== null)
		$parsed = $json;
	    elseif (mb_check_encoding($data, 'UTF-8'))
		$parsed = $data;
	    elseif (preg_match('~^(text/|application/(xml|javascript))~i', $contentType))
		$parsed = mb_convert_encoding($data, 'UTF-8', preg_match('/charset=([\w-]+)/i', $contentType, $m) ? $m[1] : 'ISO-8859-1');
	}

	$error = null;
	if ($r['tooLarge'])
	    $error = 'the response is larger than ' . (self::MAX_RESPONSE >> 20) . ' MB';
	elseif ($r['error'] !== null)
	    $error = $r['error'];
	elseif ($r['status'] >= 400)
	    $error = "HTTP status {$r['status']}";
	return [
	    'ok' => $error === null,
	    'status' => $r['status'],
	    'headers' => $hdrs,
	    'body' => $parsed,
	    'error' => $error,
	    'time_ms' => $ms,
	];
    }
}
