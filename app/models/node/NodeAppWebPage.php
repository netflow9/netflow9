<?php

/*
    CLD: 2026-10-30T15:00:00+02:00: new step: Read a web page - the basic HTTP Request (its requests: NodeAppHttp::request, public addresses only unless app.env
    HTTP_STEPS_ALLOW_PRIVATE): a GET of a URL, no credential / parameters / headers / body. Settings: URL (may hold {{ }} expressions), what to keep (the page's readable
    text, or its HTML as it is), the variable (default `page`), on error (fail the step, or continue on a second output `error`).
    The variable gets { ok, status, url, title, content, contentType, error } - content: the text / the HTML (a JSON answer: the data parsed).
*/

namespace models\node;

class NodeAppWebPage extends NodeAppHttp {
    public static $audience = 'basic';
    public static $category = 'web';
    public static $name = 'webpage';
    public static $label = 'Read a web page';
    public static $icon = 'bi-file-earmark-richtext';

    const KEEP = [ 'text' => 'The text of the page', 'html' => 'The page as it is (HTML)' ];
    const TIMEOUT = 30;

    public static function configSchema() {
	$http = [];
	foreach(parent::configSchema() as $f)
	    $http[$f['key']] = $f;

	$http['url']['label'] = 'Page address';
	$http['url']['help'] = 'http:// or https://';
	$http['url']['ui']['placeholder'] = 'https://www.example.com/news';
	$http['url']['ui']['width'] = 8;
	$http['variable']['label'] = 'Page variable';
	$http['variable']['default'] = 'page';

	return [
	    $http['variable'],
	    $http['onError'],
	    $http['url'],
	    [
		'key' => 'keep',
		'type' => 'select',
		'label' => 'Keep',
		'required' => true,
		'default' => 'text',
		'choices' => self::KEEP,
		'ui' => [ 'width' => 4 ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'page') : 'page';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the page: { ok, status, url, title, content, contentType, error }', 'fields' => [
	    'ok' => [ 'type' => 'boolean' ], 'status' => [ 'type' => 'number' ], 'url' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string', 'info' => 'the HTML title' ],
	    'content' => [ 'type' => 'any', 'info' => 'the text or the HTML (as chosen); a JSON answer: the data' ], 'contentType' => [ 'type' => 'string' ],
	    'error' => [ 'type' => 'string' ] ] ] ] : [];
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$url = trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg('url', ''), $vars)));
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \ErrorException('Read a web page: the address must start with http:// or https:// (got `' . mb_substr($url, 0, 100) . '`)');
	$shownUrl = preg_replace('/[?#].*$/', '', $url);

	$response = self::request('GET', $url, [], null, self::TIMEOUT, self::guard());
	$contentType = (string)(is_array($response['headers']['content-type'] ?? null) ? end($response['headers']['content-type']) : ($response['headers']['content-type'] ?? ''));
	$content = $response['body'];
	$title = '';
	if (is_string($content) && preg_match('~<title\b[^>]*>(.*?)</title>~is', $content, $m))
	    $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
	if (is_string($content) && $this->cfg('keep', 'text') === 'text' && preg_match('~html~i', $contentType))
	    $content = \models\node\NodeAppGmail::htmlToText(preg_replace('~<head\b.*?</head>~is', '', $content));

	$page = [
	    'ok' => $response['ok'],
	    'status' => $response['status'],
	    'url' => $url,
	    'title' => $title,
	    'content' => $content,
	    'contentType' => $contentType,
	    'error' => $response['error'],
	];
	$name = (string)$this->cfg('variable', 'page');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $page);
	$this->exec->log($this->wfid, "GET {$shownUrl} -> " . ($response['status'] ?: 'no answer') . " ({$response['time_ms']} ms)" . ($response['error'] ? ': ' . $response['error'] : ''));

	if ($response['ok'])
	    return [ 0 ];
	if ($this->cfg('onError', 'fail') === 'output')
	    return [ 1 ];
	throw new \ErrorException("Read a web page: {$shownUrl}: " . $response['error']);
    }
}
