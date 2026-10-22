<?php

namespace sys\html;

class Modal extends \sys\BaseObject {
    public function __construct($config = [] ) {
	$defaults = [
	    'title' => 'modal title',
	    'body' => 'modal body',
	    'footer' => 'modal footer',

	    'beforeContent' => '',
	    'afterContent' => '',

	    'size' => 'lg', //or 'sm', 'xl', ''
	    'fullscreen' => false, //or true
	];
	$config = \util\Set::merge($defaults, $config);
	parent::__construct($config);
    }

    public function render() {
	$tpl = '
	<div id="{:id}" class="modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
	    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered {:size-class} {:full-screen-class}">
		<div class="modal-content">
		    {:before-content}
		    <div class="modal-header">
			<div class="modal-title fs-5">{:title}</div>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		    </div>
		    <div class="modal-body">
			{:body}
		    </div>
		    <div class="modal-footer p-2">
			{:footer}
		    </div>
		    {:after-content}
		</div>
	    </div>
	</div>
	';

	//add size-class
	$sizeClass = '';
	if (in_array($this->size, [ 'sm', 'lg', 'xl' ]))
	    $sizeClass = 'modal-' . $this->size;

	$fullScreenClass = '';
	if ($this->fullscreen) {
	    $fullScreenClass = 'modal-fullscreen';
	    $sizeClass = '';
	}

	if (empty($this->id))
	    $this->id = 'modal-' . uniqid();

	return \util\VString::insert($tpl, [
	    'id' => $this->id,
	    'title' => $this->title,
	    'body' => $this->body,
	    'footer' => $this->footer,
	    'before-content' => $this->beforeContent,
	    'after-content' => $this->afterContent,
	    'size-class' => $sizeClass,
	    'full-screen-class' => $fullScreenClass,
	]);
    }
}

