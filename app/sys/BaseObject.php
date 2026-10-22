<?php

namespace sys;

/**
 * Base class in hierarchy, from which all concrete classes inherit. This class defines
 * several conventions for how classes should be structured:
 *
 * - **Universal constructor**: Any class which defines a `__construct()` method should take
 *   exactly one parameter (`$config`), and that parameter should always be an array. Any settings
 *   passed to the constructor will be stored in the `$_config` property of the object.
 * - **Initialization / automatic configuration**: After the constructor, the `_init()` method is
 *   called. This method can be used to initialize the object, keeping complex logic and
 *   high-overhead or difficult to test operations out of the constructor. This method is called
 *   automatically by `Object::__construct()`, but may be disabled by passing `'init' => false` to
 *   the constructor. The initializer is also used for automatically assigning object properties.
 *   See the documentation on the `_init()` method for more details.
 * - **Misc.**: The `_stop()` method may be used instead of `exit()`, as it can be overridden
 *   for testing purposes.
 *
 */
class BaseObject {

    /**
     * Stores configuration information for object instances at time of construction.
     * **Do not override.** Pass any additional variables to `parent::__construct()`.
     *
     * @var array
     */
    protected $_config = [];

    // Whether the object has been initialized
    protected $_initialized = false;

    /**
     * Initializes class configuration (`$_config`), and assigns object properties using the
     * `_init()` method, unless otherwise specified by configuration. See below for details.
     *
     * @param array $config The configuration options which will be assigned to the `$_config`
     *              property. This method accepts one configuration option:
     *              - `'init'` _boolean_: Controls constructor behavior for calling the `_init()`
     *                method. If `false`, the method is not called, otherwise it is. Defaults to
     *                `true`.
     */
    public function __construct(array $config = [] ) {
	$defaults = [ 'init' => true ];
	$this->_config = $config + $defaults;

	if ($this->_config['init']) {
	    $this->_init();
	}
    }

    protected function _init() {
	$this->_initialized = true;
    }

    /**
     * Calls a method on this object with the given parameters. Provides an OO wrapper
     * for call_user_func_array, and improves performance by using straight method calls
     * in most cases.
     *
     * @param string $method  Name of the method to call
     * @param array $params  Parameter list to use when calling $method
     * @return mixed  Returns the result of the method call
     */
    public function invokeMethod($method, $params = []) {
	switch (count($params)) {
	    case 0:
		return $this->{$method}();
	    case 1:
		return $this->{$method}($params[0]);
	    case 2:
		return $this->{$method}($params[0], $params[1]);
	    case 3:
		return $this->{$method}($params[0], $params[1], $params[2]);
	    case 4:
		return $this->{$method}($params[0], $params[1], $params[2], $params[3]);
	    case 5:
		return $this->{$method}($params[0], $params[1], $params[2], $params[3], $params[4]);
	    default:
		return call_user_func_array([ &$this, $method ], $params);
	}
    }

    // Exit immediately. Primarily used for overrides during testing.
    public function _stop($status = 0) {
	exit($status);
    }


    // functions to directly modify config values
    public function &__get($key) {
	if (!isset($this->_config[$key]))
	    $this->_config[$key] = null;
		
	return $this->_config[$key];
    }
	
    public function __set($name, $value = null) {
	if (is_array($name) && !$value) {
	    return array_map(array(&$this, '__set'), array_keys($name), array_values($name));
	}
	return $this->_config[$name] = $value;
    }

    public function __isset($name) {
	return array_key_exists($name, $this->_config);
    }

    public function __unset($name) {
	unset($this->_config[$name]);
    }
}
