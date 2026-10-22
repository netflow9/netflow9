<?php

namespace sys\html;

/*
    <a href="#" class="btn-expand-sidebar d-none" title="Expand sidebar">
	<i class="bi bi-layout-sidebar-inset"></i>
    </a>
*/

class BtnExpandSidebar extends \sys\BaseObject {
    public function render() {
	return \V::app()->html->link(
	    '<i class="bi bi-layout-sidebar-inset"></i>',
	    '#',
	    [ 'class' => 'btn-expand-sidebar d-none', 'title' => 'Expand Sidebar', 'escape' => false ]
	);
    }
}