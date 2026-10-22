<?php

namespace sys;

// Implements a basic controller functionallity.
class BaseController extends \sys\BaseObject {
    public $layout = null;

    public $events = [];

    public function _init() {
	parent::_init();

	if (\V::app()->config('app.mode') == 'web')
    	    \V::app()->hooks->addHook('on:before-action', [ $this, 'validateRequest' ]);
    }

    public function validateRequest() {
	if (empty(\V::app()->csrf) || \V::app()->request->is('get'))
	    return;

	//if ajax or pjax get CSRF token from server header instead of post
	if (\V::app()->request->is('ajax') || \V::app()->request->is('pjax')) {
	    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
	} else {
	    $csrf_token = \V::app()->request->data[\V::app()->csrf->getKey()] ?? null;
	}
	\V::app()->request->removeDataParam(\V::app()->csrf->getKey());

	//CLD: 2026-10-05T20:20:40+03:00: constant-time, type-strict comparison in CSRF::validate (was `!=` against getToken(), which also created a token when missing)
	if (!\V::app()->csrf->validate($csrf_token))
	    \V::app()->error(401, 'Unable to validate your request');
    }

    public function run() {
	echo "function run should be implemented\n";
    }

    //--------------------------------------------------
    public function addToast($type, $title, $body) {
	$this->events[] = [ 'type' => 'toast::add', 'data' => [ 'type' => $type, 'title' => $title, 'body' => $body ] ];
    }

    public function addEvent($type, $data = []) {
	$this->events[] = [ 'type' => $type, 'data' => $data ?? [] ];
    }

    //send events and stop
    public function sendEvents() {
	//CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was addHeader + write + end)
	\V::app()->response->sendJSON(['meta' => $this->events]);
    }

    //render a template
    public function render($template, $data = []) {
    	//should append controllerid in front of view
    	if(!empty($template) && $template[0] != '/')
    	    $template = $this->id . '/' . $template;

	if ($this->layout === null && \V::app()->request->is('pjax'))
	    $this->layout = 'pjax';
	
	if ($this->layout === null && \V::app()->request->is('ajax'))
	    $this->layout = 'ajax';

	//render the template
	\V::app()->view->meta = $this->events;
	return \V::app()->view->render($template, $data, $this->layout);
    }

    // Performs redirect
    protected function redirect($route, $params = []) {
	$url = \V::app()->createUrl($route, $params);

	//if request is pjax, render the `pjax` layout with redirect event in meta
	if (\V::app()->request->is('pjax')) {
	    $this->addEvent('pjax::redirect', [ 'url' => $url ]);

	    //render with no view(template)
	    $this->render(false, [ 'content' => 'OK' ]);
	    \V::app()->end();
	}

	return \V::app()->response->redirect($url);
    }

    //---------------------------------------------------------
    protected function getOidOrError($key = 'id') {
	$id = \V::app()->request->query[$key] ?? '';

	if (empty($id))
	    \V::app()->error(400, "malformed request: $key missing");
	if (!\util\Validator::rule('oid', $id))
	    \V::app()->error(400, "malformed request: $key not oid");

	return $id;
    }

    protected function getAlphanumericParam($key, $default = null) {
	$value = \V::app()->request->query[$key] ?? $default;
	if (!empty($value) && !\util\Validator::rule('alphanumeric', $value))
	    \V::app()->error(400, 'malformed request: not alphanumeric');

	return (string)$value;
    }

    //CLD: 2026-10-05T20:44:56+03:00: keep only $keys from request data; use with assign(), which does not check field permissions (moved from UserController)
    //CLD: 2026-10-10T19:00:56+03:00: removed onlyFields(): controllers use $model->assign($data, true) (the form fields, showInForms, the user may update)

    protected function getOidParam($key, $default = null) {
	$value = \V::app()->request->query[$key] ?? $default;
	if (!empty($value) && !\util\Validator::rule('oid', $value))
	    \V::app()->error(400, 'malformed request: not oid');

	return (string)$value;
    }
}
