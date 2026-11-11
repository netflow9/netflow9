<?php

namespace sys\http;

class Request {
    public $query;
    public $data;
    public $cookie;
    public $files;
    public $method;
    //the request headers: lower-case name => value (HTTP_* of $_SERVER, and content-type / content-length)
    public $headers = [];
    public $contentType;
    //the content type without its parameters (application/json; charset=utf-8: application/json), lower case
    public $mediaType = '';
    //a JSON body that does not parse (data is null)
    public $bodyInvalid = false;
    public $userIP = '0.0.0.0';

    public $httpHost;
    public $requestUri;
    public $uri = [];

    //the body as sent: body() reads it once
    protected $body = null;


    //$config['trusted_proxies'] (config app.trusted_proxies): see userIP below
    public function __construct($config = []) {
	$this->query = $_GET;
	$this->data = $_POST;
	$this->cookie = $_COOKIE;
	$this->files = $_FILES;
	$this->method = strtolower($_SERVER['REQUEST_METHOD']);

	if (!empty($_SERVER['CONTENT_TYPE']))
	    $this->contentType = strtolower($_SERVER['CONTENT_TYPE']);
	$this->mediaType = trim(explode(';', (string)$this->contentType)[0]);

	foreach($_SERVER as $key => $value) {
	    if (str_starts_with($key, 'HTTP_'))
		$this->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string)$value;
	    elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH')
		$this->headers[strtolower(str_replace('_', '-', $key))] = (string)$value;
	}

	//a form body of another method than POST (PHP parses POST's only)
	if ($this->method !== 'post' && $this->mediaType === 'application/x-www-form-urlencoded') {
	    $result = [];
	    mb_parse_str($this->body(), $result);
	    $this->data = $result;
	}

	//a JSON body (by its media type); one that does not parse: bodyInvalid
	if ($this->mediaType === 'application/json') {
	    $this->data = $this->body() === '' ? null : json_decode($this->body(), true);
	    $this->bodyInvalid = $this->body() !== '' && json_last_error() !== JSON_ERROR_NONE;
	}

	//the client's address is the connection's; X-Forwarded-For only when the connection comes from a trusted proxy: its last address that is not one of them
	if (isset($_SERVER['REMOTE_ADDR']))
	    $this->userIP = $_SERVER['REMOTE_ADDR'];
	$proxies = $config['trusted_proxies'] ?? [];
	if (!empty($proxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && static::ipIn($this->userIP, $proxies)) {
	    foreach(array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']))) as $ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP))
		    break;
		$this->userIP = $ip;
		if (!static::ipIn($ip, $proxies))
		    break;
	    }
	}

	$this->requestUri = $_SERVER['REQUEST_URI'];
	$this->httpHost = rtrim(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'], '.');

	//parse the url
	$this->uri = parse_url($this->requestUri);
    }

    //the request body as sent (php://input, read once)
    public function body() {
	if ($this->body === null)
	    $this->body = (string)file_get_contents('php://input');
	return $this->body;
    }

    //whether address $ip is one of $list (addresses or CIDR ranges, IPv4 or IPv6)
    public static function ipIn($ip, array $list) {
	$bin = @inet_pton((string)$ip);
	if ($bin === false)
	    return false;
	foreach($list as $item) {
	    [ $net, $bits ] = array_pad(explode('/', (string)$item, 2), 2, null);
	    $netBin = @inet_pton($net);
	    if ($netBin === false || strlen($netBin) != strlen($bin))
		continue;
	    $bits = $bits === null ? strlen($bin) * 8 : (int)$bits;
	    $bytes = intdiv($bits, 8);
	    if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes))
		continue;
	    $rest = $bits % 8;
	    if ($rest == 0)
		return true;
	    $mask = chr((0xff << (8 - $rest)) & 0xff);
	    if ((($bin[$bytes] & $mask) === ($netBin[$bytes] & $mask)))
		return true;
	}
	return false;
    }

    // spec might be: post, post+ajax, ajax, post+pjax
    public function is($spec = 'get') {
	$parts = explode('+', strtolower($spec));
	foreach($parts as $part) {
	    //method
	    if (in_array($part, [ 'get', 'post', 'put', 'patch', 'delete', 'options' ]) && $this->method != $part)
		return false;

	    //ajax, pjax
	    if ($part == 'pjax' && !isset($_SERVER['HTTP_X_PJAX']))
		    return false;
	    if ($part == 'ajax') {
		if (empty($_SERVER['HTTP_X_REQUESTED_WITH']))
		    return false;
		if ($_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest')
		    return false;
	    }
	}
	return true;
    }


    public function getQueryOrDataParam($key, $default = null) {
	$value = $this->getParam('query', $key, $default);
	if ($value === $default)
	    $value = $this->getParam('data', $key, $default);
	return $value;
    }


    //get
    public function getQueryParam($key, $default = null) {
	return $this->getParam('query', $key, $default);
    }
    public function getDataParam($key, $default = null) {
	return $this->getParam('data', $key, $default);
    }
    public function getParam($type, $key, $default) {
	if ($type == 'query')
	    return $this->query[$key] ?? $default;
	if ($type == 'data')
	    return $this->data[$key] ?? $default;
	return $default;
    }

    //remove
    public function removeQueryParam($name) {
	$this->removeParam('query', $name);
    }

    public function removeDataParam($name) {
	$this->removeParam('data', $name);
    }
    //data that is not an array (a JSON body of one value: "text", 5) has no keys to remove (unset of a string offset is an error)
    public function removeParam($type, $key) {
	if ($type == 'query')
	    unset($this->query[$key]);
	if ($type == 'data' && is_array($this->data))
	    unset($this->data[$key]);
    }

    //add
    public function addQueryParam($name, $value) {
	$this->addParam('query', $name, $value);
    }
    public function addDataParam($name, $value) {
	$this->addParam('data', $name, $value);
    }
    public function addParam($type = 'query', $name, $value) {
	if ($type == 'query')
	    $this->query[$name] = $value;
	if ($type == 'data')
	    $this->data[$name] = $value;
    }


}


