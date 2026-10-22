<?php

namespace sys\html;

/*
<!-- Nav tabs -->
<ul class="nav nav-tabs">
    <li class=" nav-item">
	<a href="#navpills-1" class="nav-link active" data-toggle="tab" aria-expanded="false">Tab One</a>
    </li>
    <li class="nav-item">
	<a href="#navpills-2" class="nav-link" data-toggle="tab" aria-expanded="false">Tab Two</a>
    </li>
    <li class="nav-item">
	<a href="#navpills-3" class="nav-link" data-toggle="tab" aria-expanded="true">Tab Three</a>
    </li>
</ul>
*/

class Nav extends \sys\BaseObject {
    public function __construct($config = []) {
	$defaults = [
	    //tabs options
	    'navItemOptions' => [ 'class' => 'nav-item' ],
	    'navLinkOptions' => [ 'class' => 'nav-link px-3' ],
	    'navWrapOptions' => [ 'class' => 'nav nav-underline pt-2' ],

	    'activeClass' => 'active',
	    'active' => null,
	    'items' => [],
	];
	$config = \util\Set::merge($defaults, $config);
	parent::__construct($config);
    }

    public function render() {
	$items = [];

	//set active tab, if provided or first
	if (empty($this->_config['active']))
	    $this->_config['active'] = array_keys($this->_config['items'])[0];

	foreach ($this->_config['items'] as $itemId => $itemCfg) {
	    $options = $this->_config['navLinkOptions'] + ($itemCfg['navLinkOptions'] ?? []);

	    //whether to add class active to link
	    if ($itemId == $this->_config['active'])
		$options['class'] .= ' ' . $this->_config['activeClass'];

	    $link = \V::app()->html->link(
		$itemCfg['label'],
		$itemCfg['href'] ?? '#' . $itemId,
		$options + [ 'escape' => false ]
	    );

	    $items[] = \V::app()->html->tag(
		'list-item',
		[ 'content' => $link, 'options' => $this->_config['navItemOptions'] ],
		$this->_config['navItemOptions'] + [ 'escape' => false]
	    );
	}

	//wrap items with an ul
	return \V::app()->html->tag(
	    'list',
	    [ 'content' => implode('', $items), 'options' => $this->_config['navWrapOptions'] ],
	    $this->_config['navWrapOptions']
	);
    }

}