<?php

require(dirname(__FILE__, 2) . '/util/VString.php');

class EndException extends \Exception {}

class V {

    protected static $classes = [];

    //the app singleton
    protected static $app;

    //app configuration
    protected $_config = [];

    //constructor, initialize configuration
    public function __construct($config = []) {
	//add system components to be loaded first
	$_components = [
	    'hooks' => [
		'class' => '\sys\Hooks',
		'preload' => true,
	    ],
	    'request' => [
		'class' => $config['app.mode'] == 'web' ? 'sys\http\Request' : 'sys\console\Request',
		'preload' => true,
		'trusted_proxies' => $config['app.trusted_proxies'] ?? [],
	    ],
	    'response' => [
		'class' => $config['app.mode'] == 'web' ? 'sys\http\Response' : 'sys\console\Response',
		'preload' => true,
	    ],
	    'view' => [
		'class' => '\sys\View',
		'views_path' => $config['app.view.views_path'] ?? 'views', //relative to app directory
		'layouts_path' => $config['app.view.layouts_path'] ?? 'layouts', //relative to app directory
		'default_layout' => $config['app.view.default_layout'] ?? 'layout_main',
		'raw' => $config['app.mode'] == 'web' ? false : true,
	    ],
	    'router' => [
		'class' => $config['app.mode'] == 'web' ? 'sys\http\Router' : 'sys\console\Router',
		'showScriptName' => false,
		'rules' => $config['app.router.rules'] ?? [],
	    ],
	    'security' => [
		'class' => '\sys\Security',
		'secret' => $config['app.security.secret'] ?? null,
	    ],
	    'session' => [
		'preload' => true,
		'class' => '\sys\Session',
		'lifetime' => 60 * 60 * 24 * 30,
		'transient_lifetime' => 60 * 60 * 24,
	    ],
	    //user ALWAYS after session
	    'user' => [
		'preload' => true,
		'class' => '\sys\User',
	    ],
	];

	$config['components'] = array_merge($_components, $config['components']);
	$this->_config = $config;

	//loader registration
	spl_autoload_register([ $this, 'loader_handler' ]);

	//preloads
	if (!empty($this->_config['autoload']['ns_map'])) {
	    foreach($this->_config['autoload']['ns_map'] as $prefix => $path)
		$this->registerNamespace($prefix, $path);
	}

	if (!empty($this->_config['autoload']['class_map'])) {
	    foreach($this->_config['autoload']['class_map'] as $class => $path)
		$this->registerClass($class, $path);
	}

	if (!empty($this->_config['autoload']['requires'])) {
	    foreach($this->_config['autoload']['requires'] as $__file)
		require($__file);
	}

	//add files and namespaces required by components
	foreach($this->_config['components'] as $cid => $cConfig) {
	    if (isset($cConfig['autoload.requires'])) {
		foreach($cConfig['autoload.requires'] as $__file)
		    require($__file);
	    }

	    if (isset($cConfig['autoload.namespaces'])) {
		foreach($cConfig['autoload.namespaces'] as $__ns => $__pfx) {
		    $this->registerNamespace($__ns, $__pfx);
		}
	    }
	}

	//timezone & language
	if (!empty($this->_config['app.timezone']))
	    date_default_timezone_set($this->_config['app.timezone']);
	if (!empty($this->_config['app.language']))
	    setlocale(LC_CTYPE, $this->_config['app.language']);


	//initialize exception, error, shutdown handlers
    	set_exception_handler(function($e) {
	    $details = [
		'class' => get_class($e),
		'code' => $e->getCode(),
		'message' => $e->getMessage(),
		'file' => $e->getFile(),
    		'line' => $e->getLine(),
    		'trace' => $e->getTraceAsString(),
	    ];
	    $text = \util\VString::insert(implode("\n", [
		'Application error:',
		'Type:{:class}',
		'Code:{:code}',
		'Message:{:message}',
		'File:{:file}',
		'Line:{:line}',
		'Trace:{:trace}',
	    ]), $details);

    	    if ($this->_config['app.mode'] != 'web') {
		$this->error(500, $text);
		return;
	    }

	    $ref = bin2hex(random_bytes(4));
	    try {
		$this->log->error("V: unhandled exception, reference {$ref}, " . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n" . $text);
	    } catch (\Throwable $logError) {
		error_log("V: unhandled exception, reference {$ref}\n" . $text);
	    }

	    if (!empty($this->_config['app.debug'])) {
    		$message = \util\VString::insert("
		    <p>Application error (reference {:ref}):</p>
		    <div><strong>Type:</strong>{:class}</div>
		    <div><strong>Code:</strong>{:code}</div>
		    <div><strong>Message:</strong>{:message}</div>
		    <div><strong>File:</strong>{:file}</div>
		    <div><strong>Line:</strong>{:line}</div>
		    <h2>Trace:</h2><pre>{:trace}</pre>
		", array_map(fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $details + [ 'ref' => $ref ]));
	    } else {
		$message = "
		    <p class='text-center'>Something went wrong on our side. Please try again; if it keeps happening, contact support with the reference below.</p>
		    <p class='text-center text-muted small'>Reference: {$ref}</p>
		";
	    }

	    $this->error(500, $message);
    	});

    	set_error_handler(function($errno, $errstr = '', $errfile = '', $errline = '') {
    	    if (error_reporting() & $errno)
    		throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
    	});
    }

    public static function create($config) {
	return static::$app = new self($config);
    }

    // get the application singleton
    public static function & app() {
	return static::$app;
    }

    //this will stop the application
    public function end() {
    	$this->response->output();
	exit(0);
    }

    public function loadComponent($cid, $cConfig = []) {
	//load component
	$class = $cConfig['class'];
	unset(
	    $cConfig['class'],
	    $cConfig['preload'],
	    $cConfig['autoload.requires'],
	    $cConfig['autoload.namespaces'],
	);

	return static::$classes[$cid] = new $class($cConfig);
    }


    // Stop the application and immediately send the response with a specific status and body to the HTTP client.
    public function error($status, $message = '') {
	$this->response->status = $status;
	$this->response->write('', true); //clear the output

	$error_layout = $this->_config['app.error.layout'];
	if ($error_layout) {
	    $this->view->render("/{$this->view->layouts_path}/{$error_layout}", compact('status', 'message'), false);
	} else {
	    $this->response->write("Error: $status\n$message", false);
	}

	$this->response->output();
    	exit(1);
    }

    //override setter/getter to operate on catalog (aka components)
    public function &__get($name) {
	if (!empty(static::$classes[$name]))
	    return static::$classes[$name];

	if (!empty($this->_config['components'][$name])) {
	    $cls = $this->loadComponent($name, $this->_config['components'][$name]);
	    return $cls;
	}

	$null = null;
	return $null;
    }

    public function __set($name, $value = null) {
	if (is_array($name) && !$value)
	    return array_map(array(&$this, '__set'), array_keys($name), array_values($name));

	return static::$classes[$name] = $value;
    }

    public function __isset($name) {
	return isset(static::$classes[$name]);
    }

    public function var($name, $value = null) {
	return $this->config($name, $value);
    }
    public function config($name, $value = null) {
	if ($name === null)
	    return null;

	if ($value === null)
	    return $this->_config[$name] ?? null;

	//value is not null set it
	return $this->_config[$name] = $value;
    }

    public function run() {
	//load components that needs to be preloaded
	foreach($this->_config['components'] as $cid => $cConfig) {
	    if (!empty($cConfig['preload']))
		$this->loadComponent($cid, $cConfig);
	}

	$modules = [];

	//load modules
	foreach($this->_config['modules'] as $mDef) {
	    if (is_string($mDef)) {
		$mDef = [ 'id' => $mDef ];
	    }

	    $fqClassName = 'plugins\\' . $mDef['id'] . '\\Plugin';

	    $modules[$mDef['id']] = [
		'namespace' => 'plugins\\' . $mDef['id'],
		'class' => $fqClassName,
	    ];

	    $this->loadComponent($mDef['id'], [ 'class' => $fqClassName ]);
	}

	$this->hooks->applyHook('on:init');
	$this->hooks->applyHook('on:start');
    	$this->hooks->applyHook('on:before-router');

	//parse the url/command and return route
	$route = $this->router->parseRequest($this->request);
	$route = trim($route, '/');
	//save route in c_config; after router might need it
	$this->config('rt.route', $route);

    	$this->hooks->applyHook('on:after-router');
	$route = $this->config('rt.route');

	//parse route and instantiate controller
	$segs = explode('/', $route);

	//sanity
	foreach($segs as $i => $seg)
	    $segs[$i] = substr($seg, 0, 40);

	$module = null;

	//search for a module by $segs[0]; if found=> module/controller/action; else, treat as controller/action
	if (!empty($segs[0]) && array_key_exists($segs[0], $modules)) {
	    $module = array_shift($segs);
	}

	$controller = $this->config('default.controller');
	if (!empty($segs[0]))
	    $controller = array_shift($segs);

	$action = $this->config('default.action');
	if (!empty($segs[0]))
	    $action = array_shift($segs);

	//save the controller/action for further use
	$this->_config['rt.module'] = $module;
	$this->_config['rt.controller'] = $controller;
	$this->_config['rt.action'] = $action;
	if ($module)
	    $this->_config['rt.module_path'] = strtr($modules[$module]['namespace'], '\\', '/');

	$pathSegs = [];
	$pathSegs[] = BASE . '/app';
	if ($module)
	    $pathSegs[] = $this->_config['rt.module_path'];

	$pathSegs[] = $this->config('module.namespace');

	//now we need to find controller/command somewhere
	$cClass = ucfirst(strtolower($controller)) . $this->config('module.classSuffix');
	$pathSegs[] = $cClass;
	$cFile = implode('/', $pathSegs) . '.php';


	if (is_file($cFile)) {
	    require $cFile;
	} else {
	    $cClass = htmlspecialchars($cClass, ENT_QUOTES, 'UTF-8');
	    \V::app()->log->debug("V::run: file not found: $cFile. render error");
	    $this->error(404, "Controller not found");
	}

	$cClass = $this->config('module.namespace') . '\\' . $cClass;
	if ($module)
	    $cClass = $modules[$module]['namespace'] . '\\' . $cClass;

	//now initialize the controller and run action
    	$instance = new $cClass([ 'action' => $action, 'id' => $controller, 'module' => $module ]);

	$this->hooks->applyHook('on:before-action');

	if(method_exists($instance,$action)) {
	    $req = &\V::app()->request;
	    $resp = &\V::app()->response;
	    $instance->$action($req, $resp);
	} else {
	    $action = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
	    \V::app()->error(404, "Action not found: $action");
	}

	$this->hooks->applyHook('on:after-action');

    	restore_error_handler();
    	restore_exception_handler();

    	$this->response->output();

	$this->hooks->applyHook('on:end');
    }


    // -------------------------------------------------------------------------------------------------------------
    public function createUrl($route, $params = [], $ampersand = '&') {
	//if empty - put just action
	if ($route === '')
	    $route = $this->_config['rt.action'];

	if (strpos($route, '/') === false)
	    $route = $this->_config['rt.controller'] . '/' . $route;

	//if not absolute and we have module - prefix it
	if ($route[0] != '/' && !empty($this->_config['rt.module']))
	    $route = $this->_config['rt.module'] .'/' . $route;

	$route = trim($route, '/');
	$url = $this->router->createUrl($route, $params, $ampersand);
	return $url;
    }

    // -------------------------------------------------------------------------------------------------------------
    public function createAbsoluteUrl($route, $params = [], $schema = '', $ampersand = '&') {
	$url = $this->createUrl($route, $params, $ampersand);
	if (preg_match('#^https?://#i', $url))
	    return preg_replace('#^http://#i', 'https://', $url);

	$base = (string)$this->config('app.base_url');
	if ($base === '')
	    $base = (string)($this->request->httpHost ?? '');

	//keep only the host[:port][/path] part, then force https
	$base = rtrim(preg_replace('#^(https?:)?//#i', '', trim($base)), '/');
	return 'https://' . $base . '/' . ltrim($url, '/');
    }

    // -------------------------------------------------------------------------------------------------------------
    public static function t($message, $params = []) {
	// no translation done yet, just the framework
	if (isset(static::$catalog['translate']))
	    return static::$catalog['translate']->t($message, $params);

	return \util\VString::insert($message, $params);
    }

    // -------------------------------------------------------------------------------------------------------------
    //class loader
    private static $loaderMap = [
	'ns' => [
	    'sys'		=> BASE . '/app/sys/',
	    'util'		=> BASE . '/app/util/',
	    'models'		=> BASE . '/app/models/',
	    'controllers'	=> BASE . '/app/controllers/',
	    'commands'		=> BASE . '/app/commands/',
	    'plugins'		=> BASE . '/app/plugins/',
	    'data'		=> BASE . '/app/data/',
	],
	'class' => [
	],
    ];

    public function registerNamespace($prefix, $path) {
	// normalize namespace prefix: make sure it ends in \
        $prefix = trim($prefix, '\\') . '\\';

	if (isset(static::$loaderMap['ns'][$prefix]))
	    return;

        // normalize the base directory with a trailing separator
        $path = rtrim($path, '/') . '/';

        static::$loaderMap['ns'][$prefix] = $path;
    }

    public function registerClass($class, $path) {
	if (isset(static::$loaderMap['class'][$class]))
	    return;

        static::$loaderMap['class'][$class] = $path;
    }

    public function loader_handler($class) {
	//if a class is defined here in class_map, just require it and return; no file check; be aware!!!!
	if (isset(static::$loaderMap['class'][$class]))
            return require(static::$loaderMap['class'][$class]);

	$__class = $class;
	foreach(static::$loaderMap['ns'] as $prefix => $path) {
	    //check if $ns is found in class
	    if (strpos($__class, $prefix) !== 0)
		continue;

	    //prefix found, strip prefix from class
	    $__class = str_replace($prefix, '', $__class);
	    $__class = str_replace('\\', '/', $__class);

	    //1. build a file name ending in .local
	    $filename = $path . $__class . '.local.php';
	    if (!is_file($filename))
		$filename = $path . $__class . '.php';
	    if (is_file($filename))
        	return require($filename);
	}
        return false;
    }

    public static function parse_env($filename) {
	$content = file_get_contents($filename);
	if (!$content)
	    return;

	$lines = explode("\n", $content);
	foreach($lines as $line) {
	    $line = trim($line);
	    if (empty($line) || $line[0] == '#')
		continue;

	    $parts = explode('=', $line, 2);
	    if (count($parts) != 2)
		continue;
	    $var = trim($parts[0]);
	    $val = trim($parts[1]);

	    //is val quoted ?
	    //a single-quoted value is literal (as in bash: no $VAR substitution, no true/false), so a written password or address may hold `$`;
	    //an empty value no longer reads $val[0] of an empty string
	    $vlen = strlen($val);
	    $literal = $vlen >= 2 && $val[0] === '\'' && $val[$vlen-1] === '\'';
    	    if ($vlen >= 2 && (($val[0] === '"' && $val[$vlen-1] === '"') || $literal))
		$val = substr($val, 1, $vlen-2);

	    if ($literal) {
		define($var, $val);
		continue;
	    }

	    if(preg_match_all('/(\$)([a-zA-Z_][a-zA-Z0-9_]*)/', $val, $m)) {
		//m[0] holds matched string(s)
		//m[2] holds constant name(s)
		foreach($m[0] as $k => $match)
		    $val = str_replace($match, constant($m[2][$k]), $val);
	    }

	    switch($val) {
		case 'true':
		    $val = true;
		    break;
		case 'false':
		    $val = false;
		    break;
	    }
	    define($var, $val);
	}
    }

}

