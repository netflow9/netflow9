<?php

namespace sys\http;

// Response
// This is a simple abstraction over top an HTTP response. This provides methods to set the HTTP status, the HTTP headers, and the HTTP body.

class Response extends \sys\BaseObject {
    public $body = '';
    public $status = 200;
    public $headers = [];

    //CLD: 2026-10-21T18:45:00+03:00: browser security headers of every response (SOC2.md gap 14; nginx adds HSTS, nosniff and Referrer-Policy):
    //	- frame-ancestors / X-Frame-Options: pages are framed by this site only (clickjacking);
    //	- scripts, styles, images, fonts, connections, workers and frames from this site only (data: / blob: where the viewers and the QR codes need them); no plugins
    //	  (object-src), no <base> elsewhere, forms post here only;
    //	- 'unsafe-inline' and 'unsafe-eval' stay for scripts: the pages add inline scripts (ClientScript::addPageScript) and the client templates / smart form expressions
    //	  are compiled with new Function (app.js, app.smartform.js); dropping them needs nonces on those scripts and precompiled templates
    const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; "
	. "connect-src 'self'; worker-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function __construct() {
	$this->headers = [
	    'Content-Type' => 'text/html',
	    //CLD: 2026-10-21T18:45:00+03:00: see CSP above
	    'Content-Security-Policy' => self::CSP,
	    'X-Frame-Options' => 'SAMEORIGIN',
	    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
	];
    }

    public function addHeader($key, $val) {
	if (empty($key))
	    return;
	$this->headers[$key] = $val;
    }

    public function redirect($url, $terminate = true, $status = 302) {
	switch(true) {
	    case \V::app()->request->is('pjax'):
		//redirect via pjax: change status and set X-Pjax-Redirect
		$this->headers['X-Pjax-Redirect'] = $url;
		$this->status = 200;
		break;
	    case \V::app()->request->is('ajax'):
		//redirect via ajax: change status and set X-Redirect
		$this->headers['X-Redirect'] = $url;
		$this->status = 200;
		break;
	    default:
		//normal redirect, via location and status code=200
		$this->headers['Location'] = $url;
		$this->status = $status;
	}

	$this->body = '';

	if($terminate)
	    \V::app()->end();
    }

    public function write($body = '', $replace = false) {
	if ($replace)
	    $this->body = '';
	
	$this->body .= $body;
    }


    public function addCookie($cookie) {
	setcookie($cookie->name, $cookie->value, $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
    }

    public function removeCookie($cookie) {
	setcookie($cookie->name, '', $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
    }

    public function output() {
	if (in_array($this->status, [ 204, 304 ])) {
            unset($this->headers['Content-Type']);
            $this->body = '';
	}

	http_response_code((int)$this->status);

        //Send headers
        if (!headers_sent($f, $l)) {
            //Send headers
            foreach ($this->headers as $name => $value) {
                $hValues = explode("\n", $value);
                foreach ($hValues as $hVal)
                    header("$name: $hVal", false);
            }
        } else {
    	    \V::app()->log->debug("Response::output: headers already sent in file `$f` at line `$l`!!!");
    	    \V::app()->log->debug("Response::output: current headers:", print_r($this->headers, true));
        }

        //Send body
    	echo $this->body;
    }

    //SC: 2026-10-18: added
    //CLD: 2026-10-18T12:49:28+03:00: + $flags (added to util\JSON::FLAGS, e.g. JSON_UNESCAPED_SLASHES)
    public function sendJSON($data, $terminate = true, $status = 200, $flags = 0) {
	$this->status = $status;
	$this->headers['Content-Type'] = 'application/json';
	$this->headers['Cache-Control'] = 'no-store';
	$this->body = \util\JSON::encode($data, $flags);

	//CLD: 2026-10-18T12:49:28+03:00: a value that cannot be encoded (NAN / INF, too deep) gave an empty body with the given status: a 500 with { error } instead
	if ($this->body === false) {
	    \V::app()->log->error('Response::sendJSON: ' . json_last_error_msg());
	    $this->status = 500;
	    $this->body = \util\JSON::encode([ 'error' => 'Response could not be encoded' ]);
	}

	//CLD: 2026-10-18T18:34:20+03:00: output buffers are no longer used by the framework (was: ob_clean() before ending)
	if($terminate)
	    \V::app()->end();
    }


}
