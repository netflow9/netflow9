<?php

/*
    CLD: 2026-10-23T12:00:00+03:00: new plugin: SAML 2.0 single sign-on (Microsoft Entra ID, Okta, Google Workspace, ADFS, Keycloak, ...). Enabled in app/plugins.php.
      - Sign-in: per account (Settings menu: SAML single sign-on), the people of the account sign in at the account's identity provider (IdP); users created on their first
	sign-in, their role from their IdP groups (util/SamlAuth.php; an SSO user has no password here: none to reset / change, the core's hooks identity:authenticate and
	identity:password-source). "Sign in with SSO" on the login page finds the account by the email (its domain); the account's own link starts at its IdP directly.
	Signing out ends the session here only (not the IdP's)
      - Not for workflows (yet): no credential, no step
      util/SamlXml.php                    XML security with PHP's DOM and openssl: parsing (no DTD), XML-DSig verification (signature wrapping refused), XML Encryption
      util/SamlSp.php                     the protocol: AuthnRequest (HTTP-Redirect, signed), the SP metadata, the Response checked (HTTP-POST), the IdP metadata read
      models/SamlSettings.php             an account's settings and its SP key pair; models/SamlLink.php: a user who is an SSO user (removed with the user)
      models/SamlRequest.php              a request waiting for its response, tied to the browser (a cookie); models/SamlReplay.php: the assertions used (replay)
      controllers/SamlController          /Saml/saml/<settings|import|test|renew|unlink> (views/saml/settings.php), for who may change the account
      controllers/SsoController           public: /Saml/sso/login (the email asked), /Saml/sso/start/<account>, /Saml/sso/acs/<account> (the IdP posts the response),
					  /Saml/sso/metadata/<account>
      check suite: ./v check::run --only=saml (a test IdP made in the checks: signed / encrypted responses, the refusals, the sign-in)
*/

namespace plugins\Saml;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'SamlSettings' => [
		'class' => __NAMESPACE__ . '\\models\\SamlSettings',
	    ],
	    'SamlLink' => [
		'class' => __NAMESPACE__ . '\\models\\SamlLink',
	    ],
	    'SamlRequest' => [
		'class' => __NAMESPACE__ . '\\models\\SamlRequest',
		'auditLog' => false,
	    ],
	    'SamlReplay' => [
		'class' => __NAMESPACE__ . '\\models\\SamlReplay',
		'auditLog' => false,
	    ],
	]);

	//a deleted user's link (in every mode)
	\V::app()->hooks->addHook('model:after-delete', [ models\SamlLink::class, 'afterModelDelete' ]);

	//the password of an SSO user is refused (sys\Identity::authenticate), and it has none to reset / change here; in every mode: the check suite signs in from the console
	\V::app()->hooks->addHook('identity:authenticate', [ util\SamlAuth::class, 'onAuthenticate' ]);
	\V::app()->hooks->addHook('identity:password-source', [ util\SamlAuth::class, 'onPasswordSource' ]);

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update'))
		echo '<a href="' . \V::app()->createUrl('/Saml/saml/settings') . '" class="nav-link"><i class="bi bi-shield-lock"></i> SAML single sign-on</a>';
	});

	//"Sign in with SSO", when an account of the installation has it on
	\V::app()->hooks->addHook('site:login-links', function() {
	    if (\V::app()->om->acrossAccounts(fn() => \V::app()->om->count('SamlSettings', [ 'enabled' => true ])) > 0)
		echo \V::app()->html->link(\V::t('Sign in with SSO'), '/Saml/sso/login', [ 'class' => 'pt-1' ]);
	});
    }
}
