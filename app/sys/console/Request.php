<?php

namespace sys\console;

class Request extends \sys\BaseObject {

    // Class Constructor
    public function __construct($config = [] ) {
	$defaults = [
	    'args' => [],
	    'env' => [],
	    'argv' => [],
	    'params' => [],
	    'command' => null,
	];
	$config += $defaults;
	parent::__construct($config);
    }

    /**
     * Initialize request object, pulling request data from superglobals.
     *
     * Defines an artificial `'PLATFORM'` environment variable as `'CLI'` to
     * allow checking for the SAPI in a normalized way. This is also for
     * establishing consistency with this class' sister classes.
     *
     * Parse incoming request from console. Short and long (GNU-style) options
     * in the form of `-f`, `--foo`, `--foo-bar` and `--foo=bar` are parsed.
     * XF68-style long options (i.e. `-foo`) are not supported but support
     * can be added by extending this class.
     *
     * @return void
     */
    protected function _init() {

	$this->_config['env'] += (array) $_SERVER + (array) $_ENV;
	$this->_config['env']['working'] = getcwd() ?: null;
	$this->_config['env']['PLATFORM'] = 'CLI';

	$this->_config['argv'] = [];
	if (isset($this->_config['env']['argv']))
	    $this->_config['argv'] = $this->_config['env']['argv'];

	$this->_config['env']['script'] = array_shift($this->_config['argv']);


	//parse args
	while ($arg = array_shift($this->_config['argv'])) {
	    //echo "Request:arg=$arg\n";
	    if (preg_match('/^-(?P<key>[a-zA-Z0-9_])$/i', $arg, $match)) {
		$key = $match['key'];
		//echo "match a -key: $key\n";
		$this->_config['params'][$key] = true;
		continue;
	    }
	    if (preg_match('/^--(?P<key>[a-z0-9-_]+)(?:=(?P<val>.+))?$/i', $arg, $match)) {
		$key = $match['key'];
		//echo "match a --key=val: $key\n";
		$this->_config['params'][$key] = !isset($match['val']) ? true : $match['val'];
		continue;
	    }
	    if (!isset($this->_config['command']))
		$this->_config['command'] = $arg;
	    else
		$this->_config['args'][] = $arg;
	    
	    //echo "match a default: $arg\n";
	}

	parent::_init();
    }

    // Get the value of a command line argument at a given key
    public function args($key = 0) {
	if (!empty($this->_config['args'][$key]))
	    return $this->_config['args'][$key];

	return null;
    }

    // Get environment variables.
    public function env($key = null) {
	if (!empty($this->_config['env'][$key]))
	    return $this->_config['env'][$key];

	if ($key === null)
	    return $this->_config['env'];

	return null;
    }


    /**
     * Moves params up a level. Sets command to action, action to passed[0], and so on.
     *
     * @param integer $num how many times to shift
     * @return self
     */
/*
    public function shift($num = 1) {
	for ($i = $num; $i > 1; $i--) {
	    $this->shift(--$i);
	}
	$this->_config['command'] = $this->_config['action'];
	if (isset($this->_config['args'][0])) {
	    $this->_config['action'] = array_shift($this->_config['args']);
	}
	return $this;
    }
*/
}


