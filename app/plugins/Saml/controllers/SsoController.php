<?php

/*
    CLD: 2026-10-23T12:00:00+03:00: new: the public pages of the SAML sign-in (the Saml plugin), served at /Saml/sso/<action>:
	login      "Sign in with SSO": the email asked (?email=), its account found (util\SamlAuth::accountFor), then start
	start      /<account>: a request sent to the account's IdP (begin): a SamlRequest, the browser's token in a cookie, the redirect
	acs        /<account>, POST from the IdP (no CSRF token: the response is checked instead): the response checked (util\SamlSp::response) against the request it
		   answers (its cookie), used once, then the user signed in (SiteController::signIn; this application's 2FA only with askTfa). A test of the settings page shows
		   what the sign-in would do instead (nothing saved)
	metadata   /<account>: the service provider's metadata (XML), for the IdP
*/

namespace plugins\Saml\controllers;

use plugins\Saml\models\SamlRequest;
use plugins\Saml\util\SamlAuth;
use plugins\Saml\util\SamlException;
use plugins\Saml\util\SamlSp;

class SsoController extends \sys\BaseController {
    public $layout = 'bare';

    //the IdP's POST carries no CSRF token (and no session cookie: SameSite=Lax)
    public function validateRequest() {
	if ($this->action !== 'acs')
	    parent::validateRequest();
    }

    //the settings of account $aid (of the URL), with rt.account set to it; null: none
    protected function settingsOf($aid, $enabled = true) {
	$om = \V::app()->om;
	$account = $om->acrossAccounts(fn() => $om->findById('Account', (string)$aid));
	//CLD: 2026-10-24T09:45:36+03:00: + the account's tier has SAML (util\Tiers)
	if (!$account || \models\Account::isSuspended($account->_id) || !\util\Tiers::has($account, 'saml'))
	    return null;
	$s = $om->acrossAccounts(fn() => $om->findOne('SamlSettings', [ 'aid' => $account->_id ] + ($enabled ? [ 'enabled' => true ] : [])));
	if ($s)
	    \V::app()->var('rt.account', $account);
	return $s;
    }

    public function login($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$email = trim((string)($req->query['email'] ?? ''));
	$error = null;
	if ($email !== '') {
	    $s = SamlAuth::accountFor($email);
	    if ($s)
		$resp->redirect(\V::app()->createUrl('/Saml/sso/start/' . $s->aid));
	    $error = \V::t('Single sign-on is not set up for this email: sign in with your password');
	}
	$this->render('login', compact('email', 'error'));
    }

    public function start($req, $resp) {
	if (!\V::app()->user->isGuest)
	    $resp->redirect('/site/index');
	$s = $this->settingsOf($this->getOidOrError());
	if (!$s)
	    $this->failed(\V::t('Single sign-on is not set up for this account'), [], 404);
	self::begin($s);
    }

    //a request sent to the IdP of $s (a test: $tester, the user testing; nobody is signed in)
    public static function begin($s, $tester = null) {
	[ $request, $token ] = SamlRequest::start($s->aid, $tester !== null, $tester);
	setcookie(SamlRequest::cookieName($request->request_id), $token, [ 'expires' => time() + SamlRequest::VALID, 'path' => parse_url($s->acsUrl(), PHP_URL_PATH),
	    'secure' => true, 'httponly' => true, 'samesite' => 'None' ]);
	try {
	    $url = SamlSp::requestUrl($s, $request->request_id);
	} catch (SamlException $e) {
	    \V::app()->log->error('SAML: account ' . $s->aid . ': ' . $e->getMessage());
	    \V::app()->error(500, 'The sign-in request was not made: ' . $e->getMessage());
	}
	\V::app()->response->redirect($url);
    }

    public function acs($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$s = $this->settingsOf($this->getOidOrError(), false);
	if (!$s)
	    $this->failed(\V::t('Single sign-on is not set up for this account'), [], 404);
	$b64 = (string)($req->data['SAMLResponse'] ?? '');

	//the request answered, sent by this browser (its cookie)
	$irt = SamlSp::inResponseTo($b64);
	$request = null;
	if ($irt !== '') {
	    $cookie = SamlRequest::cookieName($irt);
	    $request = SamlRequest::take($s->aid, $irt, $_COOKIE[$cookie] ?? null);
	    setcookie($cookie, '', [ 'expires' => 1, 'path' => parse_url($s->acsUrl(), PHP_URL_PATH), 'secure' => true, 'httponly' => true, 'samesite' => 'None' ]);
	    if (!$request)
		$this->failed(\V::t('This sign-in was not started in this browser, or it took too long: sign in again'));
	} elseif (!$s->enabled || !$s->allowIdpInitiated) {
	    $this->failed(\V::t('Sign-ins started at the identity provider are not accepted here: use "Sign in with SSO"'));
	}
	$test = $request && $request->test;
	if (!$s->enabled && !$test)
	    $this->failed(\V::t('Single sign-on is off for this account'));

	$lines = [];
	try {
	    $claims = SamlSp::response($s, $b64, $request ? $request->request_id : null);
	} catch (SamlException $e) {
	    \V::app()->log->warn('SAML: account ' . $s->aid . ': response refused: ' . $e->getMessage());
	    $this->failed(\V::t('The identity provider\'s answer was not accepted') . ': ' . $e->getMessage(), $test ? [ 'Response refused: ' . $e->getMessage() ] : []);
	}
	$lines[] = 'Response: valid, signed (' . implode(', ', $claims['signedBy']) . ')' . ($claims['encrypted'] ? ', encrypted' : '');
	if (!SamlAuth::useOnce($s->aid, $claims))
	    $this->failed(\V::t('This answer of the identity provider was already used: sign in again'), $test ? $lines : []);

	$r = SamlAuth::signIn($s, $claims, [ 'dry' => $test, 'trace' => &$lines ]);
	if ($test) {
	    $lines[] = $r['user'] !== null ? 'Result: signed in as ' . $r['user'] . ' <' . $r['user']->email . '>' : 'Result: refused: ' . $r['reason'];
	    $this->render('result', [ 'ok' => $r['user'] !== null, 'message' => 'Test of the single sign-on settings: nobody was signed in, nothing was saved', 'lines' => $lines ]);
	    return;
	}
	if (!$r['user']) {
	    \V::app()->log->info('SAML: account ' . $s->aid . ': sign-in refused: ' . $r['reason'] . ' (' . $claims['nameId'] . ')');
	    $this->failed(\V::t($r['reason']));
	}
	\controllers\SiteController::signIn($r['user'], false, 'with SAML single sign-on', !$s->askTfa);
    }

    public function metadata($req, $resp) {
	$s = $this->settingsOf($this->getOidOrError(), false);
	if (!$s || trim((string)$s->spCert) === '')
	    \V::app()->error(404, 'Not found');
	$resp->headers['Content-Type'] = 'application/samlmetadata+xml';
	if (isset($req->query['download']))
	    $resp->headers['Content-Disposition'] = 'attachment; filename="sp-metadata.xml"';
	$resp->write(SamlSp::metadata($s), true);
	\V::app()->end();
    }

    //the error page (the test's steps, when a test)
    protected function failed($message, array $lines = [], $status = 403) {
	\V::app()->response->status = $status;
	$this->render('result', [ 'ok' => false, 'message' => $message, 'lines' => $lines ]);
	\V::app()->end();
    }
}
