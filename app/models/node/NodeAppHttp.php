<?php

/*
    HTTP Request - calls an HTTP endpoint (an API).
    Settings: method, URL, query parameters, headers, body (none / JSON / form / text; JSON checked on save, also as a JSON-RPC 2.0 request when `jsonRpc`),
	an optional credential of the project providing `http` (API key, Basic, Bearer, OAuth: httpAuth()),
	timeout; URL, values and body may hold {{ }} expressions.
	The response goes into one variable (default `response`): { ok, status, headers, body, error, time_ms } - body parsed when it is JSON,
	    headers by lower-case name (a list when repeated).
    On error (no answer, timeout, status 400 or more) - user's choice per step (`onError`): fail the step,
	or continue on a second output `error` (the outputs follow the setting: $outPorts)
*/

namespace models\node;

class NodeAppHttp extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'http';
    public static $label = 'HTTP Request';
    public static $icon = 'bi-globe2';
    public static $color = 'var(--wf-color-app)';
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
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];

    //CLD: 2026-10-23T12:30:00+03:00: the JSON-RPC request proposed when JSON-RPC is switched on
    const JSON_RPC_TEMPLATE = "{\n    \"jsonrpc\": \"2.0\",\n    \"method\": \"\",\n    \"params\": {},\n    \"id\": 1\n}";

    //the largest response body kept, and the timeout limits (seconds)
    const MAX_RESPONSE = 5 * 1024 * 1024;
    const MAX_TIMEOUT = 120;

    //a header name (RFC 7230 token)
    const HEADER_NAME = '^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$';

    public static function configSchema() {
	//no credential: the select's empty choice (ui placeholder)
	$credentials = [];
	foreach(static::credentialChoices() as $c)
	    $credentials[$c['value']] = $c['label'];
	//CLD: 2026-10-18T10:45:00+03:00: nested lists (sys\fields\Field_nested; were arrays checked row by row in validates()): each row cast and validated by pairSchema() (headers:
	//a valid name), its expressions checked, errors as "<itemLabel> <n>: <field>: <message>"; the edit grid's columns come from pairSchema()
	$pairs = fn($key, $label, $itemLabel, $addLabel, $extra = []) => [
	    'key' => $key,
	    'type' => 'nested',
	    'multiple' => true,
	    'fields' => self::pairSchema($key === 'headers'),
	    'label' => $label,
	    'itemLabel' => $itemLabel,
	    'default' => [],
	    'ui' => array_merge([ 'addLabel' => $addLabel, 'compact' => true, 'maxRows' => 50 ], $extra),
	];

	return [
	    [
		'key' => 'method',
		'type' => 'select',
		'label' => 'Method',
		'required' => true,
		'default' => 'GET',
		'choices' => self::METHODS,
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 30,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'URL',
		'required' => true,
		'help' => 'http:// or https://; {{ ... }} for expressions',
		'ui' => [ 'placeholder' => 'https://api.example.com/items', 'expression' => true ],
	    ],
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Credential',
		'default' => '',
		'choices' => $credentials,
		'help' => 'API key, Basic, Bearer or OAuth credential of the project (menu: Credentials), added to the request',
		'ui' => [ 'placeholder' => 'None' ],
	    ],
	    $pairs('query', 'Query parameters', 'Query parameter', 'Add parameter'),
	    $pairs('headers', 'Headers', 'Header', 'Add header'),
	    [
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Body',
		'required' => true,
		'default' => 'none',
		'choices' => self::BODY_TYPES,
		'ui' => [
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ]
		],
	    ],
	    //CLD: 2026-10-23T12:30:00+03:00: JSON-RPC 2.0: the content is checked as a request; switching it on proposes one (ui propose: when the content is empty, else asked)
	    [
		'key' => 'jsonRpc',
		'type' => 'switch',
		'label' => 'JSON-RPC 2.0',
		'default' => false,
		'help' => 'The content is checked as a JSON-RPC request: { "jsonrpc": "2.0", "method": ..., "params": ..., "id": ... }, or a list of them (a batch)',
		'ui' => [
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ],
		    'showIf' => [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ],
		    'propose' => [ 'key' => 'body', 'value' => self::JSON_RPC_TEMPLATE, 'confirm' => 'Replace the content with a JSON-RPC request?' ],
		],
	    ],
	    //CLD: 2026-10-23T12:30:00+03:00: a code editor (app.CodeEditor); its syntax follows the body type (syntaxBy: JSON / JSON-RPC checked live, util\JsonCheck; text: the {{ }} only)
	    [
		'key' => 'body',
		'type' => 'textarea',
		'label' => 'Content',
		'help' => 'JSON: a JSON text, or {{ ... }} giving an object / list',
		'ui' => [ 'rows' => 8, 'expression' => 'text', 'code' => true,
		    'syntaxBy' => [
			[ 'syntax' => 'jsonrpc', 'if' => [ [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ], [ 'key' => 'jsonRpc', 'operator' => 'eq', 'value' => true ] ] ],
			[ 'syntax' => 'json', 'if' => [ [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'json' ] ] ],
		    ],
		    'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ], 'showIf' => [ 'key' => 'bodyType', 'operator' => 'in', 'value' => [ 'json', 'text' ] ] ],
	    ],
	    $pairs('form', 'Form fields', 'Form field', 'Add field', [ 'hideIf' => [ 'key' => 'method', 'operator' => 'in', 'value' => [ 'GET', 'HEAD' ] ], 'showIf' => [ 'key' => 'bodyType', 'operator' => 'eq', 'value' => 'form' ] ]),
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Response variable',
		'required' => true,
		'default' => 'response',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'No answer, timeout, or a status of 400 or more',
	    ],
	];
    }

    //one row of query parameters / headers / form fields
    //CLD: 2026-10-18T10:45:00+03:00: + $header: the name must be a header name (was checked by validates()); the name is trimmed by the field (was by validates())
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
	//CLD: 2026-10-13T12:34:29+03:00: + the fields and their types (suggested after `response.`)
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the HTTP response: { ok, status, headers, body, error, time_ms }', 'fields' => [
	    'ok' => [ 'type' => 'boolean' ], 'status' => [ 'type' => 'number' ], 'headers' => [ 'type' => 'object', 'info' => 'lower-case name => value (a list when repeated)' ], 'body' => [ 'type' => 'any', 'info' => 'JSON decoded, else text' ],
	    'error' => [ 'type' => 'string' ], 'time_ms' => [ 'type' => 'number' ] ] ] ] : [];
    }

    //the config, each row of the lists through the row form, header names, a credential of the workflow's project
    //CLD: 2026-10-18T10:45:00+03:00: the rows of the lists (names, header names, expressions) are checked and normalized by parent::validates (nested fields); here the credential
    public function validates($options = []) {
	$valid = parent::validates($options);

	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'http')))) {
	    $this->errors('config', 'Credential: choose an HTTP credential of this project');
	    $valid = false;
	}
	//CLD: 2026-10-23T12:30:00+03:00: a JSON body must be JSON (a JSON-RPC request when switched on); the {{ }} parts are checked by parent::validates
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

    public function execute($edgeId = null) {
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables (vars)
	$vars = $this->exec->expressionVariables();
	$text = fn($v) => \util\Expression::toText(\util\Expression::render((string)$v, $vars));
	$method = (string)$this->cfg('method', 'GET');
	if (!isset(self::METHODS[$method]))
	    throw new \ErrorException("HTTP Request: unknown method `{$method}`");

	$url = trim($text($this->cfg('url', '')));
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \ErrorException('HTTP Request: the URL must start with http:// or https:// (got `' . mb_substr($url, 0, 100) . '`)');
	$shownUrl = preg_replace('/[?#].*$/', '', $url);

	//query parameters, headers (the credential's added after them: it wins)
	$query = [];
	foreach((array)$this->cfg('query', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$query[$row['name']] = $text($row['value'] ?? '');
	}
	$headers = [];
	foreach((array)$this->cfg('headers', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$headers[strtolower($row['name'])] = [ $row['name'], str_replace([ "\r", "\n" ], ' ', $text($row['value'] ?? '')) ];
	}
	$credId = (string)$this->cfg('credential', '');
	if ($credId !== '') {
	    $cred = \models\Credential::findFor($this->exec, $credId, 'http');
	    if (empty($cred))
		throw new \ErrorException('HTTP Request: the credential is no longer available');
	    $auth = $cred->httpAuth();
	    foreach((array)($auth['headers'] ?? []) as $name => $value)
		$headers[strtolower($name)] = [ $name, (string)$value ];
	    foreach((array)($auth['query'] ?? []) as $name => $value)
		$query[$name] = (string)$value;
	}
	if ($query)
	    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

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
			    $fields[$row['name']] = $text($row['value'] ?? '');
		    }
		    $body = http_build_query($fields, '', '&');
		    $headers += [ 'content-type' => [ 'Content-Type', 'application/x-www-form-urlencoded' ] ];
		    break;
		case 'text':
		    $body = $text($this->cfg('body', ''));
		    $headers += [ 'content-type' => [ 'Content-Type', 'text/plain; charset=utf-8' ] ];
		    break;
	    }
	}

	$response = self::request($method, $url, array_map(fn($h) => $h[0] . ': ' . $h[1], array_values($headers)), $body, (int)$this->cfg('timeout', 30));
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

    //one request (redirects followed, http / https only): [ ok, status, headers, body, error, time_ms ]
    public static function request($method, $url, array $headers, $body, $timeout) {
	$timeout = max(1, min(self::MAX_TIMEOUT, (int)$timeout));
	$respHeaders = [];
	$data = '';
	$tooLarge = false;
	$ch = curl_init($url);
	$opts = [
	    CURLOPT_CUSTOMREQUEST => $method,
	    CURLOPT_HTTPHEADER => array_merge([ 'User-Agent: QSM-Workflow', 'Accept: application/json, text/plain, */*' ], $headers),
	    CURLOPT_FOLLOWLOCATION => true,
	    CURLOPT_MAXREDIRS => 5,
	    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
	    CURLOPT_TIMEOUT => $timeout,
	    CURLOPT_ENCODING => '',
	    //the headers of the last response only (a redirect starts a new set)
	    CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$respHeaders) {
		if (preg_match('~^HTTP/\S+\s+\d+~', $line))
		    $respHeaders = [];
		elseif (($p = strpos($line, ':')) !== false)
		    $respHeaders[] = [ strtolower(trim(substr($line, 0, $p))), trim(substr($line, $p + 1)) ];
		return strlen($line);
	    },
	    CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$data, &$tooLarge) {
		if (strlen($data) + strlen($chunk) > self::MAX_RESPONSE) {
		    $tooLarge = true;
		    return 0;
		}
		$data .= $chunk;
		return strlen($chunk);
	    },
	];
	if ($method === 'HEAD')
	    $opts[CURLOPT_NOBODY] = true;
	if ($body !== null)
	    $opts[CURLOPT_POSTFIELDS] = $body;
	curl_setopt_array($ch, $opts);
	$start = microtime(true);
	$okExec = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$curlError = $okExec === false ? curl_error($ch) : '';
	$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	$ms = (int)round((microtime(true) - $start) * 1000);

	//headers by name, a list when repeated; names a database field cannot have are left out
	$hdrs = [];
	foreach($respHeaders as [ $n, $v ]) {
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
	if ($tooLarge)
	    $error = 'the response is larger than ' . (self::MAX_RESPONSE >> 20) . ' MB';
	elseif ($curlError !== '')
	    $error = $curlError;
	elseif ($status >= 400)
	    $error = "HTTP status {$status}";
	return [
	    'ok' => $error === null,
	    'status' => $status,
	    'headers' => $hdrs,
	    'body' => $parsed,
	    'error' => $error,
	    'time_ms' => $ms,
	];
    }
}
