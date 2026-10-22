<?php

namespace sys\fields;

trait FieldTraitCast {

    public function __cast_boolean($value) {
	if (is_string($value)) {
	    $__value = strtolower($value);
	    if (in_array($__value, [ 'yes', 'checked', 'true' ]))
		return true;
	    if (in_array($__value, [ 'no', 'unchecked', 'false' ]))
		return false;
	}
	return (bool)$value;
    }

    public function __cast_string($value) {
	if (is_scalar($value))
	    $value = (string)$value;
	if (!is_string($value))
	    $value = '';
	return $value;
    }


    public function __cast_json2array($value) {
	if (empty($value))
	    return [];
	if (is_array($value))
	    return $value;

	if (!is_string($value))
	    throw new \ErrorException('invalid value');

	$value = htmlspecialchars_decode($value, ENT_QUOTES);
	$value = trim($value);
	$value = str_replace("\r", '', $value);

	$value = json_decode($value, true);
	if (json_last_error() != JSON_ERROR_NONE)
	    throw new \ErrorException('invalid value');

	return $value;
    }

    public function __cast_oid($value, $multiple = false) {
	//single values are most common, treat them first
	if (!$multiple) {
	    if ($value instanceof \MongoDB\BSON\ObjectId)
		return $value;

	    if ($value instanceof \sys\QModel)
		return $value->_id;

	    return \V::app()->om->to_oid($value);
	} else {
	    //handle multiple values here
	    //make sure is an array
	    if (!is_array($value))
		$value = [ $value ];

	    $new_value = [];
	    foreach($value as $k => $val) {
		if(empty($val))
		    continue;

		if ($val instanceof \MongoDB\BSON\ObjectId) {
		    $new_value[$k] = $val;
		    continue;
		}

		if ($val instanceof \sys\QModel) {
		    $new_value[$k] = $val->_id;
		    continue;
		}

		if (is_string($val)) {
		    $val = \V::app()->om->to_oid($val);
		    if (!empty($val))
			$new_value[$k] = $val;
		    continue;
		}
	    }

	    //if !assoc, rearrange
	    if (count(array_filter(array_keys($new_value), 'is_string')) == 0)
		$new_value = array_values($new_value);

	    return $new_value;
	}
    }




}