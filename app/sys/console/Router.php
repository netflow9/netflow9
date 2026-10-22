<?php

namespace sys\console;

class Router {

    /**
     * Parse incoming request from console. Short and long (GNU-style) options
     * in the form of `-f`, `--foo`, `--foo-bar` and `--foo=bar` are parsed.
     * XF68-style long options (i.e. `-foo`) are not supported but support
     * can be added by extending this class.
     *
     * @param object $request lithium\console\Request
     * @return array $params
     */
    public function parseRequest($request = null) {
	//command::action is in $request->command
	//if it contains '::' replace them with '/' so that a standard route is obtained
	$route = $request->command;
	$route = str_replace('::', '/', $route);
	return $route;
    }

    public function createPathInfo($params, $equal, $ampersand, $key=null) {
	$pairs = array();
	foreach($params as $k => $v) {
	    if ($key!==null)
		$k = $key.'['.$k.']';

	    if (is_array($v))
		$pairs[] = $this->createPathInfo($v, $equal, $ampersand, $k);
	    else
		$pairs[] = urlencode($k) . $equal . urlencode($v);
	}
	return implode($ampersand, $pairs);
    }

    public function createUrl($route, $params = [], $ampersand = '&') {
	foreach($params as $i => $param)
	    if($param === null)
		$params[$i] = '';

	$route = trim($route,'/');

	$query = $this->createPathInfo($params, '=', $ampersand);
	
	if ($query)
	    $route .= '?' . $query;

	return '/' . $route;
    }

    //just a placeholder
    public function add_rule($pattern, $route, $prepend = false) {
    }


}
