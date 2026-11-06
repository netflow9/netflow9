<?php

function get_config($mode = 'web') {

    //plugins
    // { "<plugin>": { "enabled", its own settings (e.g. MultiTenant: config, tiers, credit_rates) }, ... },
    // loaded in this order; "enabled": false keeps a plugin's settings but leaves it out.
    // No file: no plugins
    $plugins = (function() {
	//CLD: 2026-11-01T19:00:00+02:00: in V::configDir() (NFN_CONFIG_DIR, else the app's folder)
	$f = \V::configDir() . '/plugins.json';
	if (!is_file($f))
	    return [];
	$p = json_decode((string)file_get_contents($f), true);
	if (!is_array($p) || array_is_list($p) && $p !== []) {
	    error_log("$f: not a JSON object of the plugins (" . json_last_error_msg() . "): no plugins loaded");
	    return [];
	}
	return array_map(fn($v) => is_array($v) ? $v : [], $p);
    })();

    $_config = [
	//development switch: error details on the pages - set false outside development
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

	//CLD: 2026-11-01T16:00:27+02:00: the daemon's port for its commands (NEWJOB, KILLJOB), on 127.0.0.1 only: DAEMON_PORT of app.env, default 9000.
	//Several installations on one server: a port each
	'app.daemon_port' => defined('DAEMON_PORT') && (int)DAEMON_PORT > 0 ? (int)DAEMON_PORT : 9000,
	//CLD: 2026-11-01T19:00:00+02:00: where the daemon listens (DAEMON_LISTEN, default 127.0.0.1; Docker: 0.0.0.0, the containers' private network only) and where the app finds
	//it (DAEMON_HOST, default 127.0.0.1; Docker: daemon), from app.env or the environment (compose.yaml)
	'app.daemon_listen' => (string)\V::env('DAEMON_LISTEN', '') ?: '127.0.0.1',
	'app.daemon_host' => (string)\V::env('DAEMON_HOST', '') ?: '127.0.0.1',

	//plugins(modules) configuration
	//CLD: 2026-11-01T17:30:00+02:00: the enabled ones of plugins.json; 'plugins': every plugin's settings (\V::app()->config('plugins')['<plugin>'])
	'modules' => array_keys(array_filter($plugins, fn($p) => ($p['enabled'] ?? true) !== false)),
	'plugins' => $plugins,

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
	//the HTTP Request and Download File steps may call this machine and its network (private / internal addresses): HTTP_STEPS_ALLOW_PRIVATE in app.env
	//(default false: public addresses only, checked by util\Http for each redirect too)
	'http.steps.allow_private' => defined('HTTP_STEPS_ALLOW_PRIVATE') && filter_var(HTTP_STEPS_ALLOW_PRIVATE, FILTER_VALIDATE_BOOLEAN),
	'app.base_url' => defined('BASE_URL') ? BASE_URL : null,
	/*
	    the System AI (the MultiTenant plugin, option system_ai: the server this installation offers to every account, for the Assistant's provider System and the AI steps;
		off without SYSTEM_AI_PROVIDER / SYSTEM_AI_MODEL; CLD: 2026-10-31T12:12:05+02:00: was described as the Assistant's, ASSISTANT_*):
		    its provider (anthropic, openai, gemini, ollama, lemonade, lmstudio),
		    Server URL (empty: the provider's own), key, model, effort (Claude),
		    and the prices (USD per 1M tokens; empty: the known prices of Claude models)
	    the credits each account gets per month (no rollover), the most one request may reserve (0: no limit)
	    SYSTEM_AI_* in app.env.
	    The credits per model and the margin: plugins.json (MultiTenant: credit_rates)
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
		//CLD: 2026-10-23T23:30:00+03:00: LOG_LEVEL in app.env (ERROR, WARN, INFO or DEBUG): INFO when it is missing or not one of them
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

//CLD: 2026-10-29T02:00:00+02:00: the console's, while app.env is missing (app/v.php): the commands (only sys::checks / validate / install work: commands\SysCommand), without the Boot component
//and without a database until the setup connects one (util\Setup::database)
function get_setup_console_config() {
    $_config = get_config('console');
    $_config['app.setup'] = true;
    unset($_config['components']['boot']);
    //as the browser's setup: a plain PHP session (the app's is kept in the database)
    $_config['components']['session'] = [ 'preload' => true, 'class' => '\sys\SessionNoDB' ];
    return $_config;
}
