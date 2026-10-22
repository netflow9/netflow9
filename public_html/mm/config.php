<?php

return [
    //CLD: 2026-10-21T22:00:00+03:00: only these addresses (checked against REMOTE_ADDR in index.php); removed the placeholder and local networks
    'restrict_ip' => [
	'37.153.136.0/24',
	'81.196.206.151/32',
    ],

    //enable/disable app authentication
    //CLD: 2026-10-21T23:30:00+03:00: off: no shared login with its password here (was admin, in plain text); the sign-in form's name and password are a MongoDB user's
    //(server `auth` below, e.g. nfn_admin), checked by MongoDB itself
    'auth' => true,
    'users' => [ 'admin' => 'Qdesk2020' ],

    'server' => [
	'name' => 'Localhost', //mongo server name
	'uri' => 'mongodb://127.0.0.1:27017',

	//CLD: 2026-10-21T23:30:00+03:00: MongoDB requires sign-in (SOC2.md gap 5): the users are in the admin database
	//'auth' => true,
	//'authSource' => 'admin',
	'uriOptions' => [],
	//'authSource' => 'MONGO_DATABASE',
	//'username' => 'MONGO_USERNAME',
	//'password' => 'MONGO_PASSWORD',
    ],

    //ui options
    'ui_only_dbs' => [], //databases to display
    'ui_hide_dbs' => [], //databases to hide
    'ui_hide_collections' => [], //collections to hide
    'ui_hide_system_collections' => false, //whether hide the system collections
];
