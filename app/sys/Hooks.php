<?php

namespace sys;

class Hooks {

    //hooks
    protected $hooks = [
/*
        'before' => 		[ [] ],
        'before.router' => 	[ [] ],
        'before.dispatch' => 	[ [] ],
        'after.dispatch' => 	[ [] ],
        'after.router' =>	[ [] ],
        'after' =>		[ [] ],
*/
    ];


    /**
     * Assign hook
     * @param  string   $name       The hook name
     * @param  mixed    $callable   A callable object
     * @param  int      $priority   The hook priority; 0 = high, 10 = low
     */
    public function addHook($name, $callable, $priority = 10) {
        if (!isset($this->hooks[$name]))
            $this->hooks[$name] = [];
        if (!is_callable($callable))
    	    throw new \InvalidArgumentException("addHook received a non-callable argument");

        $this->hooks[$name][(int) $priority][] = $callable;
    }

    /**
     * Invoke hook
     * @param  string   $name       The hook name
     * @param  mixed    $hookArgs   (Optional) Argument for hooked functions
     */
    public function applyHook($name, $hookArgs = []) {
	if(!defined('DEBUG_HOOKS'))
	    define('DEBUG_HOOKS', false);

        if (empty($this->hooks[$name]))
    	    return;

        // Sort by priority, low to high, if there's more than one priority
        if (count($this->hooks[$name]) > 1)
    	    ksort($this->hooks[$name]);

        foreach ($this->hooks[$name] as $priority) {
            if (empty($priority))
        	continue;
            foreach ($priority as $callable) {
        	if (DEBUG_HOOKS) {
        	    switch(true) {
        		case is_array($callable):
        		    $callable_name = (is_string($callable[0]) ? $callable[0] : get_class($callable[0]) );
            		    \V::app()->log->debug("$name, callable=" . $callable_name . "::" . $callable[1]);
            		    break;
        		case is_object($callable):
            		    \V::app()->log->debug("$name, callable_class=" . get_class($callable));
            		    break;
			default:
            		    \V::app()->log->debug("$name, unknown callable");
            		    break;
        	    }
            	}
                //call_user_func_array($callable, $hookArgs);
		switch (count($hookArgs)) {
		    case 0:
			$result = $callable();
			break;
		    case 1:
			$result = $callable($hookArgs[0]);
			break;
		    case 2:
			$result = $callable($hookArgs[0], $hookArgs[1]);
			break;
		    case 3:
			$result = $callable($hookArgs[0], $hookArgs[1], $hookArgs[2]);
			break;
		    default:
			$result = call_user_func_array($callable, $hookArgs);
		}

		//SC: 2024-05-17: added
		if (!empty($result['skip-all']))
		    break 2;
            }
        }
    }

    /**
     * Get hook listeners
     *
     * Return an array of registered hooks. If `$name` is a valid
     * hook name, only the listeners attached to that hook are returned.
     * Else, all listeners are returned as an associative array whose
     * keys are hook names and whose values are arrays of listeners.
     *
     * @param  string     $name     A hook name (Optional)
     * @return array|null
     */
    public function getHooks($name = null) {
        if (!is_null($name)) {
            return isset($this->hooks[(string) $name]) ? $this->hooks[(string) $name] : null;
        } else {
            return $this->hooks;
        }
    }

    /**
     * Clear hook listeners
     *
     * Clear all listeners for all hooks. If `$name` is
     * a valid hook name, only the listeners attached
     * to that hook will be cleared.
     *
     * @param  string   $name   A hook name (Optional)
     */
    public function clearHooks($name = null) {
	$name = (string)$name;
        if (!is_null($name) && isset($this->hooks[$name])) {
            $this->hooks[$name] = [ [] ];
        } else {
            foreach ($this->hooks as $key => $value) {
                $this->hooks[$key] = [ [] ];
            }
        }
    }

}