<?php

/*
    CLD: 2026-10-21T10:00:00+03:00: new plugin: LDAP (Active Directory, OpenLDAP, ...). Enabled in app/plugins.php.
      - Sign-in: per account (Settings menu: LDAP sign-in), the people of the account sign in with their directory password; users created on their first sign-in, their
	role from their directory groups (util/LdapAuth.php through the core's hooks identity:authenticate and identity:password-source: an LDAP user has no password
	reset / change here; the 2FA of the application still applies)
      CLD: 2026-10-25T19:00:00+02:00: the sign-in and the workflows are independent: plan features `ldap` (sign-in) and `ldap_step` (the credential and the step)
      - Workflows: the LDAP server credential (models/CredentialLdap.php) and the LDAP step (models/NodeAppLdap.php: search, get, add, modify, rename, delete, group
	members, check / set a password)
      util/LdapClient.php                 the connection: PHP's ldap extension when loaded (LdapExtClient), else the plugin's own client (LdapPhpClient, LdapBer)
      models/LdapSettings.php             an account's sign-in settings; models/LdapLink.php: a user who is an LDAP user (removed with the user)
      controllers/LdapController          /Ldap/ldap/<settings|test|unlink> (views/ldap/settings.php), for who may change the account
      tests/LdapTestServer.php            a directory in memory for the check suite (./v check::run --only=ldap)
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
	    //CLD: 2026-10-24T09:45:36+03:00: `feature`: of the accounts whose tier has it (util\Tiers: new credentials, the designer's palette)
	    //CLD: 2026-10-25T19:00:00+02:00: `ldap_step` (was `ldap`, the sign-in's): the step and its credential do not depend on the LDAP sign-in
	    'CredentialLdap' => [
		'class' => __NAMESPACE__ . '\\models\\CredentialLdap',
		'variant-of' => 'Credential',
		'feature' => 'ldap_step',
	    ],
	    'NodeAppLdap' => [
		'class' => __NAMESPACE__ . '\\models\\NodeAppLdap',
		'wfNode' => true,
		'feature' => 'ldap_step',
	    ],
	]);

	//a deleted user's link (in every mode)
	\V::app()->hooks->addHook('model:after-delete', [ models\LdapLink::class, 'afterModelDelete' ]);

	//the sign-in (sys\Identity::authenticate) and who keeps a password here (password reset / change); in every mode: the check suite signs in from the console
	\V::app()->hooks->addHook('identity:authenticate', [ util\LdapAuth::class, 'onAuthenticate' ]);
	\V::app()->hooks->addHook('identity:password-source', [ util\LdapAuth::class, 'onPasswordSource' ]);

	//CLD: 2026-10-24T09:45:36+03:00: a feature of the accounts' tiers (util\Tiers: `ldap`): without it, no LDAP sign-in, no settings, no new LDAP credentials, the LDAP step does not
	//run (the settings and credentials are kept)
	//CLD: 2026-10-25T19:00:00+02:00: two features, each without the other: `ldap` the sign-in (its settings), `ldap_step` the LDAP step and the LDAP server credential (were both `ldap`)
	\V::app()->hooks->addHook('tiers:features', function(\ArrayObject $features) {
	    $features['ldap'] = 'LDAP sign-in';
	    $features['ldap_step'] = 'LDAP step (directories)';
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update') && \util\Tiers::has(\V::app()->var('rt.account'), 'ldap'))
		echo '<a href="' . \V::app()->createUrl('/Ldap/ldap/settings') . '" class="nav-link"><i class="bi bi-diagram-3"></i> LDAP sign-in</a>';
	});
    }
}
