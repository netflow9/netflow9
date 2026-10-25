<?php

namespace sys\html;

class QForm extends BaseForm {

    //normalize label/input sizes (in BS col terms)
    // from a spec like:
    //	- '2,10'
    //	- 2
    //	- [ 2, 10 ]
    //	- [ 2 ]
    public function normalizeCols($cols = '') {
	$nCols = 12;
	$defCols = [ 2, 10 ];
	if (empty($cols))
	    return $defCols;

	if (is_string($cols)) {
	    $cols = explode(',', $cols, 2);
	    $cols[0] = intval($cols[0]);
	    $cols[1] = intval($cols[1] ?? $nCols - $cols[0]);
	}

	if (!is_array($cols))
	    return $defCols;

	$cols[0] = intval($cols[0]);
	$cols[1] = intval($cols[1] ?? $nCols - $cols[0]);

	if ($cols[0] + $cols[1] > $nCols)
	    $cols[1] = $nCols - $cols[0];

	return $cols;
    }

    public function _init() {
	parent::_init();

	$config = [
	    'templates' => [
		//components(functions) templates
		'error' => '<div {:options}>{:content}</div>',

		//horizontal/vertical templates
		'tpl-inner-h-default'	=> "{:input}{:help}{:error}",
		'tpl-inner-v-default'	=> "{:label}{:input}{:help}{:error}",

		'tpl-inner-*-checkbox'	=> "<div {:ckwrap_options}>{:input}{:label}{:error}</div>",
		'tpl-inner-*-switch'	=> "<div {:ckwrap_options}>{:input}{:label}{:error}</div>",
		'tpl-inner-*-radio'	=> "<div {:ckwrap_options}>{:input}{:label}{:error}</div>",



		'tpl-outer-h-default'	=> "<div {:wrap_options}>{:label}<div {:iwrap_options}>{:inner}</div></div>",
		'tpl-outer-v-default'	=> "<div {:wrap_options}>{:inner}</div>",
		'tpl-outer-h-checkbox'	=> "<div {:wrap_options}><div {:iwrap_options}>{:inner}</div></div>",
	    ],

	    //templates defaults
	    'tpl-inner-*-checkbox' => [
		'label_options' => [ 'class' => 'form-check-label' ],
		'ckwrap_options' => [ 'class' => 'form-check'],
	    ],
	    'tpl-inner-*-switch' => [
		'label_options' => [ 'class' => 'form-check-label' ],
		'ckwrap_options' => [ 'class' => 'form-check form-switch'],
	    ],
	    'tpl-inner-*-radio' => [
		'label_options' => [ 'class' => 'custom-control-label' ],
		'ckwrap_options' => [ 'class' => 'form-check'],
	    ],

	    'tpl-inner-v-default' => [
		'label_options' => [ 'class' => 'mb-1', 'escape' => false, ],
	    ],

	    'tpl-outer-h-default' => [
		'label_options' => [ 'class' => 'col-sm-x col-form-label', 'escape' => false, ],
		'iwrap_options' => [ 'class' => 'col-sm-x' ],
		 'wrap_options' => [ 'class' => 'form-group row mb-2', ],
	    ],
	    'tpl-outer-v-default' => [
		 'wrap_options' => [ 'class' => 'form-row mb-1', ],
	    ],

	    'tpl-outer-h-checkbox' => [
		 'wrap_options' => [ 'class' => 'form-group row', ],
		'iwrap_options' => [ 'class' => 'col-sm-x offset-sm-y' ],
	    ],

	    //defaults for input types
	    'text' =>		[ 'class' => 'form-control', ],
	    'textarea' =>	[ 'class' => 'form-control', ],
	    'password' =>	[ 'class' => 'form-control', ],
	    'range' =>		[ 'class' => 'form-control', ],
	    'number' =>		[ 'class' => 'form-control' ],
	    'select' =>		[ 'class' => 'form-select', ],
	    'checkbox' =>	[ 'class' => 'form-check-input', 'hidden' => true, ],
	    'switch' =>		[ 'class' => 'form-check-input', 'hidden' => true, ],
	    'radio' =>		[ 'class' => 'form-check-input' ],
	    'date' =>		[ 'class' => 'form-control', ],
	    'datetime' =>		[ 'class' => 'form-control', ],



	    //defaults by function name
	    'help' => [ 'class' => 'form-text text-muted', 'escape' => false, ],
	    'error' => [ 'class' => 'invalid-feedback' ],
	];

	$this->config($config);

	$this->_handlers += [
	    'ckwrap_options'	=> '_attributes',
	    'wrap_options'	=> '_attributes',
	    'iwrap_options'	=> '_attributes',
	    'label_options'	=> '_attributes',
	];
    }

    public function wrap($options = []) {
	$dir = $options['dir'] ?? 'h';
	$type = $options['type'] ?? 'text';
	$cols = $this->normalizeCols($options['cols'] ?? [ 2, 10 ]);

	if (empty($options['wrap_options']))
	    unset($options['wrap_options']);

	switch($type) {
	    case 'switch':
	    case 'checkbox':
		$template = "tpl-outer-{$dir}-checkbox";
		break;
	    default:
		$template = "tpl-outer-{$dir}-default";
		break;
	}

	list(, $options, $template) = $this->_defaults($template, '', $options);

	$defaults = [
	    'label' => null,
	];

	list($options, $field) = $this->_options($defaults, $options);

	if (!empty($options['iwrap_options']['class'])) {
	    $options['iwrap_options']['class'] = str_replace('col-sm-x', 'col-sm-' . $cols[1], $options['iwrap_options']['class']);
	    $options['iwrap_options']['class'] = str_replace('offset-sm-y', 'offset-sm-' . $cols[0], $options['iwrap_options']['class']);
	}

	if ($options['label'] === null || $options['label']) {
	    //set 'for' as the id of the field
	    $options['label_options']['for'] = $options['id'];

	    if (!empty($options['label_options']['class']))
		$options['label_options']['class'] = str_replace('col-sm-x', 'col-sm-' . $cols[0], $options['label_options']['class']);

	    $options['label'] = $this->label($options['id'], $options['label'], $options['label_options']);
	}

	//final preparation
	if (!empty($options['inner']) && is_array($options['inner']))
	    $options['inner'] = implode("\n", $options['inner']);

	//fill in the template
	return $this->_render($template, $options);
    }


    public function field($name, array $options = []) {
	if (is_array($name))
	    return $this->_fields($name, $options);

	if (empty($options['id']))
	    $options['id'] = "field-$name";

	$type = $options['type'] ?? 'text';

	$cols = $options['cols'] ?? [2, 10];

	$dir = $options['dir'] ?? 'h';
	if ($dir != 'h')
	    $dir = 'v';

	//set the field template to use if not provided
	if (isset($options['template'])) {
	    $template = $options['template'];
	} else {
	    switch($type) {
		case 'switch':
		case 'checkbox':
		case 'radio':
		    $template = "tpl-inner-*-{$type}";
		    break;
		default:
		    $template = "tpl-inner-{$dir}-default";
		    break;
	    }
	}

	list(, $options, $template) = $this->_defaults($template, $name, $options);

	$defaults = [
	    'label' => null,
	    'input' => null,
	    'help' => false, //default: no help
	    'error' => [],
	    'value' => isset($options['value']) ? $options['value'] : null,

	    //select options
	    'list' => [],
	    'empty' => false,
	    'ckwrap_options' => null,
	];

	list($options, $field) = $this->_options($defaults, $options);

	//build a rendering data array
	$render_data = [
	    'help' => null,
	    'label' => null,
	    'input' => null,

	    'ckwrap_options' => $options['ckwrap_options'],
	];

	//start rendering of various components
	//1. errors
	$render_data['error'] = ($this->_binding) ? $this->error($name, true, $options['error']) : null;

	//2. input
	//if there are errors, we need to add a class to input
	if (($options['input'] === null) || ($options['input'] !== false ) || $options['input']) {
	    if (!is_array($options['input']))
		$options['input'] = [];

	    list(, $__options, ) = $this->_defaults($options['type'], $name, $options['input']);

	    list($__options, $__input) = $this->_options($defaults, $__options);

	    if ($render_data['error']) {
		$__input['class'] = empty($__input['class']) ? '' : $__input['class'];
		$__input['class'] .= !empty($__input['class']) ? ' ' : '';
		$__input['class'] .= 'is-invalid';
	    }
	    $__input['id'] = $options['id'];
	    $__input['value'] = $options['value'];
	    $__input['empty'] = $options['empty'];
	    $__input['checked'] = $options['checked'] ?? null;

	    $call = ($options['type'] == 'select') ? [ $name, $options['list'], $__input ] : [ $name, $__input ];
    	    $render_data['input'] = call_user_func_array([ $this, $options['type'] ], $call);
	}

	//3. render help; if help is empty  do not render
	if ($options['help']) {
	    if (!is_array($options['help']))
		$options['help'] = [ $options['help'] ];
	    if (!isset($options['help'][1]))
		$options['help'][1] = [];
	    $render_data['help'] = $this->help($options['id'].'-help', $options['help'][0], $options['help'][1]);
	}

	//4. label; if label is empty, generate a default label; care at `switch` where label is raw
	if (($options['label'] === null || $options['label']) && $type != 'hidden') {
	    //set 'for' as the id of the field
	    $label_options = [
		'for' => $options['id'],
	    ];
	    if (!empty($options['label_options']['class']))
		$label_options['class'] = str_replace('col-sm-x', 'col-sm-' . $cols[0], $options['label_options']['class']);

	    $render_data['label'] = $this->label($options['id'], $options['label'], $label_options);
	}

	//fill in the template
	$rendered = $this->_render($template, $render_data);

	if (isset($options['bare']) && $options['bare'] == true)
	    return $rendered;

	//new
	$__options = [
	    'type' => $type,
	    'dir' => $dir,
	    'cols' => $cols,
	    'inner' => $rendered,
	    'id' => $options['id'],
	    'label' => $options['label'],
	    'wrap_options' => $options['wrap_options'] ?? null,
	];

	return $this->wrap($__options);
    }
}