<?php

//the license notice of Lithium (li3), the framework this file is ported from (lithium\console\Router)
/*
 * Copyright (c) 2009, Union of RAD http://union-of-rad.org
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright notice,
 * 		this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright notice,
 * 		this list of conditions and the following disclaimer in the documentation
 * 		and/or other materials provided with the distribution.
 *     * Neither the name of Lithium, Union of Rad, nor the names of its contributors
 * 		may be used to endorse or promote products derived from this software
 * 		without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 * OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
 * EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

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
