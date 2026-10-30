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
	    //CLD: 2026-10-30T13:00:00+02:00: shown when the sidebar is kept collapsed (User sidebar_collapsed; was always hidden until a collapse click)
	    [ 'class' => 'btn-expand-sidebar' . (empty(\V::app()->var('rt.user')->sidebar_collapsed) ? ' d-none' : ''), 'title' => 'Expand Sidebar', 'escape' => false ]
	);
    }
}