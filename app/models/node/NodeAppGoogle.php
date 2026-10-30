<?php

/*
    CLD: 2026-10-20T14:00:00+03:00: new class: the base of the Google steps (Google Drive, Google Sheets, Gmail, Google Calendar; not registered itself): what they had each
    their own copy of.
	- the step's shape: one input, the `success` output (+ `error` when On error is "Continue on the `error` output": $outPorts)
	- the settings every Google step has: credential (of the project, providing static::$capability), operation (static::OPERATIONS), timeout, result variable, on error
	    (credentialField(), operationField(), timeoutField(), variableField(), onErrorField()); the credential checked by validates()
	- execute(): the operation run by runOperation() (the subclass), its result put in the variable and logged as "<label> <operation>: <summary> (<n> ms)"; a
	    \RuntimeException (an API error, a value that cannot be used) follows On error (the variable null, the `error` output or the step failed); other errors
	    (expressions, settings) fail the step
	- the API calls: api() on static::API (JSON in and out, a list in the query repeated), call() for any request (the answer kept up to a size), errorText() (Google's
	    message), authHeaders() (the credential's bearer token)
	- helpers: driveId() (an id from a Google link), addresses(), names(), the content types by extension (UPLOAD_TYPES)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGoogle); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\node;

abstract class NodeAppGoogle extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-google';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: the Google steps in Google
    public static $category = 'google';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    //what the step's credentials provide (models\Credential::$provides), how they are named in the settings and messages
    public static $capability = '';
    public static $credentialLabel = 'Google credential';
    public static $credentialKinds = 'Google';
    //the operation of a new step, the result variable's name
    public static $defaultOperation = '';
    public static $defaultVariable = 'google';

    //operation => label; the subclass's
    const OPERATIONS = [];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];

    //the API's base URL; the subclass's
    const API = '';
    //the largest answer of an API call kept (larger: an error)
    const MAX_ANSWER = 5 * 1024 * 1024;

    //the content type of a file, by extension (else application/octet-stream): uploads, attachments
    //CLD: 2026-10-22T00:35:40+03:00: models\File::CONTENT_TYPES (moved to the core: the FTP / SFTP step uses it too)
    const UPLOAD_TYPES = \models\File::CONTENT_TYPES;

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
	    //CLD: 2026-10-24T12:59:05+03:00: without "(menu: Credentials)" (one line in the wide dialog's half column)
	    'help' => $help ?? 'A ' . static::$credentialKinds . ' (OAuth) credential of the project',
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
	    $this->errors('config', static::$credentialLabel . ': choose a ' . static::$credentialKinds . ' credential of this project');
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

    //one call of static::API . $path: the decoded answer; \RuntimeException with Google's message on an error
    protected function api($method, $path, array $query = [], $json = null, $maxBytes = self::MAX_ANSWER) {
	$headers = array_merge($this->authHeaders(), [ 'Accept: application/json' ]);
	$body = null;
	if ($json !== null) {
	    $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	    $headers[] = 'Content-Type: application/json; charset=UTF-8';
	} elseif (in_array($method, [ 'POST', 'PUT', 'PATCH' ], true)) {
	    $body = '';
	}
	return $this->call($method, static::API . $path . self::queryString($query), $headers, $body, $maxBytes);
    }

    //one request (https): the answer decoded as JSON ([] when it is not), kept up to $maxBytes; \RuntimeException on no answer or a status of 400 or more
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

    //?a=1&b=x&b=y (lists repeated, as the Google APIs read them)
    public static function queryString(array $query) {
	$parts = [];
	foreach($query as $name => $value) {
	    foreach(is_array($value) ? $value : [ $value ] as $v)
		$parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$v);
	}
	return $parts ? '?' . implode('&', $parts) : '';
    }

    //an error of an API: Google's message when it gives one
    public static function errorText($status, $body, $fallback) {
	$message = is_array($body) ? ($body['error']['message'] ?? (is_string($body['error'] ?? null) ? $body['error'] : null)) : null;
	$text = (string)($fallback ?: ($status ? "HTTP status {$status}" : 'no answer'));
	return is_string($message) && $message !== '' ? $text . ': ' . mb_substr($message, 0, 300) : $text;
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //the id of a file / folder / document given by id or by a Google link (drive.google.com/file/d/<id>, .../folders/<id>, docs.google.com/.../d/<id>, ?id=<id>)
    public static function driveId($value) {
	$v = trim((string)$value);
	if (preg_match('~/(?:d|folders)/([A-Za-z0-9_-]{10,})~', $v, $m) || preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $v, $m))
	    return $m[1];
	return $v;
    }

    //the addresses of a setting's value: a list, or text separated by commas / semicolons (Name <a@x> too); invalid addresses refused (\RuntimeException)
    public static function addresses($value) {
	$parts = is_array($value) ? array_map(fn($v) => is_array($v) ? (string)($v['email'] ?? '') : \util\Expression::toText($v), array_values($value)) : [ \util\Expression::toText($value) ];
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

    //names separated by commas (trimmed, empty ones and doubles left out)
    public static function names($text) {
	return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$text)), fn($s) => $s !== '')));
    }
}
