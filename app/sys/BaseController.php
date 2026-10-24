<?php

namespace sys;

// Implements a basic controller functionallity.
class BaseController extends \sys\BaseObject {
    public $layout = null;

    public $events = [];

    //CLD: 2026-10-24T09:06:00+03:00: the events (e.g. toasts) of a full-page redirect, kept in the session for the next page (redirect / render)
    const FLASH_EVENTS = 'flash.events';

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

	//CLD: 2026-10-24T09:06:00+03:00: + the events kept by a full-page redirect (redirect()), before this request's own; only on a page that shows them (pjax, or a full page; not
	//an ajax fragment)
	$shows = \V::app()->request->is('pjax') || !\V::app()->request->is('ajax');
	$flash = $shows && \V::app()->session->hasState(self::FLASH_EVENTS) ? \V::app()->session->getState(self::FLASH_EVENTS) : null;
	if (is_array($flash)) {
	    \V::app()->session->setState(self::FLASH_EVENTS, null);
	    $this->events = array_merge($flash, $this->events);
	}

	//render the template
	\V::app()->view->meta = $this->events;
	//CLD: 2026-10-24T09:06:00+03:00: a full page has no meta (pjax only): its events are dispatched by a page script, after app.js has set up app.events (toasts, ...)
	if ($this->events && !\V::app()->request->is('pjax') && !\V::app()->request->is('ajax'))
	    \V::app()->cs->addPageScript('if (window.app && app.events) ' . \util\JSON::encode($this->events) . '.forEach((e) => app.events.dispatch(e));');
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

	//CLD: 2026-10-24T09:06:00+03:00: a full-page redirect: the events (e.g. toasts) are kept for the next page (were lost)
	if ($this->events)
	    \V::app()->session->setState(self::FLASH_EVENTS, array_merge((array)\V::app()->session->getState(self::FLASH_EVENTS), $this->events));
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
