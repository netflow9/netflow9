<?php
namespace util;

class Set {
    // Add the keys/values in `$array2` that are not found in `$array` onto the end of `$array`.
    // @param mixed $array Original array.
    // @param mixed $array2 Second array to add onto the original.
    // @return array An array containing all the keys of the second array not already present in the first.
    public static function append(array $array, array $array2) {
	$arrays = func_get_args();
	$array = array_shift($arrays);
	foreach ($arrays as $array2) {
	    if (!$array && $array2) {
		$array = $array2;
		continue;
	    }
	    foreach ($array2 as $key => $value) {
		if (!array_key_exists($key, $array)) {
		    $array[$key] = $value;
		} elseif (is_array($value)) {
		    $array[$key] = static::append($array[$key], $array2[$key]);
		}
	    }
	}
	return $array;
    }

    // Computes the difference between two arrays.
    // @param array $val1 First value.
    // @param array $val2 Second value.
    // @return array Computed difference.
    public static function diff(array $val1, array $val2) {
	if (!$val1 || !$val2) {
	    return $val2 ?: $val1;
	}
	$out = array();

	foreach ($val1 as $key => $val) {
	    $exists = isset($val2[$key]);

	    if (($exists && $val2[$key] !== $val) || !$exists) {
		$out[$key] = $val;
	    }
	    unset($val2[$key]);
	}

	foreach ($val2 as $key => $val) {
	    if (!isset($out[$key])) {
		$out[$key] = $val;
	    }
	}
	return $out;
    }

    /**
     * This method can be thought of as a hybrid between PHP's `array_merge()`
     * and `array_merge_recursive()`.  The difference to the two is that if an
     * array key contains another array then the function behaves recursive
     * (unlike `array_merge()`) but does not do if for keys containing strings
     * (unlike `array_merge_recursive()`).  Please note: This function will work
     * with an unlimited amount of arguments and typecasts non-array parameters
     * into arrays.
     *
     * @param array $array1 The base array.
     * @param array $array2 The array to be merged on top of the base array.
     * @return array Merged array of all passed params.
     */
    public static function merge(array $array1, array $array2) {
	$args = [ $array1, $array2 ];

	if (!$array1 || !$array2) {
	    return $array1 ?: $array2;
	}

	$result = (array) current($args);

	while (($arg = next($args)) !== false) {
	    foreach ((array) $arg as $key => $val) {
		if (is_array($val) && isset($result[$key]) && is_array($result[$key])) {
		    $result[$key] = Set::merge($result[$key], $val);

		} elseif (is_int($key)) {
		    $result[] = $val;
		} else {
		    $result[$key] = $val;
		}
	    }
	}
	return $result;
    }

	/**
	 * Collapses a multi-dimensional array into a single dimension, using a delimited array path
	 * for each array element's key, i.e. array(array('Foo' => array('Bar' => 'Far'))) becomes
	 * array('0.Foo.Bar' => 'Far').
	 *
	 * @param array $data array to flatten
	 * @param array $options Available options are:
	 *        - `'separator'`: String to separate array keys in path (defaults to `'.'`).
	 *        - `'path'`: Starting point (defaults to null).
	 * @return array
	 */
	public static function flatten($data, array $options = array()) {
		$defaults = array('separator' => '.', 'path' => null);
		$options += $defaults;
		$result = array();

		if (!is_null($options['path'])) {
			$options['path'] .= $options['separator'];
		}
		foreach ($data as $key => $val) {
			if (!is_array($val)) {
				$result[$options['path'] . $key] = $val;
				continue;
			}
			$opts = array('separator' => $options['separator'], 'path' => $options['path'] . $key);
			$result += (array) static::flatten($val, $opts);
		}
		return $result;
	}




	/**
	 * Convert flatten collection (with delimited array path) to multiple dimmensionals array
	 * @param  Collection $collection Collection to be unflatten
	 * @return Array
	 */
	public static function unflatten($collection, $options = []) {
		$defaults = array('separator' => '.', 'path' => null);
		$options += $defaults;
		$collection = (array) $collection;
    		$output = [];

    		foreach ( $collection as $key => $value ) {
        	    static::array_set($output, $key, $value, $options);
        	    if ( is_array($value) && ! strpos($key, $options['separator']) ) {
            		$nested = static::unflatten($value, $options);
            		$output[$key] = $nested;
        	    }
    		}
    		return $output;
	}


	public static function array_set(&$array, $key, $value, $options=[]) {
    		if ( is_null( $key ) ) 
    		    return $array = $value;
    		$keys = explode( $options['separator'], $key );
    		while ( count( $keys ) > 1 ) {
    		    $key = array_shift( $keys );
        	    // If the key doesn't exist at this depth, we will just create an empty array
        	    // to hold the next value, allowing us to create the arrays to hold final
        	    // values at the correct depth. Then we'll keep digging into the array.
        	    if ( ! isset( $array[$key] ) || ! is_array( $array[$key] ) ) {
            		$array[$key] = array();
        	    }
        	    $array =& $array[$key];
    		}
    		$array[array_shift( $keys )] = $value;
    		return $array;
	}


    //SC: 2019-06-28: copied from yii2, array helper
    /**
     * Builds a map (key-value pairs) from a multidimensional array or an array of objects.
     * The `$key` and `$val` parameters specify the key names or property names to set up the map.
     * Optionally, one can further group the map according to a grouping field `$group`.
     *
     * For example,
     *
     * ```php
     * $array = [
     *     ['id' => '123', 'name' => 'aaa', 'class' => 'x'],
     *     ['id' => '124', 'name' => 'bbb', 'class' => 'x'],
     *     ['id' => '345', 'name' => 'ccc', 'class' => 'y'],
     * ];
     *
     * $result = ArrayHelper::map($array, 'id', 'name');
     * // the result is:
     * // [
     * //     '123' => 'aaa',
     * //     '124' => 'bbb',
     * //     '345' => 'ccc',
     * // ]
     *
     * $result = ArrayHelper::map($array, 'id', 'name', 'class');
     * // the result is:
     * // [
     * //     'x' => [
     * //         '123' => 'aaa',
     * //         '124' => 'bbb',
     * //     ],
     * //     'y' => [
     * //         '345' => 'ccc',
     * //     ],
     * // ]
     * ```
     *
     * @param array $array
     * @param string|\Closure $from
     * @param string|\Closure $to
     * @param string|\Closure $group
     * @return array
     */
    public static function map($array, $key, $val, $group = null) {
        $result = [];
        foreach ($array as $element) {
            $__key = static::getValue($element, $key);
            $value = static::getValue($element, $val);
            if ($group !== null) {
                $result[static::getValue($element, $group)][$__key] = $value;
            } else {
                $result[$__key] = $value;
            }
        }

        return $result;
    }


    /**
     * Retrieves the value of an array element or object property with the given key or property name.
     * If the key does not exist in the array, the default value will be returned instead.
     * Not used when getting value from an object.
     *
     * The key may be specified in a dot format to retrieve the value of a sub-array or the property
     * of an embedded object. In particular, if the key is `x.y.z`, then the returned value would
     * be `$array['x']['y']['z']` or `$array->x->y->z` (if `$array` is an object). If `$array['x']`
     * or `$array->x` is neither an array nor an object, the default value will be returned.
     * Note that if the array already has an element `x.y.z`, then its value will be returned
     * instead of going through the sub-arrays. So it is better to be done specifying an array of key names
     * like `['x', 'y', 'z']`.
     *
     * Below are some usage examples,
     *
     * ```php
     * // working with array
     * $username = \util\Set::getValue($_POST, 'username');
     * // working with object
     * $username = \util\Set::getValue($user, 'username');
     * // working with anonymous function
     * $fullName = \util\Set::getValue($user, function ($user, $defaultValue) {
     *     return $user->firstName . ' ' . $user->lastName;
     * });
     * // using dot format to retrieve the property of embedded object
     * $street = \util\Set::getValue($users, 'address.street');
     * // using an array of keys to retrieve the value
     * $value = \util\Set::getValue($versions, ['1.0', 'date']);
     * ```
     *
     * @param array|object $array array or object to extract value from
     * @param string|\Closure|array $key key name of the array element, an array of keys or property name of the object,
     * or an anonymous function returning the value. The anonymous function signature should be:
     * `function($array, $defaultValue)`.
     * The possibility to pass an array of keys is available since version 2.0.4.
     * @param mixed $default the default value to be returned if the specified array key does not exist. Not used when
     * getting value from an object.
     * @return mixed the value of the element if found, default value otherwise
     */
    public static function getValue($array, $key, $default = null) {
        if ($key instanceof \Closure) {
            return $key($array, $default);
        }

        if (is_array($key)) {
            $lastKey = array_pop($key);
            foreach ($key as $keyPart) {
                $array = static::getValue($array, $keyPart);
            }
            $key = $lastKey;
        }

        if (is_array($array) && (isset($array[$key]) || array_key_exists($key, $array))) {
            return $array[$key];
        }

        if (($pos = strrpos($key, '.')) !== false) {
            $array = static::getValue($array, substr($key, 0, $pos), $default);
            $key = substr($key, $pos + 1);
        }

        if (is_object($array)) {
            // this is expected to fail if the property does not exist, or __get() is not implemented
            // it is not reliably possible to check whether a property is accessible beforehand
            return $array->$key;
        } elseif (is_array($array)) {
            return (isset($array[$key]) || array_key_exists($key, $array)) ? $array[$key] : $default;
        }

        return $default;
    }



}
