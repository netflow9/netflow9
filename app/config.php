<?php

function get_config($mode = 'web') {

    //plugins
    //CLD: 2026-10-31T11:22:13+02:00: plugins.local.php, when present, is read instead of plugins.php: an installation's own list (not in git: never blocks an update), as tiers.local.php
    $plugins_fn = dirname(__FILE__) . '/plugins.local.php';
    if (!is_readable($plugins_fn))
	$plugins_fn = dirname(__FILE__) . '/plugins.php';

    $_config = [
	//development switch; also enables development-only endpoints (DataController / data/get) - set false outside development
	'app.debug' => defined('APP_DEBUG') && filter_var(APP_DEBUG, FILTER_VALIDATE_BOOLEAN),
	'app.name' => 'NetFlow9',
	'app.author' => 'None',
	'app.version' => '1.0.0',
	'app.mode' => $mode,

	//application timezone; might be changed
	'app.timezone' => 'Europe/Bucharest',

	'app.language' => 'en_US',
	'app.color-scheme' => 'auto',

	//proxies in front of nginx whose X-Forwarded-For is believed (addresses or CIDR ranges; sys\http\Request::userIP); none: the
	//client's address is the connection's (REMOTE_ADDR), as nginx takes the connections itself
	'app.trusted_proxies' => [],

	//default date formats
	'app.format.datetime' => 'Y-m-d H:i:s',
	'app.format.date' => 'Y-m-d',

	//ntp
	'app.ntp.server' => 'local', // or 'time.google.com:123'

	//used for communication to Job Server
	'app.job_socket_path' => BASE . '/run/job-notify.sock',

	//plugins(modules) configuration
	'modules' => is_readable($plugins_fn) ? require($plugins_fn) : [],

	'module.namespace' => $mode == 'web' ? 'controllers' : 'commands',
	'module.classSuffix' => $mode == 'web' ? 'Controller' : 'Command',

	'default.controller' => 'site',
	'default.action' => $mode == 'web' ? 'index' : 'run',

	'app.error.layout' => $mode == 'web' ? 'error' : false,
	'app.view.views_path' => 'views', //relative to the app directory
	'app.view.layouts_path' => 'layouts', //relative to the app directory
	'app.view.default_layout' => 'main',
	'app.router.rules' => [
	    '<module:\w+>/<controller:\w+>/<action:\w+>/<id:[a-z0-9]{24}>' => '<module>/<controller>/<action>',
	    '<controller:\w+>/<action:\w+>/<id:[a-z0-9]{24}>' => '<controller>/<action>',
	    '<module:\w+>/<controller:\w+>/<action:\w+>' => '<module>/<controller>/<action>',
	    '<controller:\w+>/<action:\w+>' => '<controller>/<action>',
	],

	'app.security.secret' => defined('APP_SECRET') ? APP_SECRET : null,
	'mail.inbound_domain' => defined('MAIL_INBOUND_DOMAIN') ? MAIL_INBOUND_DOMAIN : null,
	//CLD: 2026-10-31T12:12:05+02:00: an empty MAIL_FROM (app.env.example's placeholder) is as none
	'mail.from' => defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'noreply@' . (defined('SMTP_DOMAIN') && SMTP_DOMAIN ? SMTP_DOMAIN : 'localhost'),
	//CLD: 2026-10-30T15:00:00+02:00: app.executions.max_sync (MAX_SYNC_EXECUTIONS) and app.executions.sync_timeout (SYNC_EXECUTION_TIMEOUT) removed: every execution runs in the daemon
	//the HTTP Request and Download File steps may call this machine and its network (private / internal addresses): HTTP_STEPS_ALLOW_PRIVATE in app.env
	//(default false: public addresses only, checked by util\Http for each redirect too)
	'http.steps.allow_private' => defined('HTTP_STEPS_ALLOW_PRIVATE') && filter_var(HTTP_STEPS_ALLOW_PRIVATE, FILTER_VALIDATE_BOOLEAN),
	'app.base_url' => defined('BASE_URL') ? BASE_URL : null,
	/*
	    the System AI (the MultiTenant plugin, option system_ai: the server this installation offers to every account, for the Assistant's provider System and the AI steps;
		off without SYSTEM_AI_PROVIDER / SYSTEM_AI_MODEL; CLD: 2026-10-31T12:12:05+02:00: was described as the Assistant's, ASSISTANT_*):
		    its provider (anthropic, openai, gemini, ollama, lemonade),
		    Server URL (empty: the provider's own), key, model, effort (Claude),
		    and the prices (USD per 1M tokens; empty: the known prices of Claude models)
	    the credits each account gets per month (no rollover), the most one request may reserve (0: no limit)
	    SYSTEM_AI_* in app.env.
	    The credits per model and the margin: plugins/MultiTenant/credit_rates.php
	*/
	'ai.system' => (function() {
	    $env = fn($name, $default = '') => defined('SYSTEM_AI_' . $name) ? constant('SYSTEM_AI_' . $name) : $default;
	    return [
		'provider' => (string)$env('PROVIDER'),
		'url' => (string)$env('URL'),
		'api_key' => (string)$env('API_KEY'),
		'model' => (string)$env('MODEL'),
		'effort' => (string)$env('EFFORT'),
		'price_input' => (float)$env('PRICE_INPUT', 0),
		'price_output' => (float)$env('PRICE_OUTPUT', 0),
		'monthly_credits' => (int)$env('MONTHLY_CREDITS', 0),
		'max_credits_per_request' => (int)$env('MAX_CREDITS_PER_REQUEST', 0),
	    ];
	})(),

	//components configuration
	'components' => [
	    'log' => [
		'class' => '\sys\Logger',
		'enabled' => true,
		//CLD: 2026-10-23T23:30:00+03:00: LOG_LEVEL in app.env (ERROR, WARN, INFO or DEBUG; was DEBUG here): INFO when it is missing or not one of them
		'level' => defined('LOG_LEVEL') && in_array(strtoupper(trim((string)LOG_LEVEL)), [ 'ERROR', 'WARN', 'INFO', 'DEBUG' ], true) ? strtoupper(trim((string)LOG_LEVEL)) : 'INFO',
	    ],
	    'mongo' => [
		'class' => '\sys\Mongo',
		'autoload.requires' => [ BASE . '/app/pkgs/mongo-php-library/src/functions.php', ],
		'autoload.namespaces' => [
		    'MongoDB' => BASE . '/app/pkgs/mongo-php-library/src',
		],
		'uri' => defined('MONGO_URI') ? MONGO_URI : null,
		'database' => defined('MONGO_DB') ? MONGO_DB : null,

		//see: https://docs.mongodb.com/manual/reference/connection-string/#connections-connection-options
		'uriOptions' => [],
	    ],
	    'om' => [
		'class' => '\sys\ObjectManager',
		'preload' => true,
	    ],
	    'boot' => [
		'class' => '\sys\Boot',
		'preload' => true,
	    ],
	    'auth' => [
		'preload' => true,
		'class' => '\sys\AAA',
	    ],
	    'form' => [
		'class' => '\sys\html\QForm',
	    ],
	    'html' => [
		'class' => '\sys\html\BaseHtml',
	    ],

	],
    ];

    $_config['autoload'] = [
	'ns_map' => [
	    'chillerlan\QRCode'		=> BASE . '/app/pkgs/php-qrcode/src/',

	    //ReactPHP and friends
	    'React\EventLoop'		=> BASE . '/app/pkgs/reactphp-event-loop/src',
	    'React\ChildProcess'	=> BASE . '/app/pkgs/reactphp-child-process/src',
	    'React\Stream'		=> BASE . '/app/pkgs/reactphp-stream/src',
	    'Evenement'			=> BASE . '/app/pkgs/evenement/src',

	    //php asn1 library
	    'FG\ASN1'			=> BASE . '/app/pkgs/php-asn1/lib/ASN1',
	    'FG\Utility'		=> BASE . '/app/pkgs/php-asn1/lib/Utility',
	    'FG\X509'			=> BASE . '/app/pkgs/php-asn1/lib/X509',

	    // github.com/dealfonso/sapp modified sapp library
	    'ddn\sapp'			=> BASE . '/app/pkgs/sapp-custom',
        ],
	'class_map' => [
	    'chillerlan\Settings\SettingsContainerAbstract' => BASE . '/app/util/SettingsContainer.php',
	],
	'requires' => [
	],
    ];

    if ($mode == 'web') {
	$_components = [
	    'cs' => [
		'class' => '\sys\ClientScript',
	    ],
	    'csrf' => [
		'preload' => true,
		'class' => '\sys\CSRF',
	    ],
	    'am' => [
		'class' => '\sys\Assets',
		'appendTimestamp' => true,
	    ],
	    'form' => [
		'class' => '\sys\html\QForm',
	    ],
	    'html' => [
		'class' => '\sys\html\BaseHtml',
	    ],
	];
	$_config['components'] = array_merge($_config['components'], $_components);
    }

    return $_config;
}

// the configuration of the initial setup, used while app.env is missing (public_html/index.php):
// the web configuration where every address is the setup page
//(controllers\SetupController::index), without the Boot component (its hooks and rules need the database and the app session)
function get_setup_config() {
    $_config = get_config('web');
    $_config['app.setup'] = true;
    $_config['app.router.rules'] = [
	'setup/server' => 'setup/server',
	'setup/account' => 'setup/account',
	'<path:.*>' => 'setup/index'
    ];
    unset($_config['components']['boot']);
    //a plain PHP session instead of the app's, which is kept in the database
    $_config['components']['session'] = [ 'preload' => true, 'class' => '\sys\SessionNoDB' ];
    return $_config;
}

//CLD: 2026-10-29T02:00:00+02:00: the console's, while app.env is missing (app/v.php): the commands (only setup::install works: commands\SetupCommand), without the Boot component
//and without a database until the setup connects one (util\Setup::database)
function get_setup_console_config() {
    $_config = get_config('console');
    $_config['app.setup'] = true;
    unset($_config['components']['boot']);
    //as the browser's setup: a plain PHP session (the app's is kept in the database)
    $_config['components']['session'] = [ 'preload' => true, 'class' => '\sys\SessionNoDB' ];
    return $_config;
}
