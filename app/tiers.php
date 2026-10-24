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
		    features    the features of the tier (the plugins name theirs: hook tiers:features), e.g. saml, ldap
    A super-admin sets an account's tier and changes its limits / features (Administration, the account's page: Tier & limits; ./v tiers::<show | set>)
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
	    'features' => [ 'saml', 'ldap' ],
	],
    ],
];
