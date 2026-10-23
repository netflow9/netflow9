<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new class: the base of the Microsoft Graph steps (Outlook Mail, Outlook Calendar, OneDrive / SharePoint, Excel, Teams; not registered
    itself), as NodeAppGoogle is the Google steps' (GoogleIntegration plugin).
	- the step's shape: one input, the `success` output (+ `error` when On error is "Continue on the `error` output": $outPorts)
	- the settings every Microsoft step has: credential (of the project, providing static::$capability), operation (static::OPERATIONS), timeout, result variable, on error
	    (credentialField(), operationField(), timeoutField(), variableField(), onErrorField()); the credential checked by validates()
	- execute(): the operation run by runOperation() (the subclass), its result put in the variable and logged as "<label> <operation>: <summary> (<n> ms)"; a
	    \RuntimeException (an API error, a value that cannot be used) follows On error (the variable null, the `error` output or the step failed); other errors
	    (expressions, settings) fail the step
	- the API calls: api() on Microsoft Graph v1.0 (JSON in and out), pages() (a list followed through @odata.nextLink up to a number of items), call() for any request
	    (the answer kept up to a size), errorText() (Graph's code and message), authHeaders() (the credential's bearer token)
	- helpers: addresses(), recipients() (Graph's [ { emailAddress: { address } } ]), names(), the content types by extension (models\File::CONTENT_TYPES)
*/

namespace plugins\MicrosoftIntegration\models;

abstract class NodeAppMicrosoft extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-microsoft';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    //what the step's credentials provide (models\Credential::$provides), how they are named in the settings and messages
    public static $capability = '';
    public static $credentialLabel = 'Microsoft credential';
    public static $credentialKinds = 'Microsoft';
    //the operation of a new step, the result variable's name
    public static $defaultOperation = '';
    public static $defaultVariable = 'microsoft';

    //operation => label; the subclass's
    const OPERATIONS = [];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];

    const API = 'https://graph.microsoft.com/v1.0';
    //the largest answer of an API call kept (larger: an error)
    const MAX_ANSWER = 5 * 1024 * 1024;

    //the credential of the run (execute)
    protected $cred = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting's value as trimmed text, $value($key): its value ({{ }} evaluated, a single expression keeps its type)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    // ------------------------------------------------------------------------------------
    //settings

    //credentials of the current project providing static::$capability, for the designer
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor(static::$capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    protected static function credentialField($help = null) {
	$choices = [];
	foreach(static::credentialChoices() as $c)
	    $choices[$c['value']] = $c['label'];
	return [
	    'key' => 'credential',
	    'type' => 'select',
	    'label' => static::$credentialLabel,
	    'choices' => $choices,
	    'help' => $help ?? ucfirst(self::article(static::$credentialKinds)) . ' (Microsoft Graph) credential of the project (menu: Credentials)',
	    'required' => true,
	];
    }

    protected static function operationField() {
	return [
	    'key' => 'operation',
	    'type' => 'select',
	    'label' => 'Operation',
	    'required' => true,
	    'default' => static::$defaultOperation,
	    'choices' => static::OPERATIONS,
	];
    }

    protected static function timeoutField($default = 60, $max = \models\node\NodeAppHttp::MAX_TIMEOUT, $help = null) {
	return [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => $default,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => $max, 'skipEmpty' => true, 'message' => "from 1 to {$max}" ] ],
	    'ui' => [ 'step' => 1, 'width' => 6 ],
	] + ($help !== null ? [ 'help' => $help ] : []);
    }

    protected static function variableField($help = null) {
	return [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	] + ($help !== null ? [ 'help' => $help ] : []);
    }

    protected static function onErrorField($help) {
	return [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => $help,
	];
    }

    //the result variable's name of $config; null when it is not a name
    protected static function variableName($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	return \util\Validator::isIdentifier($name) ? $name : null;
    }

    //the credential: of the workflow's project, providing static::$capability
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::$capability)))) {
	    $this->errors('config', static::$credentialLabel . ': choose ' . self::article(static::$credentialKinds) . ' credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$value = fn($key) => \util\Expression::render((string)$this->cfg($key, ''), $vars);
	$text = fn($key) => trim(\util\Expression::toText($value($key)));
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");

	$this->cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::$capability);
	if (empty($this->cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $text, $value);
	} catch (\RuntimeException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    // ------------------------------------------------------------------------------------
    //the API

    protected function authHeaders() {
	return [ 'Authorization: Bearer ' . $this->cred->accessToken() ];
    }

    //the step's timeout for an API call (seconds)
    protected function apiTimeout() {
	return max(1, min(\models\node\NodeAppHttp::MAX_TIMEOUT, (int)$this->cfg('timeout', 60)));
    }

    //one call of Graph ($path from /v1.0, or a full https://graph.microsoft.com URL, e.g. an @odata.nextLink): the decoded answer; \RuntimeException with Graph's message
    //on an error; $headers: more request headers (e.g. Prefer, ConsistencyLevel)
    protected function api($method, $path, array $query = [], $json = null, array $headers = [], $maxBytes = self::MAX_ANSWER) {
	$headers = array_merge($this->authHeaders(), [ 'Accept: application/json' ], $headers);
	$body = null;
	if ($json !== null) {
	    $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	    $headers[] = 'Content-Type: application/json; charset=UTF-8';
	} elseif (in_array($method, [ 'POST', 'PUT', 'PATCH' ], true)) {
	    $body = '';
	}
	$url = str_starts_with($path, 'https://') ? $path : static::API . $path . self::queryString($query);
	if (!str_starts_with($url, 'https://graph.microsoft.com/'))
	    throw new \RuntimeException('not a Microsoft Graph address: `' . mb_substr($url, 0, 100) . '`');
	return $this->call($method, $url, $headers, $body, $maxBytes);
    }

    //the items of a list ($answer['value']) followed through @odata.nextLink, $max at most: [ items, more (true when the list goes on) ]
    protected function pages($path, array $query, $max, array $headers = []) {
	$items = [];
	$answer = $this->api('GET', $path, $query, null, $headers);
	while (true) {
	    foreach((array)($answer['value'] ?? []) as $item) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $item;
	    }
	    $next = $answer['@odata.nextLink'] ?? null;
	    if (!is_string($next) || $next === '' || count($items) >= $max)
		return [ $items, is_string($next) && $next !== '' ];
	    $answer = $this->api('GET', $next, [], null, $headers);
	}
    }

    //one request (https): the answer decoded as JSON ([] when it is not, e.g. 202 / 204), kept up to $maxBytes; \RuntimeException on no answer or a status of 400 or more
    protected function call($method, $url, array $headers, $body, $maxBytes = self::MAX_ANSWER) {
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here)
	$r = \util\Http::request($method, $url, [ 'headers' => $headers, 'body' => $body, 'timeout' => $this->apiTimeout(), 'maxBytes' => $maxBytes, 'https' => true, 'compressed' => true ]);
	if ($r['tooLarge'])
	    throw new \RuntimeException('the answer is larger than ' . (int)($maxBytes / 1048576) . ' MB');
	[ $status, $curlError ] = [ $r['status'], (string)$r['error'] ];
	$answer = json_decode($r['body'], true);
	if ($curlError !== '' || $status >= 400)
	    throw new \RuntimeException(self::errorText($status, $answer, $curlError !== '' ? $curlError : "HTTP status {$status}"));
	return is_array($answer) ? $answer : [];
    }

    //?a=1&b=x ($select, $filter, ... as they are: rawurlencode keeps the $ of the name encoded, which Graph reads)
    public static function queryString(array $query) {
	$parts = [];
	foreach($query as $name => $value) {
	    if ($value === null || $value === '')
		continue;
	    $parts[] = str_replace('%24', '$', rawurlencode((string)$name)) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
	}
	return $parts ? '?' . implode('&', $parts) : '';
    }

    //an error of Graph: { error: { code, message } } -> "<fallback>: <message> (<code>)"
    public static function errorText($status, $body, $fallback) {
	$error = is_array($body) ? ($body['error'] ?? null) : null;
	$message = is_array($error) ? ($error['message'] ?? null) : (is_string($error) ? $error : null);
	$code = is_array($error) ? ($error['code'] ?? null) : null;
	$text = (string)($fallback ?: ($status ? "HTTP status {$status}" : 'no answer'));
	if (!is_string($message) || $message === '')
	    return $text . (is_string($code) && $code !== '' ? ": {$code}" : '');
	return $text . ': ' . mb_substr($message, 0, 300) . (is_string($code) && $code !== '' ? " ({$code})" : '');
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //the addresses of a setting's value: a list, or text separated by commas / semicolons (Name <a@x> too); invalid addresses refused (\RuntimeException)
    public static function addresses($value) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? (string)($v['email'] ?? $v['address'] ?? '') : \util\Expression::toText($v), array_values($value)) : [ \util\Expression::toText($value) ];
	$emails = [];
	foreach($parts as $part) {
	    $found = \util\MailParser::parseAddresses(str_replace(';', ',', $part));
	    if (trim($part) !== '' && !$found)
		throw new \RuntimeException('not an email address: `' . mb_substr($part, 0, 100) . '`');
	    foreach($found as $a)
		$emails[] = trim($a['email']);
	}
	foreach($emails as $email) {
	    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
		throw new \RuntimeException('not an email address: `' . mb_substr($email, 0, 100) . '`');
	}
	return array_values(array_unique($emails));
    }

    //Graph's recipients of a setting's value: [ { emailAddress: { address } } ]
    public static function recipients($value) {
	return array_map(fn($a) => [ 'emailAddress' => [ 'address' => $a ] ], self::addresses($value));
    }

    //the addresses of Graph's recipients
    public static function addressesOf($recipients) {
	return array_values(array_filter(array_map(fn($r) => (string)($r['emailAddress']['address'] ?? ''), (array)$recipients), fn($a) => $a !== ''));
    }

    //"a <kind>" / "an <kind>" (Outlook, OneDrive)
    public static function article($kind) {
	return (preg_match('/^[AEIOU]/i', (string)$kind) ? 'an ' : 'a ') . $kind;
    }

    //names separated by commas (trimmed, empty ones and doubles left out)
    public static function names($text) {
	return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$text)), fn($s) => $s !== '')));
    }

    //an id of Graph (messages, events, items, ...): letters, digits, - _ = + / ! . , :; \RuntimeException when it is not
    public static function graphId($id, $what) {
	$id = trim((string)$id);
	if (!preg_match('~^[A-Za-z0-9_=+/!.,:@-]{1,1024}$~', $id))
	    throw new \RuntimeException($id === '' ? "no {$what} given" : "not a {$what} id: `" . mb_substr($id, 0, 100) . '`');
	//in a path: the / and + of base64 ids encoded
	return rawurlencode($id);
    }
}
