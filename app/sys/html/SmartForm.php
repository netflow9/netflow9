<?php

namespace sys\html;

class SmartForm extends \sys\BaseObject {

    public $binding = null;

    public function bindTo(&$model) {
	if ($model === null)
	    throw new \ErrorException(__METHOD__ . ": model is null");

	$this->binding = $model;
    }

    // ----------------------------------------------------------------------------------------------------------------
    //render block
    public function render() {

	$binding = $this->binding;

	$output = '';
	foreach ($binding->schema as $key => $field) {
	    if (!$attr->checkAccess(\V::app()->user->_id, 'read'))
		continue;

	    $output .= $attr->render($tpl, $options);
	}

	if ($this->js)
	    \V::app()->cs->addPageScript($this->js);

	return $output;
    }
}