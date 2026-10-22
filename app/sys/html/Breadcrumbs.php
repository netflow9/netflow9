<?php

namespace sys\html;

/*
    <!-- breadcrumbs -->
    <nav aria-label="breadcrumb">
	<ol class="breadcrumb mb-0">
	    <li class="breadcrumb-item"><a href="/site/index">Home</a></li>
	    <li class="breadcrumb-item active" aria-current="page">Roles</li>
	</ol>
    </nav>
*/

class Breadcrumbs extends \sys\BaseObject {
    public function __construct($config = []) {
	$defaults = [
	    //tabs options
	    'navItemOptions' => [ 'class' => 'breadcrumb-item' ],
	    'navLinkOptions' => [],
	    'navWrapOptions' => [ 'class' => 'breadcrumb mb-0' ],

	    'activeClass' => 'active',
	    'items' => [],
	];
	$config = \util\Set::merge($defaults, $config);
	parent::__construct($config);
    }

    public function render() {
	$items = [];

	$count = count($this->_config['items']);
	foreach ($this->_config['items'] as $k => $itemCfg) {
	    $itemOptions = $this->_config['navItemOptions'] + ($itemCfg['navItemOptions'] ?? []);
	    $linkOptions = $this->_config['navLinkOptions'] + ($itemCfg['navLinkOptions'] ?? []);

	    //whether to add class active to link
	    if ($k == $count - 1) {
		$itemOptions['class'] .= ' ' . $this->_config['activeClass'];
		$content = $itemCfg['label'];
	    } else {
		$url = (!empty($itemCfg['href']) ? $itemCfg['href'] : \V::app()->createUrl($itemCfg['route'], $itemCfg['params']));
		$content = \V::app()->html->link(
		    $itemCfg['label'],
		    $url,
		    $linkOptions + [ 'escape' => false ]
		);
	    }

	    $items[] = \V::app()->html->tag(
		'list-item',
		[ 'content' => $content, 'options' => $itemOptions ],
		$itemOptions + [ 'escape' => false]
	    );
	}

	//wrap items with an ul
	$list = \V::app()->html->tag(
	    'olist',
	    [ 'content' => implode('', $items), 'options' => $this->_config['navWrapOptions'] ],
	    $this->_config['navWrapOptions']
	);

	return "<nav aria-label='breadcrumb'>$list</nav>";
    }

}