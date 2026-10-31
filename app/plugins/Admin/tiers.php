<?php

/*
    CLD: 2026-10-24T09:45:36+03:00: new: the accounts' tiers (util\Tiers): what each one may use and which features it has. Read once per request; tiers.local.php, when present,
    is read instead (an installation's own tiers, not overwritten by updates).

    default     the tier of the accounts that have none set (Account `tier` empty: the accounts made before the tiers, and the new ones until a super-admin sets one)
    tiers       by name:
		    label       shown to the owners (the usage page) and the super-admins
		    limits      for the whole account (null: no limit):
				  datatables           data tables
				  datatable_records    rows in one data table
				  files_bytes          the size of all the files (bytes)
				  ai_monthly_credits   the System AI credits of a month (null: the installation's, SYSTEM_AI_MONTHLY_CREDITS)
		    features    the features of the tier (the plugins name theirs: hook tiers:features)
				CLD: 2026-10-24T10:27:54+03:00: + google, microsoft (the GoogleIntegration / MicrosoftIntegration plugins)
				CLD: 2026-10-25T18:30:00+02:00: + database (the Database plugin), on enterprise as ldap
				CLD: 2026-10-25T19:00:00+02:00: + ldap_step (the LDAP step and its credential; ldap is the LDAP sign-in only), on enterprise
				CLD: 2026-10-25T19:30:00+02:00: - ldap_step (the LDAP step and its credential are core, for every tier)
				CLD: 2026-10-25T20:00:00+02:00: - database (the Database step and its credentials are core, for every tier)
				CLD: 2026-10-26T23:39:49+02:00: - google, microsoft (the Google and Microsoft credentials and steps are core again, for every tier)
				- saml, ldap (the SAML and LDAP sign-ins are core, for every tier)
    A super-admin sets an account's tier and changes its limits / features (Administration, the account's page: Tier & limits; ./v Admin::tiers::<show | set>)
    CLD: 2026-10-31T11:53:38+02:00: the Admin plugin's (was app/tiers.php; read by its hook tiers:table); tiers.local.php next to it, when present, instead. Without the plugin: no tiers
*/

return [
    'default' => 'enterprise',
    'tiers' => [
	'free' => [
	    'label' => 'Free',
	    'limits' => [
		'datatables' => 3,
		'datatable_records' => 1000,
		'files_bytes' => 100 * 1024 * 1024,
		'ai_monthly_credits' => 100,
	    ],
	    'features' => [],
	],
	'team' => [
	    'label' => 'Team',
	    'limits' => [
		'datatables' => 50,
		'datatable_records' => 100000,
		'files_bytes' => 10 * 1024 * 1024 * 1024,
		'ai_monthly_credits' => 2000,
	    ],
	    'features' => [],
	],
	'enterprise' => [
	    'label' => 'Enterprise',
	    'limits' => [
		'datatables' => null,
		'datatable_records' => null,
		'files_bytes' => null,
		'ai_monthly_credits' => null,
	    ],
	    'features' => [],
	],
    ],
];
