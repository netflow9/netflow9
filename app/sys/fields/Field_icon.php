<?php

namespace sys\fields;

class Field_icon extends Field {
    public $icon = 'bi bi-image-fill';
    public $name = 'Icon';
    public $ops = [
	'eval' => [],
	'set' => [],
    ];


    public function __display(&$options = []) {
	$value = $this->binding->data($this->key);
	if (empty($value))
	    $value = 'bi-question::black';

	$i = explode('::', $value);
	if (empty($i[0]))
	    $i[0] = 'bi-question';
	if (empty($i[1]))
	    $i[1] = 'black';

	return "<i class='text-{$i[1]} bi bi-{$i[0]}'></i>";
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());

	//normalize columns
	$cols = \V::app()->form->normalizeCols($options['cols'] ?? null);

	//icon is the form icon::color or just icon
	$icon_html = $this->__display();

	\V::app()->cs->addPageScript("new app.IconPicker('#{$fieldId}');");

	$value = $this->binding->data($this->key);
	//build inner
	$inner = "
	<div class='dropdown' id='{$fieldId}'>
    	    <input type='hidden' name='{$this->key}' value='{$value}'>
	    <button class='btn btn-outline-secondary dropdown-toggle bg-white' type='button' data-bs-toggle='dropdown' aria-expanded='false'>
		{$icon_html}
	    </button>
	    <div class='dropdown-menu p-2' style='width: 15rem;'>
		<input type='text' class='form-control icon-search' placeholder='Search...'>
		<div class='mt-2 icons-container' style='height: 10rem; max-height: 10rem; overflow-y:scroll'>

		    <div class='spinner-border text-primary' role='status'>
			<span class='visually-hidden'>Loading...</span>
		    </div>

		</div>
	    </div>
	</div>
	";

	$bare = $options['bare'] ?? null;
	if (!empty($bare))
	    return $inner;

	$dir = $options['dir'] ?? 'h';
	$dir = ($dir != 'h' ? 'v' : $dir);

	$label = $this->getLabel();

	switch($dir){
	    case 'v':
		$content = "
    		    <label class='col-form-label'>{$label}</label>
    		    <div>{$inner}</div>";
    	    	    $wrap_options = $options['wrap_options'] ?? [ 'class' => 'form-group' ];
		break;

	    case 'h':
	    default:
		$content = "
    		    <label class='col-sm-{$cols[0]} col-form-label'>{$label}</label>
    		    <div class='col-sm-$cols[1]'>{$inner}</div>";
		    $wrap_options = $options['wrap_options'] ?? [ 'class' => 'form-group row' ];
		break;
	}

	return \V::app()->html->tag('block', [ 'content' => $content, 'options' => $wrap_options ] , [ 'escape' => false ]);
    }
}