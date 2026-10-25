<?php

/*
    CLD: 2026-10-21T10:00:00+03:00: new plugin: LDAP (Active Directory, OpenLDAP, ...). Enabled in app/plugins.php.
      - Sign-in: per account (Settings menu: LDAP sign-in), the people of the account sign in with their directory password; users created on their first sign-in, their
	role from their directory groups (util/LdapAuth.php through the core's hooks identity:authenticate and identity:password-source: an LDAP user has no password
	reset / change here; the 2FA of the application still applies)
      CLD: 2026-10-25T19:30:00+02:00: the plugin is the sign-in only (plan feature `ldap`); the workflows' part is core, for every account: the LDAP server credential
	(models\credential\CredentialLdap), the LDAP step (models\node\NodeAppLdap) and the connection (util\LdapClient, LdapExtClient, LdapPhpClient, LdapBer, LdapException;
	the server's settings: models\credential\LdapServerTrait); the check suite's test server: app/tests/LdapTestServer.php
      models/LdapSettings.php             an account's sign-in settings; models/LdapLink.php: a user who is an LDAP user (removed with the user)
      controllers/LdapController          /Ldap/ldap/<settings|test|unlink> (views/ldap/settings.php), for who may change the account
*/

namespace plugins\Ldap;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'LdapSettings' => [
		'class' => __NAMESPACE__ . '\\models\\LdapSettings',
	    ],
	    'LdapLink' => [
		'class' => __NAMESPACE__ . '\\models\\LdapLink',
	    ],
	    //CLD: 2026-10-25T19:30:00+02:00: the LDAP server credential and the LDAP step are core (models\credential\CredentialLdap, models\node\NodeAppLdap, data\SysObjects), with the LDAP
	    //client (util\LdapClient ...): no plan feature (were registered here, feature ldap_step)
	]);

	//a deleted user's link (in every mode)
	\V::app()->hooks->addHook('model:after-delete', [ models\LdapLink::class, 'afterModelDelete' ]);

	//the sign-in (sys\Identity::authenticate) and who keeps a password here (password reset / change); in every mode: the check suite signs in from the console
	\V::app()->hooks->addHook('identity:authenticate', [ util\LdapAuth::class, 'onAuthenticate' ]);
	\V::app()->hooks->addHook('identity:password-source', [ util\LdapAuth::class, 'onPasswordSource' ]);

	//CLD: 2026-10-24T09:45:36+03:00: a feature of the accounts' tiers (util\Tiers: `ldap`): without it, no LDAP sign-in, no settings, no new LDAP credentials, the LDAP step does not
	//run (the settings and credentials are kept)
	//CLD: 2026-10-25T19:30:00+02:00: `ldap` is the sign-in only (its settings); the LDAP step and its credential are core, not a feature (were `ldap_step`)
	\V::app()->hooks->addHook('tiers:features', function(\ArrayObject $features) {
	    $features['ldap'] = 'LDAP sign-in';
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update') && \util\Tiers::has(\V::app()->var('rt.account'), 'ldap'))
		echo '<a href="' . \V::app()->createUrl('/Ldap/ldap/settings') . '" class="nav-link"><i class="bi bi-diagram-3"></i> LDAP sign-in</a>';
	});
    }
}
