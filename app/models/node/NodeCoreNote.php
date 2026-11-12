<?php

namespace models\node;

class NodeCoreNote extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'core';
    public static $name = 'note';
    public static $label = 'Note';
    public static $icon = 'bi-sticky-fill';
    public static $inputs = 0;
    public static $outputs = 0;
    public static $isNote = true;
    public static $defaultWidth = 220; //px
    public static $defaultHeight = 150; //px
    public static $minWidth = 220; //px
    public static $minHeight = 150; //px

    //model field definitions (WfNode::configForm(), a sys\NestedModel)
    public static function configSchema() {
	return [
	    [
		'key' => 'text',
		'type' => 'textarea',
		'label' => 'Description',
		'ui' => [ 'rows' => 7, 'placeholder' => 'Write a note about this part of the workflow…' ],
	    ],
	];
    }

}
